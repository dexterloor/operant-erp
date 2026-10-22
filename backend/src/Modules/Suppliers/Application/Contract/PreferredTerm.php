<?php

declare(strict_types=1);

namespace App\Modules\Suppliers\Application\Contract;

/**
 * One item's preferred supplier ({@see PreferredTerms}). The four terms are all null, or all set: a live catalogue entry
 * for this item gives `unit_cost` (scale 4) in `currency_code`, `lead_time_days` and `min_order_quantity` (scale 6).
 */
final readonly class PreferredTerm
{
    public function __construct(
        public string $supplierId,
        public string $supplierName,
        public bool $supplierArchived,
        public ?string $unitCost,
        public ?string $currencyCode,
        public ?int $leadTimeDays,
        public ?string $minOrderQuantity,
    ) {
    }
}
