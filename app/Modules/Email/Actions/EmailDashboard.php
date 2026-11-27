<?php

namespace App\Modules\Email\Actions;

use App\Mail\EmailSending;
use App\Models\Team;
use App\Modules\Email\Models\Email;
use App\Modules\Email\Models\EmailRecipient;
use App\Modules\Email\Models\EmailRun;
use Carbon\CarbonImmutable;

/**
 * The Email card on the dashboard: which email goes out next, how the last
 * send went, and the size of the list.
 */
class EmailDashboard
{
    /**
     * @return array<string, mixed> The card, shaped as ProvidesDashboard describes.
     */
    public function handle(Team $team): array
    {
        $scheduled = Email::query()->forTeam($team)->where('status', 'scheduled')->get();
        $subscribers = EmailRecipient::query()->forTeam($team)->where('status', 'subscribed')->count();
        $unsubscribed = EmailRecipient::query()->forTeam($team)
            ->where('status', 'unsubscribed')
            ->where('unsubscribed_at', '>=', now()->subDays(30))
            ->count();

        $runs = EmailRun::query()->forTeam($team)
            ->withCount([
                'deliveries',
                'deliveries as sent_count' => fn ($query) => $query->where('status', 'sent'),
                'deliveries as failed_count' => fn ($query) => $query->where('status', 'failed'),
                'deliveries as retryable_count' => fn ($query) => $query->retryable(),
            ])
            ->with('email:id,name')
            ->latest('scheduled_for')
            ->limit(3)
            ->get();

        $last = $runs->first();
        $sending = $last && in_array($last->status, ['scheduled', 'sending'], true);
        [$nextEmail, $next] = $this->nextSend($scheduled, $team);

        $attention = [];

        $paused = $last?->status === 'paused';

        if ($paused) {
            $attention[] = [
                'title' => 'Sending is paused',
                'detail' => $last->pause_reason.' Fix it, then resume the run.',
                'href' => route('email.runs.show', [$team->slug, $last]),
                'tone' => 'danger',
            ];
        }

        // Failures to people since taken off the list are not worth retrying;
        // they show below as people taken off the list.
        if ($last && $last->retryable_count > 0) {
            $attention[] = [
                'title' => trans_choice(':count email failed to send|:count emails failed to send', $last->retryable_count),
                'detail' => 'In the send of “'.$last->subject.'” on '.$this->local($last->scheduled_for, $team)->format('M j').'. Retry it from Sent.',
                'href' => route('email.runs.show', [$team->slug, $last]),
                'tone' => 'danger',
            ];
        }

        if ($scheduled->isNotEmpty() && ($problem = EmailSending::problem($team)) !== null) {
            $attention[] = [
                'title' => 'Scheduled emails cannot send',
                'detail' => $problem.($next ? ' Until it is fixed, the email due '.$next->format('l, M j').' will not go out.' : ''),
                'href' => route('teams.edit', $team->slug),
                'tone' => 'danger',
            ];
        }

        if ($scheduled->isNotEmpty() && $subscribers > $team->daily_email_limit) {
            $attention[] = [
                'title' => 'The email list is over the daily sending limit',
                'detail' => "{$subscribers} subscribers, but the MailChannels plan allows {$team->daily_email_limit} a day. Emails will not go out until the limit is raised.",
                'href' => route('teams.edit', $team->slug),
                'tone' => 'danger',
            ];
        }

        if ($last && ($removed = $this->removedAfter($last)) !== null) {
            $attention[] = $removed + ['href' => route('email.recipients', $team->slug)];
        }

        if ($scheduled->isNotEmpty() && $subscribers === 0) {
            $attention[] = [
                'title' => 'Nobody is on the email list',
                'detail' => 'An email is scheduled, but nobody will get it.',
                'href' => route('email.recipients', $team->slug),
                'tone' => 'warning',
            ];
        }

        return [
            'headline' => match (true) {
                $paused => [
                    'title' => 'Sending paused',
                    'detail' => "{$last->sent_count} of {$last->deliveries_count} sent before it paused",
                    'status' => ['label' => 'Paused', 'tone' => 'danger'],
                ],
                $sending => [
                    'title' => 'Sending now',
                    'detail' => "{$last->sent_count} of {$last->deliveries_count} sent so far",
                    'status' => ['label' => 'Sending', 'tone' => 'warning'],
                ],
                $next !== null => [
                    'title' => 'Next email '.$next->format('l, M j \a\t g:i A'),
                    'detail' => $nextEmail?->name,
                    'status' => ['label' => 'Scheduled', 'tone' => 'success'],
                ],
                default => [
                    'title' => 'Not scheduled',
                    'detail' => 'Schedule an email under Emails to start sending.',
                    'status' => ['label' => 'Off', 'tone' => 'muted'],
                ],
            },
            'stats' => [
                ['label' => 'Subscribers', 'value' => (string) $subscribers],
                ['label' => 'Unsubscribed (30 days)', 'value' => (string) $unsubscribed, 'tone' => 'muted'],
                [
                    'label' => 'Last send',
                    'value' => $last ? "{$last->sent_count} of {$last->deliveries_count}" : '—',
                    'tone' => $last && $last->failed_count > 0 ? 'danger' : 'muted',
                ],
            ],
            'attention' => $attention,
            'activity' => $runs->map(fn (EmailRun $run) => [
                'at' => CarbonImmutable::parse($run->scheduled_for)->toIso8601String(),
                'title' => ($run->email->name ?? $run->subject).' '.$run->status,
                'detail' => "{$run->sent_count} of {$run->deliveries_count} sent".($run->failed_count ? ", {$run->failed_count} failed" : ''),
                'href' => route('email.runs.show', [$team->slug, $run]),
            ])->values()->all(),
        ];
    }

