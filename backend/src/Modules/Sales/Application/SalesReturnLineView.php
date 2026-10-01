<?php

declare(strict_types=1);

namespace App\Modules\Sales\Application;

use Symfony\Component\Serializer\Attribute\SerializedName;

/**
 * One line of a sales return (design §4.2; §14 item 25): the shipped line it returns (Q-H), the item, the quantity
 * that came back, the bin it came back INTO, and `quantity_credited` — credited **quantities** only, never an amount,
 * under PC-9's third deliberate exception (`SEC` S-4). The line's own `id` is API-23 step 2's (`SEC`'s §1.3 amendment,
 * 2026-10-01: an opaque handle that discloses nothing, and C1's `credit_note_line.sales_return_line_id` addresses it).
 */
final readonly class SalesReturnLineView
{
    /**
     * @param string $quantity         numeric(19,6)
     * @param string $quantityCredited numeric(19,6); `0.000000` until credit notes exist (C0)
     */
    public function __construct(
        public string $id,
        #[SerializedName('fulfilment_line')]
        public ShippedLineRef $fulfilmentLine,
        #[SerializedName('inventory_item')]
        public SalesItemRef $inventoryItem,
        public string $quantity,
        #[SerializedName('storage_location')]
        public SalesLocationRef $storageLocation,
        #[SerializedName('quantity_credited')]
        public string $quantityCredited,
    ) {
    }
}
