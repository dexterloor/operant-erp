<?php

declare(strict_types=1);

namespace App\Modules\Purchasing\Application;

/**
 * A goods receipt as `GoodsReceiptReader` reads it. Declared in this layer, not
 * in `ApiResource`, so the reader and writer do not depend "up" (A-7);
 * `GoodsReceiptResource::fromView()` turns it into the HTTP shape.
 */
final readonly class GoodsReceiptView
{
    /**
     * @param list<GoodsReceiptLineView> $lines
     */
    public function __construct(
        public string $id,
        public string $receiptNumber,
        public string $receivedAt,
        public OrderReference $purchaseOrder,
        public array $lines,
    ) {
    }
}
