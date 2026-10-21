<?php

declare(strict_types=1);

namespace App\Platform\Http;

/**
 * **The one RFC 3339 parser for request fields**, shared by the purchase-order
 * validator and `RequestDigest::instant()` so the two can never disagree about
 * what a timestamp is.
 *
 * Accepts `Y-m-d\TH:i:sP` and `Y-m-d\TH:i:s.vP` (`Z` or an offset) — what
 * `timestamptz` accepts, and nothing looser: `strtotime` would take "next
 * tuesday".
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
 */
final class Rfc3339Timestamp
{
    public static function parse(string $value): ?\DateTimeImmutable
    {
        foreach ([\DateTimeInterface::RFC3339_EXTENDED, \DateTimeInterface::RFC3339] as $format) {
            $parsed = \DateTimeImmutable::createFromFormat($format, $value);
            $problems = \DateTimeImmutable::getLastErrors();

            if (false !== $parsed
                && (false === $problems || (0 === $problems['warning_count'] && 0 === $problems['error_count']))
            ) {
                return self::postgresCanHold($parsed) ? $parsed : null;
            }
        }

        return null;
    }

    /** Year at least 1 as written and in UTC, and an offset within Postgres's `±15:59:59` (see the class docblock). */
    private static function postgresCanHold(\DateTimeImmutable $parsed): bool
    {
        return (int) $parsed->format('Y') >= 1
            && (int) $parsed->setTimezone(new \DateTimeZone('UTC'))->format('Y') >= 1
            && abs($parsed->getOffset()) < 16 * 3600;
    }
}
