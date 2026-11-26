<?php

namespace App\Modules\WeeklyEmail\Jobs;

use App\Modules\WeeklyEmail\Actions\SendWeeklyEmailMessage;
use App\Modules\WeeklyEmail\Models\EmailDelivery;
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

    public function handle(SendWeeklyEmailMessage $sender): void
    {
        $delivery = EmailDelivery::query()->with(['run.team', 'recipient'])->find($this->deliveryId);

        if (! $delivery || $delivery->status !== 'pending' || $delivery->run->status === 'cancelled') {
            return;
        }

        if (! $delivery->recipient || ! $delivery->run->team) {
            $delivery->update(['status' => 'failed', 'error' => 'Missing recipient or congregation.']);
            $this->refreshRun($delivery);

            return;
        }

        if ($delivery->recipient->status !== 'subscribed') {
            $delivery->update(['status' => 'cancelled', 'error' => 'Unsubscribed before this email went out.']);
            $this->refreshRun($delivery);

            return;
        }

        $settings = WeeklyEmailSetting::forTeam($delivery->run->team);
        $settings->subject = $delivery->run->subject;
        $settings->body = $delivery->run->body;

        try {
            $messageId = $sender->handle($delivery->run->team, $settings, $delivery->recipient, 'weekly-'.$delivery->run->id);
        } catch (Throwable $exception) {
            $delivery->update(['status' => 'failed', 'error' => $exception->getMessage()]);
            $this->refreshRun($delivery);

            return;
        }

        $delivery->update(['status' => 'sent', 'sent_at' => now(), 'error' => null, 'message_id' => $messageId]);
        $this->refreshRun($delivery);
    }

    private function refreshRun(EmailDelivery $delivery): void
    {
        $pending = $delivery->run->deliveries()->where('status', 'pending')->exists();
        $delivery->run->update(['status' => $pending ? 'sending' : 'completed']);
    }
}
