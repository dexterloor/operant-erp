<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Application\Contract;

/**
 * The command `ReceiveStock` takes: one goods receipt's worth of stock arriving
 * in one warehouse.
 */
final readonly class StockReceipt
{
    /** numeric(19,6): at most 13 digits before the point and 6 after; no sign, because a receipt only adds. */
    private const QUANTITY = '/^\d{1,13}(\.\d{1,6})?$/';

    /**
     * @param list<StockReceiptLine> $lines
     */
    public function __construct(
        public string $goodsReceiptId,
        public string $warehouseId,
        public \DateTimeImmutable $occurredAt,
        public array $lines,
    ) {
        if ([] === $lines) {
            throw StockReceiptRefused::noLines();
        }

        foreach ($lines as $index => $line) {
            // Unsigned and exactly representable in numeric(19,6): a seventh
            // decimal would be rounded away by Postgres without an error, so the
            // stock recorded would not be the stock received.
            if (1 !== preg_match(self::QUANTITY, $line->quantity) || 1 !== bccomp($line->quantity, '0', 6)) {
                throw StockReceiptRefused::quantityIsNotPositive($index, $line->quantity);
            }
        }
    }
}
