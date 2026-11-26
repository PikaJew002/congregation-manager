# Weekly email sending

Each congregation sends its weekly email through its own MailChannels account. We hold no shared key and support no other provider. Mail goes out one person at a time, a few seconds apart, at the same time every week, so receiving servers and MailChannels see a small organization writing to its members rather than a burst from a bulk sender.

## What we have today

- `MailChannelsTransport` posts one recipient per message to `POST /tx/v1/send`, with List-Unsubscribe and List-Unsubscribe-Post headers. The `mailchannels` mailer reads one key and one DKIM key from `.env`, and Weekly email uses it for every congregation.
- `DispatchWeeklyEmailRun` runs every minute. When a congregation's send window opens it creates an `EmailRun`, one `EmailDelivery` per subscriber, and one delayed `SendWeeklyEmailJob` per delivery, spread evenly across `window_minutes` (30–240) with up to 20 seconds of jitter. Recipients go out in id order every week.
- A failed send marks the delivery failed at once. There is no retry for temporary errors, and a bad key or exhausted quota fails every remaining delivery one by one.
- One-click unsubscribe works (`unsubscribe/*` is exempt from CSRF). Bounces and spam complaints are never learned about, so bad addresses keep getting mail.
- The test send goes through the same shared key.

## Decisions

- **Bring your own key, MailChannels only.** The key, sending domain and DKIM selector belong to the congregation and live on `teams`, like the time zone, so any module that sends mail uses the same account. Weekly email is the first to read them. Congregation mail never falls back to the platform key. The `MAILCHANNELS_*` variables stay in `.env.example` and `config/mail.php`, and the `mailchannels` mailer stays as the platform's own account for app mail (password resets, invitations) when that is wired up.
- **Send honestly as non-transactional.** A weekly newsletter is list mail, and MailChannels' sending policy asks for it to be marked `transactional: false` with a `campaign_id`. That requires DKIM and one recipient per personalization, which we already do. Looking personal comes from how we send (pace, plain content, no tracking, a real reply-to), not from mislabeling the stream; mislabeling risks the congregation's account.
- **MailChannels holds the congregation's DKIM key.** We create it with `POST /tx/v1/domains/{domain}/dkim-keys` and keep only the selector. No congregation private key is stored in our database or sent per request. The platform mailer keeps signing with the DKIM key in `.env`.
- **Sending is blocked until the domain checks pass.** The schedule cannot be turned on, and a due run is not created, until DKIM, SPF and Domain Lockdown pass `POST /tx/v1/check-domain`.

## Sending settings

A new **Email sending** section on the congregation settings page (`teams/Edit`), below Modules, for people who can update the congregation. It is shown whether or not Weekly email is on, since the account is the congregation's.

New columns on `teams`:

| Column                              | Notes                                                                                                                                                                                                    |
| ----------------------------------- | -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `mailchannels_api_key`              | `text`, cast `encrypted`, and in `$hidden` so it never reaches the browser through the shared team props. The page gets `has_mailchannels_api_key`. Same save/clear handling as the Zoom webhook secret. |
| `mailchannels_account_id`           | Shown in the Domain Lockdown record the admin must publish. Entered by the admin (it is in the MailChannels dashboard footer).                                                                           |
| `sending_domain`                    | Entered here. Weekly email's `from_address` must be on this domain; the template page validates it. Changing the domain clears the checks below.                                                         |
| `dkim_selector`                     | Set when we create the managed key.                                                                                                                                                                      |
| `domain_checked_at`, `domain_check` | `json` of the last `check-domain` result (DKIM, SPF, lockdown, sender domain verdicts and reasons).                                                                                                      |
| `daily_email_limit`                 | The congregation's MailChannels plan limit per day. Defaults to 100, the free plan.                                                                                                                      |
| `webhook_token`                     | Random 64 characters, identifies the congregation on incoming webhooks. Also `$hidden`.                                                                                                                  |

The new columns are added through an `UpdateTeamEmailSendingRequest` rather than `SaveTeamRequest`, and are not in `Fillable`, so the general team update cannot touch them.

The section walks through setup in order and shows the state of each step:

