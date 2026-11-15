#!/usr/bin/env bash
set -euo pipefail

INPUT="${1:?input mp4}"
WORKDIR="${2:?output directory}"
FFMPEG="${FFMPEG_BINARY:-ffmpeg}"
FFPROBE="${FFPROBE_BINARY:-ffprobe}"

mkdir -p "$WORKDIR"

HEIGHT="$("$FFPROBE" -v error -select_streams v:0 -show_entries stream=height -of csv=p=0 "$INPUT" | head -n 1)"
HEIGHT="${HEIGHT:-720}"

MASTER="$WORKDIR/playlist.m3u8"
cat > "$MASTER" <<'EOF'
#EXTM3U
#EXT-X-VERSION:3
EOF

add_variant() {
    local label="$1" width="$2" height="$3" bitrate="$4"
    if (( HEIGHT + 16 < height )); then
        return
    fi
    local dir="$WORKDIR/$label"
    mkdir -p "$dir"
    "$FFMPEG" -y -i "$INPUT" \
        -c:v libx264 -profile:v main -level:v 4.0 \
        -preset veryfast -b:v "$bitrate" \
        -vf "scale=${width}:${height}:force_original_aspect_ratio=decrease,scale=trunc(iw/2)*2:trunc(ih/2)*2" \
        -pix_fmt yuv420p -g 60 -keyint_min 60 -sc_threshold 0 \
        -c:a aac -b:a 128k -ac 2 \
        -hls_time 4 -hls_list_size 0 -hls_playlist_type vod \
        -hls_segment_filename "$dir/segment_%03d.ts" \
        "$dir/playlist.m3u8"
    local bandwidth="${bitrate%k}000"
    echo "#EXT-X-STREAM-INF:BANDWIDTH=${bandwidth},RESOLUTION=${width}x${height}" >> "$MASTER"
    echo "$label/playlist.m3u8" >> "$MASTER"
}

add_variant 480p 854 480 500k
add_variant 720p 1280 720 1200k
add_variant 1080p 1920 1080 2500k

"$FFMPEG" -y -ss 5 -i "$INPUT" -frames:v 1 "$WORKDIR/poster.jpg"
echo "HLS packaging complete: $MASTER"
