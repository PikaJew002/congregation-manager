<?php

namespace App\Modules\ZoomRecordings\Jobs;

use App\Modules\ZoomRecordings\Models\ZoomRecording;
use App\Support\LocalFile;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Throwable;

class TranscodeAndUpload implements ShouldQueue
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
        $recording->update(['status' => 'transcoding', 'failed_step' => null, 'error' => null]);
        $work = storage_path('app/zoom-'.$recording->id);
        $input = $work.'/source.mp4';

        try {
            if (! is_dir($work)) {
                mkdir($work, 0755, true);
            }
            $source = Storage::disk('files')->readStream($recording->source_path);
            $local = LocalFile::open($input, 'w');
            stream_copy_to_stream($source, $local);
            fclose($local);
            if (is_resource($source)) {
                fclose($source);
            }

            $result = Process::timeout(7200)->env([
                'FFMPEG_BINARY' => config('services.ffmpeg.ffmpeg'),
                'FFPROBE_BINARY' => config('services.ffmpeg.ffprobe'),
            ])->run([base_path('scripts/hls_package.sh'), $input, $work.'/hls']);

            if ($result->failed()) {
                throw new \RuntimeException($result->errorOutput() ?: $result->output());
            }

            $recording->update(['status' => 'uploading']);
            $disk = Storage::disk('r2-public');
            $directory = $recording->directory();
            $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($work.'/hls', \FilesystemIterator::SKIP_DOTS));

            foreach ($iterator as $file) {
                $relative = substr($file->getPathname(), strlen($work.'/hls/'));
                $this->upload($disk, $directory.'/'.$relative, $file->getPathname(), [
                    'ContentType' => $this->contentType($file->getFilename()),
                    'CacheControl' => str_ends_with($file->getFilename(), '.m3u8') ? 'public, max-age=60' : 'public, max-age=31536000',
                ]);
            }

            $this->upload($disk, $directory.'/source.mp4', $input, [
                'ContentType' => 'video/mp4',
            ]);

            $recording->update(['hls_path' => $directory.'/playlist.m3u8', 'status' => 'uploaded']);
            $recording->note('Uploaded HLS and source MP4.');
        } catch (Throwable $exception) {
            $recording->update(['status' => 'failed', 'failed_step' => 'transcode', 'error' => $exception->getMessage()]);
            throw $exception;
        } finally {
            $this->deleteTree($work);
        }
    }

    /**
     * Streams a local file to the disk and closes it afterwards.
     *
     * @param  array<string, string>  $options
     */
    private function upload(Filesystem $disk, string $path, string $local, array $options): void
    {
        $stream = LocalFile::open($local);

        try {
            $disk->put($path, $stream, $options);
        } finally {
            // The S3 client can close the stream itself once the upload is done.
            if (is_resource($stream)) {
                fclose($stream);
            }
        }
    }

    private function contentType(string $filename): string
    {
        return match (true) {
            str_ends_with($filename, '.m3u8') => 'application/vnd.apple.mpegurl',
            str_ends_with($filename, '.ts') => 'video/mp2t',
            str_ends_with($filename, '.jpg') => 'image/jpeg',
            default => 'application/octet-stream',
        };
    }

    private function deleteTree(string $path): void
    {
        if (! is_dir($path)) {
            return;
        }
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $file) {
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($path);
    }
}
