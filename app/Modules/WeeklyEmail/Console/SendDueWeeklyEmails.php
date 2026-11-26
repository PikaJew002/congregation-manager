<?php

namespace App\Modules\WeeklyEmail\Console;

use App\Modules\WeeklyEmail\Actions\QueueDueDeliveries;
use App\Modules\WeeklyEmail\Actions\RunState;
use Illuminate\Console\Command;

class SendDueWeeklyEmails extends Command
{
    protected $signature = 'weekly-email:send-due';

    protected $description = 'Queue weekly emails due in the next minute';

    public function handle(QueueDueDeliveries $queue, RunState $state): int
    {
        $cancelled = $state->cancelStalePauses();
        $queued = $queue->handle();
        $this->components->info("Queued {$queued} weekly email(s). Cancelled {$cancelled} run(s) paused for over a day.");

        return self::SUCCESS;
    }
}
