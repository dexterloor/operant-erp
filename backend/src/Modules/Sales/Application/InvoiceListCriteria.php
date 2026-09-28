<?php

declare(strict_types=1);

namespace App\Modules\Sales\Application;

use App\Platform\Http\Pagination\Cursor;

/** One `ListInvoices` request, validated by `InvoiceCollectionProvider`. */
final readonly class InvoiceListCriteria
{
    /**
     * @param list<string> $statuses
     */
    public function __construct(
        public int $limit,
        public string $sort,
        public array $statuses = [],
        public ?string $customerId = null,
        public ?string $fulfilmentId = null,
        public ?string $search = null,
        public ?Cursor $after = null,
        public ?Cursor $before = null,
    ) {
    }

    public function isBackwards(): bool
    {
        return null !== $this->before;
    }

    public function isNewestFirst(): bool
    {
        return InvoiceViews::isNewestFirst(null !== $this->fulfilmentId);
    }
}
