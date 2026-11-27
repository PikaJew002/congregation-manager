<?php

namespace App\Modules\Email\Actions;

use App\Modules\Email\Jobs\SendEmailJob;
use App\Modules\Email\Models\EmailDelivery;
use App\Modules\Email\Models\EmailRun;
use Carbon\CarbonImmutable;

/**
 * Hands deliveries to the queue a minute ahead of when they are due, each
 * delayed to its own second. Nothing sits in the queue for long, so a run can
 * be cancelled, paused or re-paced by changing its rows.
 */
class QueueDueDeliveries
{
    /** How far ahead deliveries are queued. Matches the scheduler's tick. */
    public const LOOKAHEAD_SECONDS = 60;

    /** A delivery queued this long ago and still pending is queued again. */
    public const STALLED_MINUTES = 15;

    public function handle(?CarbonImmutable $now = null): int
    {
        $now = ($now ?? CarbonImmutable::now())->utc();
        $queued = 0;

        EmailDelivery::query()
            ->with('run')
            ->where('status', 'pending')
            ->where('scheduled_at', '<=', $now->addSeconds(self::LOOKAHEAD_SECONDS))
            ->where(fn ($query) => $query
                ->whereNull('queued_at')
                ->orWhere('queued_at', '<', $now->subMinutes(self::STALLED_MINUTES)))
            ->whereHas('run', fn ($query) => $query->whereIn('status', ['scheduled', 'sending']))
            ->orderBy('scheduled_at')
            ->each(function (EmailDelivery $delivery) use ($now, &$queued) {
                // Claim the row so an overlapping tick cannot queue it twice.
                $claimed = EmailDelivery::query()
                    ->whereKey($delivery->id)
                    ->where('status', 'pending')
                    ->where(fn ($query) => $query
                        ->whereNull('queued_at')
                        ->orWhere('queued_at', '<', $now->subMinutes(self::STALLED_MINUTES)))
                    ->update(['queued_at' => $now]);

                if ($claimed === 0) {
                    return;
                }

                if ($delivery->run->status === 'scheduled') {
                    EmailRun::query()->whereKey($delivery->run->id)->update(['status' => 'sending']);
                }

                SendEmailJob::dispatch($delivery->id, $delivery->run->team_id)
                    ->delay($delivery->scheduled_at->max($now));

                $queued++;
            });

        return $queued;
    }
}