1. **API key.** Saving it calls `check-domain` for the sending domain as a cheap key check: 403 or 401 means the key is wrong, and we say so before saving.
2. **DKIM.** A Create DKIM key button calls the dkim-keys endpoint with selector `cm{yyyymm}` and shows the TXT record (`<selector>._domainkey.<domain>`) to publish.
3. **SPF and Domain Lockdown.** Shows the records to publish: `include:relay.mailchannels.net` in the domain's SPF record, and `_mailchannels.<domain> TXT "v=mc1 auth=<account id>"`.
4. **Check again.** Runs `check-domain` with the selector and stores the result. Each record shows passed or failed with MailChannels' reason.
5. **Delivery events.** An Enable button enrolls our webhook on their account (see below).

`updateSchedule` refuses `enabled = true` unless the last check passed all three. `DispatchWeeklyEmailRun` re-runs the check when it is older than 24 hours before creating a run, and skips the run with an attention item on the dashboard if it now fails.

## Building the mailer per congregation

Weekly email stops using `Mail::mailer('mailchannels')`, which stays for platform mail. New `App\Mail\CongregationMailer::for(Team $team)` builds a mailer with `Mail::build(['transport' => 'mailchannels', ...])` from that congregation's key and selector, and throws `EmailSendingNotReady` when the key is missing or the domain checks have not passed. It lives outside the module so later modules can use it. The transport changes:

- Takes the DKIM private key as optional: with one (the platform mailer from `.env`) it sends all three DKIM fields as today; without one (congregations) it sends `dkim_domain` and `dkim_selector` only, so MailChannels signs with the stored key.
- Takes `transactional` and tracking as mailer config, so the platform mailer can send password resets as transactional while congregation mail is not.
- Sends `transactional: false` and `campaign_id` (`weekly-{run id}`, or `test` for test sends).
- Turns tracking off explicitly (`tracking_settings.click_tracking.enable` and `open_tracking.enable` false). Tracking pixels and rewritten links are a common marketing signal.
- Keeps our own List-Unsubscribe headers, so MailChannels does not add its own.
- Reads the 202 body and returns the per-message `message_id`, which `SendWeeklyEmailJob` stores on the delivery so webhooks can find it.
- Raises distinct exceptions: `MailChannelsRejected` (400, 413: this message is bad), `MailChannelsAccountProblem` (401, 403, "exceeded their daily limit", "Sending quota exceeded": nothing more will go through today), and `MailChannelsTemporary` (5xx, timeouts, "Sending too fast").

## Cadence

MailChannels sends from its own shared servers, so receiving providers judge the congregation's domain, not the pace inside one send. What builds trust is the same volume at the same time every week. A long window adds little and makes members get the email at very different times, which matters for a same-day Zoom link. So the send is short and paced: one person at a time, a few seconds apart, finished in minutes.

**Pacing.** Each delivery is scheduled 5–15 seconds (random) after the one before, starting at `send_time`. A list of 60 finishes in about 10 minutes; 240 in about 40. The gap keeps clear of MailChannels' "Sending too fast" throttle and means a bad key or exhausted quota stops the run after one failure instead of sixty.

**Order.** Recipients are shuffled each run, so the same people are not always first or last.

**Volume.** If the subscribed count exceeds `daily_email_limit`, the run is not created; the dashboard and Schedule page say why. Splitting a weekly email across days would confuse members, so we stop and ask rather than spread it.

**The window setting goes away.** `window_minutes` is dropped from `weekly_email_settings` and the Schedule page. The Schedule page instead says roughly how long the send will take for the current list. `DispatchWeeklyEmailRun` still only creates a run on the send day at or after `send_time`, and keeps a fixed 30-minute catch-up so a scheduler outage at `send_time` does not skip the week.

**Who drives the clock.** Today each delivery is a delayed job queued when the run starts. That cannot be paused or stopped cleanly once queued. Instead:

- The run stores each delivery's `scheduled_at` as today, but queues nothing up front.
- `weekly-email:send-due` runs every minute (with `withoutOverlapping`). For each run in `scheduled` or `sending`, it queues `SendWeeklyEmailJob` for deliveries with `scheduled_at` in the coming minute, delayed to the second, and sets their `queued_at` (they stay `pending`, so the run pages are unchanged). A delivery queued more than 15 minutes ago and still pending is queued again, in case a job was lost.
- `SendWeeklyEmailJob` uses `WithoutOverlapping` keyed by team, so one congregation's sends stay serial however the queue is scaled, and `RateLimited` keyed by team at 10 a minute as a safety ceiling well above the normal pace.

## Failures

