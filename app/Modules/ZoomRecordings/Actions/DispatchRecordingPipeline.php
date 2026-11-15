<?php

namespace App\Modules\ZoomRecordings\Actions;

use App\Modules\ZoomRecordings\Jobs\DownloadRecording;
use App\Modules\ZoomRecordings\Jobs\TranscodeAndUpload;
use App\Modules\ZoomRecordings\Jobs\UpdateManifest;
use App\Modules\ZoomRecordings\Models\ZoomRecording;
use Illuminate\Support\Facades\Bus;

class DispatchRecordingPipeline
{
    public function handle(ZoomRecording $recording, ?string $fromStep = null): void
    {
        $jobs = [
            'download' => new DownloadRecording($recording->id),
            'transcode' => new TranscodeAndUpload($recording->id),
            'manifest' => new UpdateManifest($recording->id),
        ];

        $order = array_keys($jobs);
        $start = array_search($fromStep ?? 'download', $order, true);
        $start = $start === false ? 0 : $start;

        Bus::chain(array_values(array_slice($jobs, $start)))
            ->onConnection('database-media')
            ->dispatch();
    }
}
