<?php

declare(strict_types=1);

namespace App\Modules\Analytics\Application;

/**
 * One LISTED item ({@see ReorderSuggester::listed()}): what Inventory and Purchasing say about it, before any catalogue read.
 * Every quantity at scale 6. `reorderPoint` / `reorderQuantity` are the item's stored values (served as stored); the
 * computation reads a value ≤ 0 as unset.
 */
final readonly class ReorderLine
{
    public function __construct(
        public string $itemId,
        public string $sku,
        public string $name,
        public string $qtyOnHand,
        public string $qtyOnOrder,
        public string $reorderPoint,
        public string $reorderQuantity,
        public ?string $preferredSupplierId,
    ) {
    }
}
