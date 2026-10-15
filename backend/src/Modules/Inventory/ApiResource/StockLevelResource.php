<?php

declare(strict_types=1);

namespace App\Modules\Inventory\ApiResource;

use App\Modules\Inventory\Application\StockItemRef;
use App\Modules\Inventory\Application\StockLevelView;
use App\Modules\Inventory\Application\StockLocationRef;
use App\Modules\Inventory\Application\StockWarehouseRef;
use Symfony\Component\Serializer\Attribute\SerializedName;

/**
 * **One stock row — `ListStockLevels`' item** (stock reads C1; `API.md` §4.4.3's StockLevel row; owner ruling Q1 of
 * 2026-10-14). On-hand in one bin, with its item, its bin, the bin's warehouse and the row's `version`. **Nothing else:**
 * no `qty_reserved`, no `qty_available` (Q1; held by `OwedAmendmentTripwireTest::noResponseServesAnAvailableOrReservedStockFigure`).
 *
 * Not an `ApiResource` of its own: no §4.2 row reads one stock level, so API Platform mints no component for it and
 * `StockLevelCollection` publishes the item schema INLINE ({@see self::WIRE_SCHEMA}), held equal to this class's members by
 * `StockLevelReadTest::theWireSchemaIsTheResourcesOwnMembers` (the `InvoiceLineResource` precedent). `ResponseShapeTest`
 * finds it by the `<Resource>Resource` convention beside its envelope.
 *
 * `warehouse = {id, name}` is `SECURITY_MODEL.md` PC-9's fourth named exception, not a PC-15 reference (`SEC`, 2026-10-14).
 * `qty_on_hand` is numeric(19,6) as a string (S-12; `wire-scale.json`); `version` is an integer (ST-6's lock).
 */
final readonly class StockLevelResource
{
    /** The published item schema — the members below, nothing more. */
    public const WIRE_SCHEMA = [
        'type' => 'object',
        'required' => ['id', 'inventory_item', 'storage_location', 'warehouse', 'qty_on_hand', 'version'],
        'properties' => [
            'id' => ['type' => 'string', 'format' => 'uuid'],
            'inventory_item' => ['type' => 'object', 'properties' => ['id' => ['type' => 'string', 'format' => 'uuid'], 'sku' => ['type' => 'string'], 'name' => ['type' => 'string']]],
            'storage_location' => ['type' => 'object', 'properties' => ['id' => ['type' => 'string', 'format' => 'uuid'], 'code' => ['type' => 'string']]],
            'warehouse' => ['type' => 'object', 'properties' => ['id' => ['type' => 'string', 'format' => 'uuid'], 'name' => ['type' => 'string']]],
            'qty_on_hand' => ['type' => 'string'],
            'version' => ['type' => 'integer'],
        ],
    ];

    public function __construct(
        public string $id,
        #[SerializedName('inventory_item')]
        public StockItemRef $inventoryItem,
        #[SerializedName('storage_location')]
        public StockLocationRef $storageLocation,
        public StockWarehouseRef $warehouse,
        #[SerializedName('qty_on_hand')]
        public string $qtyOnHand,
        public int $version,
    ) {
    }

    public static function fromView(StockLevelView $view): self
    {
        return new self($view->id, $view->inventoryItem, $view->storageLocation, $view->warehouse, $view->qtyOnHand, $view->version);
    }
}
