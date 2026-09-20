<?php

declare(strict_types=1);

namespace App\Modules\Sales\ApiResource;

/**
 * API-23b's expansion of an `inventory_item_id`: `{id, sku, name}`.
 *
 * The display field is derived — `inventory_item` has a `name` — and **clause
 * (ii)** adds `sku` beside it, which is owner ruling Q-A (2026-09-15, *"Yes,
 * everywhere"*): every reference to `inventory_item` also carries `sku`, because
 * `/items` is ordered and searched by SKU (IA §5.6). That ruling names one table,
 * so no other reference gains `sku` from it.
 *
 * **A second class rather than an import of `Purchasing\Application\ItemReference`,
 * and that is the module boundary rather than duplication for its own sake.**
 * `Modules\Sales` may not import `Modules\Purchasing` (A-7), and the shared shape
 * is three strings; the thing that must not be duplicated is the *rule*, which
 * lives in API-23b and is checked on the wire by `everyReferenceIsExpanded` over
 * every operation at every depth — so a divergence between the two classes is red
 * without either file mentioning the other.
 *
 * Declared beside `SalesNamedReference` and `SalesActorReference`, which is this
 * module's own convention for reference DTOs; `SalesOrderRow` already imports
 * from here, so no new dependency edge arrives with it.
 */
final readonly class SalesItemReference
{
    public function __construct(
        public string $id,
        public string $sku,
        public string $name,
    ) {
    }
}
