<?php

declare(strict_types=1);

namespace App\Modules\Suppliers\Application;

/**
 * One catalogue entry (`supplier_product`) as the catalogue reader and writer return it — scalars only (A-7;
 * `CustomerAddressView`'s shape). §4.4.3's derived `SupplierProduct` shape: the item reference `{id, sku, name}` (API-23b
 * clause (ii), expanded from `inventory_item_id`), `supplier_sku`, `unit_cost` (scale 4), `lead_time_days`,
 * `minimum_order_quantity` (scale 6; the column is `min_order_quantity`), `id`, and the additions row's `currency_code` —
 * copied from the supplier when the entry is added (owner Q-S4 (a), suppliers slice 1 C3). `supplier_id` is implied by the
 * path (API-23). Decimals stay strings (S-12), exactly as Postgres prints them at the column's scale.
 */
final readonly class SupplierProductView
{
    public function __construct(
        public string $id,
        public string $itemId,
        public string $itemSku,
        public string $itemName,
        public string $supplierSku,
        public string $unitCost,
        public int $leadTimeDays,
        public string $minimumOrderQuantity,
        public string $currencyCode,
    ) {
    }
}
