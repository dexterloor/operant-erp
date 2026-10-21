<?php

declare(strict_types=1);

namespace App\Tests\Platform\Http;

use App\Platform\Http\Idempotency\RequestDigest;
use App\Platform\Http\Rfc3339Timestamp;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The one RFC 3339 parser the purchase-order validator and `RequestDigest` share.
 *
 * `DateTimeImmutable::createFromFormat()` does not fail on an impossible date:
 * it records a *warning* and rolls it over. `2026-02-30` came back as 2 March,
 * so a create given 30 February succeeded as 2 March, and a retry that sent the
 * real 2 March digested equal to it. The parser refuses any input that produced
 * a warning.
 */
final class Rfc3339TimestampTest extends TestCase
{
    #[Test]
    public function aWellFormedTimestampIsParsedToTheInstantItNames(): void
    {
        foreach (['2026-10-01T00:00:00Z', '2026-10-01T00:00:00+00:00', '2026-10-01T02:00:00+02:00', '2026-10-01T00:00:00.000Z'] as $value) {
            self::assertSame(1790812800, Rfc3339Timestamp::parse($value)?->getTimestamp(), $value);
        }

        // The edges Postgres still takes: the first instant of year 1 in UTC, the largest displacement, year 9999.
        foreach (['0001-01-01T00:00:00Z', '0001-01-01T15:59:00+15:59', '2026-01-01T00:00:00-15:59', '9999-12-31T23:59:59-15:59'] as $value) {
            self::assertNotNull(Rfc3339Timestamp::parse($value), $value);
        }
    }

    /** @return iterable<string, array{string}> */
    public static function refused(): iterable
    {
        // Rolled over by PHP with a warning: the three that motivated the class.
        yield '30 February' => ['2026-02-30T00:00:00Z'];
        yield 'month 13' => ['2026-13-01T00:00:00Z'];
        // Rolled over by PHP AND by Postgres (`'…T24:00:00Z'::timestamptz` is
        // the next midnight). Refused anyway: one rule, "no warnings", and a
        // client meaning midnight can say 00:00 of the next day.
        yield 'hour 24' => ['2026-10-01T24:00:00Z'];
        yield 'second 60' => ['2026-10-01T23:59:60Z'];
        // Refused before this class existed, and still.
        yield 'date only' => ['2026-10-01'];
        yield 'prose' => ['next tuesday'];
        // Accepted by PHP without a warning, refused by Postgres -- each was a 500 (shipped-defect fix, 2026-10-21).
        yield 'year 0000' => ['0000-06-01T00:00:00Z'];
        yield 'year 0000 once in UTC' => ['0001-01-01T00:00:00+01:00'];
        yield 'offset +16:00' => ['2026-01-01T00:00:00+16:00'];
        yield 'offset -16:00' => ['2026-01-01T00:00:00-16:00'];
        yield 'offset +99:59' => ['2026-01-01T00:00:00+99:59'];
    }

    #[Test]
    #[DataProvider('refused')]
    public function anImpossibleOrMalformedTimestampIsRefusedRatherThanRolledOver(string $value): void
    {
        self::assertNull(Rfc3339Timestamp::parse($value));
    }

    #[Test]
    public function theDigestRefusesAnImpossibleDateInsteadOfDigestingTheDayItRollsTo(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        RequestDigest::instant('2026-02-30T00:00:00Z');
    }
}
