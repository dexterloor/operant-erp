<?php

declare(strict_types=1);

namespace App\Modules\Sales\Application;

/** One keyset page of `ListSalesReturns` (API-17/API-18). */
final readonly class SalesReturnPage
{
    /**
     * @param list<SalesReturnView> $items
     */
    public function __construct(
        public array $items,
        public ?string $nextCursor,
        public ?string $previousCursor,
        public bool $tenantHasRows,
    ) {
    }
}
