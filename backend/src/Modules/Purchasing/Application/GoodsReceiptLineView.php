<?php

declare(strict_types=1);

namespace App\Modules\Purchasing\Application;

use Symfony\Component\Serializer\Attribute\SerializedName;

/**
 * One line of a goods receipt: which order line and item arrived, how much, and
 * into which bin.
 *
 * **Every reference is expanded (ruled 2026-09-15).** `purchase_order_line =
 * {id, line_number}` is API-23b clause (i) (owner ruling Q1(b)); until the ruling
 * it was returned bare as a registered deviation. `inventory_item = {id, sku,
 * name}` is §4.4.3's additions sentence for GoodsReceipt (owner ruling Q1(c)) in
 * clause (ii)'s shape (Q-A): `goods_receipt_line` has no item column, so it is the
 * ordered line's item. `storage_location = {id, code}` is the chain. Since BK-33 the
 * line also carries `unit_of_measure_code`, that item's unit, beside the reference.
 */
final readonly class GoodsReceiptLineView
{
    public function __construct(
        public string $id,
        #[SerializedName('purchase_order_line')]
        public OrderLineReference $purchaseOrderLine,
        #[SerializedName('inventory_item')]
        public ItemReference $inventoryItem,
        public string $quantity,
        /** The ordered line's item's unit, the unit `quantity` is in; one of `UnitsOfMeasure::CODES`, never null (BK-33). */
        #[SerializedName('unit_of_measure_code')]
        public string $unitOfMeasureCode,
        #[SerializedName('storage_location')]
        public LocationReference $storageLocation,
    ) {
    }
}
