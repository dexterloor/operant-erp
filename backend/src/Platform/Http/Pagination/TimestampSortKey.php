<?php

declare(strict_types=1);

namespace App\Platform\Http\Pagination;

/**
 * **Whether a cursor's sort key may be bound as `?::timestamptz`** — the ONE check every timestamp-keyed list uses
 * (shipped-defect fix, 2026-10-21).
 *
 * A {@see Cursor} is unsigned, so its key is whatever a client sends. Where a view orders by a `timestamptz` the reader
 * casts the key in SQL, and a key Postgres refuses there (`22007`/`22008`) is raised inside the read, where nothing
 * translates it: a bare 500. Until this class the check was copied into eight providers (`isTimestamp()`: a regex,
 * `date_parse()`, `checkdate()`), and it let a UTC offset of `+16` … `+99` through — Postgres's displacement limit is
 * `±15:59:59`, measured on Postgres 17 (`time zone displacement out of range`). Two lists cast with no check at all
 * (`ListSalesOrders` / `ListPurchaseOrders` on `ordered_at`) and one checked only a regex (`ListRoleGrantProposals`).
 *
 * **The contract, both halves pinned against the live server by `TimestampSortKeyTest`:**
 *  - **everything accepted here, Postgres accepts** — so a forged key is a 422 `malformed_cursor`, never a 500;
 *  - **everything Postgres renders for a `timestamptz` under DateStyle ISO is accepted here** — so no real cursor is
 *    refused: `YYYY-MM-DD HH:MM:SS[.f{1,6}]±HH[:MM[:SS]]` (the offset carries seconds only for a zone whose historical
 *    offset had them, e.g. LMT; the connection is UTC, but the shape is the server's, not the session's).
 *
 * **Deliberately narrower than Postgres, never wider:** `24:00:00`, a leap second `:60`, more than six fraction digits,
 * a year outside 0001–9999 and `BC` are all valid Postgres input but are refused here. Postgres never RENDERS the first
 * three, so no real cursor carries them; the last two would need a stored instant outside 0001–9999 AD, which no
 * column of this product can hold today (a real cursor from such a row would be refused as malformed — a 422, not a 500
 * — and the shipped check refused the same).
 */
final class TimestampSortKey
{
    private const SHAPE = '/^(\d{4})-(\d{2})-(\d{2}) (\d{2}):(\d{2}):(\d{2})(?:\.\d{1,6})?[+-](\d{2})(?::(\d{2}))?(?::(\d{2}))?$/D';

    /** Postgres's `±15:59:59` — the largest displacement it accepts on input (`MAX_TZDISP_HOUR`). */
    private const MAXIMUM_OFFSET_HOURS = 15;

    public static function isValid(string $value): bool
    {
        if (1 !== preg_match(self::SHAPE, $value, $m)) {
            return false;
        }

        [, $year, $month, $day, $hour, $minute, $second, $offsetHours] = $m;
        $offsetMinutes = $m[8] ?? '';
        $offsetSeconds = $m[9] ?? '';

        return checkdate((int) $month, (int) $day, (int) $year)
            && (int) $year >= 1
            && (int) $hour <= 23 && (int) $minute <= 59 && (int) $second <= 59
            && (int) $offsetHours <= self::MAXIMUM_OFFSET_HOURS
            && ('' === $offsetMinutes || (int) $offsetMinutes <= 59)
            && ('' === $offsetSeconds || (int) $offsetSeconds <= 59);
    }
}
