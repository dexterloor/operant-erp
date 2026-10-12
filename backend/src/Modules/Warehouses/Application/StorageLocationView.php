<?php

declare(strict_types=1);

namespace App\Modules\Warehouses\Application;

/**
 * One storage location as `StorageLocationReader` reads it — scalars only (A-7). §4.4.3's derived `StorageLocation` shape:
 * `id`, `code`, `kind`, `archived_at`. `warehouse_id` is path-implied (`/warehouses/{id}/locations`), as
 * `supplier_product.supplier_id` is, so it is not served.
 */
final readonly class StorageLocationView
{
    public function __construct(
        public string $id,
        public string $code,
        public string $kind,
        public ?string $archivedAt,
    ) {
    }
}
