<?php

declare(strict_types=1);

namespace App\Modules\Sales\Application;

use Symfony\Component\Serializer\Attribute\SerializedName;

/**
 * **A shipped line named by an invoice or credit-note line — owner ruling Q-H (2026-09-30), the money side.** Under
 * `invoice.view` the reference stays **binless** (no `storage_location`) and adds the **shipped `quantity`**, so two
 * lines of one order line shipped from two bins are told apart by what shipped, not by where from (design §12 Q-H as
 * revised after RED-4). `ShippedLineRef` is the return side's shape, with the bin.
 */
final readonly class BilledLineRef
{
    /** @param string $quantity numeric(19,6), what this shipped line shipped */
    public function __construct(
        public string $id,
        #[SerializedName('sales_order_line')]
        public SalesLineRef $salesOrderLine,
        #[SerializedName('inventory_item')]
        public SalesItemRef $inventoryItem,
        public string $quantity,
    ) {
    }
}
