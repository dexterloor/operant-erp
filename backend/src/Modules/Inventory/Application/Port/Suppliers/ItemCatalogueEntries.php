<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Application\Port\Suppliers;

use Doctrine\ORM\EntityManagerInterface;

/**
 * **Whether any supplier catalogue entry names an item — one leg of "this item's unit is in use"** (BK-24, items
 * batch C3, 2026-11-10; `docs/session13/items-batch-design.md` "Revision 1 — FINAL" C3, `items-batch-sec-arch.md`
 * A-1/A-4). `UpdateItem` (`ItemWriter::update()`) asks it, only when the submitted unit differs from the stored one;
 * **Suppliers answers it**, because only Suppliers may read `supplier_product` (A-2). The split-port form: its own
 * deptrac layer `InventorySuppliersPort`, listed only by `InventoryApplication` and `SuppliersApplication` — never
 * `Ai*` or `Analytics*` (`DeptracRulesetTest`).
 *
 * Call it inside your own tenancy transaction (the implementation refuses at nesting level 0 or with no tenant set,
 * `PartyPortTenancyGuardTest`: its answer FAILS OPEN — outside one, RLS hides every row, the answer is `false`, and a
 * used item's unit would change), AFTER taking `ItemWriter::UNIT_LOCK` on the item: every insert of a row naming the
 * item holds it `KEY SHARE` through its FK, so this later statement sees everything committed and nothing new can
 * arrive. **The answerer locks nothing**: one plain statement, no write.
 */
interface ItemCatalogueEntries
{
    /**
     * Whether any `supplier_product` row of this workspace names the item.
     *
     * @param string $itemId canonical lower-case RFC 4122
     */
    public function namesItemInside(EntityManagerInterface $em, string $itemId): bool;
}
