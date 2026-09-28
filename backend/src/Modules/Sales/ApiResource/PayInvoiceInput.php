<?php

declare(strict_types=1);

namespace App\Modules\Sales\ApiResource;

use Symfony\Component\Serializer\Attribute\SerializedName;

/**
 * `PayInvoice`'s body — §4.2's `Accepted request fields` cell is `paid_at` alone (sales step 6, C5).
 *
 * Optional: absent and `null` mean the same thing, *now*, on the database's clock (`InvoicePayment` step 4). A string
 * that is not an RFC 3339 timestamp is 422 `not_a_timestamp`; its bounds (not in the future beyond a minute, not
 * before the invoice was issued) are decided under the invoice's lock. **There is no field for the payer:** it is the
 * authenticated caller (`SEC` §2), and API-9 refuses an unknown field rather than ignoring it.
 */
final class PayInvoiceInput
{
    #[SerializedName('paid_at')]
    public ?string $paidAt = null;
}
