<?php

namespace App\Modules\ZoomRecordings;

use App\Modules\ZoomRecordings\Actions\MediaWorker;
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
}
