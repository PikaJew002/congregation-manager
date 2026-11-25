<?php

namespace App\Modules\ZoomRecordings\Jobs;

use App\Modules\ZoomRecordings\Actions\LookUpHebcal;
use App\Modules\ZoomRecordings\Models\ZoomRecording;
use Illuminate\Foundation\Bus\Dispatchable;

/**
 * Runs after the webhook has answered Zoom (dispatched with afterResponse), so
 * the Hebcal request never counts against Zoom's response deadline.
 */
class LookUpRecordingDate
{
    use Dispatchable;

    public function __construct(public int $recordingId) {}

    public function handle(LookUpHebcal $lookUp): void
    {
        $recording = ZoomRecording::query()->find($this->recordingId);

        if ($recording !== null) {
            $lookUp->handle($recording);
        }
    }
}
