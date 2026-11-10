<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Application\Port\Purchasing;

use Doctrine\ORM\EntityManagerInterface;

/**
 * **Whether any purchase order line names an item — one leg of "this item's unit is in use"** (BK-24, items batch C3,
 * 2026-11-10; `docs/session13/items-batch-design.md` "Revision 1 — FINAL" C3, `items-batch-sec-arch.md` A-1/A-4).
 * `UpdateItem` (`ItemWriter::update()`) asks it, only when the submitted unit differs from the stored one; **Purchasing
 * answers it**, because only Purchasing may read `purchase_order_line` (A-2). The split-port form: its own deptrac
 * layer `InventoryPurchasingPort`, listed only by `InventoryApplication` and `PurchasingApplication` — never `Ai*` or
 * `Analytics*` (`DeptracRulesetTest`).
 *
 * Call it inside your own tenancy transaction (the implementation refuses at nesting level 0 or with no tenant set,
 * `PartyPortTenancyGuardTest`: its answer FAILS OPEN — outside one, RLS hides every row, the answer is `false`, and a
 * used item's unit would change), AFTER taking `ItemWriter::UNIT_LOCK` on the item: every insert of a row naming the
 * item holds it `KEY SHARE` through its FK, so this later statement sees everything committed and nothing new can
 * arrive. **The answerer locks nothing**: one plain statement, no write.
 */
interface ItemPurchaseLines
{
    /**
     * Whether any `purchase_order_line` row of this workspace names the item, **deleted lines included**: an order line
     * archived with its draft still recorded a quantity in the item's unit.
     *
     * @param string $itemId canonical lower-case RFC 4122
     */
    public function namesItemInside(EntityManagerInterface $em, string $itemId): bool;
}
