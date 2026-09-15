<?php

declare(strict_types=1);

namespace App\Modules\Purchasing\Application;

/**
 * API-23b's expansion of a `storage_location_id`: `{id, code}`.
 *
 * The display field is derived, not chosen: `storage_location` has no `name`
 * column, so it is `code` — the same `{id, code}` §4.4.3 writes for
 * `StockLevel` and `StockMovement`. Id and display field only; the bin's
 * warehouse is never nested here (an expansion carries no further reference).
 *
 * Declared in this layer, beside `OrderReference`, because `GoodsReceiptLineView`
 * is built here and a class in `ApiResource` would be a new A-7 edge.
 */
final readonly class LocationReference
{
    public function __construct(
        public string $id,
        public string $code,
    ) {
    }
}
