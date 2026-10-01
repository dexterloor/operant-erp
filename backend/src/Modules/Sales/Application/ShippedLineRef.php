<?php

declare(strict_types=1);

namespace App\Modules\Sales\Application;

use Symfony\Component\Serializer\Attribute\SerializedName;

/**
 * **A shipped line named by a return line — owner ruling Q-H (2026-09-30; Q1(d)'s deferral, `API.md` §4.4.3).**
 * `fulfilment_line` has no number of its own, so it is named by what `FulfilmentLineView` shows: its order line
 * `{id, line_number}`, the item `{id, sku, name}` and — on a RETURN line, because one order line may ship from two
 * bins (`FulfilmentWriter`: duplicates keyed on (line, bin)) — the bin it left from, `{id, code}`.
 */
final readonly class ShippedLineRef
{
    public function __construct(
        public string $id,
        #[SerializedName('sales_order_line')]
        public SalesLineRef $salesOrderLine,
        #[SerializedName('inventory_item')]
        public SalesItemRef $inventoryItem,
        #[SerializedName('storage_location')]
        public SalesLocationRef $storageLocation,
    ) {
    }
}
