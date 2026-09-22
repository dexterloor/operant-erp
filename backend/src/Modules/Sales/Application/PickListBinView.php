<?php

declare(strict_types=1);

namespace App\Modules\Sales\Application;

use Symfony\Component\Serializer\Attribute\SerializedName;

/**
 * One candidate bin on a pick-list line: where the item may be picked from and
 * what it held when read. `qty_on_hand` and nothing else — never `qty_reserved`,
 * never an available figure (owner ruling R1 with Q6(a); `SECURITY_MODEL.md`
 * PC-9's second exception).
 */
final readonly class PickListBinView
{
    /**
     * @param string $qtyOnHand numeric(19,6)
     */
    public function __construct(
        #[SerializedName('storage_location')]
        public SalesLocationRef $storageLocation,
        #[SerializedName('qty_on_hand')]
        public string $qtyOnHand,
    ) {
    }
}