    /**
     * The scheduled email that goes out next, and when, in the congregation's
     * time zone. A one-time email already past its time is left out.
     *
     * @param  iterable<Email>  $emails
     * @return array{0: Email|null, 1: CarbonImmutable|null}
     */
    public function nextSend(iterable $emails, Team $team, ?CarbonImmutable $now = null): array
    {
        $now ??= CarbonImmutable::now();
        $best = [null, null];

        foreach ($emails as $email) {
            $at = $email->nextSendAt($team, $now);

            if ($at !== null && $at->gte($now) && ($best[1] === null || $at->lt($best[1]))) {
                $best = [$email, $at];
            }
        }

        return $best;
    }

    /**
     * People taken off the list because of what happened to the given send:
     * bounces, spam complaints and MailChannels refusals. A complaint is the
     * one that most hurts the account, so it makes the item urgent.
     *
     * @return array{title: string, detail: string, tone: string}|null
     */
    private function removedAfter(EmailRun $run): ?array
    {
        $counts = EmailRecipient::query()
            ->whereIn('status', ['bounced', 'complained', 'suppressed'])
            ->whereHas('deliveries', fn ($query) => $query->where('email_run_id', $run->id))
            ->toBase()
            ->selectRaw('status, count(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status')
            ->map(fn ($total) => (int) $total);

        $total = $counts->sum();

        if ($total === 0) {
            return null;
        }

        $parts = array_filter([
            $counts->has('complained') ? trans_choice(':count reported it as spam|:count reported it as spam', $counts['complained']) : null,
            $counts->has('bounced') ? trans_choice(':count bounced|:count bounced', $counts['bounced']) : null,
            $counts->has('suppressed') ? trans_choice(':count refused by MailChannels|:count refused by MailChannels', $counts['suppressed']) : null,
        ]);

        return [
            'title' => trans_choice(':count person was taken off the email list|:count people were taken off the email list', $total),
            'detail' => 'After the last send: '.implode(', ', $parts).'. The reasons are on the email list.',
            'tone' => $counts->has('complained') ? 'danger' : 'warning',
        ];
    }

    private function local(mixed $moment, Team $team): CarbonImmutable
    {
        return CarbonImmutable::parse($moment)->setTimezone($team->localTimezone());
    }
}
