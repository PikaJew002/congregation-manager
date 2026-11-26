<?php

namespace App\Modules\WeeklyEmail\Actions;

use Carbon\CarbonImmutable;

/**
 * When each email in a send goes out: one person at a time, a random 5 to 15
 * seconds apart. Receiving providers judge the congregation's domain, not the
 * pace inside one send, so a short steady send beats a long window, and the
 * gap keeps clear of MailChannels' "sending too fast" throttle.
 */
class PaceDeliveries
{
    public const MIN_GAP_SECONDS = 5;

    public const MAX_GAP_SECONDS = 15;

    /**
     * @return list<CarbonImmutable> One moment per email, in order.
     */
    public function times(CarbonImmutable $start, int $count): array
    {
        $times = [];
        $at = $start;

        for ($index = 0; $index < $count; $index++) {
            if ($index > 0) {
                $at = $at->addSeconds(random_int(self::MIN_GAP_SECONDS, self::MAX_GAP_SECONDS));
            }

            $times[] = $at;
        }

        return $times;
    }

    /** Roughly how many minutes a send to this many people takes. */
    public static function estimatedMinutes(int $count): int
    {
        $average = (self::MIN_GAP_SECONDS + self::MAX_GAP_SECONDS) / 2;

        return (int) ceil(max($count - 1, 0) * $average / 60);
    }
}
