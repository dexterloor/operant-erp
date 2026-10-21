<?php

declare(strict_types=1);

namespace App\Modules\AuditLog\ApiResource\State;

use App\Modules\AuditLog\Application\AuditLogListCriteria;
use App\Platform\Http\Pagination\Cursor;
use App\Platform\Http\Pagination\InvalidCursor;

/**
 * **The paging parameters both audit reads share** — `limit` and the `after`│`before` cursor pair (API-17, API-18) — ONE
 * copy for `ListAuditLogEntries` and `ListAuditRecords` (audit reads C2 moved them here out of C1's provider rather than
 * writing a second). Both reads key their cursor on the raw `occurred_at` text, so both refuse a cursor whose key is not
 * such a timestamp (`malformed_cursor`) before the cast can raise (mutation M10).
 */
final class AuditListParameters
{
    /** Not instantiable: functions over a query array. */
    private function __construct()
    {
    }

    /**
     * @param array<string, mixed>                                        $query
     * @param list<array{pointer: string, code: string, message: string}> $errors
     */
    public static function limit(array $query, array &$errors): int
    {
        if (!isset($query['limit'])) {
            return AuditLogListCriteria::DEFAULT_LIMIT;
        }

        $raw = $query['limit'];
        if (!\is_string($raw) || 1 !== preg_match('/^\d+$/', $raw) || (int) $raw < 1 || (int) $raw > AuditLogListCriteria::MAXIMUM_LIMIT) {
            $errors[] = self::error('limit', 'out_of_range', \sprintf('limit must be a whole number between 1 and %d.', AuditLogListCriteria::MAXIMUM_LIMIT));

            return AuditLogListCriteria::DEFAULT_LIMIT;
        }

        return (int) $raw;
    }

    /**
     * Both cursors, after the `mutually_exclusive` check.
     *
     * @param array<string, mixed>                                        $query
     * @param list<array{pointer: string, code: string, message: string}> $errors
     *
     * @return array{?Cursor, ?Cursor} after, before
     */
    public static function cursors(array $query, array &$errors): array
    {
        if (isset($query['after'], $query['before'])) {
            $errors[] = self::error('after', 'mutually_exclusive', 'Send at most one of after and before.');
        }

        return [self::cursor($query, 'after', $errors), self::cursor($query, 'before', $errors)];
    }

    /** @return array{pointer: string, code: string, message: string} */
    public static function error(string $parameter, string $code, string $message): array
    {
        return ['pointer' => '?'.$parameter, 'code' => $code, 'message' => $message];
    }

    /**
     * @param array<string, mixed>                                        $query
     * @param list<array{pointer: string, code: string, message: string}> $errors
     */
    private static function cursor(array $query, string $name, array &$errors): ?Cursor
    {
        if (!isset($query[$name]) || '' === $query[$name]) {
            return null;
        }

        try {
            $cursor = Cursor::decode((string) $query[$name]);
        } catch (InvalidCursor) {
            $cursor = null;
        }

        if (null === $cursor || !self::isTimestamp($cursor->sortKey)) {
            $errors[] = self::error($name, 'malformed_cursor', \sprintf('%s must be a cursor returned by a previous response for this view.', $name));

            return null;
        }

        return $cursor;
    }

    /** A `timestamptz` exactly as Postgres renders one in this project's sessions (`2026-09-02 00:00:00.123456+00`). */
    private static function isTimestamp(string $value): bool
    {
        if (1 !== preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}(\.\d{1,6})?[+-]\d{2}(:\d{2})?$/', $value)) {
            return false;
        }

        $parsed = date_parse($value);

        return 0 === $parsed['error_count'] && 0 === $parsed['warning_count']
            && checkdate((int) $parsed['month'], (int) $parsed['day'], (int) $parsed['year']);
    }
}
