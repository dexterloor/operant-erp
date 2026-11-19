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

        // The edges Postgres still takes and every reader can read back: the first instant of year 1 in UTC, the largest
        // displacement, the last instant of year 9999 in UTC (as `Z`, and at the widest offset east, still 9999 in UTC).
        foreach (['0001-01-01T00:00:00Z', '0001-01-01T15:59:00+15:59', '2026-01-01T00:00:00-15:59', '9999-12-31T23:59:59Z', '9999-12-31T23:59:59+15:59'] as $value) {
            self::assertNotNull(Rfc3339Timestamp::parse($value), $value);
        }
    }

    /**
     * **Leniency kept on purpose (G-105):** RFC 3339 names none of these, but each is a numeric offset or `Z` that PHP
     * reads unambiguously, every writer stores PHP's reading, and refusing them would refuse requests that work. A strike
     * to the strict form `±hh:mm` moves the `+05` and `+0000` rows into `refused()`.
     */
    #[Test]
    public function aNumericOffsetWithoutItsColonOrAfterOneSpaceIsStillAccepted(): void
    {
        foreach ([
            '2026-10-01T05:00:00+05' => 1790812800,
            '2026-10-01T00:00:00+0000' => 1790812800,
            '2026-10-01T00:00:00 Z' => 1790812800,
            "2026-10-01T00:00:00\tZ" => 1790812800,
            '2026-10-01T00:00:00z' => 1790812800,
        ] as $value => $instant) {
            self::assertSame($instant, Rfc3339Timestamp::parse($value)?->getTimestamp(), $value);
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
        // Accepted by PHP's `P` without a warning, outside RFC 3339, and read differently by Postgres or refused by it
        // (G-105, measured 2026-11-18): a zone name, abbreviation or military letter is refused wherever it ends.
        yield 'UTC zone name' => ['2026-10-01T00:00:00UTC'];
        yield 'EST' => ['2026-10-01T00:00:00EST'];
        yield 'region name' => ['2026-10-01T00:00:00Europe/Paris'];
        yield 'GMT+2' => ['2026-10-01T00:00:00GMT+2'];
        yield 'military letter' => ['2026-10-01T00:00:00a'];
        // Names whose text ends like a valid zone -- in `Z`, in two digits, in `HH:MM` -- so the rule is anchored to the
        // seconds, not to the end alone.
        yield 'NZ, ending in Z' => ['2026-10-01T00:00:00NZ'];
        yield 'GMT+12, ending in two digits' => ['2026-10-01T00:00:00GMT+12'];
        yield 'Etc/GMT+12, a region ending in two digits' => ['2026-10-01T00:00:00Etc/GMT+12'];
        yield 'GMT+05:00, ending in an offset' => ['2026-10-01T00:00:00GMT+05:00'];
        yield 'GMT+02:00, ending in an offset' => ['2026-10-01T00:00:00GMT+02:00'];
        yield 'GMT+02, ending in two digits' => ['2026-10-01T00:00:00GMT+02'];
        yield 'Etc/GMT-14' => ['2026-10-01T00:00:00Etc/GMT-14'];
        // Numeric, but not `±hh:mm`, `±hhmm` or `±hh` (accepted by PHP before G-105; `+020` is read as +00:20).
        yield 'one-digit hour offset' => ['2026-10-01T00:00:00+5'];
        yield 'one-digit hour offset with minutes' => ['2026-10-01T00:00:00+5:00'];
        yield 'offset with seconds' => ['2026-10-01T00:00:00+02:00:00'];
        yield 'offset with one minute digit' => ['2026-10-01T00:00:00+02:0'];
        yield 'three-digit offset' => ['2026-10-01T00:00:00+020'];
        // Offset minutes 60 to 99: PHP rolls them over (`+02:99` is read as +03:39) and the instant stored was not the one
        // written; RFC 3339 allows 00 to 59 (BK-18 R18-f/g C2).
        yield 'offset minutes 99' => ['2026-10-01T00:00:00+02:99'];
        yield 'offset minutes 60' => ['2026-10-01T00:00:00+00:60'];
        // Postgres stores year 10000; `new \DateTimeImmutable()` cannot read it back, so every later read was a 500.
        yield 'past 9999 in UTC by one minute' => ['9999-12-31T23:59:59-00:01'];
        yield 'past 9999 at the widest offset' => ['9999-12-31T23:59:59-15:59'];
    }

    #[Test]
    #[DataProvider('refused')]
    public function anImpossibleOrMalformedTimestampIsRefusedRatherThanRolledOver(string $value): void
    {
        self::assertNull(Rfc3339Timestamp::parse($value));
    }

    /**
     * BK-18 R18-f T-F3: the published shape is true. Every clause of {@see Rfc3339Timestamp::PROFILE} occurs in it (so a
     * sentence edit that drops one is red) and its accepted and refused values are what {@see Rfc3339Timestamp::parse()}
     * does (so a parser change the sentence does not follow is red).
     */
    #[Test]
    public function thePublishedShapeIsWhatTheParserAccepts(): void
    {
        self::assertGreaterThanOrEqual(7, \count(Rfc3339Timestamp::PROFILE_CLAUSES), 'Anti-vacuity: the profile has its clauses.');
        foreach (Rfc3339Timestamp::PROFILE_CLAUSES as [$clause, $accepted, $refused]) {
            self::assertStringContainsString(mb_strtolower($clause), mb_strtolower(Rfc3339Timestamp::PROFILE), 'The profile no longer says: '.$clause);
            self::assertNotNull(Rfc3339Timestamp::parse($accepted), $clause.': '.$accepted.' should be accepted');
            self::assertNull(Rfc3339Timestamp::parse($refused), $clause.': '.$refused.' should be refused');
        }
    }

    #[Test]
    public function theDigestRefusesAnImpossibleDateInsteadOfDigestingTheDayItRollsTo(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        RequestDigest::instant('2026-02-30T00:00:00Z');
    }
}
