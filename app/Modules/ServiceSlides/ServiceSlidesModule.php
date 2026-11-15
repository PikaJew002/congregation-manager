<?php

namespace App\Modules\ServiceSlides;

use App\Support\Modules\ModuleDefinition;
use Illuminate\Console\Scheduling\Schedule;

class ServiceSlidesModule implements ModuleDefinition
{
    public function key(): string
    {
        return 'service-slides';
    }

    public function name(): string
    {
        return 'Service slides';
    }

    public function icon(): string
    {
        return 'music';
    }

    public function navItems(): array
    {
        return [
            ['title' => 'Slide library', 'route' => 'service-slides.library'],
            ['title' => 'Slide templates', 'route' => 'service-slides.templates'],
            ['title' => 'Services', 'route' => 'service-slides.services'],
            ['title' => 'Slide history', 'route' => 'service-slides.history'],
            ['title' => 'Slide access', 'route' => 'service-slides.settings'],
        ];
    }

    public function schedule(Schedule $schedule): void {}
}
