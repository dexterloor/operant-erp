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
 * What it refuses, and what it does not do
 * ---------------------------------------------------------------------------
 * **It refuses to run outside a transaction.** There the statement is its own
 * implicit transaction and the lock is released the moment it is granted, so
 * a caller that forgot the transaction would get no protection and no error.
 * `TenantContext::establishOn()` refuses a `set_config` with nothing to bind
 * to for the same reason.
 *
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
 *
 * ---------------------------------------------------------------------------
 * Who must call it, and what the check that says so cannot see
 * ---------------------------------------------------------------------------
 * `PurchaseOrderRowLockTest::everyMethodThatWritesAPurchaseOrderIsCoveredByALockFirstCheck`
 * **derives** the writers from `src/` with PHP's tokenizer: every method whose
 * string literals write `purchase_order` or `purchase_order_line` (`UPDATE`,
 * `INSERT INTO`, `DELETE FROM`, or DQL on the entity), every method that names
 * `PurchaseOrder::class` / `PurchaseOrderLine::class` and either flushes,
 * persists or removes **or calls one of those entities' own state-changing
 * methods** (taken by reflection, so a new transition is covered the day it is
 * written), and every in-class caller of a private method that does any of it.
 * It fails on any writer not covered by a lock-first check.
 * `PurchaseOrderWriter::create` is allowed by name: it has no existing row to
 * lock.
 *
 * **⚠ The mutator half was added on 2026-09-12, and the list below used to be
 * wrong.** It said the ORM gap was limited to methods that do **not** name
 * either entity class. `OrmPurchaseOrderReceiptStatus::recordReceipt` names
 * `PurchaseOrder::class`, changes the order's status, and flushes nothing — its
 * caller flushes, after the stock is posted — so it fell through an arm that
 * fired only on `flush`/`persist`/`remove`, and step 8-ii shipped a
 * purchase-order writer the scan could not see. **A method that leaves the flush
 * to somebody else is the ordinary shape in this codebase, not an exotic one.**
 *
 * **It still cannot see:**
 *
 *   - SQL whose table name is not inside one string literal: assembled at
 *     runtime (`sprintf('UPDATE %s', $table)`), held in a variable or a
 *     constant, or split so `UPDATE` and the table name are separate pieces;
 *   - an ORM write in a method that names neither entity class **and** calls
 *     none of their mutators by name: an entity handed in as a parameter, or
 *     reached through a repository or an association, and changed or flushed
 *     somewhere else; and a QueryBuilder `->update()` that never spells the
 *     statement as a string;
 *   - writes outside `src/`: migrations, scripts, `psql`, and triggers. The
 *     cascade trigger writes lines from inside an order `UPDATE`, which
 *     already holds the order, so it keeps the order → lines rule.
 */
final class PurchaseOrderRowLock
{
    public const SQL = 'SELECT 1 FROM purchase_order WHERE id = ?::uuid FOR NO KEY UPDATE';

    public static function holdInside(EntityManagerInterface $em, string $orderId): void
    {
        $connection = $em->getConnection();

        if (0 === $connection->getTransactionNestingLevel()) {
            throw new \LogicException(
                'PurchaseOrderRowLock::holdInside() needs an open transaction: outside one the lock is '
                .'released as soon as the statement ends, which protects nothing.'
            );
        }

        if (!Uuid::isValid($orderId)) {
            return;
        }

        $connection->executeStatement(self::SQL, [$orderId]);
    }
}
