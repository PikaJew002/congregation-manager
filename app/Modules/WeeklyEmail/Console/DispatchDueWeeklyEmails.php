<?php

namespace App\Modules\WeeklyEmail\Console;

use App\Modules\WeeklyEmail\Actions\DispatchWeeklyEmailRun;
use Illuminate\Console\Command;

class DispatchDueWeeklyEmails extends Command
{
    protected $signature = 'weekly-email:dispatch-due';

    protected $description = 'Queue weekly congregation emails whose send window has started';

    public function handle(DispatchWeeklyEmailRun $dispatch): int
    {
        $created = $dispatch->handle();
        $this->components->info("Queued {$created} weekly email run(s).");

        return self::SUCCESS;
    }
}
