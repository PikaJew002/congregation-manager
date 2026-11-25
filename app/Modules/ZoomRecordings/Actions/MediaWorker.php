<?php

namespace App\Modules\ZoomRecordings\Actions;

use App\Modules\ZoomRecordings\Jobs\UpdateManifest;
use App\Modules\ZoomRecordings\Models\ZoomRecording;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Sends recording steps to the Cloudflare media worker, one at a time.
 *
 * Steps run in this order: download, one rendition per size the source is
 * tall enough for, poster. After the poster, Laravel writes the master
 * playlist and the public manifest itself. A step key is what lands in
 * failed_step, so the retry button resumes at the step that failed.
 */
class MediaWorker
{
    /**
     * Same table and height rule as scripts/hls_package.sh. 360p is the
     * fallback: it is encoded only when the source is too short for 480p, so
     * every recording gets at least one stream.
     *
     * @var array<string, array{width: int, height: int, bitrate: string}>
     */
    public const RENDITIONS = [
        '360p' => ['width' => 640, 'height' => 360, 'bitrate' => '400k'],
        '480p' => ['width' => 854, 'height' => 480, 'bitrate' => '500k'],
        '720p' => ['width' => 1280, 'height' => 720, 'bitrate' => '1200k'],
        '1080p' => ['width' => 1920, 'height' => 1080, 'bitrate' => '2500k'],
    ];

    public const FALLBACK = '360p';

    public const SIGNATURE_HEADER = 'X-Media-Signature';

    /** Longer than the slowest encode on a fractional-vCPU container. */
    public const STALL_HOURS = 4;

    public static function enabled(): bool
    {
        return filled(config('services.media_worker.url'));
    }

    public static function sign(string $body): string
    {
        return hash_hmac('sha256', $body, (string) config('services.media_worker.secret'));
    }

    /**
     * Start (or resume) the pipeline. $fromStep is a failed_step value, which
     * may also be one of the local queue's step names.
     */
    public function start(ZoomRecording $recording, ?string $fromStep = null): void
    {
        $steps = $this->steps($recording);
        $step = match (true) {
            $fromStep === null, $fromStep === 'download' => 'download',
            $fromStep === 'manifest' => 'manifest',
            blank($recording->source_path) || $recording->source_height === null => 'download',
            $fromStep === 'transcode' => $steps[1],
            in_array($fromStep, $steps, true) => $fromStep,
            default => 'download',
        };

        $this->run($recording, $step);
    }

    /** Move on after $completed succeeded. */
    public function advance(ZoomRecording $recording, string $completed): void
    {
        $steps = $this->steps($recording);
        $index = array_search($completed, $steps, true);
        $next = $index === false ? 'manifest' : ($steps[$index + 1] ?? 'manifest');

        $this->run($recording, $next);
    }

    /**
     * @return list<string>
     */
    public function steps(ZoomRecording $recording): array
    {
        $height = $recording->source_height ?? 720;
        $labels = collect(self::RENDITIONS)
            ->except(self::FALLBACK)
            ->filter(fn (array $variant) => $height + 16 >= $variant['height'])
            ->keys();
        $renditions = ($labels->isEmpty() ? collect([self::FALLBACK]) : $labels)
            ->map(fn (string $label) => 'rendition:'.$label)
            ->all();

        return ['download', ...$renditions, 'poster'];
    }

    /**
     * The worker reports every step, but a lost container or Durable Object
     * reset could leave a step with no report. After STALL_HOURS with no news,
     * mark it failed so the retry button appears instead of a spinner.
     */
    public function failStalled(): int
    {
        $stalled = ZoomRecording::query()
            ->whereNotNull('media_step')
            ->where('media_step', '!=', 'manifest')
            ->where('status', '!=', 'failed')
            ->where('updated_at', '<', now()->subHours(self::STALL_HOURS))
            ->get();

        foreach ($stalled as $recording) {
            $this->fail($recording, (string) $recording->media_step, 'The media worker did not report back within '.self::STALL_HOURS.' hours.');
        }

        return $stalled->count();
    }

    public function fail(ZoomRecording $recording, string $step, string $error): void
    {
        $recording->update([
            'status' => 'failed',
            'failed_step' => $step,
            'media_step' => null,
            'error' => $error,
        ]);
        $recording->note("Media worker failed at {$step}: {$error}");
    }

    private function run(ZoomRecording $recording, string $step): void
    {
        if ($step === 'manifest') {
            $this->finish($recording);

            return;
        }

        [$name, $rendition] = array_pad(explode(':', $step, 2), 2, null);
        $recording->update([
            'status' => match ($name) {
                'download' => 'downloading',
                'rendition' => 'transcoding',
                default => 'uploading',
            },
            'media_step' => $step,
            'failed_step' => null,
            'error' => null,
        ]);

        $body = json_encode(array_filter([
            'recording_id' => $recording->id,
            'step' => $name,
            'rendition' => $rendition,
            'directory' => $recording->directory(),
            'download_url' => $name === 'download' ? $recording->download_url : null,
            'download_token' => $name === 'download' ? $recording->download_token : null,
            'sent_at' => time(),
        ], fn ($value) => $value !== null), JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        try {
            $response = Http::withHeaders([self::SIGNATURE_HEADER => self::sign($body)])
                ->withBody($body, 'application/json')
                ->connectTimeout(10)
                ->timeout(30)
                ->post(rtrim((string) config('services.media_worker.url'), '/').'/jobs');
        } catch (Throwable $exception) {
            $this->fail($recording, $step, 'Media worker unreachable: '.$exception->getMessage());

            return;
        }

        if (! $response->successful()) {
            $this->fail($recording, $step, 'Media worker refused the job: '.$response->status().' '.mb_substr($response->body(), 0, 300));

            return;
        }

        $recording->note("Sent {$step} to the media worker.");
    }

    private function finish(ZoomRecording $recording): void
    {
        try {
            $master = "#EXTM3U\n#EXT-X-VERSION:3\n";
            foreach ($this->steps($recording) as $step) {
                if (! str_starts_with($step, 'rendition:')) {
                    continue;
                }
                $label = substr($step, strlen('rendition:'));
                $variant = self::RENDITIONS[$label];
                // Peak video (1.1x maxrate) plus 128k audio, as in hls_package.sh.
                $bandwidth = (intdiv((int) $variant['bitrate'] * 11, 10) + 128) * 1000;
                $master .= "#EXT-X-STREAM-INF:BANDWIDTH={$bandwidth},RESOLUTION={$variant['width']}x{$variant['height']}\n{$label}/playlist.m3u8\n";
            }

            $path = $recording->directory().'/playlist.m3u8';
            Storage::disk('r2-public')->put($path, $master, [
                'ContentType' => 'application/vnd.apple.mpegurl',
                'CacheControl' => 'public, max-age=60',
            ]);

            $recording->update(['hls_path' => $path, 'status' => 'uploaded', 'media_step' => 'manifest']);
            $recording->note('Uploaded HLS and source MP4.');
            (new UpdateManifest($recording->id))->handle();
            $recording->update(['media_step' => null]);
        } catch (Throwable $exception) {
            $this->fail($recording, 'manifest', $exception->getMessage());
        }
    }
}
