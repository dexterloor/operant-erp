<?php

declare(strict_types=1);

namespace App\Modules\Purchasing\Application;

use Symfony\Component\Serializer\Attribute\SerializedName;

/**
 * API-23b clause (i)'s expansion of a reference to a purchase order line:
 * `{id, line_number}` (owner ruling Q1(b), 2026-09-15).
 *
 * `purchase_order_line` is a sub-listed line table — `ListPurchaseOrderLines` is
 * bounded and `CreatePurchaseOrderLine` sits at the same path — and it has a
 * `line_number` column, so a reference to one of its rows displays that number
 * and nothing else: no `sku`, because clause (i) takes precedence over (ii). The
 * number is unique among the order's live lines.
 *
 * Declared in this layer, beside `LocationReference`, because
 * `GoodsReceiptLineView` is built here and a class in `ApiResource` would be a
 * new A-7 edge.
 */
final readonly class OrderLineReference
{
    public function __construct(
        public string $id,
        #[SerializedName('line_number')]
        public int $lineNumber,
    ) {
    }
}
