<?php

namespace App\Modules\ZoomRecordings;

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

    public function schedule(Schedule $schedule): void {}
}
