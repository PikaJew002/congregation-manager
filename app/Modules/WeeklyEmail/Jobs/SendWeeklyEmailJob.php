<?php

namespace App\Modules\WeeklyEmail\Jobs;

use App\Modules\WeeklyEmail\Actions\SendWeeklyEmailMessage;
use App\Modules\WeeklyEmail\Models\EmailDelivery;
use App\Modules\WeeklyEmail\Models\WeeklyEmailSetting;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class SendWeeklyEmailJob implements ShouldQueue
{
    use Queueable;

    public function __construct(public int $deliveryId) {}

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

        $settings = WeeklyEmailSetting::forTeam($delivery->run->team);
        $settings->subject = $delivery->run->subject;
        $settings->body = $delivery->run->body;

        try {
            $sender->handle($settings, $delivery->recipient);
        } catch (Throwable $exception) {
            $delivery->update(['status' => 'failed', 'error' => $exception->getMessage()]);
            $this->refreshRun($delivery);

            return;
        }

        $delivery->update(['status' => 'sent', 'sent_at' => now(), 'error' => null]);
        $this->refreshRun($delivery);
    }

    private function refreshRun(EmailDelivery $delivery): void
    {
        $pending = $delivery->run->deliveries()->where('status', 'pending')->exists();
        $delivery->run->update(['status' => $pending ? 'sending' : 'completed']);
    }
}
