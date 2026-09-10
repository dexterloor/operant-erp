<?php

declare(strict_types=1);

namespace App\Modules\Purchasing\Application;

use App\Platform\Http\Pagination\Cursor;

/**
 * The parsed, validated form of `ListPurchaseOrders`' query parameters.
 *
 * Exactly one of `$after` and `$before` may be set (API-17); both null is the
 * first page. `$statuses` is the **expanded** segment — §10.3 derivation step 2
 * turns `open` into the two `purchase_order.status` values it covers, and this
 * object carries the expansion so nothing downstream re-derives it. An empty
 * `$statuses` means no status predicate at all.
 *
 * `$sort` is one of `PurchaseOrderCollectionProvider::SORT_*`, already checked
 * against the legal set for *this* view (API-19).
 */
final readonly class PurchaseOrderListCriteria
{
    /** @param list<string> $statuses */
    public function __construct(
        public int $limit,
        public string $sort,
        public array $statuses = [],
        public ?string $supplierId = null,
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
