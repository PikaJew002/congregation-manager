<?php

namespace App\Modules\WeeklyEmail;

use App\Models\Team;
use App\Modules\WeeklyEmail\Actions\WeeklyEmailDashboard;
use App\Support\Modules\ModuleDefinition;
use App\Support\Modules\ProvidesDashboard;
use Illuminate\Console\Scheduling\Schedule;

class WeeklyEmailModule implements ModuleDefinition, ProvidesDashboard
{
    public function key(): string
    {
        return 'weekly-email';
    }

    public function name(): string
    {
        return 'Weekly email';
    }

    public function icon(): string
    {
        return 'mail';
    }

    public function navItems(): array
    {
        return [
            ['title' => 'Email list', 'route' => 'weekly-email.recipients'],
            ['title' => 'Email template', 'route' => 'weekly-email.template'],
            ['title' => 'Email schedule', 'route' => 'weekly-email.schedule'],
            ['title' => 'Email runs', 'route' => 'weekly-email.runs'],
        ];
    }

    public function schedule(Schedule $schedule): void
    {
        $schedule->command('weekly-email:dispatch-due')->everyMinute()->withoutOverlapping();
        $schedule->command('weekly-email:send-due')->everyMinute()->withoutOverlapping();
    }

    public function mcpTools(): array
    {
        return [];
    }

    public function mcpInstructions(): string
    {
        return '';
    }

    public function dashboard(Team $team): array
    {
        return app(WeeklyEmailDashboard::class)->handle($team);
    }

    public function dashboardRoute(): string
    {
        return 'weekly-email.runs';
    }
}
