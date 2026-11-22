# Congregation Manager

Multi-congregation admin app. Each congregation is a team. People join by invitation, and a super admin turns modules on for the congregation and grants them to members.

## Local setup

```bash
composer install
npm install
cp .env.example .env
php artisan key:generate
php artisan migrate
npm run dev
php artisan serve
```

Use `QUEUE_CONNECTION=database` and run `php artisan queue:work` for email. Recording jobs use the `database-media` connection:

```bash
php artisan queue:work database-media --queue=media --timeout=7200
```

`FFMPEG_BINARY` and `FFPROBE_BINARY` should point at a local FFmpeg install. `deploy/ffmpeg.sh` downloads a static build into `$HOME/bin` on Linux.

## Laravel Cloud

Build command:

```bash
bash deploy/ffmpeg.sh
composer install --no-dev --optimize-autoloader
npm ci && npm run build
php artisan optimize
```

Deploy command:

```bash
php artisan migrate --force
```

Turn the scheduler on. It dispatches the weekly email once a minute.

Run two worker clusters:

- `default`, command `php artisan queue:work database --queue=default`, for email.
- `media`, command `php artisan queue:work database-media --queue=media --timeout=7200`, with more CPU, memory, and disk. FFmpeg writes the HLS package on that machine before uploading it.

Set `FFMPEG_BINARY` to `$HOME/bin/ffmpeg` and `FFPROBE_BINARY` to `$HOME/bin/ffprobe`.

Create a MySQL database. Create an R2 bucket for public recordings and, if you want originals and slide files private, a second bucket. Point these variables at them:

- `R2_ACCESS_KEY_ID`, `R2_SECRET_ACCESS_KEY`, `R2_ENDPOINT`, `R2_REGION`
- `R2_BUCKET` and `R2_PUBLIC_URL` for the public recording disk
- `R2_FILES_BUCKET` for private slide files and generated decks. It falls back to `R2_BUCKET` when unset.

Weekly email uses the `mailchannels` mailer even when `MAIL_MAILER` stays `log`. Set `MAILCHANNELS_API_KEY`, `MAILCHANNELS_DKIM_DOMAIN`, `MAILCHANNELS_DKIM_SELECTOR`, and `MAILCHANNELS_DKIM_PRIVATE_KEY`. Publish the matching DKIM TXT record at `{selector}._domainkey.{domain}`. Add the MailChannels domain lockdown TXT record (`_mailchannels`) so only this account can send for that domain.

## Zoom recordings

1. In Zoom Marketplace, create an app that can send webhooks. Event subscriptions need `endpoint.url_validation` and `recording.completed`.
2. Open Recording settings in the app. Copy the webhook URL. It looks like `https://your-app.example/webhooks/zoom/{congregation-uuid}`. Paste that URL and the Zoom secret token into the app.
3. Set the public base URL to `R2_PUBLIC_URL` plus the prefix you store recordings under. The player reads `{prefix}/recordings.json`.

Allow the website that plays the recordings to read the HLS files. On the public R2 bucket, add a CORS rule:

```json
[
    {
        "AllowedOrigins": ["https://your-site.example"],
        "AllowedMethods": ["GET", "HEAD"],
        "AllowedHeaders": ["*"],
        "ExposeHeaders": ["Content-Length", "Content-Range", "Accept-Ranges"],
        "MaxAgeSeconds": 3600
    }
]
```

## Service slides

Songs must be ODP (saved from Impress); liturgy must be PPTX (saved from PowerPoint). Upload a file or paste a Dropbox or Google Drive share link. A template is one format or the other, and its decks only take library items in the same format. The app merges template slides and library items into one file per deck, in the template's format. It does not edit slide text.

An agent can call `POST /mcp/services`. Claude signs in with OAuth: add that URL as a custom connector, then approve one congregation. The server publishes the OAuth discovery documents, dynamic client registration, and PKCE that Claude's connector expects. Run `php artisan passport:keys` once on each server so Passport can issue tokens; the key files stay out of git.

Scripts that cannot open a browser can still send a Sanctum token from Slide access. Create the token in that congregation; it is limited to the `service-slides` ability and that congregation id. The tools are `search-library`, `list-templates`, `list-services`, `get-service`, `upsert-service-deck`, `add-alias`, `add-library-item`, and `generate-deck`. `add-library-item` stores an ODP or PPTX file in the connected congregation's library from base64, a Dropbox link, or a Google Drive share/view/edit link.
