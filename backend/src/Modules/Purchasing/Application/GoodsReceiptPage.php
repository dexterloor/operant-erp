<?php

declare(strict_types=1);

namespace App\Modules\Purchasing\Application;

/**
 * One page of `ListGoodsReceipts`, as `GoodsReceiptReader::list()` reads it. The provider serializes it as
 * `GoodsReceiptCollection` (API-22), so the reader returns an Application read model and never an `ApiResource`
 * class — `FulfilmentPage`'s arrangement, and no new `Application -> ApiResource` edge.
 */
final readonly class GoodsReceiptPage
{
    /**
     * @param list<GoodsReceiptView> $items
     */
    public function __construct(
        public array $items,
        public ?string $nextCursor,
        public ?string $previousCursor,
        public bool $tenantHasRows,
    ) {
    }
}
