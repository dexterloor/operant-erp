<?php

declare(strict_types=1);

namespace App\Modules\Analytics\ApiResource;

use App\Modules\Analytics\Application\ReorderSuggestionView;
use Symfony\Component\Serializer\Attribute\SerializedName;

/**
 * **One reorder suggestion** — §4.4.3's ReorderSuggestion row (remaining-ops G3-C1, 2026-10-22; design §2.5, D-C1-4).
 *
 * `id` is the inventory item's id (the `PickList` precedent: a tableless read whose `id` is its source row's). F-4 step 2's
 * row content in full — `inventory_item`, `qty_on_hand_total` (pickable bins only, M-4), `reorder_point`, `reorder_quantity`
 * (as stored), `suggested_quantity`, `preferred_supplier`, `lead_time_days`, `unit_cost` — plus `qty_on_order` (without it a
 * row reading *on hand 2, point 10, suggest 0* is unexplainable) and `currency_code` (the currency of `unit_cost`, the
 * `SupplierProduct` precedent). **No reserved or available figure** (`noResponseServesAnAvailableOrReservedStockFigure`).
 *
 * `preferred_supplier` is null when the item names none or names an archived supplier; the three terms are null then, and
 * when the live supplier has no catalogue entry for the item. Quantities are numeric(19,6) strings, `unit_cost` numeric(19,4)
 * (S-12; `wire-scale.json`). `unit_cost` is a COST field (PC-12): `MarginSourcesDerivationTest` derives this operation's six
 * codes as a cost source, reduced away because `supplier_product.view` (and `purchase_order.view`) already are one.
 *
 * `ReorderSuggestionCollection` publishes this schema INLINE ({@see self::WIRE_SCHEMA}), held equal to the members by
 * `ReorderSuggestionTest::theWireSchemaIsTheResourcesOwnMembers` (the `StockLevelResource` precedent).
 */
final readonly class ReorderSuggestionResource
{
    /** The published item schema — the members below, nothing more. */
    public const WIRE_SCHEMA = [
        'type' => 'object',
        'required' => ['id', 'inventory_item', 'qty_on_hand_total', 'qty_on_order', 'reorder_point', 'reorder_quantity', 'suggested_quantity', 'preferred_supplier', 'lead_time_days', 'unit_cost', 'currency_code'],
        'properties' => [
            'id' => ['type' => 'string', 'format' => 'uuid'],
            'inventory_item' => ['type' => 'object', 'properties' => ['id' => ['type' => 'string', 'format' => 'uuid'], 'sku' => ['type' => 'string'], 'name' => ['type' => 'string']]],
            'qty_on_hand_total' => ['type' => 'string'],
            'qty_on_order' => ['type' => 'string'],
            'reorder_point' => ['type' => 'string'],
            'reorder_quantity' => ['type' => 'string'],
            'suggested_quantity' => ['type' => 'string'],
            'preferred_supplier' => ['type' => ['object', 'null'], 'properties' => ['id' => ['type' => 'string', 'format' => 'uuid'], 'name' => ['type' => 'string']]],
            'lead_time_days' => ['type' => ['integer', 'null']],
            'unit_cost' => ['type' => ['string', 'null']],
            'currency_code' => ['type' => ['string', 'null']],
        ],
    ];

    public function __construct(
        public string $id,
        #[SerializedName('inventory_item')]
        public ReorderItemReference $inventoryItem,
        #[SerializedName('qty_on_hand_total')]
        public string $qtyOnHandTotal,
        #[SerializedName('qty_on_order')]
        public string $qtyOnOrder,
        #[SerializedName('reorder_point')]
        public string $reorderPoint,
        #[SerializedName('reorder_quantity')]
        public string $reorderQuantity,
        #[SerializedName('suggested_quantity')]
        public string $suggestedQuantity,
        #[SerializedName('preferred_supplier')]
        public ?ReorderSupplierReference $preferredSupplier,
        #[SerializedName('lead_time_days')]
        public ?int $leadTimeDays,
        #[SerializedName('unit_cost')]
        public ?string $unitCost,
        #[SerializedName('currency_code')]
        public ?string $currencyCode,
    ) {
    }

    public static function fromView(ReorderSuggestionView $view): self
    {
        $line = $view->line;

        return new self(
            $line->itemId,
            new ReorderItemReference($line->itemId, $line->sku, $line->name),
            $line->qtyOnHand,
            $line->qtyOnOrder,
            $line->reorderPoint,
            $line->reorderQuantity,
            $view->suggestedQuantity,
            null === $view->supplierId || null === $view->supplierName ? null : new ReorderSupplierReference($view->supplierId, $view->supplierName),
            $view->leadTimeDays,
            $view->unitCost,
            $view->currencyCode,
        );
    }
}
