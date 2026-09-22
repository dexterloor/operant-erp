<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Application\Contract;

use Symfony\Component\Uid\Uuid;

/**
 * The command `DepleteStock` takes: one fulfilment's worth of stock leaving
 * one warehouse. `StockReceipt`'s mirror, and checked the same way.
 */
final readonly class StockDepletion
{
    /**
     * numeric(19,6), unsigned: the amount LEAVING. The movement written is its
     * negation. `\A…\z`, never `^…$` (see `StockReceipt::QUANTITY`).
     */
    private const QUANTITY = '/\A\d{1,13}(\.\d{1,6})?\z/';

    /** Canonical lower-case RFC 4122. */
    public string $fulfilmentId;

    /** Canonical lower-case RFC 4122. */
    public string $warehouseId;

    /**
     * @param list<StockDepletionLine> $lines
     */
    public function __construct(
        string $fulfilmentId,
        string $warehouseId,
        public \DateTimeImmutable $occurredAt,
        public array $lines,
    ) {
        $this->fulfilmentId = self::canonical($fulfilmentId, 'fulfilmentId');
        $this->warehouseId = self::canonical($warehouseId, 'warehouseId');

        if ([] === $lines) {
            throw StockDepletionRefused::noLines();
        }

        foreach ($lines as $index => $line) {
            if (1 !== preg_match(self::QUANTITY, $line->quantity) || 1 !== bccomp($line->quantity, '0', 6)) {
                throw StockDepletionRefused::quantityIsNotPositive($index, $line->quantity);
            }
        }
    }

    private static function canonical(string $id, string $field): string
    {
        if (!Uuid::isValid($id)) {
            throw new \InvalidArgumentException(\sprintf('%s is not an RFC 4122 UUID: "%s".', $field, $id));
        }

        return Uuid::fromString($id)->toRfc4122();
    }
}
