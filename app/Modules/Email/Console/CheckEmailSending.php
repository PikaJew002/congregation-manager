<?php

namespace App\Modules\Email\Console;

use App\Mail\EmailSending;
use App\Models\Team;
use App\Modules\Email\Models\Email;
use App\Support\Modules\ModuleAccess;
use Illuminate\Console\Command;
use Throwable;

/**
 * Re-checks the sending setup of every congregation with an email scheduled,
 * so a broken DNS record shows on the dashboard days before the send rather
 * than when the send is skipped. A result younger than a day is kept, so each
 * congregation is checked about once a day.
 */
class CheckEmailSending extends Command
{
    protected $signature = 'email:check-sending';

    protected $description = 'Re-check the email sending setup of congregations with an email scheduled';

    public function handle(ModuleAccess $access): int
    {
        $checked = 0;
        $teamIds = Email::query()->where('status', 'scheduled')->distinct()->pluck('team_id');

        Team::query()->whereIn('id', $teamIds)->each(function (Team $team) use ($access, &$checked) {
            if (! $access->isEnabled($team, 'email')) {
                return;
            }

            try {
                EmailSending::check($team);
                $checked++;
            } catch (Throwable $exception) {
                report($exception);
            }
        });

        $this->components->info("Checked email sending for {$checked} congregation(s).");

        return self::SUCCESS;
    }
}
