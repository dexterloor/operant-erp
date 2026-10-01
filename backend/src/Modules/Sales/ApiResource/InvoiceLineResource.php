<?php

declare(strict_types=1);

namespace App\Modules\Sales\ApiResource;

use App\Modules\Sales\Application\BilledLineRef;
use App\Modules\Sales\Application\InvoiceLineView;
use App\Modules\Sales\Application\SalesItemRef;
use Symfony\Component\Serializer\Attribute\SerializedName;

/**
 * **One derived invoice line — `ListInvoiceLines`' item** (sales step 7 C0; `API.md` §4.4.3's InvoiceLine row).
 *
 * Not an `ApiResource` of its own: no §4.2 row reads or writes a single invoice line, so API Platform mints no
 * component for it and `InvoiceLineCollection` publishes the item schema INLINE ({@see self::WIRE_SCHEMA}), held equal
 * to this class's members by `InvoiceLineListTest::theWireSchemaIsTheResourcesOwnMembers` (the `SalesOrderLine`
 * precedent of 2026-09-21). `ResponseShapeTest` finds it by the `<Resource>Resource` convention beside its envelope.
 * Decimals are strings: quantities numeric(19,6), money numeric(19,4) (S-12; `wire-scale.json`).
 */
final readonly class InvoiceLineResource
{
    /** The published item schema — the members below, nothing more. */
    public const WIRE_SCHEMA = [
        'type' => 'object',
        'required' => ['id', 'fulfilment_line', 'inventory_item', 'quantity_billed', 'unit_price', 'amount_billed', 'quantity_credited', 'amount_credited'],
        'properties' => [
            'id' => ['type' => 'string', 'format' => 'uuid'],
            'fulfilment_line' => ['type' => 'object', 'properties' => [
                'id' => ['type' => 'string', 'format' => 'uuid'],
                'sales_order_line' => ['type' => 'object', 'properties' => ['id' => ['type' => 'string', 'format' => 'uuid'], 'line_number' => ['type' => 'integer']]],
                'inventory_item' => ['type' => 'object', 'properties' => ['id' => ['type' => 'string', 'format' => 'uuid'], 'sku' => ['type' => 'string'], 'name' => ['type' => 'string']]],
                'quantity' => ['type' => 'string'],
            ]],
            'inventory_item' => ['type' => 'object', 'properties' => ['id' => ['type' => 'string', 'format' => 'uuid'], 'sku' => ['type' => 'string'], 'name' => ['type' => 'string']]],
            'quantity_billed' => ['type' => 'string'],
            'unit_price' => ['type' => 'string'],
            'amount_billed' => ['type' => 'string'],
            'quantity_credited' => ['type' => 'string'],
            'amount_credited' => ['type' => 'string'],
        ],
    ];

    public function __construct(
        public string $id,
        #[SerializedName('fulfilment_line')]
        public BilledLineRef $fulfilmentLine,
        #[SerializedName('inventory_item')]
        public SalesItemRef $inventoryItem,
        #[SerializedName('quantity_billed')]
        public string $quantityBilled,
        #[SerializedName('unit_price')]
        public string $unitPrice,
        #[SerializedName('amount_billed')]
        public string $amountBilled,
        #[SerializedName('quantity_credited')]
        public string $quantityCredited,
        #[SerializedName('amount_credited')]
        public string $amountCredited,
    ) {
    }

    public static function fromView(InvoiceLineView $view): self
    {
        return new self($view->id, $view->fulfilmentLine, $view->inventoryItem, $view->quantityBilled, $view->unitPrice,
            $view->amountBilled, $view->quantityCredited, $view->amountCredited);
    }
}
