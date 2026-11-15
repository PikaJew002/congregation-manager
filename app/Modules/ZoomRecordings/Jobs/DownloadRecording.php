<?php

namespace App\Modules\ZoomRecordings\Jobs;

use App\Modules\ZoomRecordings\Models\ZoomRecording;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
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

        try {
            $response = Http::withToken((string) $recording->download_token)
                ->sink(storage_path('app/zoom-source-'.$recording->id.'.mp4'))
                ->get($recording->download_url);

            if ($response->failed()) {
                throw new \RuntimeException('Zoom download failed: '.$response->status());
            }

            $path = $recording->directory().'/source.mp4';
            $stream = fopen(storage_path('app/zoom-source-'.$recording->id.'.mp4'), 'r');
            Storage::disk('files')->writeStream($path, $stream);
            if (is_resource($stream)) {
                fclose($stream);
            }
            @unlink(storage_path('app/zoom-source-'.$recording->id.'.mp4'));

            $recording->update(['source_path' => $path, 'status' => 'downloaded']);
            $recording->note('Downloaded source MP4.');
        } catch (Throwable $exception) {
            $recording->update(['status' => 'failed', 'failed_step' => 'download', 'error' => $exception->getMessage()]);
            throw $exception;
        }
    }
}
