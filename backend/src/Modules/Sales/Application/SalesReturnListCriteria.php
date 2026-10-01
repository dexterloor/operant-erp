<?php

declare(strict_types=1);

namespace App\Modules\Sales\Application;

use App\Platform\Http\Pagination\Cursor;

/**
 * `ListSalesReturns`' parsed query (sales step 7 R3). One order, `sales_return_number` newest first over
 * `sales_return_number_unique` (IA R-1: the six-digit number is a faithful proxy for creation order). Each boolean
 * filter is `null` when absent.
 */
final readonly class SalesReturnListCriteria
{
    public function __construct(
        public int $limit,
        public ?string $fulfilmentId = null,
        public ?string $invoiceId = null,
        public ?bool $creditable = null,
        public ?bool $deducts = null,
        public ?bool $creditBlockedByPayment = null,
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
