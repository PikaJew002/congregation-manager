<?php

namespace App\Modules\Email;

use App\Models\Team;
use App\Modules\Email\Actions\EmailDashboard;
use App\Support\Modules\ModuleDefinition;
use App\Support\Modules\ProvidesDashboard;
use Illuminate\Console\Scheduling\Schedule;

class EmailModule implements ModuleDefinition, ProvidesDashboard
{
    public function key(): string
    {
        return 'email';
    }

    public function name(): string
    {
        return 'Email';
    }

    public function icon(): string
    {
        return 'mail';
    }

    public function navItems(): array
    {
        return [
            ['title' => 'Emails', 'route' => 'email.index'],
            ['title' => 'Email list', 'route' => 'email.recipients'],
            ['title' => 'Sent', 'route' => 'email.runs'],
            ['title' => 'Sender & variables', 'route' => 'email.settings'],
        ];
    }

    public function schedule(Schedule $schedule): void
    {
        $schedule->command('email:dispatch-due')->everyMinute()->withoutOverlapping();
        $schedule->command('email:send-due')->everyMinute()->withoutOverlapping();
        $schedule->command('email:check-sending')->hourly()->withoutOverlapping();
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
        return app(EmailDashboard::class)->handle($team);
    }

    public function dashboardRoute(): string
    {
        return 'email.runs';
    }
}
