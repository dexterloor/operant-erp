<?php

declare(strict_types=1);

namespace App\Modules\Sales\ApiResource;

use Symfony\Component\Serializer\Attribute\SerializedName;

/**
 * `CreateSalesOrderLine`'s accepted request fields (§4.2): `inventory_item_id`, `quantity`, `unit_price`.
 *
 * **There is no `id`, and that is B-3.** The row identifier is minted server-side by `Uuid::v4()`
 * (API-10, D-12).
 *
 * **There is no `line_number`.** It is server-minted as the next free number on this order, because §4.2
 * accepts no field for it and `sales_order_line_unique` would otherwise make a client responsible for a
 * collision it cannot see. A discarded number is reused — the mint is `MAX + 1` over the order's **live**
 * lines, which is the same set the partial unique constrains.
 *
 * **There is no supplier-catalogue lookup and no snapshot beyond the price**, which is the one place this
 * input is genuinely simpler than `CreatePurchaseOrderLineInput`: the purchase line snapshots
 * `lead_time_days` from `supplier_product` and refuses an item the order's supplier does not list. A sales
 * line has no such parent relation — the customer does not have a catalogue — so `unit_price` arrives from
 * the caller and nothing else is read.
 *
 * `quantity` and `unit_price` are carried as decimal **strings** in both directions (S-12). A JSON number
 * is a double through most clients, and money that rounds on the way in is money nobody can reconcile.
 */
final class CreateSalesOrderLineInput
{
    #[SerializedName('inventory_item_id')]
    public ?string $inventoryItemId = null;

    public ?string $quantity = null;

    #[SerializedName('unit_price')]
    public ?string $unitPrice = null;
}
