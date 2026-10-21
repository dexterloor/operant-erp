<?php

declare(strict_types=1);

namespace App\Modules\AuditLog\Application;

/**
 * One page of `ListAuditRecords`, as {@see AuditRecordReader::list()} reads it — an Application read model, never an
 * `ApiResource` class ({@see AuditLogPage}'s arrangement).
 */
final readonly class AuditRecordPage
{
    /**
     * @param list<AuditRecordView> $items
     */
    public function __construct(
        public array $items,
        public ?string $nextCursor,
        public ?string $previousCursor,
        public bool $tenantHasRows,
    ) {
    }
}
