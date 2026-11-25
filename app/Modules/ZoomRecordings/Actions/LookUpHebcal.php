<?php

namespace App\Modules\ZoomRecordings\Actions;

use App\Modules\ZoomRecordings\Jobs\UpdateManifest;
use App\Modules\ZoomRecordings\Models\ZoomRecording;
use App\Support\HebrewCalendar;
use Illuminate\Support\Str;
use Throwable;

/**
 * Looks up the recording's local date on Hebcal, keeps the response on the
 * recording, and prefills the service name and Torah portion. Fields someone
 * already filled in are left alone.
 */
class LookUpHebcal
{
    public function __construct(private HebrewCalendar $calendar) {}

    public function handle(ZoomRecording $recording): void
    {
        $date = $recording->localDate();
        if ($date === null) {
            return;
        }

        try {
            $hebcal = $this->calendar->day($date->toDateString());
        } catch (Throwable $exception) {
            $recording->note('Could not look up the Hebrew calendar: '.Str::limit($exception->getMessage(), 200));

            return;
        }

        $items = (array) ($hebcal['items'] ?? []);

        $recording->update(array_filter([
            'hebcal' => $hebcal,
            'service' => blank($recording->service) ? HebrewCalendar::service($items) : null,
            'torah_portion' => blank($recording->torah_portion) ? HebrewCalendar::portion($items) : null,
        ], fn ($value) => $value !== null));
        $recording->note('Looked up '.$date->toDateString().' on the Hebrew calendar.');

        if (filled($recording->hls_path) && ! $recording->isArchived()) {
            (new UpdateManifest($recording->id))->handle();
        }
    }
}
