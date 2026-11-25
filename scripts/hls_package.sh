#!/usr/bin/env bash
set -euo pipefail

INPUT="${1:?input mp4}"
WORKDIR="${2:?output directory}"
# Optional third argument runs one piece only: 360p, 480p, 720p, 1080p, or poster.
# The media worker uses it to run one command per container request. With it
# set, the master playlist is left to the caller.
ONLY="${3:-}"
FFMPEG="${FFMPEG_BINARY:-ffmpeg}"
FFPROBE="${FFPROBE_BINARY:-ffprobe}"

mkdir -p "$WORKDIR"

HEIGHT="$("$FFPROBE" -v error -select_streams v:0 -show_entries stream=height -of csv=p=0 "$INPUT" | head -n 1)"
HEIGHT="${HEIGHT:-720}"

MASTER="$WORKDIR/playlist.m3u8"
if [[ -z "$ONLY" ]]; then
    printf '#EXTM3U\n#EXT-X-VERSION:3\n' > "$MASTER"
fi

add_variant() {
    local label="$1" width="$2" height="$3" bitrate="$4" always="${5:-}"
    if [[ -n "$ONLY" && "$ONLY" != "$label" ]]; then
        return
    fi
    if [[ -z "$always" ]] && (( HEIGHT + 16 < height )); then
        return
    fi
    # Keyframes every 2 seconds by time (not frame count, so 25 and 30 fps
    # both give exact 4-second segments). Cap peaks at 1.1x the target with a
    # one-second buffer so they stay under the BANDWIDTH the playlist declares.
    local kbps="${bitrate%k}"
    local maxrate="$(( kbps * 11 / 10 ))k" bufsize="$(( kbps ))k"
    local dir="$WORKDIR/$label"
    mkdir -p "$dir"
    "$FFMPEG" -y -i "$INPUT" \
        -c:v libx264 -profile:v main -level:v 4.0 \
        -preset veryfast -b:v "$bitrate" -maxrate "$maxrate" -bufsize "$bufsize" \
        -vf "scale=${width}:${height}:force_original_aspect_ratio=decrease,scale=trunc(iw/2)*2:trunc(ih/2)*2" \
        -pix_fmt yuv420p -force_key_frames "expr:gte(t,n_forced*2)" -sc_threshold 0 \
        -c:a aac -b:a 128k -ac 2 \
        -hls_time 4 -hls_list_size 0 -hls_playlist_type vod \
        -hls_segment_filename "$dir/segment_%03d.ts" \
        "$dir/playlist.m3u8"
    if [[ -n "$ONLY" ]]; then
        return
    fi
    # Peak video (maxrate) plus 128k audio, as HLS BANDWIDTH expects.
    local bandwidth="$(( kbps * 11 / 10 + 128 ))000"
    echo "#EXT-X-STREAM-INF:BANDWIDTH=${bandwidth},RESOLUTION=${width}x${height}" >> "$MASTER"
    echo "$label/playlist.m3u8" >> "$MASTER"
}

# A source shorter than the smallest size below still needs one stream, or
# the master playlist would be empty. Encode it at 360p instead.
if (( HEIGHT + 16 < 480 )); then
    add_variant 360p 640 360 400k always
fi
add_variant 480p 854 480 500k
add_variant 720p 1280 720 1200k
add_variant 1080p 1920 1080 2500k

if [[ -z "$ONLY" || "$ONLY" == "poster" ]]; then
    "$FFMPEG" -y -ss 5 -i "$INPUT" -frames:v 1 "$WORKDIR/poster.jpg"
fi
echo "HLS packaging complete: $WORKDIR"
