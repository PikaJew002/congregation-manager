# Zoom recordings on a Cloudflare container

Zoom stays pointed at the Laravel app. A Cloudflare Worker answers a second, private webhook from Laravel and wakes a PHP container for one download or one FFmpeg command. The container sleeps when that command finishes. Laravel remains the system of record for the recording row, the retry button, and `recordings.json`.

Laravel Cloud’s ephemeral disk is 512 MB for every 1 GB of RAM. The current media job keeps the source MP4 and every finished HLS rendition on that disk at once, which a 30–45 minute recording does not fit. Workers Paid includes container memory, CPU, and disk, and the container is billed only while it is awake.

## What stays in Laravel

- `POST /webhooks/zoom/{congregation-uuid}` keeps answering `endpoint.url_validation` and checking `x-zm-signature`.
- The Zoom secret token stays in recording settings. It is not copied to Cloudflare.
- The webhook still stores the meeting, the MP4 `download_url`, and the `download_token`, and it still refreshes those until a source file exists.
- The recordings page, retry, title edits, and the public manifest stay here.
- With no media-worker URL configured, the current `database-media` chain still runs. Local tests and a machine with real disk keep working.

## Code changes in this app

`DispatchRecordingPipeline` gains a branch. When `MEDIA_WORKER_URL` is set, it POSTs the next step to the Worker instead of chaining `DownloadRecording`, `TranscodeAndUpload`, and `UpdateManifest` on `database-media`.

The POST body carries the recording id, the step (`download`, `rendition`, or `poster`), the rendition label when the step is a rendition, the download URL and token for the download step only, and the R2 prefix and public base URL already stored on the recording settings. The body is signed with `MEDIA_WORKER_SECRET` (HMAC-SHA256 over the raw body, sent in one header). The Laravel request returns as soon as the Worker accepts the job. It does not wait for FFmpeg.

A callback route, authenticated with the same secret, receives the step result. On success it advances the recording status and dispatches the next step. On failure it sets `failed` and `failed_step` to the step that failed, which is what the existing retry button already reads. The last successful callback, after every required rendition and the poster are uploaded, runs the existing manifest update in Laravel. That step only rewrites JSON.

Rendition choice stays the rule in `scripts/hls_package.sh`. A variant is encoded only when the source is at least about that tall. The container reports the source height after download, and Laravel decides the remaining steps from that. A 360p file does not grow a 480p, 720p, or 1080p variant.

`DownloadRecording` and `TranscodeAndUpload` stay for the local path. They are not invoked on Laravel Cloud once the worker URL is set, because neither the Zoom file nor the HLS package fits on that disk.

New environment variables on the Laravel app:

- `MEDIA_WORKER_URL`, the Worker URL, empty in local and test.
- `MEDIA_WORKER_SECRET`, shared with the Worker.

## The media worker

This is a new directory in this repo, deployed separately from Laravel. It has three pieces.

**Worker.** Public only to Laravel. `POST /jobs` checks the signature, returns 202, and hands the body to one Durable Object named by the recording id. Zoom never calls this URL.

**Durable Object.** One object per recording. It stores the current step and starts the container. It then calls PHP and keeps that call open until the command exits. FFmpeg running inside the container does not reset the object’s idle timer, so the open call is what keeps the instance alive. The inactivity timeout is set longer than the worst encode (a few hours) so a quiet gap cannot stop the process mid-command. A second POST for a step that is already running is acknowledged and does not start a second encode.

**Container.** A small PHP process plus FFmpeg, not the congregation app. One request runs exactly one command:

1. `download`. Save the Zoom MP4 to the container disk, using the bearer token on every redirect, then upload it to the private files bucket. This is the only step that sees the Zoom download token.
2. `rendition`. Read that MP4 back from the private bucket, run the matching command from `scripts/hls_package.sh` (one of 480p, 720p, 1080p), upload the playlist and segments to the public bucket.
3. `poster`. Grab the single frame the script already grabs and upload it next to the playlists.

PHP exits when the command exits. The disk is ephemeral and disappears with the instance, so a retry downloads from R2 again. The Zoom token is only required when the private object was never written and the token is still inside its 24-hour life.

Outbound requests from a container are limited to a 100 MB body. The source MP4 is uploaded to R2 in parts. HLS segments are a few seconds each and can be single puts. The public object keys stay `{prefix}/{recording id}/...`, which is the layout `UpdateManifest` and the website already use.

Instance size is chosen per step. Download and a 360p rendition use `basic` (1 GiB RAM, 4 GB disk). A 720p or 1080p rendition uses `standard-1` (4 GiB RAM, 8 GB disk) so the source and that one rendition fit. Nothing in this pipeline needs the container to stay warm between recordings.

## Cloudflare configuration

You only need to turn on Workers Paid. There is no queue, container, or custom domain to create in the dashboard. Deploy creates the Worker, the Durable Object, and the container image.

After the account is on Workers Paid, the values below are what implementation needs. Do not put the Zoom secret in Cloudflare.

1. Cloudflare account ID.
2. Permission to deploy from this machine: either a completed `wrangler login`, or an API token that can edit Workers scripts, create Durable Objects, and deploy containers on that account.
3. An R2 API token that can read and write the private files bucket and the public recordings bucket this app already uses. Send the access key id, the secret, the account id embedded in the S3 endpoint, and both bucket names. A token scoped to those two buckets is enough. The Laravel app’s existing keys can stay as they are.
4. The public base URL of the Laravel app on Laravel Cloud, so the container can call the callback route.
5. Confirmation that Zoom should keep using the Laravel webhook URL shown on the recording settings page.

`MEDIA_WORKER_SECRET` does not need to be invented on your side. Implementation will generate it, store it as a Worker secret, and give you the same value to set on Laravel Cloud together with `MEDIA_WORKER_URL`.

Workers and Durable Object usage for a few recordings a month sits inside the paid plan’s included request amounts. Container CPU, memory, and disk past the monthly allowance bill on top of the $5. A weekly recording of the 360p file already measured stays inside the allowance. A weekly 1080p rendition can use most of the included memory on a single run, and the overage past that is a fraction of a dollar at the published per-second rates.
