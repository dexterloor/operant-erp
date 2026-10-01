<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Application\Contract;

use Symfony\Component\Uid\Uuid;

/** One line of a `StockReturn`: this much of this item came back into this bin. Ids held canonical. */
final readonly class StockReturnLine
{
    public string $inventoryItemId;

    public string $storageLocationId;

    public function __construct(
        string $inventoryItemId,
        string $storageLocationId,
        public string $quantity,
    ) {
        $this->inventoryItemId = self::canonical($inventoryItemId, 'inventoryItemId');
        $this->storageLocationId = self::canonical($storageLocationId, 'storageLocationId');
    }

    private static function canonical(string $id, string $field): string
    {
        if (!Uuid::isValid($id)) {
            throw new \InvalidArgumentException(\sprintf('%s is not an RFC 4122 UUID: "%s".', $field, $id));
        }

        return Uuid::fromString($id)->toRfc4122();
    }
}
