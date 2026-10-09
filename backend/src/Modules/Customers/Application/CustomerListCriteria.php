<?php

declare(strict_types=1);

namespace App\Modules\Customers\Application;

use App\Platform\Http\Pagination\Cursor;

/**
 * `ListCustomers`' parsed parameters. `sort` is one of {@see self::SORTS}: the sort manifest's `/customers` row offers
 * `name` (default) and `code` (`IA.md` §5.6), each served by a `_live` index. Both are NOT NULL text, so `6fe8b7d`'s
 * empty-key cursor cannot arise.
 */
final readonly class CustomerListCriteria
{
    public const SORT_NAME = 'name';
    public const SORT_CODE = 'code';

    public const DEFAULT_LIMIT = 50;
    public const MAXIMUM_LIMIT = 200;

    /** The default first. */
    public const SORTS = [self::SORT_NAME, self::SORT_CODE];

    public function __construct(
        public int $limit,
        public string $sort = self::SORT_NAME,
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
