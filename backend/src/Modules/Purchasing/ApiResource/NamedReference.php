<?php

declare(strict_types=1);

namespace App\Modules\Purchasing\ApiResource;

/**
 * API-23b — a foreign-key reference is returned expanded as
 * `{id, <display field>}` and never as a bare uuid.
 *
 * The display field is **derived, not chosen per resource**: the referenced
 * table's `name` where it has one, else `code`, else `sku`, else its document
 * number. All three references this module returns through this class resolve to
 * `name` — `supplier.name`, `warehouse.name` and `inventory_item.name` are all
 * present in `Version20260906000100` — so one class serves all three rather than
 * three classes with one property each.
 *
 * **`created_by` does not come through here**, and that is the whole reason
 * `ActorReference` exists beside it: §4.4.3 writes that expansion out as
 * `{id, display_name}`, and `app_user` has no `name` column at all.
 *
 * The "id and display field only" half is what keeps PC-12 out of this. Expanding
 * a supplier on a purchase order discloses the supplier's **name** — a document
 * names who it is addressed to, which `purchase_order.view` already implies — and
 * never `payment_terms_days`, `unit_cost` or anything else `supplier.view` and
 * `supplier_product.view` gate.
 *
 * **It is a second class rather than a reuse of
 * `App\Modules\Inventory\ApiResource\SupplierReference`, and the reason is a
 * boundary rather than taste:** `deptrac.yaml` does not admit
 * `InventoryApiResource` into `PurchasingApiResource`'s allowed list, so reaching
 * for Inventory's DTO is exactly the edge `make deptrac` is there to refuse. The
 * generated spec therefore carries two components with different names and the
 * same two members, which is the cost of the boundary and is stated rather than
 * worked around.
 */
final readonly class NamedReference
{
    public function __construct(
        public string $id,
        public string $name,
    ) {
    }
}
