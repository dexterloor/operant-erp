<?php

declare(strict_types=1);

namespace App\Modules\AuditLog\Application;

/**
 * One `audit_log` row as `ListAuditLogEntries` serves it (audit reads C1; `API.md` §4.4.3's AuditLogEntry row).
 *
 * `actorId` / `actorDisplayName` are both null iff the row has no actor (`actor_user_id IS NULL`); otherwise the display
 * name is the user's **live** name, and `actorLabel` — always present — is the name **as recorded**
 * (`Version20260906000100`: *"a renamed or soft-deleted user does not rewrite history"*). `metadata` is the stored jsonb
 * object, decoded, served as an opaque object (§4.4.3; `SEC`/`PO` C.1). `ipAddress` is non-null only on a security event
 * (`audit_log_ip_address_scope`).
 */
final readonly class AuditLogEntryView
{
    /**
     * @param array<string, mixed> $metadata
     */
    public function __construct(
        public string $id,
        public string $occurredAt,
        public string $eventType,
        public ?string $actorId,
        public ?string $actorDisplayName,
        public string $actorLabel,
        public ?string $subjectType,
        public ?string $subjectId,
        public array $metadata,
        public ?string $ipAddress,
    ) {
    }
}
