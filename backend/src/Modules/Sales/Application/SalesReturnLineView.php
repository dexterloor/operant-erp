<?php

declare(strict_types=1);

namespace App\Modules\Sales\Application;

use Symfony\Component\Serializer\Attribute\Context;
use Symfony\Component\Serializer\Attribute\SerializedName;
use Symfony\Component\Serializer\Normalizer\AbstractObjectNormalizer;

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
        /**
         * G-29 / G-30 part 3 (design §5 (4)): the units of this line recorded as ALREADY CREDITED by an adjustment
         * (live `same` decisions), numeric(19,6). Not `quantity_credited`, which stays what credit notes naming this line
         * credited. **Absent — not null — for a caller without `invoice.view`** (M-4; PC-9's third exception).
         */
        #[SerializedName('quantity_covered_by_adjustment')]
        #[Context([AbstractObjectNormalizer::SKIP_NULL_VALUES => true])]
        public ?string $quantityCoveredByAdjustment = null,
        /**
         * Every decision recorded on this line, oldest first, reversals included ({@see AdjustmentDecisionView}).
         * **Absent for a caller without `invoice.view`.**
         *
         * @var list<AdjustmentDecisionView>|null
         */
        #[SerializedName('adjustment_decisions')]
        #[Context([AbstractObjectNormalizer::SKIP_NULL_VALUES => true])]
        public ?array $adjustmentDecisions = null,
    ) {
    }
}
