<?php

namespace App\Modules\Email\Console;

use App\Modules\Email\Actions\DispatchDueRuns;
use Illuminate\Console\Command;

class DispatchDueEmails extends Command
{
    protected $signature = 'email:dispatch-due';

    protected $description = 'Create a run for each email whose send time has come';

    public function handle(DispatchDueRuns $dispatch): int
    {
        $created = $dispatch->handle();
        $this->components->info("Created {$created} email run(s).");

        return self::SUCCESS;
    }
}
