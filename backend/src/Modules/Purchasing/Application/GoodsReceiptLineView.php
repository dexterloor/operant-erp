<?php

declare(strict_types=1);

namespace App\Modules\Purchasing\Application;

use Symfony\Component\Serializer\Attribute\SerializedName;

/** One line of a goods receipt: how much of which order line arrived, and into which bin. */
final readonly class GoodsReceiptLineView
{
    public function __construct(
        public string $id,
        #[SerializedName('purchase_order_line_id')]
        public string $purchaseOrderLineId,
        public string $quantity,
        #[SerializedName('storage_location_id')]
        public string $storageLocationId,
    ) {
    }
}
