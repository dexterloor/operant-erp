<?php

declare(strict_types=1);

namespace App\Modules\AuditLog\Application;

/**
 * One page of `ListAuditLogEntries`, as `AuditLogEntryReader::list()` reads it. The provider serializes it as
 * `AuditLogEntryCollection` (API-22), so the reader returns an Application read model and never an `ApiResource` class
 * (`StockMovementPage`'s arrangement).
 */
final readonly class AuditLogPage
{
    /**
     * @param list<AuditLogEntryView> $items
     */
    public function __construct(
        public array $items,
        public ?string $nextCursor,
        public ?string $previousCursor,
        public bool $tenantHasRows,
    ) {
    }
}
