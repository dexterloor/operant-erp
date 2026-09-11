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
                return $parsed;
            }
        }

        return null;
    }
}
