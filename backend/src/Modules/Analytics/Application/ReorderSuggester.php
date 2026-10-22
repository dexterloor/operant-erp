<?php

declare(strict_types=1);

namespace App\Modules\Analytics\Application;

use App\Modules\Inventory\Application\Contract\ReorderStock;
use App\Modules\Purchasing\Application\Contract\OpenPurchaseSupply;
use App\Modules\Suppliers\Application\Contract\PreferredTerms;
use App\Platform\Tenancy\CurrentTenant;
use App\Platform\Tenancy\TenantContext;

/**
 * **The reorder computation — the first class of `Modules\Analytics`** (remaining-ops G3-C1, 2026-10-22; owner ruling RO-R1
 * (c): the stored reorder point, net of open purchase-order supply; `remaining-ops-final-red.md` M-4;
 * `docs/session13/g3-g7b-reorder-settings-design.md` §2.2 and Revision 1 FINAL; final RED F-L3).
 *
 * ```
 * on_hand   = Σ qty_on_hand over PICKABLE bins (Inventory's ReorderStock)
 * on_order  = Σ max(0, outstanding) over live lines of live POs in ordered | partially_received (Purchasing's OpenPurchaseSupply)
 * projected = on_hand + on_order
 * listed  iff the item is managed (point > 0 OR quantity > 0) AND projected <= max(point, 0)       ("at or below", F-4 step 1)
 * suggested = max(max(quantity, 0), max(point, 0) - projected, MOQ)  — MOQ only from a live preferred catalogue entry
 * ```
 *
 * A value ≤ 0 is unset (F-L3): `max(…, 0)` on both stored values, so a suggestion is never negative. `safety_stock` is not
 * added — the stored point is taken as already including it (ADR-004's formula; `PO` to confirm, D-C1-7). An item at its
 * point with no quantity and no catalogue entry suggests 0 (RED L-5; owner notice N-13; `UX` copy owed). Reserved stock is
 * not netted (G-7 model A: owner notice N-3, may under-suggest). No forecasting (M4).
 *
 * **One path, two depths** (ADR-004's *"one shared query path"*; RED L-1): {@see self::listed()} (S1 + S2) decides what is
 * listed and feeds both operations; only {@see self::suggestions()} enriches it with the catalogue (S3), so the Overview
 * count never reads a cost. The three statements run in ONE tenancy transaction under READ COMMITTED — not one snapshot
 * (a new Low gap; display only).
 */
final readonly class ReorderSuggester
{
    private const SCALE = 6;

    public function __construct(
        private TenantContext $tenants,
        private CurrentTenant $tenant,
        private ReorderStock $stock,
        private OpenPurchaseSupply $supply,
        private PreferredTerms $terms,
    ) {
    }

    /**
     * Every listed item, ordered `sku, id`. No catalogue read.
     *
     * @return list<ReorderLine>
     */
    public function listed(): array
    {
        return $this->tenants->run($this->tenant->tenantId(), fn (): array => $this->listedInside());
    }

    /** `GetReorderSummary`: how many items {@see self::listed()} lists — the same list, counted. */
    public function count(): int
    {
        return \count($this->listed());
    }

    /**
     * `ListReorderSuggestions`: the listed items with their preferred supplier's terms and the suggested quantity.
     *
     * @return array{suggestions: list<ReorderSuggestionView>, tenantHasItems: bool}
     */
    public function suggestions(): array
    {
        return $this->tenants->run($this->tenant->tenantId(), function (): array {
            $lines = $this->listedInside();

            $preferred = [];
            foreach ($lines as $line) {
                if (null !== $line->preferredSupplierId) {
                    $preferred[$line->itemId] = $line->preferredSupplierId;
                }
            }
            $terms = $this->terms->termsFor($preferred);

            $suggestions = [];
            foreach ($lines as $line) {
                $term = $terms[$line->itemId] ?? null;
                // An archived preferred supplier is never suggested (C5 C2b; the retired G-41 tripwire's last clause): the row
                // stays listed — the item still needs stock — with no supplier and no terms.
                $live = null !== $term && !$term->supplierArchived ? $term : null;

                $suggested = self::max(self::positive($line->reorderQuantity), bcsub(self::positive($line->reorderPoint), self::projected($line), self::SCALE));
                if (null !== $live?->minOrderQuantity) {
                    $suggested = self::max($suggested, $live->minOrderQuantity);
                }

                $suggestions[] = new ReorderSuggestionView(
                    $line,
                    $suggested,
                    $live?->supplierId,
                    $live?->supplierName,
                    $live?->leadTimeDays,
                    $live?->unitCost,
                    $live?->currencyCode,
                );
            }

            return ['suggestions' => $suggestions, 'tenantHasItems' => [] !== $lines || $this->stock->tenantHasItems()];
        });
    }

    /** @return list<ReorderLine> */
    private function listedInside(): array
    {
        $onOrder = $this->supply->onOrderByItem();

        $lines = [];
        foreach ($this->stock->managedItems() as $item) {
            $line = new ReorderLine(
                $item->id,
                $item->sku,
                $item->name,
                $item->qtyOnHandPickable,
                $onOrder[$item->id] ?? bcadd('0', '0', self::SCALE),
                bcadd($item->reorderPoint, '0', self::SCALE),
                bcadd($item->reorderQuantity, '0', self::SCALE),
                $item->preferredSupplierId,
            );

            if (bccomp(self::projected($line), self::positive($line->reorderPoint), self::SCALE) <= 0) {
                $lines[] = $line;
            }
        }

        return $lines;
    }

    private static function projected(ReorderLine $line): string
    {
        return bcadd($line->qtyOnHand, $line->qtyOnOrder, self::SCALE);
    }

    /** A stored value ≤ 0 is unset (F-L3). */
    private static function positive(string $value): string
    {
        return 1 === bccomp($value, '0', self::SCALE) ? bcadd($value, '0', self::SCALE) : bcadd('0', '0', self::SCALE);
    }

    private static function max(string $a, string $b): string
    {
        return bcadd(bccomp($a, $b, self::SCALE) >= 0 ? $a : $b, '0', self::SCALE);
    }
}
