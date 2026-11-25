<?php

namespace App\Modules\ZoomRecordings;

use App\Modules\ZoomRecordings\Actions\MediaWorker;
use App\Modules\ZoomRecordings\Mcp\Tools\ArchiveRecording;
use App\Modules\ZoomRecordings\Mcp\Tools\DeleteRecordingTool;
use App\Modules\ZoomRecordings\Mcp\Tools\GetRecording;
use App\Modules\ZoomRecordings\Mcp\Tools\ListRecordings;
use App\Modules\ZoomRecordings\Mcp\Tools\RestoreRecording;
use App\Modules\ZoomRecordings\Mcp\Tools\RetryRecording;
use App\Modules\ZoomRecordings\Mcp\Tools\UpdateRecordingDetails;
use App\Support\Modules\ModuleDefinition;
use Illuminate\Console\Scheduling\Schedule;

class ZoomRecordingsModule implements ModuleDefinition
{
    public function key(): string
    {
        return 'zoom-recordings';
    }

    public function name(): string
    {
        return 'Recordings';
    }

    public function icon(): string
    {
        return 'video';
    }

    public function navItems(): array
    {
        return [
            ['title' => 'Recordings', 'route' => 'zoom-recordings.index'],
            ['title' => 'Recording settings', 'route' => 'zoom-recordings.settings'],
        ];
    }

    public function schedule(Schedule $schedule): void
    {
        $schedule->call(fn () => app(MediaWorker::class)->failStalled())
            ->everyFifteenMinutes()
            ->description('Fail media worker steps that never reported back');
    }

    public function mcpTools(): array
    {
        return [
            ListRecordings::class,
            GetRecording::class,
            UpdateRecordingDetails::class,
            ArchiveRecording::class,
            RestoreRecording::class,
            RetryRecording::class,
            DeleteRecordingTool::class,
        ];
    }

    public function mcpInstructions(): string
    {
        return 'Zoom cloud recordings of services are downloaded, converted for streaming and published to the congregation website automatically. Find recordings with list-recordings (view current, archived, failed or all) and read one with get-recording. Use update-recording to set the title, service name, speaker or Torah portion; send only what changes. The service name is shown with the date added, such as "Shabbat Morning Service - Nov 21, 2026". archive-recording takes a recording off the website but keeps it; restore-recording brings it back. retry-recording reruns a failed recording from the step that failed. delete-recording removes a recording and all its files for good: only use it when the person clearly asks to delete, and prefer archiving otherwise. Recording settings, such as the Zoom webhook secret and time zone, are only in the web app.';
    }
}
