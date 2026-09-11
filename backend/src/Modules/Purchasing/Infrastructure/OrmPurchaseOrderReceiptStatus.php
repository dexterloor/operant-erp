<?php

declare(strict_types=1);

namespace App\Modules\Purchasing\Infrastructure;

use App\Modules\Purchasing\Application\PurchaseOrderReceiptStatus;
use App\Modules\Purchasing\Infrastructure\Entity\PurchaseOrder;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * `PurchaseOrderReceiptStatus` through the ORM. It does not flush: the caller
 * does, after the stock is posted (FLOWS F-3 step 5).
 */
final readonly class OrmPurchaseOrderReceiptStatus implements PurchaseOrderReceiptStatus
{
    public function recordReceipt(EntityManagerInterface $em, string $orderId, bool $allLinesReceived): void
    {
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
