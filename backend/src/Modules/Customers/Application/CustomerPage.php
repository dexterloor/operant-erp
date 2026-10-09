<?php

declare(strict_types=1);

namespace App\Modules\Customers\Application;

/** One keyset page of `ListCustomers` (API-17/API-22), scalars and views only (A-7). */
final readonly class CustomerPage
{
    /**
     * @param list<CustomerView> $items
     */
    public function __construct(
        public array $items,
        public ?string $nextCursor,
        public ?string $previousCursor,
        public bool $tenantHasRows,
    ) {
    }
}
