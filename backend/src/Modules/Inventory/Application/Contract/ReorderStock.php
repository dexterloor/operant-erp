<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Application\Contract;

/**
 * **The reorder computation's Inventory half** (remaining-ops G3-C1, 2026-10-22; `docs/session13/g3-g7b-reorder-settings-design.md`
 * §2.3 and Revision 1 FINAL, final RED F-L3; `remaining-ops-final-red.md` M-4). Read by `Modules\Analytics`' `ReorderSuggester`
 * through this contract — never a raw cross-module join from Analytics (A-2) — so what *on hand* means for a reorder lives here,
 * beside the pick read that defines *pickable*.
 *
 * **Managed** (RED M-3, final RED F-L3): a live item (`deleted_at IS NULL`) with `reorder_point > 0 OR reorder_quantity > 0`.
 * Both columns default to 0 and nothing CHECKs their sign (`ItemWriter::DECIMAL` admits a negative), so **a value ≤ 0 means
 * unset**: an item with neither set above zero is never listed; the caller treats a non-positive point or quantity as 0.
 * `inventory_item.is_active` is ignored, as everywhere (nothing writes it).
 *
 * **On hand** (M-4) is the sum of `qty_on_hand` over the item's **pickable** bins only — a live location of a live warehouse
 * whose kind is not {@see PickableStock::NEVER_PICKABLE_KIND}: the same definition `PickableStock::candidateBins()` reads, from
 * the one shared SQL fragment (`PickableStockReader::PICKABLE_FROM` / `::PICKABLE_WHERE`). Quarantine and archived stock never
 * counts. On-hand only: no reserved or available figure (G-7 model A; owner ruling Q1 of 2026-10-14).
 *
 * Call it inside your own tenancy transaction (W-2's shape; it joins yours). It writes nothing and takes no lock — a display.
 */
interface ReorderStock
{
    /**
     * One aggregate pass over the tenant's stock (plan test P-2: no per-item subplan), ordered `sku, id`.
     *
     * @return list<ManagedItem>
     */
    public function managedItems(): array;

    /** API-22's ST-2 discriminator: does the workspace hold any item row at all (live or archived), with no other predicate. */
    public function tenantHasItems(): bool;
}
