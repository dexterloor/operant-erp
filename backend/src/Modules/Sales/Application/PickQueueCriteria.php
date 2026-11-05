<?php

declare(strict_types=1);

namespace App\Modules\Sales\Application;

use App\Platform\Http\Pagination\Cursor;

/**
 * `ListPickQueueEntries`' parsed parameters (BK-21). One view, one order (`ordered_at, id` ascending), so no sort and no
 * segment: only the warehouse filter, the order-number prefix, the page size and the cursor.
 */
final readonly class PickQueueCriteria
{
    public function __construct(
        public int $limit,
        public ?string $warehouseId = null,
        public ?string $search = null,
        public ?Cursor $after = null,
        public ?Cursor $before = null,
    ) {
    }

    public function isBackwards(): bool
    {
        return null !== $this->before;
    }
}
