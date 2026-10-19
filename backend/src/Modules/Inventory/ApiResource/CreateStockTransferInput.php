<?php

declare(strict_types=1);

namespace App\Modules\Inventory\ApiResource;

use Symfony\Component\Serializer\Attribute\SerializedName;

/**
 * `CreateStockTransfer`'s body — §4.2's accepted cell: `inventory_item_id`; `source_storage_location_id`;
 * `destination_storage_location_id`; `quantity`; `note` (stock transfer C1, 2026-10-20; the note optional, owner Q-T3).
 * No `id` (B-3).
 *
 * The properties are `mixed` and **uninitialised** on purpose ({@see CreateStockAdjustmentInput}'s precedent): the writer
 * refuses a wrong type at the field's own pointer rather than the serializer refusing the whole body, and an absent `note`
 * stays apart from a `null` one until the writer canonicalises both to "no note".
 */
final class CreateStockTransferInput
{
    #[SerializedName('inventory_item_id')]
    public mixed $inventoryItemId;

    #[SerializedName('source_storage_location_id')]
    public mixed $sourceStorageLocationId;

    #[SerializedName('destination_storage_location_id')]
    public mixed $destinationStorageLocationId;

    public mixed $quantity;

    public mixed $note;

    /** @return array<string, mixed> wire name => value, for exactly the fields the body carried */
    public function submitted(): array
    {
        $wire = [
            'inventoryItemId' => 'inventory_item_id',
            'sourceStorageLocationId' => 'source_storage_location_id',
            'destinationStorageLocationId' => 'destination_storage_location_id',
            'quantity' => 'quantity',
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
