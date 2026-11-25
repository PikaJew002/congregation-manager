<?php

namespace App\Modules\ZoomRecordings\Jobs;

use App\Modules\ZoomRecordings\Models\ZoomRecording;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

class DownloadRecording implements ShouldQueue
{
    use Queueable;

    public int $timeout = 7200;

    public function __construct(public int $recordingId)
    {
        $this->onConnection('database-media');
    }

    public function handle(): void
    {
        $recording = ZoomRecording::query()->findOrFail($this->recordingId);
        $recording->update(['status' => 'downloading', 'failed_step' => null, 'error' => null]);
        $temp = storage_path('app/zoom-source-'.$recording->id.'.mp4');

        try {
            $this->download((string) $recording->download_url, (string) $recording->download_token, $temp);

            $path = $recording->directory().'/source.mp4';
            $stream = fopen($temp, 'r');
            if ($stream === false) {
                throw new RuntimeException('Zoom download could not be read.');
            }
            Storage::disk('files')->writeStream($path, $stream);
            if (is_resource($stream)) {
                fclose($stream);
            }

            $recording->update(['source_path' => $path, 'status' => 'downloaded']);
            $recording->note('Downloaded source MP4.');
        } catch (Throwable $exception) {
            $recording->update(['status' => 'failed', 'failed_step' => 'download', 'error' => $exception->getMessage()]);
            throw $exception;
        } finally {
            if (is_file($temp)) {
                unlink($temp);
            }
        }
    }

    /**
     * Zoom's download URL redirects to a file host. The HTTP client drops the
     * Authorization header on that hop, so follow each redirect and send the
     * webhook download token again.
     */
    private function download(string $url, string $token, string $destination): void
    {
        if ($url === '' || $token === '') {
            throw new RuntimeException('Zoom did not provide a download URL and download token.');
        }

        $current = $url;

        for ($hop = 0; $hop < 5; $hop++) {
            if (parse_url($current, PHP_URL_SCHEME) !== 'https') {
                throw new RuntimeException('Zoom download URL must use https.');
            }

            $response = Http::withToken($token)
                ->withoutRedirecting()
                ->connectTimeout(15)
                ->timeout(7000)
                ->sink($destination)
                ->get($current);

            if ($response->redirect()) {
                $current = $this->absoluteUrl($current, $response->header('Location'));

                continue;
            }

            $this->assertRecording($response, $destination);

            return;
        }

        throw new RuntimeException('Zoom download redirected too many times.');
    }

    private function assertRecording(Response $response, string $destination): void
    {
        if ($response->failed()) {
            throw new RuntimeException('Zoom download failed: '.$response->status());
        }

        $head = (string) file_get_contents($destination, false, null, 0, 64);
        $type = strtolower($response->header('Content-Type'));
        $html = str_contains($type, 'text/html')
            || str_contains(strtolower($head), '<html')
            || str_contains(strtolower($head), '<!doctype');

        if ($html || ! str_contains($head, 'ftyp')) {
            throw new RuntimeException('Zoom returned a web page instead of the recording.');
        }
    }

    private function absoluteUrl(string $current, string $location): string
    {
        if ($location === '') {
            throw new RuntimeException('Zoom download redirect was missing a location.');
        }

        if (str_starts_with($location, '//')) {
            return 'https:'.$location;
        }

        if (parse_url($location, PHP_URL_SCHEME)) {
            return $location;
        }

        $parts = parse_url($current);
        $origin = ($parts['scheme'] ?? 'https').'://'.($parts['host'] ?? '');
        if (isset($parts['port'])) {
            $origin .= ':'.$parts['port'];
        }

        if (str_starts_with($location, '/')) {
            return $origin.$location;
        }

        $path = $parts['path'] ?? '/';
        $directory = str_contains($path, '/') ? substr($path, 0, (int) strrpos($path, '/') + 1) : '/';

        return $origin.$directory.$location;
    }
}
