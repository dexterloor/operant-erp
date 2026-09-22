<?php

declare(strict_types=1);

namespace App\Modules\Sales\Infrastructure;

use App\Modules\Sales\Application\SalesOrderFulfilmentStatus;
use App\Modules\Sales\Application\SalesOrderRowLock;
use App\Modules\Sales\Infrastructure\Entity\SalesOrder;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * `SalesOrderFulfilmentStatus` through the ORM. It does not flush: the caller does,
 * after the stock is taken out (FLOWS F-5 step 5).
 *
 * **It takes the order's row lock itself, first**, for `OrmPurchaseOrderReceiptStatus`'s
 * reason: the lock is re-entrant for the transaction that holds it, so this costs one
 * cheap statement, and the claim that the status is written under the lock stops
 * depending on a caller.
 */
final readonly class OrmSalesOrderFulfilmentStatus implements SalesOrderFulfilmentStatus
{
    public function recordFulfilment(EntityManagerInterface $em, string $orderId, bool $allLinesFulfilled): void
    {
        SalesOrderRowLock::holdInside($em, $orderId);

        $order = $em->find(SalesOrder::class, Uuid::fromString($orderId))
            ?? throw new \LogicException(
                'A live sales_order row is invisible to the ORM in the same transaction. The only predicate '
                .'that differs is SoftDeleteFilter\'s, and the caller has just checked deleted_at.'
            );

        // Re-read under the lock, whoever loaded the entity and when.
        $em->refresh($order);

        $order->recordFulfilment($allLinesFulfilled);
    }
}
