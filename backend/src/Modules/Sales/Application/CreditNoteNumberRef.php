<?php

declare(strict_types=1);

namespace App\Modules\Sales\Application;

use Symfony\Component\Serializer\Attribute\SerializedName;

/**
 * A reference to a credit note: `{id, credit_note_number}` (PC-15). Served on a return line's `adjustment_decisions[]`
 * only to an `invoice.view` holder — PC-9's third exception never lets a `sales_return.view` holder see a credit-note id
 * or number (`g29-g30-sec.md` §5).
 */
final readonly class CreditNoteNumberRef
{
    public function __construct(
        public string $id,
        #[SerializedName('credit_note_number')]
        public string $creditNoteNumber,
    ) {
    }
}
