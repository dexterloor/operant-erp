<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Application\Contract;

/**
 * One line of a `StockReceipt`: this much of this item arrived in this bin.
 *
 * `quantity` is a decimal string, never a float (S-12: numeric(19,6)). It is
 * checked by `StockReceipt`, not here, because only the receipt knows the
 * line's index — the one thing the caller needs to point its own refusal at.
 */
final readonly class StockReceiptLine
{
    public function __construct(
        public string $inventoryItemId,
        public string $storageLocationId,
        public string $quantity,
    ) {
    }
}
