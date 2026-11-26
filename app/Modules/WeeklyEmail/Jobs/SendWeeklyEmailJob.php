<?php

namespace App\Modules\WeeklyEmail\Jobs;

use App\Mail\MailChannels\AccountProblem;
use App\Mail\MailChannels\EmailSendingNotReady;
use App\Mail\MailChannels\MessageRejected;
use App\Mail\MailChannels\RecipientSuppressed;
use App\Mail\MailChannels\SendingTooFast;
use App\Mail\MailChannels\TemporaryFailure;
use App\Modules\WeeklyEmail\Actions\RunState;
use App\Modules\WeeklyEmail\Actions\SendWeeklyEmailMessage;
use App\Modules\WeeklyEmail\Models\EmailDelivery;
use App\Modules\WeeklyEmail\Models\EmailRun;
use App\Modules\WeeklyEmail\Models\WeeklyEmailSetting;
use DateTimeInterface;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\RateLimited;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Throwable;

class SendWeeklyEmailJob implements ShouldQueue
{
    use Queueable;

    /** The per-congregation ceiling, well above the normal pace of 4 to 12 a minute. */
    public const RATE_LIMITER = 'weekly-email-sends';

    public const MAX_PER_MINUTE = 10;

    /** Sends of one email, counting the first, before a temporary failure is final. */
    public const MAX_ATTEMPTS = 3;

    /** Wait before the second and third attempts. */
    public const RETRY_AFTER_MINUTES = [1, 5];

    /** How far the rest of the run moves back when MailChannels asks us to slow down. */
    public const SLOW_DOWN_SECONDS = 120;

    public function __construct(public int $deliveryId, public ?int $teamId = null) {}

    /**
     * One congregation's emails go out one at a time, however many workers
     * run, and never faster than the ceiling.
     *
     * @return list<object>
     */
    public function middleware(): array
    {
        if ($this->teamId === null) {
            return [];
        }

        return [
            (new WithoutOverlapping('weekly-email-team-'.$this->teamId))->releaseAfter(5)->expireAfter(60),
            new RateLimited(self::RATE_LIMITER),
        ];
    }

    /**
     * Released jobs (held back by the middleware) keep trying for a while
     * instead of failing on a fixed attempt count.
     */
    public function retryUntil(): DateTimeInterface
    {
        return now()->addHours(2);
    }

    public function handle(SendWeeklyEmailMessage $sender, RunState $state): void
    {
        $delivery = EmailDelivery::query()->with(['run.team', 'recipient'])->find($this->deliveryId);

        if (! $delivery || $delivery->status !== 'pending' || ! in_array($delivery->run->status, ['scheduled', 'sending'], true)) {
            return;
        }

        // Moved later since this job was queued (a retry wait, or the run was
        // slowed down); the send loop queues it again for its new time.
        if ($delivery->scheduled_at->gt(now()->addSeconds(5))) {
            return;
        }

        if (! $delivery->recipient || ! $delivery->run->team) {
            $delivery->update(['status' => 'failed', 'error' => 'Missing recipient or congregation.']);
            $this->refreshRun($delivery);

            return;
        }

        if ($delivery->recipient->status !== 'subscribed') {
            $delivery->update(['status' => 'cancelled', 'error' => match ($delivery->recipient->status) {
                'bounced' => 'The address bounced before this email went out.',
                'complained' => 'Reported the weekly email as spam before this email went out.',
                'suppressed' => 'MailChannels refused the address before this email went out.',
                default => 'Unsubscribed before this email went out.',
            }]);
            $this->refreshRun($delivery);

            return;
        }

        $settings = WeeklyEmailSetting::forTeam($delivery->run->team);
        $settings->subject = $delivery->run->subject;
        $settings->body = $delivery->run->body;

        try {
            $ids = $sender->handle($delivery->run->team, $settings, $delivery->recipient, 'weekly-'.$delivery->run->id);
        } catch (EmailSendingNotReady|AccountProblem $exception) {
            // Nothing else will go through either: stop the run until someone
            // fixes the account, and leave this delivery pending.
            $state->pause($delivery->run, $exception->getMessage());

            return;
        } catch (TemporaryFailure $exception) {
            $this->retryLater($delivery, $exception, $state);

            return;
        } catch (RecipientSuppressed $exception) {
            // MailChannels will refuse this address every week; stop scheduling it.
            $delivery->recipient->update(['status' => 'suppressed', 'status_reason' => $exception->getMessage()]);
            $delivery->update(['status' => 'failed', 'attempts' => $delivery->attempts + 1, 'error' => $exception->getMessage()]);
            $this->refreshRun($delivery);

            return;
        } catch (Throwable $exception) {
            if (! $exception instanceof MessageRejected) {
                report($exception);
            }

            $delivery->update(['status' => 'failed', 'attempts' => $delivery->attempts + 1, 'error' => $exception->getMessage()]);
            $this->refreshRun($delivery);

            return;
        }

        $delivery->update([
            'status' => 'sent',
            'sent_at' => now(),
            'attempts' => $delivery->attempts + 1,
            'error' => null,
            'message_id' => $ids['message_id'],
            'request_id' => $ids['request_id'],
        ]);
        $this->refreshRun($delivery);
    }

    /**
     * Try a temporary failure again after a wait, up to MAX_ATTEMPTS. When
     * MailChannels said we were sending too fast, the rest of the run waits
     * too.
     */
    private function retryLater(EmailDelivery $delivery, TemporaryFailure $exception, RunState $state): void
    {
        $attempts = $delivery->attempts + 1;

        if ($attempts >= self::MAX_ATTEMPTS) {
            $delivery->update(['status' => 'failed', 'attempts' => $attempts, 'error' => $exception->getMessage()]);
            $this->refreshRun($delivery);

            return;
        }

        if ($exception instanceof SendingTooFast) {
            $state->pushBack($delivery->run, self::SLOW_DOWN_SECONDS);
        }

        $delivery->update([
            'attempts' => $attempts,
            'scheduled_at' => now()->addMinutes(self::RETRY_AFTER_MINUTES[$attempts - 1]),
            'queued_at' => null,
            'error' => 'Will try again: '.$exception->getMessage(),
        ]);
    }

    /** Mark the run completed once nothing is pending. A paused or cancelled run is left alone. */
    private function refreshRun(EmailDelivery $delivery): void
    {
        $pending = $delivery->run->deliveries()->where('status', 'pending')->exists();

        EmailRun::query()
            ->whereKey($delivery->run->id)
            ->whereIn('status', ['scheduled', 'sending'])
            ->update(['status' => $pending ? 'sending' : 'completed']);
    }
}
