<?php

declare(strict_types=1);

namespace App\Modules\Suppliers\Application;

/**
 * One supplier as `SupplierReader` reads it — scalars only, so no `Application` class returns or accepts an
 * `ApiResource` DTO (A-7; `UserView`'s rule, as `CustomerView`). §4.4.3's derived `Supplier` shape:
 * `id`, `code`, `name`, `country_code`, `currency_code`, `payment_terms_days`, `archived_at`.
 *
 * `email`, `phone` and `is_active` are **columns, not fields** (`ItemResource`'s rule): nothing writes them (owner
 * Q-S6, 2026-10-12; `RESIDUAL_GAPS.md` G-36), so nothing reads them either.
 */
final readonly class SupplierView
{
    public function __construct(
        public string $id,
        public string $code,
        public string $name,
        public string $countryCode,
        public string $currencyCode,
        public int $paymentTermsDays,
        public ?string $archivedAt,
    ) {
    }
}
