<?php

declare(strict_types=1);

namespace App\Modules\Sales\Application;

use Symfony\Component\Serializer\Attribute\SerializedName;

/**
 * One line of a fulfilment: this much of this order line left from this bin. Step 1's line fields one level
 * down — `sales_order_line = {id, line_number}` (API-23b clause (i)), `quantity`, `storage_location = {id,
 * code}` — plus `id` and §4.4.3's `inventory_item = {id, sku, name}` (the 2026-09-15 cost-exposure ruling,
 * written in the additions form with this build). `fulfilment_line` has no item column: the item is the
 * ordered line's.
 */
final readonly class FulfilmentLineView
{
    /**
     * @param string $quantity numeric(19,6)
     */
    public function __construct(
        public string $id,
        #[SerializedName('sales_order_line')]
        public SalesLineRef $salesOrderLine,
        #[SerializedName('inventory_item')]
        public SalesItemRef $inventoryItem,
        public string $quantity,
        #[SerializedName('storage_location')]
        public SalesLocationRef $storageLocation,
    ) {
    }
}
