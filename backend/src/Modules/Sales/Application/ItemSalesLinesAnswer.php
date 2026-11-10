<?php

declare(strict_types=1);

namespace App\Modules\Sales\Application;

use App\Modules\Inventory\Application\Port\Sales\ItemSalesLines;
use Doctrine\ORM\EntityManagerInterface;

/**
 * **Sales's answer to {@see ItemSalesLines}** (BK-24, items batch C3, 2026-11-10;
 * `docs/session13/items-batch-design.md` "Revision 1 — FINAL" C3): whether any `sales_order_line` row of the workspace
 * names an item, deleted lines included (no `deleted_at` predicate: the partial `sales_order_line_tenant_item_idx`
 * cannot serve it, so migration 140 adds the non-partial one). One statement, inside the caller's transaction; it reads
 * only Sales's table (A-2), and RLS bounds it to the workspace. `sales_order_line_tenant_item_all_idx` serves it
 * (`SchemaQueryPlanTest`). **It locks nothing** (W-5, the item row amendment).
 */
final readonly class ItemSalesLinesAnswer implements ItemSalesLines
{
    /** Public so a test reads the statement it pins, never a transcription. */
    public const NAMES_ITEM = 'SELECT EXISTS (SELECT 1 FROM sales_order_line WHERE inventory_item_id = ?::uuid)';

    /** `SupplierOpenPurchaseOrders::TENANT_GUC`'s reason: the answer FAILS OPEN. Public so the refusal tests name it. */
    public const TENANT_GUC = "SELECT NULLIF(current_setting('app.tenant_id', true), '')";

    public function namesItemInside(EntityManagerInterface $em, string $itemId): bool
    {
        self::refuseOutsideATenancyTransaction($em, 'ItemSalesLines');

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
