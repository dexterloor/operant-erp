<?php

declare(strict_types=1);

namespace App\Modules\Customers\Application;

use App\Platform\Http\Pagination\Cursor;

/**
 * `ListCustomers`' parsed parameters. **Two views** (C5 C4a; `IA.md` §5.6): the live view, sorted by
 * {@see self::LIVE_SORTS} — `name` (default) and `code`, each served by a `_live` index, both NOT NULL text — and the
 * **Archived** view (`archived=true`, TB-3: instead of the live set, never beside it), sorted only by `archived_at`
 * (the wire name of `deleted_at`), newest first, served by `customer_tenant_archived_idx`. The declared enum is
 * {@see self::SORTS}, the union, with a live value first so a client sending `enum[0]` alone is served (RED L-2); which
 * value each view accepts is {@see self::legalSorts()}'s (`InvoiceViews`' precedent), and a value from the other view is
 * 422 `unserved_sort`.
 */
final readonly class CustomerListCriteria
{
    public const SORT_NAME = 'name';
    public const SORT_CODE = 'code';
    public const SORT_ARCHIVED_AT = 'archived_at';

    public const DEFAULT_LIMIT = 50;
    public const MAXIMUM_LIMIT = 200;

    /** The live view's orders, the default first. */
    public const LIVE_SORTS = [self::SORT_NAME, self::SORT_CODE];

    /** The Archived view's one order, most recently archived first (`IA.md` §5.7 D-a). */
    public const ARCHIVED_SORTS = [self::SORT_ARCHIVED_AT];

    /** The declared `sort` enum: the union of both views, a live value first (RED L-2: `SORTS[0]` is load-bearing). */
    public const SORTS = [self::SORT_NAME, self::SORT_CODE, self::SORT_ARCHIVED_AT];

    public function __construct(
        public int $limit,
        public string $sort = self::SORT_NAME,
        public ?string $search = null,
        public ?Cursor $after = null,
        public ?Cursor $before = null,
        public bool $archived = false,
    ) {
    }

    /** @return list<string> the orders a view accepts, its default first */
    public static function legalSorts(bool $archived): array
    {
        return $archived ? self::ARCHIVED_SORTS : self::LIVE_SORTS;
    }

    public function isBackwards(): bool
    {
        return null !== $this->before;
    }
}
