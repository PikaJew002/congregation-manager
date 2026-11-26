<?php

namespace App\Modules\WeeklyEmail\Console;

use App\Modules\WeeklyEmail\Actions\DispatchWeeklyEmailRun;
use Illuminate\Console\Command;

class DispatchDueWeeklyEmails extends Command
{
    protected $signature = 'weekly-email:dispatch-due';

    protected $description = 'Create the weekly email run for congregations whose send time has come';

    public function handle(DispatchWeeklyEmailRun $dispatch): int
    {
        $created = $dispatch->handle();
        $this->components->info("Created {$created} weekly email run(s).");

        return self::SUCCESS;
    }
}
