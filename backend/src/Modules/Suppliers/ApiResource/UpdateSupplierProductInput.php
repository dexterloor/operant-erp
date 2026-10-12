<?php

declare(strict_types=1);

namespace App\Modules\Suppliers\ApiResource;

use Symfony\Component\Serializer\Attribute\SerializedName;

/**
 * `UpdateSupplierProduct`'s body — a PATCH of any of §4.2's four fields (`supplier_sku`, `unit_cost`, `lead_time_days`,
 * `minimum_order_quantity`). Never the item (a different item is a remove and an add) and never `currency_code` (copied at
 * create, owner Q-S4 (a)). Properties are left **uninitialised** so an absent field and an explicit `null` stay
 * distinguishable (`UpdateSupplierInput`'s precedent; the writer refuses `null` on all four, which are NOT NULL).
 */
final class UpdateSupplierProductInput
{
    #[SerializedName('supplier_sku')]
    public ?string $supplierSku;

    #[SerializedName('unit_cost')]
    public ?string $unitCost;

    #[SerializedName('lead_time_days')]
    public mixed $leadTimeDays;

    #[SerializedName('minimum_order_quantity')]
    public ?string $minimumOrderQuantity;

    private const WIRE = [
        'supplierSku' => 'supplier_sku',
        'unitCost' => 'unit_cost',
        'leadTimeDays' => 'lead_time_days',
        'minimumOrderQuantity' => 'minimum_order_quantity',
    ];

    /** @return array<string, mixed> wire name => value, for exactly the fields the body carried */
    public function submitted(): array
    {
        $submitted = [];

        foreach ((new \ReflectionClass($this))->getProperties(\ReflectionProperty::IS_PUBLIC) as $property) {
            if ($property->isInitialized($this)) {
                $submitted[self::WIRE[$property->getName()]] = $property->getValue($this);
            }
        }

        return $submitted;
    }
}
