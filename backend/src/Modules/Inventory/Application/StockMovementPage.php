<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Application;

/**
 * One page of `ListStockMovements`, as `StockMovementReader::list()` reads it. The provider serializes it as
 * `StockMovementCollection` (API-22), so the reader returns an Application read model and never an `ApiResource` class
 * (`FulfilmentPage`'s arrangement).
 */
final readonly class StockMovementPage
{
    /**
     * @param list<StockMovementView> $items
     */
    public function __construct(
        public array $items,
        public ?string $nextCursor,
        public ?string $previousCursor,
        public bool $tenantHasRows,
    ) {
    }
}
