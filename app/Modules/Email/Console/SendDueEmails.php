<?php

namespace App\Modules\Email\Console;

use App\Modules\Email\Actions\QueueDueDeliveries;
use App\Modules\Email\Actions\RunState;
use Illuminate\Console\Command;

class SendDueEmails extends Command
{
    protected $signature = 'email:send-due';

    protected $description = 'Queue emails due in the next minute';

    public function handle(QueueDueDeliveries $queue, RunState $state): int
    {
        $cancelled = $state->cancelStalePauses();
        $queued = $queue->handle();
        $this->components->info("Queued {$queued} email(s). Cancelled {$cancelled} run(s) paused for over a day.");

        return self::SUCCESS;
    }
}
