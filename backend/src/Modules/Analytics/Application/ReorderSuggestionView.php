<?php

declare(strict_types=1);

namespace App\Modules\Analytics\Application;

/**
 * One row of `ListReorderSuggestions` ({@see ReorderSuggester::suggestions()}): a listed item plus its preferred supplier's
 * terms and the suggested quantity. The supplier half is all null when the item has no preferred supplier or it is archived;
 * the four terms are null when the live supplier has no catalogue entry for the item.
 */
final readonly class ReorderSuggestionView
{
    public function __construct(
        public ReorderLine $line,
        public string $suggestedQuantity,
        public ?string $supplierId,
        public ?string $supplierName,
        public ?int $leadTimeDays,
        public ?string $unitCost,
        public ?string $currencyCode,
    ) {
    }
}
