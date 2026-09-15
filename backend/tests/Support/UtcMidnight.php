<?php

declare(strict_types=1);

namespace App\Tests\Support;

/**
 * **How long a test that seeds "today's UTC day" and then drives several
 * requests must wait so that no UTC midnight falls inside it** (V1-R-16, V4 B4d).
 *
 * E-12's login-failure counter resets at the UTC day boundary, decided by
 * Postgres' `now()`. `LoginAuditCapTest` seeds the counter for today and then
 * signs in several times; a run that crosses 00:00 UTC between the seed and the
 * last request would see the counter reset and fail — loudly, but for a reason
 * that is not a defect. The test takes the current time **from the database**,
 * asks this helper, and sleeps past the boundary when it is closer than the
 * test's budget. No skip and no retry: a slow run is still a run.
 */
final class UtcMidnight
{
    /**
     * `0` when the next UTC midnight is more than `$budget` seconds away;
     * otherwise the whole seconds that take `$nowUtc` strictly past it (the
     * seconds remaining, plus one, so a fractional `now` still lands after the
     * boundary). `$nowUtc` may carry any time zone; it is read as the instant it
     * names.
     */
    public static function secondsToWaitClearOfUtcMidnight(\DateTimeImmutable $nowUtc, int $budget): int
    {
        if ($budget < 0) {
            throw new \InvalidArgumentException('A budget is a number of seconds and cannot be negative.');
        }

        $now = $nowUtc->setTimezone(new \DateTimeZone('UTC'));
        $nextMidnight = $now->setTime(0, 0)->modify('+1 day');
        $remaining = $nextMidnight->getTimestamp() - $now->getTimestamp();

        return $remaining <= $budget ? $remaining + 1 : 0;
    }
}
