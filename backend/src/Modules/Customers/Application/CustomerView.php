<?php

declare(strict_types=1);

namespace App\Modules\Customers\Application;

/**
 * One customer as `CustomerReader` reads it — scalars only, so no `Application` class returns or accepts an
 * `ApiResource` DTO (A-7; `UserView`'s rule, customers slice 1 final RED M-4). §4.4.3's derived `Customer` shape:
 * `id`, `code`, `name`, `country_code`, `currency_code`, `payment_terms_days`, `archived_at`.
 *
 * `email`, `phone`, `credit_limit` and `is_active` are **columns, not fields** (`ItemResource`'s rule): nothing writes
 * them (owner Q-C2, `RESIDUAL_GAPS.md`), so nothing reads them either.
 */
final readonly class CustomerView
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
