# Media worker

Cloudflare Worker + Durable Objects + container that run the Zoom recording media steps for Laravel. The design is in `docs/plans/zoom-recording-container.md`.

- `src/index.ts`: the Worker (`POST /jobs`, signed by Laravel) and the `MediaSmall` (`basic`) / `MediaLarge` (`standard-1`) container objects. 720p and 1080p go to `MediaLarge`. Everything else goes to `MediaSmall`.
- `container/`: PHP + FFmpeg. One request runs one step (`download`, `rendition`, `poster`) through `scripts/hls_package.sh`, which is copied in at deploy time.

Each object reports the result to Laravel at `CALLBACK_URL`, then destroys its container so billing stops. A watchdog alarm restarts a step whose run was lost (up to 3 tries), and Laravel fails any step with no report after 4 hours.

## Deploy

Docker must be running.

```bash
npm install
npm run deploy
```

Secrets (already set): `MEDIA_WORKER_SECRET`, `R2_ACCESS_KEY_ID`, `R2_SECRET_ACCESS_KEY`. Change one with `npx wrangler secret put NAME`. If you change `MEDIA_WORKER_SECRET`, set the same value on Laravel Cloud.

Logs: `npx wrangler tail`.
