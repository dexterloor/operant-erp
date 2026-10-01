<?php

declare(strict_types=1);

namespace App\Modules\Sales\Application;

use Symfony\Component\Serializer\Attribute\SerializedName;

/**
 * One line of a credit note (design §4.5; §14 item 25's CreditNote row): the shipped line credited (owner ruling Q-H,
 * the money side — binless, with the shipped `quantity`), the item, the quantity credited and its telescoped amount
 * (design §4.4 step 8b; may be `0.0000`, RED L-1).
 */
final readonly class CreditNoteLineView
{
    /**
     * @param string $quantity numeric(19,6)
     * @param string $amount   numeric(19,4)
     */
    public function __construct(
        public string $id,
        #[SerializedName('fulfilment_line')]
        public BilledLineRef $fulfilmentLine,
        #[SerializedName('inventory_item')]
        public SalesItemRef $inventoryItem,
        public string $quantity,
        public string $amount,
    ) {
    }
}
