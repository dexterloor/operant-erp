<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Application;

use App\Platform\Http\Pagination\Cursor;

/**
 * The parsed, validated form of `ListItems`' query parameters.
 *
 * Exactly one of `$after` and `$before` may be set (API-17). Both null is the
 * first page.
 *
 * **Two views** (remaining-ops G-I, 2026-10-21; `IA.md` §5.6): the live view (`$archived` false), ordered by `sku`, and the
 * **Archived** view (`archived=true`, TB-3: the archived items instead of the live ones, never beside them), ordered by
 * `archived_at` (the wire name of `deleted_at`) newest first, served by `inventory_item_tenant_archived_idx` (D-a). **Each
 * view has exactly one order**, so `/items` declares no `sort` (`IA.md` §5.6 gives neither view an alternate; §10.3 step 4,
 * held by `SortManifestTest`). The Archived view is never combined with `supplier_id` (the provider's refusal).
 */
final readonly class ItemListCriteria
{
    public function __construct(
        public int $limit,
        public ?string $supplierId = null,
        public ?string $search = null,
        public ?Cursor $after = null,
        public ?Cursor $before = null,
        public bool $archived = false,
    ) {
    }

    public function isBackwards(): bool
    {
        return null !== $this->before;
    }
}
