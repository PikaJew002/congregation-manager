<?php

namespace App\Modules\Email\Actions;

use App\Modules\Email\Models\EmailRun;
use Carbon\CarbonImmutable;

/**
 * Pausing, resuming and re-pacing a run by changing its rows. Jobs already in
 * the queue check the run and their delivery before sending, so changes here
 * take effect without touching the queue.
 */
class RunState
{
    /** A paused run this long after its send time is cancelled, not sent late. */
    public const STALE_PAUSE_HOURS = 24;

    public function __construct(private PaceDeliveries $pace) {}

    /**
     * Stop a run because the congregation's account cannot send. Pending
     * deliveries stay pending and are re-paced on resume.
     */
    public function pause(EmailRun $run, string $reason): void
    {
        $paused = EmailRun::query()
            ->whereKey($run->id)
            ->whereIn('status', ['scheduled', 'sending'])
            ->update(['status' => 'paused', 'pause_reason' => $reason, 'paused_at' => now()]);

        if ($paused === 0) {
            return;
        }

        $run->deliveries()->where('status', 'pending')->update(['queued_at' => null]);
        $run->refresh();
    }

    /**
     * Pick a paused run up again: its pending deliveries go out from now at
     * the usual pace.
     */
    public function resume(EmailRun $run): void
    {
        $pending = $run->deliveries()->where('status', 'pending')->orderBy('scheduled_at')->get();
        $times = $this->pace->times(CarbonImmutable::now()->utc(), $pending->count());

        foreach ($pending->values() as $index => $delivery) {
            $delivery->update(['scheduled_at' => $times[$index], 'queued_at' => null]);
        }

        $run->update([
            'status' => $pending->isEmpty() ? 'completed' : 'sending',
            'pause_reason' => null,
            'paused_at' => null,
        ]);
    }

    /**
     * Push every delivery not yet sent back by some seconds, after MailChannels
     * asked us to slow down.
     */
    public function pushBack(EmailRun $run, int $seconds): void
    {
        $run->deliveries()
            ->where('status', 'pending')
            ->get()
            ->each(fn ($delivery) => $delivery->update([
                'scheduled_at' => $delivery->scheduled_at->max(now())->addSeconds($seconds),
                'queued_at' => null,
            ]));
    }

    /**
     * Cancel runs paused for more than a day past their send time, so a week's
     * email never goes out days late.
     */
    public function cancelStalePauses(?CarbonImmutable $now = null): int
    {
        $cutoff = ($now ?? CarbonImmutable::now())->utc()->subHours(self::STALE_PAUSE_HOURS);
        $cancelled = 0;

        EmailRun::query()
            ->where('status', 'paused')
            ->where('scheduled_for', '<', $cutoff)
            ->each(function (EmailRun $run) use (&$cancelled) {
                $run->update(['status' => 'cancelled']);
                $run->deliveries()->where('status', 'pending')->update([
                    'status' => 'cancelled',
                    'error' => 'Not sent: the run was paused for over a day. '.$run->pause_reason,
                ]);
                $cancelled++;
            });

        return $cancelled;
    }
}
