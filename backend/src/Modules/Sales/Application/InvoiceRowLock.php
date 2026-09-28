<?php

declare(strict_types=1);

namespace App\Modules\Sales\Application;

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * **The invoice's row lock — `SalesOrderRowLock`'s shape, for the document that is paid, reversed and voided**
 * (sales step 6, C5; design §19 C5; `ARCH` §6 (b)).
 *
 * Every writer of an EXISTING invoice takes it as the first statement of its transaction, before anything it reads:
 * Doctrine's `UPDATE` carries no status predicate, so without it a payment that read `issued` would overwrite whatever
 * a concurrent payment, reversal or void committed meanwhile. The second writer waits here, then re-reads the row under
 * the lock and meets the status guard. `IssueInvoice` takes none — it creates the row, and its races are answered by
 * `invoice_tenant_fulfilment_not_void` and `invoice_number_unique` (`PurchaseOrderRowLockTest`'s
 * `INVOICE_ALLOWED_WITHOUT_A_LOCK`).
 *
 * **Legal only since C5's column grant** (M-21): a locking clause needs UPDATE on the table it locks (ADR-002 A4.3
 * constraint 1), and any one granted column suffices — `erp_app` holds UPDATE on `status`, `paid_at`,
 * `paid_by_user_id` and `updated_at`. Before `Version20260928000400` this statement was a `42501`.
 *
 * `FOR NO KEY UPDATE`, as the order's lock: nothing here changes a key, so the lock does not block the `KEY SHARE`
 * an FK check from a child row would take.
 */
final class InvoiceRowLock
{
    public const SQL = 'SELECT 1 FROM invoice WHERE id = ?::uuid FOR NO KEY UPDATE';

    public static function holdInside(EntityManagerInterface $em, string $invoiceId): void
    {
        $connection = $em->getConnection();

        if (0 === $connection->getTransactionNestingLevel()) {
            throw new \LogicException(
                'InvoiceRowLock::holdInside() needs an open transaction: outside one the lock is '
                .'released as soon as the statement ends, which protects nothing.'
            );
        }

        if (!Uuid::isValid($invoiceId)) {
            return;
        }

        $connection->executeStatement(self::SQL, [$invoiceId]);
    }
}
