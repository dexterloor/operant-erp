<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Application;

use App\Platform\Http\Pagination\Cursor;

/**
 * The parsed, validated form of `ListStockMovements`' query parameters (stock reads C2).
 *
 * **One order, both views:** newest first by business time, `occurred_at DESC, id DESC` — `IA.md` §5.6 offers no alternate
 * for either `/stock-movements` row, so there is no `sort` (§14.2 item 29). `$inventoryItemId` narrows to one item. Exactly
 * one of `$after` and `$before` may be set (API-17); both null is the first page.
 */
final readonly class StockMovementListCriteria
{
    public const DEFAULT_LIMIT = 50;
    public const MAXIMUM_LIMIT = 200;

    public function __construct(
        public int $limit = self::DEFAULT_LIMIT,
        public ?string $inventoryItemId = null,
        public ?Cursor $after = null,
        public ?Cursor $before = null,
    ) {
    }

    public function isBackwards(): bool
    {
        return null !== $this->before;
    }
}
