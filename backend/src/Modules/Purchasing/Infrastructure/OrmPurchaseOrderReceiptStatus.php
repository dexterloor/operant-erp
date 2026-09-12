<?php

declare(strict_types=1);

namespace App\Modules\Purchasing\Infrastructure;

use App\Modules\Purchasing\Application\PurchaseOrderReceiptStatus;
use App\Modules\Purchasing\Application\PurchaseOrderRowLock;
use App\Modules\Purchasing\Infrastructure\Entity\PurchaseOrder;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * `PurchaseOrderReceiptStatus` through the ORM. It does not flush: the caller
 * does, after the stock is posted (FLOWS F-3 step 5).
 *
 * **It takes the order's row lock itself, as its first statement, even though
 * its only caller has held that lock since step 1 of the same transaction.**
 * The lock is re-entrant for the transaction that holds it, so the second
 * `SELECT … FOR NO KEY UPDATE` costs one cheap statement and changes nothing
 * about the order in which locks are taken.
 *
 * What it buys is that the claim stops depending on a caller. Step 8-ii shipped
 * this class relying on `GoodsReceiptWriter` having locked first, and
 * `PurchaseOrderRowLockTest`'s derived scan could not see this method at all —
 * it names `PurchaseOrder::class` but flushes nothing, so the ORM arm, which
 * fired only on `flush`/`persist`/`remove`, passed straight over a method that
 * changes a purchase order's status. The scan is widened; this makes the
 * widening answerable with a fact rather than with a comment, and it means a
 * second caller cannot arrive later and quietly write without the lock.
 */
final readonly class OrmPurchaseOrderReceiptStatus implements PurchaseOrderReceiptStatus
{
    public function recordReceipt(EntityManagerInterface $em, string $orderId, bool $allLinesReceived): void
    {
        PurchaseOrderRowLock::holdInside($em, $orderId);

        $order = $em->find(PurchaseOrder::class, Uuid::fromString($orderId))
            ?? throw new \LogicException(
                'A live purchase_order row is invisible to the ORM in the same transaction. The only predicate '
                .'that differs is SoftDeleteFilter\'s, and the caller has just checked deleted_at.'
            );

        // 6c's follow-up: re-read under the lock, whoever loaded the entity and when.
        $em->refresh($order);

        $order->recordReceipt($allLinesReceived);
    }
}
