#!/usr/bin/env bash
set -euo pipefail

bin_dir="${HOME}/bin"
mkdir -p "${bin_dir}"

if [[ -x "${bin_dir}/ffmpeg" && -x "${bin_dir}/ffprobe" ]]; then
    exit 0
fi

case "$(uname -m)" in
    aarch64 | arm64)
        archive="ffmpeg-release-arm64-static.tar.xz"
        ;;
    x86_64 | amd64)
        archive="ffmpeg-release-amd64-static.tar.xz"
        ;;
    *)
        echo "No static FFmpeg build is published for $(uname -m)." >&2
        exit 1
        ;;
esac

work="$(mktemp -d)"
trap 'rm -rf "${work}"' EXIT

curl -fsSL "https://johnvansickle.com/ffmpeg/releases/${archive}" -o "${work}/ffmpeg.tar.xz"
tar -xJf "${work}/ffmpeg.tar.xz" -C "${work}"
cp "${work}"/ffmpeg-*-static/ffmpeg "${bin_dir}/ffmpeg"
cp "${work}"/ffmpeg-*-static/ffprobe "${bin_dir}/ffprobe"
chmod +x "${bin_dir}/ffmpeg" "${bin_dir}/ffprobe"
