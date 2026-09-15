<?php

declare(strict_types=1);

namespace App\Tests\Meta;

use App\Tests\Support\UtcMidnight;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * `UtcMidnight::secondsToWaitClearOfUtcMidnight()`, pure (V1-R-16, V4 B4d).
 *
 * Mutation: the helper returns `0` → RED on every case that must wait (the
 * boundary is then never waited for and `LoginAuditCapTest` can straddle it).
 */
final class UtcMidnightTest extends TestCase
{
    #[Test]
    public function itWaitsPastTheNextUtcMidnightOnlyWhenItFallsInsideTheBudget(): void
    {
        $cases = [
            'one minute to midnight, budget 120' => ['2026-09-16T23:59:00+00:00', 120, 61],
            'exactly the budget away' => ['2026-09-16T23:58:00+00:00', 120, 121],
            'one second more than the budget' => ['2026-09-16T23:57:59+00:00', 120, 0],
            'midday' => ['2026-09-16T12:00:00+00:00', 120, 0],
            'just after midnight' => ['2026-09-17T00:00:00+00:00', 120, 0],
            'a fraction of a second before midnight' => ['2026-09-16T23:59:59.900+00:00', 5, 2],
            'another zone naming 23:59:30 UTC' => ['2026-09-17T01:59:30+02:00', 60, 31],
            'a local midnight that is not the UTC one' => ['2026-09-17T00:00:00+02:00', 60, 0],
            'budget zero, one second away' => ['2026-09-16T23:59:59+00:00', 0, 0],
        ];

        $waited = 0;
        foreach ($cases as $label => [$now, $budget, $expected]) {
            self::assertSame($expected, UtcMidnight::secondsToWaitClearOfUtcMidnight(new \DateTimeImmutable($now), $budget), $label);
            $waited += $expected > 0 ? 1 : 0;
        }
        self::assertGreaterThan(0, $waited, 'No case waits, so a helper returning 0 would pass.');

        $this->expectException(\InvalidArgumentException::class);
        UtcMidnight::secondsToWaitClearOfUtcMidnight(new \DateTimeImmutable('2026-09-16T23:59:59+00:00'), -1);
    }
}
