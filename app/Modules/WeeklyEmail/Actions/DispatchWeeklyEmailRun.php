<?php

namespace App\Modules\WeeklyEmail\Actions;

use App\Modules\WeeklyEmail\Jobs\SendWeeklyEmailJob;
use App\Modules\WeeklyEmail\Models\EmailRecipient;
use App\Modules\WeeklyEmail\Models\EmailRun;
use App\Modules\WeeklyEmail\Models\WeeklyEmailSetting;
use App\Support\Modules\ModuleAccess;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class DispatchWeeklyEmailRun
{
    public function __construct(private ModuleAccess $access) {}

    public function handle(?CarbonImmutable $now = null): int
    {
        $created = 0;

        WeeklyEmailSetting::query()->where('enabled', true)->with('team')->each(function (WeeklyEmailSetting $settings) use ($now, &$created) {
            if (! $settings->team || ! $this->access->isEnabled($settings->team, 'weekly-email')) {
                return;
            }

            $localNow = ($now ?? CarbonImmutable::now())->timezone($settings->team->localTimezone());
            $start = $localNow->setTimeFromTimeString($settings->send_time);

            if ($localNow->dayOfWeek !== (int) $settings->send_day) {
                return;
            }

            $windowEnd = $start->addMinutes((int) $settings->window_minutes);

            if ($localNow->lt($start) || $localNow->gte($windowEnd)) {
                return;
            }

            $scheduledFor = $start->utc();
            $exists = EmailRun::query()
                ->where('team_id', $settings->team_id)
                ->where('scheduled_for', $scheduledFor)
                ->exists();

            if ($exists) {
                return;
            }

            DB::transaction(function () use ($settings, $scheduledFor, $start) {
                $run = EmailRun::query()->create([
                    'team_id' => $settings->team_id,
                    'scheduled_for' => $scheduledFor,
                    'status' => 'scheduled',
                    'subject' => $settings->subject,
                    'body' => $settings->body,
                ]);

                $recipients = EmailRecipient::query()->forTeam($settings->team)->where('status', 'subscribed')->orderBy('id')->get();
                $count = max($recipients->count(), 1);
                $window = max(((int) $settings->window_minutes) * 60, 1);

                foreach ($recipients as $index => $recipient) {
                    $slot = (int) floor($index * $window / $count);
                    $jitter = $count > 1 ? random_int(0, min(20, (int) floor($window / $count))) : 0;
                    $delay = min($window - 1, $slot + $jitter);
                    $delivery = $run->deliveries()->create([
                        'email_recipient_id' => $recipient->id,
                        'scheduled_at' => $start->addSeconds($delay)->utc(),
                        'status' => 'pending',
                    ]);

                    SendWeeklyEmailJob::dispatch($delivery->id)->delay(now()->addSeconds($delay));
                }
            });

            $created++;
        });

        return $created;
    }
}
