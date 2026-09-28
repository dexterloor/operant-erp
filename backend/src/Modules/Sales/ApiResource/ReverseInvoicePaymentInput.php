<?php

declare(strict_types=1);

namespace App\Modules\Sales\ApiResource;

/**
 * `ReverseInvoicePayment`'s body — §4.2's `Accepted request fields` cell is `reason` alone (sales step 6, C6).
 *
 * **Required** (§8.3, OI-2): a reversal without one is 422 `/reason` `required`, decided after the status guard so an
 * unpaid invoice is told it is not paid first. It lands in `audit_log.metadata` as `{"reason": …}` — `invoice` has no
 * free-text column — and API-9 refuses any other field rather than ignoring it.
 */
final class ReverseInvoicePaymentInput
{
    public ?string $reason = null;
}
