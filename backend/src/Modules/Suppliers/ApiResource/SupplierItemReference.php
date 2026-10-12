<?php

declare(strict_types=1);

namespace App\Modules\Suppliers\ApiResource;

/**
 * API-23b's expansion of an `inventory_item_id`: `{id, sku, name}` (clause (ii), owner ruling Q-A of 2026-09-15: every
 * reference to `inventory_item` carries `sku`).
 *
 * **A third class rather than an import of `Purchasing\Application\ItemReference` or `Sales\ApiResource\SalesItemReference`**
 * — `SalesItemReference`'s reason: `Modules\Suppliers` may not import another module (A-7), the shared shape is three
 * strings, and the RULE lives in API-23b, checked on the wire by `ResponseShapeTest::everyReferenceIsExpanded` over every
 * operation, so a divergence is red without any of the three files naming the others. Suppliers slice 1 C3.
 */
final readonly class SupplierItemReference
{
    public function __construct(
        public string $id,
        public string $sku,
        public string $name,
    ) {
    }
}
