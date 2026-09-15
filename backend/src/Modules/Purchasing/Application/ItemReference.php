<?php

declare(strict_types=1);

namespace App\Modules\Purchasing\Application;

/**
 * API-23b's expansion of an `inventory_item_id`: `{id, sku, name}`.
 *
 * The display field is derived — `inventory_item` has a `name` — and **clause
 * (ii)** adds `sku` beside it. That clause is owner ruling Q-A (2026-09-15, "Yes,
 * everywhere"): every reference to `inventory_item` also carries `sku`, because
 * `/items` is ordered and searched by SKU (IA §5.6), and it names that one table,
 * so no other reference gains `sku` from it. `sku` is the item's identifier, not a
 * further reference, a money field, a terms field or a status, so PC-12 still
 * holds.
 *
 * Declared in this layer, beside `LocationReference` and `OrderReference`,
 * because the rows that build it live here and a class in `ApiResource` would be
 * a new A-7 edge.
 */
final readonly class ItemReference
{
    public function __construct(
        public string $id,
        public string $sku,
        public string $name,
    ) {
    }
}
