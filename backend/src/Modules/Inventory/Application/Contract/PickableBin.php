<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Application\Contract;

/** One candidate bin: where, and how much it holds (`qty_on_hand`, scale 6). */
final readonly class PickableBin
{
    public function __construct(
        public string $storageLocationId,
        public string $code,
        public string $qtyOnHand,
    ) {
    }
}
