<?php

declare(strict_types=1);

namespace App\Modules\Warehouses\Application;

/**
 * One warehouse as `WarehouseReader` reads it — scalars only, so no `Application` class returns or accepts an
 * `ApiResource` DTO (A-7; `SupplierView`'s rule). §4.4.3's derived `Warehouse` shape: `id`, `code`, `name`,
 * `country_code`, `archived_at`.
 *
 * `address_line`, `city`, `postal_code` and `is_active` are **columns, not fields** (`ItemResource`'s rule): nothing writes
 * them (warehouses slice 1 Q-W4, adopted under the owner's delegation 2026-10-13; `RESIDUAL_GAPS.md` G-36 is widened at
 * C2), so nothing reads them either.
 */
final readonly class WarehouseView
{
    public function __construct(
        public string $id,
        public string $code,
        public string $name,
        public string $countryCode,
        public ?string $archivedAt,
    ) {
    }
}
