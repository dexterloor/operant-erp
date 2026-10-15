<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Application;

/** API-23b clause (ii)'s `inventory_item = {id, sku, name}`, as an `Application` value (`SalesItemRef`'s shape, one module over). */
final readonly class StockItemRef
{
    public function __construct(
        public string $id,
        public string $sku,
        public string $name,
    ) {
    }
}
