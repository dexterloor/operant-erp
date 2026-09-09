<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Application;

use App\Platform\Http\Pagination\Cursor;

/**
 * The parsed, validated form of `ListItems`' query parameters.
 *
 * Exactly one of `$after` and `$before` may be set (API-17). Both null is the
 * first page.
 */
final readonly class ItemListCriteria
{
    public function __construct(
        public int $limit,
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
