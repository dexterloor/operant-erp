<?php

declare(strict_types=1);

namespace App\Modules\Suppliers\ApiResource;

use Symfony\Component\Serializer\Attribute\SerializedName;

/**
 * `CreateSupplierProduct`'s body — §4.2's accepted cell: `inventory_item_id`; `supplier_sku`; `unit_cost`; `lead_time_days`;
 * `minimum_order_quantity`. No `id` (B-3), no `supplier_id` (the path implies it, API-23) and **no `currency_code`**: it is
 * copied from the supplier (owner Q-S4 (a)), so a body carrying one is an undeclared field (API-9). Decimals are strings
 * (S-12). `lead_time_days` is `mixed` and `minimum_order_quantity` left uninitialised so the writer can tell absent from
 * `null` and name a wrong type as a 422 on the field. Every value is checked by `SupplierProductWriter`.
 */
final class CreateSupplierProductInput
{
    #[SerializedName('inventory_item_id')]
    public ?string $inventoryItemId = null;

    #[SerializedName('supplier_sku')]
    public ?string $supplierSku = null;

    #[SerializedName('unit_cost')]
    public ?string $unitCost = null;

    #[SerializedName('lead_time_days')]
    public mixed $leadTimeDays = null;

    #[SerializedName('minimum_order_quantity')]
    public ?string $minimumOrderQuantity;

    /** @return array<string, mixed> wire name => value; `minimum_order_quantity` only when the body carried it */
    public function submitted(): array
    {
        $values = [
            'inventory_item_id' => $this->inventoryItemId,
            'supplier_sku' => $this->supplierSku,
            'unit_cost' => $this->unitCost,
            'lead_time_days' => $this->leadTimeDays,
        ];
        if ((new \ReflectionProperty($this, 'minimumOrderQuantity'))->isInitialized($this)) {
            $values['minimum_order_quantity'] = $this->minimumOrderQuantity;
        }

        return $values;
    }
}
