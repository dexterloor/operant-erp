<?php

declare(strict_types=1);

namespace App\Modules\Purchasing\Application;

use App\Platform\Http\Pagination\Cursor;

/**
 * The parsed, validated form of `ListGoodsReceipts`' query parameters (remaining-ops G1) — `FulfilmentListCriteria`'s
 * shape one module over.
 *
 * The **view** is decided by `$purchaseOrderId` alone, because `IA.md` §5.6 gives this route exactly two orders and no
 * `sort` parameter: the register (no filter) in `receipt_number` order, and one order's receipts in `received_at`
 * order. Both newest first. Exactly one of `$after` and `$before` may be set (API-17); both null is the first page.
 */
final readonly class GoodsReceiptListCriteria
{
    public const DEFAULT_LIMIT = 50;
    public const MAXIMUM_LIMIT = 200;

    public const SORT_RECEIPT_NUMBER = 'receipt_number';
    public const SORT_RECEIVED_AT = 'received_at';

    public function __construct(
        public int $limit,
        public ?string $purchaseOrderId = null,
        public ?string $search = null,
        public ?Cursor $after = null,
        public ?Cursor $before = null,
    ) {
    }

    /** The view's one order (IA §5.6): number order on the register, receipt time inside an order. */
    public function sort(): string
    {
        return null === $this->purchaseOrderId ? self::SORT_RECEIPT_NUMBER : self::SORT_RECEIVED_AT;
    }

    public function isBackwards(): bool
    {
        return null !== $this->before;
    }
}
