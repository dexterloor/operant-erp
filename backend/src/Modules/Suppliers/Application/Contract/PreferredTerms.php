<?php

declare(strict_types=1);

namespace App\Modules\Suppliers\Application\Contract;

/**
 * **The reorder suggestion's Suppliers half: an item's preferred supplier and that supplier's catalogue terms for it**
 * (remaining-ops G3-C1, 2026-10-22; `docs/session13/g3-g7b-reorder-settings-design.md` §2.3, RED L-1). Read by
 * `Modules\Analytics`' `ReorderSuggester` for the DETAIL list only — the Overview count never calls it, so `/overview` never
 * reads a cost (`ReorderSuggestionTest::theSummaryReadsNoCatalogue`).
 *
 * The supplier is served with its archived flag: **an archived supplier is never suggested** (C5 C2b keeps the reference
 * and the catalogue; the caller drops the supplier and its terms). The terms are `null` when the live supplier has no
 * catalogue entry for the item.
 *
 * Call it inside your own tenancy transaction (W-2's shape; it joins yours). It writes nothing and takes no lock.
 */
interface PreferredTerms
{
    /**
     * A unique-key probe per pair (`supplier_product_unique`); no plan test (it cannot fail — `DATA`'s session-32 lesson).
     *
     * @param array<string, string> $supplierIdByItemId inventory item id => its preferred supplier id
     *
     * @return array<string, PreferredTerm> inventory item id => the supplier and its terms; an unknown supplier is absent
     */
    public function termsFor(array $supplierIdByItemId): array;
}
