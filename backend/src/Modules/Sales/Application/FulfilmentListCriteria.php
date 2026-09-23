<?php

declare(strict_types=1);

namespace App\Modules\Sales\Application;

use App\Platform\Http\Pagination\Cursor;

/**
 * The parsed, validated form of `ListFulfilments`' query parameters.
 *
 * The **view** is decided by `$salesOrderId` alone, because `IA.md` §5.6 gives this route exactly two orders and no
 * `sort` parameter: the register (no filter) in `fulfilment_number` order, and one order's shipments in `shipped_at`
 * order. Both newest first. Exactly one of `$after` and `$before` may be set (API-17); both null is the first page.
 */
final readonly class FulfilmentListCriteria
{
    public const SORT_FULFILMENT_NUMBER = 'fulfilment_number';
    public const SORT_SHIPPED_AT = 'shipped_at';

    public function __construct(
        public int $limit,
        public ?string $salesOrderId = null,
        public ?string $search = null,
        public ?Cursor $after = null,
        public ?Cursor $before = null,
    ) {
    }

    /** The view's one order (IA §5.6): number order on the register, shipment time inside an order. */
    public function sort(): string
    {
        return null === $this->salesOrderId ? self::SORT_FULFILMENT_NUMBER : self::SORT_SHIPPED_AT;
    }

    public function isBackwards(): bool
    {
        return null !== $this->before;
    }
}
