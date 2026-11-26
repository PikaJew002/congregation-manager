<?php

namespace App\Modules\WeeklyEmail\Console;

use App\Modules\WeeklyEmail\Actions\QueueDueDeliveries;
use Illuminate\Console\Command;

class SendDueWeeklyEmails extends Command
{
    protected $signature = 'weekly-email:send-due';

    protected $description = 'Queue weekly emails due in the next minute';

    public function handle(QueueDueDeliveries $queue): int
    {
        $queued = $queue->handle();
        $this->components->info("Queued {$queued} weekly email(s).");

        return self::SUCCESS;
    }
}
