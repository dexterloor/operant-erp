<?php

declare(strict_types=1);

namespace App\Modules\Inventory\ApiResource;

use Symfony\Component\Serializer\Attribute\SerializedName;

/**
 * `CreateStockAdjustment`'s body — §4.2's accepted cell: `inventory_item_id`; `storage_location_id`; `counted_quantity`;
 * `version`; `note` (stock count C1; `version` added to the cell by owner Q2). No `id` (B-3).
 *
 * The properties are `mixed` and **uninitialised** on purpose (`UpdateItemInput`'s precedent, final RED L-12): `version` is
 * REQUIRED and NULLABLE, so "absent" and "sent as null" must stay apart — absent is a 422, null means "this bin showed no
 * row for this item". `mixed` lets the writer refuse a wrong type at the field's own pointer
 * (`CreateCustomerInput::$paymentTermsDays`'s precedent) rather than the serializer refusing the whole body.
 */
final class CreateStockAdjustmentInput
{
    #[SerializedName('inventory_item_id')]
    public mixed $inventoryItemId;

    #[SerializedName('storage_location_id')]
    public mixed $storageLocationId;

    #[SerializedName('counted_quantity')]
    public mixed $countedQuantity;

    public mixed $version;

    public mixed $note;

    /** @return array<string, mixed> wire name => value, for exactly the fields the body carried */
    public function submitted(): array
    {
        $wire = [
            'inventoryItemId' => 'inventory_item_id',
            'storageLocationId' => 'storage_location_id',
            'countedQuantity' => 'counted_quantity',
            'version' => 'version',
            'note' => 'note',
        ];
        $submitted = [];
        foreach ($wire as $property => $name) {
            if ((new \ReflectionProperty($this, $property))->isInitialized($this)) {
                $submitted[$name] = $this->{$property};
            }
        }

        return $submitted;
    }
}
