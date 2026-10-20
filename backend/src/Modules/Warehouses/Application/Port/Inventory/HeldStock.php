<?php

declare(strict_types=1);

namespace App\Modules\Warehouses\Application\Port\Inventory;

/**
 * What {@see WarehouseHeldStock} answers (warehouse/location delete-restore C3; design Revision 1 G2), counted in
 * **locations**, never quantities or items (`SEC` S-3: the refusal tells a `.delete` holder without `stock_level.view` only
 * that stock is there, and whether any of it is an archived item's):
 *  - `stocked` — locations holding stock (0 or 1 for one location; C4's warehouse question counts several);
 *  - `archivedItemStocked` — of those, the locations holding stock of an archived item (`inventory_item.deleted_at` set):
 *    that stock can be moved or counted off only once the item is restored (`RESIDUAL_GAPS.md` G-51).
 */
final readonly class HeldStock
{
    public function __construct(
        public int $stocked,
        public int $archivedItemStocked,
    ) {
    }
}
