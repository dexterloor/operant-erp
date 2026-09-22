<?php

declare(strict_types=1);

namespace App\Modules\Sales\Application;

use Symfony\Component\Serializer\Attribute\SerializedName;

/**
 * One live line of the order, as the pick read shows it: what it is, how much
 * was ordered and shipped, what is still owed (the shared V2-C-14(b)
 * derivation), and where to pick it from. **No `unit_price`** — PC-12 clause 1's
 * domain, and the reason this read exists at all.
 */
final readonly class PickListLineView
{
    /**
     * @param string                $quantity            numeric(19,6)
     * @param string                $quantityFulfilled   numeric(19,6)
     * @param string                $quantityOutstanding numeric(19,6), 0 on a cancelled order (V2-C-14(b))
     * @param list<PickListBinView> $candidateBins       empty when nothing is owed or the warehouse is archived
     */
    public function __construct(
        #[SerializedName('sales_order_line')]
        public SalesLineRef $salesOrderLine,
        #[SerializedName('inventory_item')]
        public SalesItemRef $inventoryItem,
        public string $quantity,
        #[SerializedName('quantity_fulfilled')]
        public string $quantityFulfilled,
        #[SerializedName('quantity_outstanding')]
        public string $quantityOutstanding,
        #[SerializedName('candidate_bins')]
        public array $candidateBins,
    ) {
    }
}
