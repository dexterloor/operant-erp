<?php

declare(strict_types=1);

namespace App\Modules\Purchasing\ApiResource;

use Symfony\Component\Serializer\Attribute\SerializedName;

/**
 * `CreatePurchaseOrderLine`'s accepted request fields (§4.2), **minus
 * `expected_at`, which has no column** — see `PurchaseOrderLineResource`'s
 * docblock for the finding and
 * `PurchaseOrderOperationContractTest::lineExpectedAtIsAcceptedIfAndOnlyIfItHasAColumn`
 * for the tie that makes the day the column arrives a red suite.
 *
 * **There is no `id`, and that is B-3.** The row identifier is minted server-side
 * by `Uuid::v4()` (API-10, D-12).
 *
 * **There is no `line_number` and no `lead_time_days`, and the two are absent for
 * different reasons.** `line_number` is server-minted — the next free number on
 * this order — because §4.2 does not accept it and `purchase_order_line_unique`
 * would otherwise make a client responsible for a collision it cannot see.
 * `lead_time_days` is `NOT NULL` with no default and is **snapshotted** from the
 * order's supplier's `supplier_product` row, which is F-2 step 3 in terms:
 * *"Lead time and unit cost are snapshotted onto the line, not referenced —
 * `supplier_product` is deletion class `hard` precisely so current terms can
 * change without rewriting a placed order."* `unit_cost` is snapshotted by the
 * **caller**, because §4.2 accepts it; the lead time has no field to arrive in,
 * so the writer reads it. An item the order's supplier does not list is refused.
 *
 * `quantity` and `unit_cost` are carried as decimal **strings** in both
 * directions (S-12). A JSON number is a double through most clients, and money
 * that rounds on the way in is money nobody can reconcile.
 */
final class CreatePurchaseOrderLineInput
{
    #[SerializedName('inventory_item_id')]
    public ?string $inventoryItemId = null;

    public ?string $quantity = null;

    #[SerializedName('unit_cost')]
    public ?string $unitCost = null;
}
