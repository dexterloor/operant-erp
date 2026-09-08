<?php

declare(strict_types=1);

namespace App\Inventory\ApiResource;

/**
 * API-23b — a foreign-key reference is returned expanded as
 * `{id, <display field>}` and never as a bare uuid. `supplier` has a `name`, so
 * `name` is the display field.
 *
 * The "id and display field only" half is what keeps PC-12 out of this: an
 * expansion never carries a further reference, a money field, a terms field or a
 * status, so expanding a supplier here discloses its name and nothing
 * `supplier_product.view` gates.
 */
final readonly class SupplierReference
{
    public function __construct(
        public string $id,
        public string $name,
    ) {
    }
}
