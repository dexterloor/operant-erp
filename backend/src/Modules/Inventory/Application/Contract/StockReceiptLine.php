<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Application\Contract;

use Symfony\Component\Uid\Uuid;

/**
 * One line of a `StockReceipt`: this much of this item arrived in this bin.
 *
 * Both ids are held in canonical lower-case RFC 4122 form, so one bin is one
 * bin however the caller spelled it: the W-5 sort key, the per-bin sum and the
 * bin check all compare strings. Any other form — braces, no hyphens, not a
 * UUID — is refused here with `\InvalidArgumentException`, as
 * `RequestDigest::uuid()` refuses one: the ids come from the caller's own rows.
 *
 * `quantity` is a decimal string, never a float (S-12: numeric(19,6)). It is
 * checked by `StockReceipt`, not here, because only the receipt knows the
 * line's index — the one thing the caller needs to point its own refusal at.
 */
final readonly class StockReceiptLine
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
