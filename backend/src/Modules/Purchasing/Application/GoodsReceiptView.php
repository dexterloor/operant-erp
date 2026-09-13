<?php

declare(strict_types=1);

namespace App\Modules\Purchasing\Application;

/**
 * A goods receipt as `GoodsReceiptReader` reads it. Declared in this layer, not
 * in `ApiResource`, so the reader and writer do not depend "up" (A-7);
 * `GoodsReceiptResource::fromView()` turns it into the HTTP shape.
 *
 * **The receiver is two scalars here and an `ActorReference` only in the
 * resource**, for the same reason: `ActorReference` is declared in `ApiResource`,
 * and building it in this layer would be a new A-7 edge. Both are null together,
 * exactly when `goods_receipt.received_by_user_id` is.
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
        public ?string $receivedById,
        public ?string $receivedByDisplayName,
    ) {
    }
}
