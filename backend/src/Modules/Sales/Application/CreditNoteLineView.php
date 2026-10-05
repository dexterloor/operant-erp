<?php

declare(strict_types=1);

namespace App\Modules\Sales\Application;

use Symfony\Component\Serializer\Attribute\Context;
use Symfony\Component\Serializer\Attribute\SerializedName;
use Symfony\Component\Serializer\Normalizer\AbstractObjectNormalizer;

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
        /**
         * G-29 / G-30 part 3 (design §5, H-1 fix 2): on an ADJUSTMENT's line, every decision recorded about a return line
         * against it ({@see ReturnDecisionView}), oldest first; `[]` on a return credit's line. **Absent — not null — for
         * a caller without `invoice.view`**, in all four credit-note responses (J-8; `SEC` S-7, a PC-17 hold-test field).
         *
         * @var list<ReturnDecisionView>|null
         */
        #[SerializedName('return_decisions')]
        #[Context([AbstractObjectNormalizer::SKIP_NULL_VALUES => true])]
        public ?array $returnDecisions = null,
    ) {
    }
}
