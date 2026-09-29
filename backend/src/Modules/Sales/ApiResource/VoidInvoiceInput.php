<?php

declare(strict_types=1);

namespace App\Modules\Sales\ApiResource;

/**
 * `VoidInvoice`'s body — §4.2's `Accepted request fields` cell is `reason` alone (sales step 6, C7).
 *
 * **Required** (§8.3, OI-2 R2): a void without one is 422 `/reason` `required`, decided after the status guard so a paid
 * or void invoice is told that first (`ReverseInvoicePaymentInput`'s arrangement). It lands in `audit_log.metadata` as
 * `{"reason": …}` — `invoice` has no free-text column — and API-9 refuses any other field rather than ignoring it.
 */
final class VoidInvoiceInput
{
    public ?string $reason = null;
}
