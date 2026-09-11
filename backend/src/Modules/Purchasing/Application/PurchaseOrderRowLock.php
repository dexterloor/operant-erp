<?php

declare(strict_types=1);

namespace App\Modules\Purchasing\Application;

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * **The one place a purchase-order write takes its row lock (step 6c).**
 *
 * Every path that writes a purchase order or its lines calls this as the
 * **first statement inside its transaction**, before it reads anything it
 * will check. Before 6c each of them read without a lock, checked, then
 * wrote. Under READ COMMITTED a second `UPDATE` of the order row waits for the
 * first to commit and then goes ahead **without re-checking**, so an approval
 * could place an order whose last line was archived a moment earlier, and an
 * approval and a cancellation could each overwrite the other's status. With
 * the lock first, the second writer waits **before** its checks, and when it
 * gets the lock its reads see what the first writer committed.
 *
 * ---------------------------------------------------------------------------
 * `FOR NO KEY UPDATE`, not `FOR UPDATE`
 * ---------------------------------------------------------------------------
 * It is the lock a plain `UPDATE` of a non-key column takes anyway, so taking
 * it early adds no new kind of conflict. It does **not** block the `KEY SHARE`
 * lock a foreign-key check takes when another table inserts a row pointing at
 * the order (a goods receipt, from step 8 on). `FOR UPDATE` would serialise
 * those inserts behind every edit of the order for no benefit.
 *
 * ---------------------------------------------------------------------------
 * Its own statement, and never a suffix on `PurchaseOrderRow::FROM`
 * ---------------------------------------------------------------------------
 * `PurchaseOrderRow::FROM` joins `supplier`, `warehouse` and `app_user`, and a
 * locking clause on a join locks a row in **every** joined table: an edit of
 * one order would lock its supplier against everyone else's orders, and
 * `erp_app` need not hold `UPDATE` on those tables at all. So the lock
 * names `purchase_order` alone.
 *
 * ---------------------------------------------------------------------------
 * What it does not do
 * ---------------------------------------------------------------------------
 * It does not answer "not found". Under RLS another tenant's row is invisible,
 * so the lock takes nothing, and the caller's own read answers not-found
 * exactly as before. A string that is not a UUID names no row in any tenant
 * and takes nothing too: casting it would raise 22P02, and the caller's read
 * already turns such an id into not-found. An archived order **is** locked,
 * on purpose: the caller must see `deleted_at` and refuse with
 * `purchase_order.is_archived`, and reading it after the lock is what makes
 * that refusal current.
 *
 * Lock order follows from calling this first: **order, then lines**. It is the
 * same order the `purchase_order_cascade_deletion` trigger uses (the order row,
 * then its lines), and `recomputeTotal()`'s later `UPDATE` of the order
 * re-takes a lock this transaction already holds.
 */
final class PurchaseOrderRowLock
{
    public const SQL = 'SELECT 1 FROM purchase_order WHERE id = ?::uuid FOR NO KEY UPDATE';

    public static function holdInside(EntityManagerInterface $em, string $orderId): void
    {
        if (!Uuid::isValid($orderId)) {
            return;
        }

        $em->getConnection()->executeStatement(self::SQL, [$orderId]);
    }
}
