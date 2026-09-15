<?php

declare(strict_types=1);

namespace App\Modules\Purchasing\ApiResource;

use Symfony\Component\Serializer\Attribute\SerializedName;

/**
 * `UpdatePurchaseOrderLine`'s accepted request fields (§4.2). A line has no
 * `expected_at`, for the reason `CreatePurchaseOrderLineInput` states (owner
 * ruling Q2, 2026-09-15).
 *
 * `inventory_item_id` is deliberately not here either, and that is §4.2's cell
 * rather than a choice: changing which item a line is for is not an edit of that
 * line, it is a different line. Both quantities are `NOT NULL` columns, so
 * neither may be cleared by a `PATCH`.
 *
 * Properties are declared **uninitialised**; see `SubmittedFields`.
 */
final class UpdatePurchaseOrderLineInput
{
    use SubmittedFields;

    public ?string $quantity;

    #[SerializedName('unit_cost')]
    public ?string $unitCost;
}