- **Temporary errors** release the job with backoff (1, 5, 15 minutes), up to 3 attempts, then mark the delivery failed. "Sending too fast" also pushes every later delivery in the run back by 2 minutes.
- **Account problems** pause the run (`status = paused`, `pause_reason` stored) and leave the remaining deliveries pending. The dashboard shows the reason with a link to Email sending in congregation settings. A Resume button on the run reschedules the pending deliveries from now at the same pace. A run still paused 24 hours after it was scheduled is cancelled, so last week's email does not go out days late.
- **Rejected messages** fail that delivery only.
- Retry on a run (existing button) reschedules failed deliveries through the same pacing instead of sending them all at once.

## Delivery events (webhooks)

MailChannels allows one webhook per account. Because the account is the congregation's, we enroll with their key: `POST /tx/v1/webhook?endpoint=<app url>/webhooks/mailchannels/{webhook_token}`. A 409 means their account already has a webhook pointing elsewhere; we show that and the URL they would need to replace it with, and sending still works without it.

The endpoint (already CSRF-exempt under `webhooks/*`):

- Verifies the RFC 9421 signature: checks `Content-Digest` against the body, rebuilds the signature base from `Signature-Input`, fetches the ed25519 key from `GET /tx/v1/webhook/public-key?id=<keyid>` (cached a day), verifies with sodium, and rejects signatures older than 5 minutes.
- Finds the congregation by token, then each event's delivery by `request_id` / message id and recipient email.
- Acts on events:
    - `delivered`: `delivered_at` on the delivery.
    - `hard-bounced`: delivery `bounced`; recipient `status = bounced`, no longer sent to.
    - `complained`: recipient `status = complained`, no longer sent to. A complaint is the strongest negative signal there is.
    - `unsubscribed`: recipient unsubscribed, same as our own link.
    - `soft-bounced`, `dropped`: recorded on the delivery; a recipient with soft bounces on 3 consecutive runs becomes `bounced`.
    - `open`, `click`: ignored (tracking is off).
- Returns 200 quickly; processing is a queued job so the webhook never times out.

Bounced and complained recipients show on the Email list with the reason. An admin can resubscribe a bounced address (they fixed a typo); a complained one needs the person to sign up again, so the admin can only delete it.

## Test sends

Test sends use the congregation's mailer, so they double as a setup check. Limited to 5 an hour per congregation. If the domain checks have not passed, the button is disabled with the reason.

## Schema changes

- `teams`: the columns above.
- `weekly_email_settings`: drop `window_minutes`.
- `email_runs`: `paused` status, `pause_reason`, `paused_at`.
- `email_deliveries`: `bounced` status; `queued_at`, `message_id`, `attempts`, `delivered_at`, `last_event`.
- `email_recipients`: `bounced` and `complained` statuses, `status_reason`, `soft_bounce_count`.

## Tests

- Settings: key saved encrypted and never returned, including in the shared team props; the general team update cannot set it; wrong key refused on save; DKIM create stores the selector and shows the record; check results stored; schedule cannot be enabled until checks pass.
- Transport: no private key in the payload for congregation mail, and the `.env` key still sent for the platform mailer (the existing `MailChannelsTransportTest` keeps passing); `transactional: false`, `campaign_id` and tracking off are sent; `message_id` returned; each error class from the right responses.
- Pacing: every gap is 5–15 seconds; recipients are shuffled; run refused over `daily_email_limit`; a run is still created when the scheduler catches up within 30 minutes, and not after.
- Send loop: only deliveries due in the next minute are queued; one team's sends never overlap; the 10-a-minute ceiling holds per team with two teams sending at once.
- Failures: temporary error retries then fails; account problem pauses the run and leaves the rest pending; resume reschedules from now; stale paused run is cancelled.
- Webhooks: valid signature accepted; bad digest, bad signature, old signature and unknown token refused; each event updates delivery and recipient as described; bounced and complained recipients are skipped by the next run.

## Order of work

1. Congregation key and the Email sending section on congregation settings (key, account id, domain, daily limit), `CongregationMailer`, the transport changes, and test sends through it. Weekly email stops using the platform `mailchannels` mailer.
2. Managed DKIM, `check-domain`, and the gate on enabling the schedule and creating runs.
3. Pacing: 5–15 second gaps, shuffle, daily limit, dropping `window_minutes`, and the per-minute send loop with per-team job middleware.
4. Failure handling: error classes, retries, pause and resume.
5. Webhook enrollment, signature verification, and event handling, with the new recipient statuses on the Email list.
6. Dashboard attention items for paused runs, failed domain checks and lists over the daily limit.

## Open questions

- None outstanding.
