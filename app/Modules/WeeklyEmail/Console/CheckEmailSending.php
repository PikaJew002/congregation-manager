<?php

namespace App\Modules\WeeklyEmail\Console;

use App\Mail\EmailSending;
use App\Modules\WeeklyEmail\Models\WeeklyEmailSetting;
use App\Support\Modules\ModuleAccess;
use Illuminate\Console\Command;
use Throwable;

/**
 * Re-checks the sending setup of every congregation whose weekly email is
 * scheduled, so a broken DNS record shows on the dashboard days before the
 * send rather than when the week is skipped. A result younger than a day is
 * kept, so each congregation is checked about once a day.
 */
class CheckEmailSending extends Command
{
    protected $signature = 'weekly-email:check-sending';

    protected $description = 'Re-check the email sending setup of congregations with the weekly email scheduled';

    public function handle(ModuleAccess $access): int
    {
        $checked = 0;

        WeeklyEmailSetting::query()->where('enabled', true)->with('team')->each(function (WeeklyEmailSetting $settings) use ($access, &$checked) {
            if (! $settings->team || ! $access->isEnabled($settings->team, 'weekly-email')) {
                return;
            }

            try {
                EmailSending::check($settings->team);
                $checked++;
            } catch (Throwable $exception) {
                report($exception);
            }
        });

        $this->components->info("Checked email sending for {$checked} congregation(s).");

        return self::SUCCESS;
    }
}
