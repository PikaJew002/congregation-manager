<?php

namespace App\Modules\ZoomRecordings\Actions;

use App\Modules\ZoomRecordings\Jobs\UpdateManifest;
use App\Modules\ZoomRecordings\Models\ZoomRecording;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * Removes a recording for good: takes it off the public feed, deletes every
 * file it has in both buckets (source MP4, streams, poster), then the row.
 */
class DeleteRecording
{
    /** Statuses where a download or encode may still write files. */
    public const PROCESSING = ['received', 'downloading', 'downloaded', 'transcoding', 'uploading', 'uploaded'];

    public static function canDelete(ZoomRecording $recording): bool
    {
        return ! in_array($recording->status, self::PROCESSING, true);
    }

    public function handle(ZoomRecording $recording): void
    {
        if (! self::canDelete($recording)) {
            throw new RuntimeException('This recording is still processing. Delete it once it has finished or failed.');
        }

        $directory = $recording->directory();
        if (! str_ends_with($directory, '/'.$recording->id)) {
            throw new RuntimeException('Refusing to delete an unexpected folder: '.$directory);
        }

        if (filled($recording->hls_path) && ! $recording->hidden) {
            $recording->update(['hidden' => true]);
            (new UpdateManifest($recording->id))->handle();
        }

        Storage::disk('r2-public')->deleteDirectory($directory);
        Storage::disk('files')->deleteDirectory($directory);

        $recording->delete();
    }
}
