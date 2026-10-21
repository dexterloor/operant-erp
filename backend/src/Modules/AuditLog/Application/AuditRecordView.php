<?php

declare(strict_types=1);

namespace App\Modules\AuditLog\Application;

/**
 * One `audit_log` row as `ListAuditRecords` serves it (audit reads C2; `API.md` §4.4.3's AuditRecord row): the
 * {@see AuditLogEntryView} members **minus `metadata` and `ip_address`** (PC-11's colleague-readable view; `SEC`/`PO` C.2:
 * History ships without `metadata`). `actorId`/`actorDisplayName` are both null iff the row has no actor; `actorLabel` is
 * always the name as recorded.
 */
final readonly class AuditRecordView
{
    public function __construct(
        public string $id,
        public string $occurredAt,
        public string $eventType,
        public ?string $actorId,
        public ?string $actorDisplayName,
        public string $actorLabel,
        public string $subjectType,
        public string $subjectId,
    ) {
    }
}
