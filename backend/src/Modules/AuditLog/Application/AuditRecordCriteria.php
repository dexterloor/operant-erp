<?php

declare(strict_types=1);

namespace App\Modules\AuditLog\Application;

use App\Platform\Http\Pagination\Cursor;

/**
 * The parsed, validated form of `ListAuditRecords`' query parameters (audit reads C2; `API.md` §4.2: **required**
 * `subject_type`, `subject_id`; `limit`; `after`│`before`). One subject — the History of ONE row (E-5: an unbounded History
 * endpoint is the whole defect) — newest first, `occurred_at DESC, id DESC`. `limit` shares `ListAuditLogEntries`' bounds.
 */
final readonly class AuditRecordCriteria
{
    public function __construct(
        public string $subjectType,
        public string $subjectId,
        public int $limit = AuditLogListCriteria::DEFAULT_LIMIT,
        public ?Cursor $after = null,
        public ?Cursor $before = null,
    ) {
        if (null === RecordHistorySubjects::readCodeOf($subjectType)) {
            throw new \LogicException(\sprintf('"%s" is not a History subject (RecordHistorySubjects).', $subjectType));
        }
    }

    public function isBackwards(): bool
    {
        return null !== $this->before;
    }
}
