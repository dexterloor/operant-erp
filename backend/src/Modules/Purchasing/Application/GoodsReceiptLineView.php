<?php

declare(strict_types=1);

namespace App\Modules\Purchasing\Application;

use Symfony\Component\Serializer\Attribute\SerializedName;

/**
 * One line of a goods receipt: how much of which order line arrived, and into which bin.
 *
 * **The bin is expanded and the order line is not, and the difference is a
 * pending ruling, not a choice.** `storage_location` follows API-23b
 * (`{id, code}`). A reference to a document *line* has no display field
 * API-23b can derive, so `purchase_order_line_id` stays bare until the owner
 * rules (`docs/session10/red-row11-attack.md` R2); `ResponseShapeTest` holds it
 * as a registered deviation that turns red once it is gone.
 */
final readonly class GoodsReceiptLineView
{
    public function __construct(
        public string $id,
        #[SerializedName('purchase_order_line_id')]
        public string $purchaseOrderLineId,
        public string $quantity,
        #[SerializedName('storage_location')]
        public LocationReference $storageLocation,
    ) {
    }
}
