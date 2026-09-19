<?php

declare(strict_types=1);

namespace App\Modules\Sales\Application;

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * **The one place a sales-order write takes its row lock** — `ARCHITECTURE.md` W-5's first half,
 * *"Sales locks the order, then stock"*.
 *
 * It is `PurchaseOrderRowLock` on the selling side, deliberately and line for line, and the reasons
 * are that class's rather than new ones:
 *
 *   - **`FOR NO KEY UPDATE`, not `FOR UPDATE`.** It is the lock a plain `UPDATE` of a non-key column
 *     takes anyway, so taking it early adds no new kind of conflict, and it does **not** block the
 *     `KEY SHARE` a foreign-key check takes when another table inserts a row pointing at the order —
 *     a fulfilment or an invoice, from the output this one serves.
 *   - **Its own statement, never a suffix on `SalesOrderRow::FROM`.** That `FROM` joins `customer`,
 *     `warehouse` and `app_user`, and a locking clause on a join locks a row in **every** joined
 *     table: confirming one order would lock its customer against everyone else's orders, and
 *     `erp_app` holds no `UPDATE` on any of the three. So the lock names `sales_order` alone — which
 *     is also why `Version20260919000300` grants `UPDATE` on `sales_order` and not on the parents
 *     (ADR-002 A4.3 constraint 1).
 *   - **It refuses to run outside a transaction.** There the statement is its own implicit
 *     transaction and the lock is released the moment it is granted, so a caller that forgot the
 *     transaction would get no protection and no error.
 *   - **It does not answer "not found".** Under RLS another tenant's row is invisible, so the lock
 *     takes nothing and the caller's own read answers not-found exactly as before. A string that is
 *     not a UUID names no row in any tenant and takes nothing either: casting it would raise `22P02`.
 *     An archived order **is** locked, on purpose — the caller must read `deleted_at` under the lock
 *     and refuse with `sales_order.is_archived` (§5.2a / ST-9).
 *
 * **Why a second class rather than a shared one.** The two are one statement apart, but a shared
 * helper would take the table name as an argument, and a locking statement whose table is a
 * parameter is exactly the shape `WriteOwnershipTest` and `PurchaseOrderRowLockTest` cannot read: both
 * scans resolve a table only from a literal. A cross-module `Purchasing\Application` dependency from
 * `Modules\Sales` would also be a new deptrac edge for a `SELECT`.
 *
 * **Who must call it.** `PurchaseOrderRowLockTest::everyMethodThatWritesASalesOrderIsCoveredByALockFirstCheck`
 * derives the sales writers from `src/` and fails on any writer not covered by a lock-first check;
 * `theSalesOrderRowLockIsTheFirstStatementToTouchTheOrder` proves the position per HTTP path, and
 * `aSecondConfirmationWaitsAndIsRefusedByTheStatusGuard` proves the behaviour under a real race.
 * `SalesOrderWriter::create` is allow-listed by name: it has no existing row to lock. The blind spots
 * are `PurchaseOrderRowLock`'s, and one of them already writes here — `sales_order_cascade_deletion`
 * carries an order's `deleted_at` to its lines from inside the order's own `UPDATE`, so it keeps the
 * order → lines order this class establishes.
 */
final class SalesOrderRowLock
{
    public const SQL = 'SELECT 1 FROM sales_order WHERE id = ?::uuid FOR NO KEY UPDATE';

    public static function holdInside(EntityManagerInterface $em, string $orderId): void
    {
        $connection = $em->getConnection();

        if (0 === $connection->getTransactionNestingLevel()) {
            throw new \LogicException(
                'SalesOrderRowLock::holdInside() needs an open transaction: outside one the lock is '
                .'released as soon as the statement ends, which protects nothing.'
            );
        }

        if (!Uuid::isValid($orderId)) {
            return;
        }

        $connection->executeStatement(self::SQL, [$orderId]);
    }
}
