<?php

namespace App\Modules\Email\Actions;

use App\Mail\EmailSending;
use App\Models\Team;
use App\Modules\Email\Models\Email;
use App\Modules\Email\Models\EmailDelivery;
use App\Modules\Email\Models\EmailRecipient;
use App\Modules\Email\Models\EmailRun;
use App\Modules\Email\Models\EmailSetting;
use App\Support\Modules\ModuleAccess;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Creates a run for each scheduled email when its send time comes: the
 * finished text, with variables and dates filled in, and one paced delivery
 * per subscriber in a new random order each time. Nothing is queued here;
 * email:send-due hands deliveries to the queue as they come due.
 */
class DispatchDueRuns
{
    /**
     * How late a recurring email may still start when the scheduler was down
     * at the send time. Later than this, that send is skipped rather than
     * sent late; the next one comes soon enough.
     */
    public const CATCH_UP_MINUTES = 30;

    /**
     * How late a one-time email may still start. There is no next time for
     * it, so it waits longer; past this it goes back to being a draft.
     */
    public const ONE_TIME_CATCH_UP_HOURS = 24;

    public function __construct(
        private ModuleAccess $access,
        private PaceDeliveries $pace,
        private RenderEmail $renderer,
    ) {}

    public function handle(?CarbonImmutable $now = null): int
    {
        $now ??= CarbonImmutable::now();
        $created = 0;

        Email::query()->where('status', 'scheduled')->with('team')->orderBy('id')->each(function (Email $email) use ($now, &$created) {
            $team = $email->team;

            if (! $team || ! $this->access->isEnabled($team, 'email')) {
                return;
            }

            $start = $this->dueAt($email, $team, $now);

            if ($start === null) {
                return;
            }

            $scheduledFor = $start->utc();

            if (EmailRun::query()->where('email_id', $email->id)->where('scheduled_for', $scheduledFor)->exists()) {
                return;
            }

            // Re-check the domain once a day; a failing setup holds the send
            // and shows on the dashboard instead.
            EmailSending::check($team);

            if (! EmailSending::ready($team)) {
                return;
            }

            $recipients = EmailRecipient::query()->forTeam($team)->where('status', 'subscribed')->get()->shuffle()->values();

            // An email split across days would confuse people, so a send that
            // would take the day over the plan's limit is held back; the
            // dashboard says why.
            if ($recipients->count() + $this->sentInLastDay($team, $now) > $team->daily_email_limit) {
                return;
            }

            $settings = EmailSetting::forTeam($team);
            $variables = $this->renderer->variablesFor($email, $settings);

            // Pace from now, not the send time, so a late start does not
            // send everything overdue at once.
            $firstAt = $now->max($start)->utc();

            DB::transaction(function () use ($email, $scheduledFor, $start, $variables, $recipients, $firstAt) {
                $run = EmailRun::query()->create([
                    'team_id' => $email->team_id,
                    'email_id' => $email->id,
                    'scheduled_for' => $scheduledFor,
                    'status' => 'scheduled',
                    'subject' => $this->renderer->resolve($email->subject, $variables, $start),
                    'body' => $this->renderer->resolve($email->body, $variables, $start),
                ]);

                $times = $this->pace->times($firstAt, $recipients->count());

                foreach ($recipients as $index => $recipient) {
                    $run->deliveries()->create([
                        'email_recipient_id' => $recipient->id,
                        'scheduled_at' => $times[$index],
                        'status' => 'pending',
                    ]);
                }

                if (! $email->isRecurring()) {
                    $email->update(['status' => 'sent']);
                }
            });

            $created++;
        });

        return $created;
    }

    /**
     * When this email's current send started, in the congregation's time
     * zone, or null when nothing is due now.
     */
    private function dueAt(Email $email, Team $team, CarbonImmutable $now): ?CarbonImmutable
    {
        $localNow = $now->setTimezone($team->localTimezone());

        if (! $email->isRecurring()) {
            if ($email->send_at === null) {
                return null;
            }

            $start = $email->send_at->setTimezone($team->localTimezone());

            if ($localNow->lt($start)) {
                return null;
            }

            if ($localNow->gte($start->addHours(self::ONE_TIME_CATCH_UP_HOURS))) {
                $email->update(['status' => 'draft']);

                return null;
            }

            return $start;
        }

        if (blank($email->send_time) || ! in_array($localNow->dayOfWeek, $email->sendDays(), true)) {
            return null;
        }

        $start = $localNow->setTimeFromTimeString($email->send_time);

        if ($localNow->lt($start) || $localNow->gte($start->addMinutes(self::CATCH_UP_MINUTES))) {
            return null;
        }

        return $start;
    }

    /** Emails this congregation has sent or still has to send from the last day's runs. */
    private function sentInLastDay(Team $team, CarbonImmutable $now): int
    {
        return EmailDelivery::query()
            ->whereIn('status', ['pending', 'sent', 'bounced'])
            ->where('scheduled_at', '>=', $now->utc()->subDay())
            ->whereHas('run', fn ($query) => $query->where('team_id', $team->id))
            ->count();
    }
}
