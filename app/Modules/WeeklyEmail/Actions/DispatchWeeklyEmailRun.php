<?php

namespace App\Modules\WeeklyEmail\Actions;

use App\Mail\EmailSending;
use App\Modules\WeeklyEmail\Models\EmailRecipient;
use App\Modules\WeeklyEmail\Models\EmailRun;
use App\Modules\WeeklyEmail\Models\WeeklyEmailSetting;
use App\Support\Modules\ModuleAccess;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Creates each congregation's weekly run when its send time comes: one paced
 * delivery per subscriber, in a new random order each week. Nothing is queued
 * here; weekly-email:send-due hands deliveries to the queue as they come due.
 */
class DispatchWeeklyEmailRun
{
    /**
     * How late a run may still start when the scheduler was down at the send
     * time. Later than this, the week is skipped rather than sent late.
     */
    public const CATCH_UP_MINUTES = 30;

    public function __construct(private ModuleAccess $access, private PaceDeliveries $pace) {}

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

            if ($localNow->lt($start) || $localNow->gte($start->addMinutes(self::CATCH_UP_MINUTES))) {
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

            // Re-check the domain once a day; a failing setup skips the send
            // and shows on the dashboard instead.
            EmailSending::check($settings->team);

            if (! EmailSending::ready($settings->team)) {
                return;
            }

            $recipients = EmailRecipient::query()->forTeam($settings->team)->where('status', 'subscribed')->get()->shuffle()->values();

            // A weekly email split across days would confuse people, so a
            // list over the plan's limit is not sent; the dashboard says why.
            if ($recipients->count() > $settings->team->daily_email_limit) {
                return;
            }

            // Pace from now, not the send time, so a late start does not
            // send everything overdue at once.
            $firstAt = $localNow->max($start)->utc();

            DB::transaction(function () use ($settings, $scheduledFor, $recipients, $firstAt) {
                $run = EmailRun::query()->create([
                    'team_id' => $settings->team_id,
                    'scheduled_for' => $scheduledFor,
                    'status' => 'scheduled',
                    'subject' => $settings->subject,
                    'body' => $settings->body,
                ]);

                $times = $this->pace->times($firstAt, $recipients->count());

                foreach ($recipients as $index => $recipient) {
                    $run->deliveries()->create([
                        'email_recipient_id' => $recipient->id,
                        'scheduled_at' => $times[$index],
                        'status' => 'pending',
                    ]);
                }
            });

            $created++;
        });

        return $created;
    }
}
