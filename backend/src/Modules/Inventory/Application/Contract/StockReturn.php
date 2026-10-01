<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Application\Contract;

use Symfony\Component\Uid\Uuid;

/**
 * One customer return to post (`ReturnStock`). `StockReceipt`'s shape: the ids canonical, at least one line, each
 * quantity positive and exactly representable in numeric(19,6).
 */
final readonly class StockReturn
{
    /** numeric(19,6): at most 13 digits before the point. `\A…\z`, never `^…$`. */
    private const QUANTITY = '/\A\d{1,13}(\.\d{1,6})?\z/';

    public string $salesReturnId;

    /** The warehouse that SHIPPED the goods (owner ruling Q-M): returns come back to it. */
    public string $warehouseId;

    /**
     * @param list<StockReturnLine> $lines
     */
    public function __construct(
        string $salesReturnId,
        string $warehouseId,
        public \DateTimeImmutable $occurredAt,
        public array $lines,
    ) {
        $this->salesReturnId = self::canonical($salesReturnId, 'salesReturnId');
        $this->warehouseId = self::canonical($warehouseId, 'warehouseId');

        if ([] === $lines) {
            throw StockReturnRefused::noLines();
        }

        foreach ($lines as $index => $line) {
            if (1 !== preg_match(self::QUANTITY, $line->quantity) || 1 !== bccomp($line->quantity, '0', 6)) {
                throw StockReturnRefused::quantityIsNotPositive($index, $line->quantity);
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
