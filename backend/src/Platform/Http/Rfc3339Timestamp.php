<?php

declare(strict_types=1);

namespace App\Platform\Http;

/**
 * **The one RFC 3339 parser for request fields**, shared by the purchase-order
 * validator and `RequestDigest::instant()` so the two can never disagree about
 * what a timestamp is.
 *
 * Accepts `Y-m-d\TH:i:sP` and `Y-m-d\TH:i:s.vP` ending in `Z`, `z` or a
 * numeric offset — nothing looser: `strtotime` would take "next tuesday".
 * Kept on purpose although RFC 3339 does not name them: `+05`, `+0500` and
 * horizontal space before `Z` or the offset. PHP reads each one unambiguously,
 * and every writer stores PHP's reading.
 *
 * ---------------------------------------------------------------------------
 * Why "no warnings" is the whole rule
 * ---------------------------------------------------------------------------
 * `createFromFormat()` does not fail on an impossible value. It records a
 * **warning** and rolls it over: `2026-02-30` becomes 2 March, month 13 becomes
 * next January. Before this class nothing read the warning, so the validator
 * passed 30 February (Postgres then refused the cast — a 500) and the digest
 * turned it into 2 March.
 *
 * **`T24:00:00` and `:60` are refused too — decided, not incidental.** Postgres
 * rolls both over to the next instant (`'…T24:00:00Z'::timestamptz` is the
 * following midnight), so accepting them would store a different time than the
 * one written. A leap second cannot be represented by either PHP or Postgres,
 * and a client meaning "end of day" can send `00:00` of the next one. One rule
 * — refuse anything PHP had to correct — covers all four cases.
 *
 * ---------------------------------------------------------------------------
 * Year 0000 and offsets past ±15:59 are refused too (shipped-defect fix, 2026-10-21)
 * ---------------------------------------------------------------------------
 * PHP accepts both without a warning, and Postgres refuses both: year 0 does not exist in its calendar (`date/time field
 * value out of range` — the year before 1 AD is 1 BC), and its time-zone displacement stops at `±15:59:59` (`time zone
 * displacement out of range`), both measured on Postgres 17. A value carrying either reached the database and came back as
 * a 500 — measured over HTTP on `ListSalesReturns?decided_since=0000-06-01T00:00:00Z` and on
 * `0001-01-01T00:00:00+01:00`, which is year 0000 once converted to UTC. So the year must be at least 1 both as written
 * and in UTC (callers format either), and the offset must be one Postgres takes. The cursor-side twin of this rule is
 * `Pagination\TimestampSortKey`.
 *
 * ---------------------------------------------------------------------------
 * A zone name and a year past 9999 are refused too (shipped-defect fix, 2026-11-18)
 * ---------------------------------------------------------------------------
 * PHP's `P` also takes a zone name, abbreviation or military letter. **A zone name, abbreviation or military letter
 * (`UTC`, `EST`, `Europe/Paris`, `GMT+2`, `GMT+02:00`, `NZ`, `Etc/GMT+12`, `a`) is refused.** PHP and Postgres read some
 * of them differently: `GMT+2` with opposite signs (PHP +02:00, Postgres the POSIX −02:00), and Postgres refuses a
 * military letter outright, measured 2026-11-18 over HTTP on purchase-order `expected_at` (a 500, and a date stored four
 * hours off). PHP even disagrees with itself: `GMT+12` is +12:00 and `Etc/GMT+12` is −12:00. The rule is on the text:
 * the whole zone, after the seconds (or their fraction) and optional horizontal space, must be `Z`, `z` or a numeric
 * offset `±hh:mm`, `±hhmm` or `±hh` — matched there rather than at the end alone, because `NZ` ends in `Z` and
 * `GMT+02:00` in an offset. So a one-digit hour (`+5`), an offset with seconds (`+02:00:00`) and `+020` (which PHP reads
 * as +00:20) are refused too.
 *
 * **A year must be 0001–9999 both as written and in UTC.** Postgres stores `9999-12-31T23:59:59-15:59` as year 10000,
 * but `new \DateTimeImmutable()` cannot read that back, so every read of such a row is a 500. PHP's `Y` already refuses
 * five digits as written; the UTC half is checked here.
 */
final class Rfc3339Timestamp
{
    /** The zone, directly after the seconds or their fraction: `Z`, `z` or a numeric offset (see the class docblock). */
    private const ZONE_SUFFIX = '/:\d{2}(?:\.\d+)?\h*(?:[Zz]|[+-]\d{2}(?::?\d{2})?)\z/';

    public static function parse(string $value): ?\DateTimeImmutable
    {
        foreach ([\DateTimeInterface::RFC3339_EXTENDED, \DateTimeInterface::RFC3339] as $format) {
            $parsed = \DateTimeImmutable::createFromFormat($format, $value);
            $problems = \DateTimeImmutable::getLastErrors();

            if (false !== $parsed
                && (false === $problems || (0 === $problems['warning_count'] && 0 === $problems['error_count']))
            ) {
                return 1 === preg_match(self::ZONE_SUFFIX, $value) && self::postgresCanHold($parsed) ? $parsed : null;
            }
        }

        return null;
    }

    /**
     * Year 0001–9999 as written and in UTC (Postgres refuses year 0; it stores year 10000, which no reader can read back),
     * and an offset within Postgres's `±15:59:59` (see the class docblock).
     */
    private static function postgresCanHold(\DateTimeImmutable $parsed): bool
    {
        $utcYear = (int) $parsed->setTimezone(new \DateTimeZone('UTC'))->format('Y');

        return (int) $parsed->format('Y') >= 1
            && $utcYear >= 1
            && $utcYear <= 9999
            && abs($parsed->getOffset()) < 16 * 3600;
    }
}
