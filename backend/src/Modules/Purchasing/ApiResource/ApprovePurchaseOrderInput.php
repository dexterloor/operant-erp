<?php

declare(strict_types=1);

namespace App\Modules\Purchasing\ApiResource;

use Symfony\Component\Serializer\Attribute\SerializedName;

/**
 * `ApprovePurchaseOrder`'s accepted request fields, exhaustively (§4.2): one
 * field, `expected_at`.
 *
 * **The uninitialised declaration is load-bearing here rather than tidy.**
 * `PurchaseOrder::place(\DateTimeImmutable $orderedAt, ?\DateTimeImmutable
 * $expectedAt = null)` assigns `$this->expectedAt = $expectedAt` **unconditionally**,
 * so approving an order without naming a date would erase the date the buyer
 * entered at F-2 step 5 — silently, on the one transition F-2 calls
 * *"the moment the document stops being internal"*. Three cases, three
 * behaviours:
 *
 *   absent          → the order keeps the `expected_at` it already had
 *   sent as `null`  → the caller cleared it, and it is cleared
 *   sent with value → that value
 *
 * `ApprovePurchaseOrderProcessor` is where the first case reads the current row
 * and passes it back into `place()`.
 */
final class ApprovePurchaseOrderInput
{
    use SubmittedFields;

    #[SerializedName('expected_at')]
    public ?string $expectedAt;
}
