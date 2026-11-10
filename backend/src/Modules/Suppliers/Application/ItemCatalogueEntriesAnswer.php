<?php

declare(strict_types=1);

namespace App\Modules\Suppliers\Application;

use App\Modules\Inventory\Application\Port\Suppliers\ItemCatalogueEntries;
use Doctrine\ORM\EntityManagerInterface;

/**
 * **Suppliers' answer to {@see ItemCatalogueEntries}** (BK-24, items batch C3, 2026-11-10;
 * `docs/session13/items-batch-design.md` "Revision 1 — FINAL" C3): whether any `supplier_product` row of the workspace
 * names an item (the table hard-deletes, so there is no `deleted_at` to weigh; the shipped non-partial `(tenant_id,
 * inventory_item_id, lead_time_days)` index serves it). One statement, inside the caller's transaction; it reads only
 * Suppliers' table (A-2), and RLS bounds it to the workspace. `supplier_product_tenant_item_idx` serves it
 * (`SchemaQueryPlanTest`). **It locks nothing** (W-5, the item row amendment).
 */
final readonly class ItemCatalogueEntriesAnswer implements ItemCatalogueEntries
{
    /** Public so a test reads the statement it pins, never a transcription. */
    public const NAMES_ITEM = 'SELECT EXISTS (SELECT 1 FROM supplier_product WHERE inventory_item_id = ?::uuid)';

    /** `SupplierOpenPurchaseOrders::TENANT_GUC`'s reason: the answer FAILS OPEN. Public so the refusal tests name it. */
    public const TENANT_GUC = "SELECT NULLIF(current_setting('app.tenant_id', true), '')";

    public function namesItemInside(EntityManagerInterface $em, string $itemId): bool
    {
        self::refuseOutsideATenancyTransaction($em, 'ItemCatalogueEntries');

        return true === $em->getConnection()->fetchOne(self::NAMES_ITEM, [$itemId]);
    }

    /** The party ports' guard, copied (each module answers for itself; A-2): nesting level 0, then an empty tenant setting. */
    private static function refuseOutsideATenancyTransaction(EntityManagerInterface $em, string $port): void
    {
        // Through the EntityManager, never a typed `Connection` (A-10; the caller's own connection, S-7(b)).
        $connection = $em->getConnection();
        if (0 === $connection->getTransactionNestingLevel()) {
            throw new \LogicException($port.' needs the caller\'s open tenancy transaction: outside one, RLS hides every row and the answer is false, which lets a used item\'s unit change.');
        }
        $tenant = $connection->fetchOne(self::TENANT_GUC);
        if (null === $tenant || false === $tenant) {
            throw new \LogicException($port.' was called in a transaction with no tenant set: RLS would hide every row and the answer would be false, which lets a used item\'s unit change.');
        }
    }
}
