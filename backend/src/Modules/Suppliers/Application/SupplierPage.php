<?php

declare(strict_types=1);

namespace App\Modules\Suppliers\Application;

/** One keyset page of `ListSuppliers` (API-17/API-22), scalars and views only (A-7). */
final readonly class SupplierPage
{
    /**
     * @param list<SupplierView> $items
     */
    public function __construct(
        public array $items,
        public ?string $nextCursor,
        public ?string $previousCursor,
        public bool $tenantHasRows,
    ) {
    }
}
