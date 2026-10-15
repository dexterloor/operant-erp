<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Application;

/**
 * A stock row's warehouse, `{id, name}` (stock reads C1, 2026-10-14; `BE-LEAD`'s V2-C-12 carry, display only).
 *
 * **Not a PC-15 reference:** `stock_level` has no warehouse column, so this is reached through the bin's NOT NULL
 * `storage_location.warehouse_id` and is served under `SECURITY_MODEL.md` PC-9's **fourth named exception** (`SEC`,
 * `docs/session13/stock-reads-sec.md` §1). Bounded to exactly these two members: never `code`, `country_code`, an address
 * column, `is_active` or `deleted_at` (`StockLevelReadTest` pins the key set).
 */
final readonly class StockWarehouseRef
{
    public function __construct(
        public string $id,
        public string $name,
    ) {
    }
}
