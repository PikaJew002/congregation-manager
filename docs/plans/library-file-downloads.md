# Library file downloads

Let people download the files behind the slide library, both in the app and through MCP. Someone fixing a song in Impress needs its file, and Claude should be able to hand over a song or a generated deck as a link.

## What we have today

- Library files live on the private `files` disk (R2). A song has two: the upload as received (`original_path`) and the formatted copy decks are built from (`file_path`). Other kinds use the same file for both.
- Generated decks can be downloaded in the app: `DeckController::download` checks the team and streams from the `files` disk. `ServiceController::downloadAll` zips every deck of a service.
- Nothing in MCP returns a file. `generate-deck` answers `status: queued`, and `get-service` shows deck status but no way to fetch the result.
- MCP connections are OAuth only. The token carries the user and the congregation, and each tool call checks `ModuleAccess::canUse($user, $team, 'service-slides')` through `CongregationContext`.

## In the app

New route `GET service-slides/library/{source}/download`, next to the other library routes and behind the same login and team middleware:

- `file=formatted` (default) streams `file_path`; `file=original` streams `original_path`.
- It returns 404 when the item belongs to another team or the file is missing, as the deck download does.
- The download is named after the title with the stored format's extension, for example `Blessed Be Your Name.odp`. Characters not allowed in file names are replaced, matching how `DeckController::fileName()` names decks.
- The Library page gets a Download button in each item's actions. For songs whose original differs from the formatted file (`has_original_file` on each row), a second Download original button sits beside it.
- Archived items stay downloadable.

## Through MCP: signed links

A tool cannot put a file on the user's computer, and embedding files in responses is handled poorly by many clients and wastes context on files up to several megabytes. MCP returns a short-lived link to the app instead.

New route `GET slide-files/{kind}/{id}` (`kind` is `library` or `deck`) outside the login middleware, using Laravel's signed URLs and throttled to 60 requests a minute:

- Created with `URL::temporarySignedRoute()`, valid for 10 minutes.
- The signed parameters say what to send (`library` item and `formatted` or `original`, or a generated `deck`), the user, and the congregation. Changing any of them breaks the signature, so a link cannot be edited to reach another item or congregation.
- When the link is opened, the route checks the signature and expiry, then repeats the tool-call checks: the user still belongs to the congregation and can still use Service slides there, and the item or deck still belongs to that congregation. A link stops working when someone loses access, even before it expires.
- It then streams the file with the same name the app uses. Redirecting to a presigned R2 URL would avoid streaming through the app, but streaming keeps the bucket host private and makes the checks above the last word. Files are small enough that this is fine.
- Each download is logged with the user, congregation, and what was sent.

The URL holds ids and a signature, nothing private. Anyone holding a link can use it until it expires, and MCP transcripts may keep it, which is why it is short-lived and re-checked.

## MCP tools

- New `get-library-file`: takes a library item `id` (as returned by `search-library`) and optional `file` (`formatted` or `original`). It returns `{ title, format, file, fileName, url, expiresAt }`, or an error when the item is not in this congregation.
- `get-service`: each deck with a generated file gains `downloadUrl` and `expiresAt`. A deck still generating or failed has none.
- `generate-deck` stays asynchronous. Its description says to call `get-service` for the link once the deck is ready.

Tool descriptions tell the model the link expires in 10 minutes and should be given to the person rather than fetched.

## Tests

- App route: formatted and original files, another team's item, missing file, and the download name.
- Signed route: a valid link streams the file; an expired link, an edited parameter, a user removed from the congregation, a congregation without Service slides access, and an item moved out of the congregation are all refused.
- `get-library-file` and `get-service`: the links they return work through the signed route and carry the right expiry.
- Downloads are logged.

## Order of work

1. App download route and Library page button.
2. Signed file route with its checks and logging.
3. `get-library-file`, then deck links in `get-service`, and the `generate-deck` description.
