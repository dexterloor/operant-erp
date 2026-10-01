<?php

declare(strict_types=1);

namespace App\Modules\Sales\Application;

use Symfony\Component\Serializer\Attribute\SerializedName;

/**
 * An invoice named as `{id, invoice_number}` — a PC-15 reference: its display field is visible under the parent's
 * permission (`SEC` S-4, 2026-09-30: a return's `invoice` and `credit_target` are PC-15's case, not R-14's).
 */
final readonly class InvoiceNumberRef
{
    public function __construct(
        public string $id,
        #[SerializedName('invoice_number')]
        public string $invoiceNumber,
    ) {
    }
}
