<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Application\Contract;

use Symfony\Component\Uid\Uuid;

/**
 * The command `ReceiveStock` takes: one goods receipt's worth of stock arriving
 * in one warehouse.
 */
final readonly class StockReceipt
{
    /**
     * numeric(19,6): at most 13 digits before the point and 6 after; no sign,
     * because a receipt only adds. `\A…\z`, never `^…$`: `$` also matches
     * before a final newline, and `"5\n"` would then reach bccomp(), which
     * throws a ValueError — a 500 instead of a refusal.
     */
    private const QUANTITY = '/\A\d{1,13}(\.\d{1,6})?\z/';

    /** Canonical lower-case RFC 4122, as `StockReceiptLine` holds its ids. */
    public string $goodsReceiptId;

    /** Canonical lower-case RFC 4122. */
    public string $warehouseId;

    /**
     * @param list<StockReceiptLine> $lines
     */
    public function __construct(
        string $goodsReceiptId,
        string $warehouseId,
        public \DateTimeImmutable $occurredAt,
        public array $lines,
    ) {
        $this->goodsReceiptId = self::canonical($goodsReceiptId, 'goodsReceiptId');
        $this->warehouseId = self::canonical($warehouseId, 'warehouseId');

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

    /** Refuses any form but RFC 4122 — braces, no hyphens, not a UUID — as `RequestDigest::uuid()` does. */
    private static function canonical(string $id, string $field): string
    {
        if (!Uuid::isValid($id)) {
            throw new \InvalidArgumentException(\sprintf('%s is not an RFC 4122 UUID: "%s".', $field, $id));
        }

        return Uuid::fromString($id)->toRfc4122();
    }
}
