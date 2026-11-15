# Zoom recordings

A Zoom `recording.completed` webhook downloads the MP4, builds an HLS package with FFmpeg, uploads it to the public R2 bucket, and rewrites `recordings.json` for the website.

The download token is only valid briefly, so the webhook stores the payload and returns immediately. Processing runs on the `database-media` queue. The source MP4 is kept on the private files disk so a later step can retry without the Zoom token.
