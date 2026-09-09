<?php

declare(strict_types=1);

namespace App\Modules\Inventory\ApiResource;

use Symfony\Component\Serializer\Attribute\SerializedName;

/**
 * `UpdateItem`'s accepted request fields, exhaustively (§4.2) — the same seven
 * as `CreateItem`, and again with no `id`: API-5 removes `PUT`, so no operation
 * in this contract creates a row at a client-chosen path, and `{id}` in the path
 * names a row that already exists (§3.1 case 2).
 *
 * The properties are declared **uninitialised** on purpose. `PATCH` has to tell
 * three cases apart -- the field was absent, the field was sent as `null`, the
 * field was sent with a value -- and a property with a `= null` default collapses
 * the first two. `\ReflectionProperty::isInitialized()` separates them, which is
 * what lets `supplier_id: null` clear a supplier while an absent `supplier_id`
 * leaves it alone. The set of fields the caller sent is therefore *derived* from
 * this object rather than restated as a list in the writer.
 */
final class UpdateItemInput
{
    public ?string $sku;

    public ?string $name;

    #[SerializedName('unit_of_measure_code')]
    public ?string $unitOfMeasureCode;

    #[SerializedName('supplier_id')]
    public ?string $supplierId;

    #[SerializedName('reorder_point')]
    public ?string $reorderPoint;

    #[SerializedName('reorder_quantity')]
    public ?string $reorderQuantity;

    #[SerializedName('safety_stock')]
    public ?string $safetyStock;

    /**
     * The fields this request actually carried, keyed by property name.
     *
     * @return array<string, string|null>
     */
    public function submitted(): array
    {
        $submitted = [];

        foreach ((new \ReflectionClass($this))->getProperties(\ReflectionProperty::IS_PUBLIC) as $property) {
            if ($property->isInitialized($this)) {
                $submitted[$property->getName()] = $property->getValue($this);
            }
        }

        return $submitted;
    }
}
