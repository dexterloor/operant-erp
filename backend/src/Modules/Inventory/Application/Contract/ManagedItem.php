<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Application\Contract;

/**
 * One managed item ({@see ReorderStock}): its identity and display fields, the two stored reorder values exactly as stored
 * (scale 6; possibly ≤ 0 on one of them, which the caller reads as unset), the preferred supplier reference (nullable,
 * never checked for liveness here — that is the Suppliers half's), and the pickable on-hand total (scale 6).
 */
final readonly class ManagedItem
{
    public function __construct(
        public string $id,
        public string $sku,
        public string $name,
        public string $reorderPoint,
        public string $reorderQuantity,
        public ?string $preferredSupplierId,
        public string $qtyOnHandPickable,
    ) {
    }
}
