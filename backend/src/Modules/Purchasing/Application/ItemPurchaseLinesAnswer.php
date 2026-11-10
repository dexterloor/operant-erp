<?php

declare(strict_types=1);

namespace App\Modules\Purchasing\Application;

use App\Modules\Inventory\Application\Port\Purchasing\ItemPurchaseLines;
use Doctrine\ORM\EntityManagerInterface;

/**
 * **Purchasing's answer to {@see ItemPurchaseLines}** (BK-24, items batch C3, 2026-11-10;
 * `docs/session13/items-batch-design.md` "Revision 1 — FINAL" C3): whether any `purchase_order_line` row of the
 * workspace names an item, deleted lines included (no `deleted_at` predicate: the partial
 * `purchase_order_line_tenant_item_idx` cannot serve it, so migration 140 adds the non-partial one). One statement,
 * inside the caller's transaction; it reads only Purchasing's table (A-2), and RLS bounds it to the workspace.
 * `purchase_order_line_tenant_item_all_idx` serves it (`SchemaQueryPlanTest`). **It locks nothing** (W-5, the item row
 * amendment).
 */
final readonly class ItemPurchaseLinesAnswer implements ItemPurchaseLines
{
    /** Public so a test reads the statement it pins, never a transcription. */
    public const NAMES_ITEM = 'SELECT EXISTS (SELECT 1 FROM purchase_order_line WHERE inventory_item_id = ?::uuid)';

    /** `SupplierOpenPurchaseOrders::TENANT_GUC`'s reason: the answer FAILS OPEN. Public so the refusal tests name it. */
    public const TENANT_GUC = "SELECT NULLIF(current_setting('app.tenant_id', true), '')";

    public function namesItemInside(EntityManagerInterface $em, string $itemId): bool
    {
        self::refuseOutsideATenancyTransaction($em, 'ItemPurchaseLines');

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
