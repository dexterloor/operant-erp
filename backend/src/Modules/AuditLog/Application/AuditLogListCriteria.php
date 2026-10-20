<?php

declare(strict_types=1);

namespace App\Modules\AuditLog\Application;

use App\Platform\Http\Pagination\Cursor;

/**
 * The parsed, validated form of `ListAuditLogEntries`' query parameters (audit reads C1;
 * `docs/session13/audit-reads-design.md` R1.3).
 *
 * **One order, all three views:** newest first, `occurred_at DESC, id DESC` — `IA.md` §5.6 offers no alternate for any
 * `/admin/audit-log` row, so there is no `sort` (`API.md` §14.2 item 30). **At most one filter:** `$actorUserId` OR
 * `$eventType`, never both — no index serves the pair, so the provider refuses it (`one_filter_at_a_time`) before this
 * class is built, and the constructor refuses it again so a direct caller cannot reach the unbounded scan either. Exactly
 * one of `$after` and `$before` may be set (API-17); both null is the first page.
 */
final readonly class AuditLogListCriteria
{
    public const DEFAULT_LIMIT = 50;
    public const MAXIMUM_LIMIT = 200;

    public function __construct(
        public int $limit = self::DEFAULT_LIMIT,
        public ?string $actorUserId = null,
        public ?string $eventType = null,
        public ?Cursor $after = null,
        public ?Cursor $before = null,
    ) {
        if (null !== $actorUserId && null !== $eventType) {
            throw new \LogicException('ListAuditLogEntries filters by one person or one kind of event, never both: no index serves the pair.');
        }
    }

    public function isBackwards(): bool
    {
        return null !== $this->before;
    }
}
