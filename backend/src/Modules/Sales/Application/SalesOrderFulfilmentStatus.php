<?php

declare(strict_types=1);

namespace App\Modules\Sales\Application;

use Doctrine\ORM\EntityManagerInterface;

/**
 * **The order's side of a fulfilment: §8.3's fulfilment-posting transition.**
 * `PurchaseOrderReceiptStatus`'s shape, one module over and for its reason: the
 * transition is `SalesOrder::recordFulfilment()` on the entity (API-15 puts the
 * guard there) and A-7 forbids this layer to reach its own `Infrastructure`, so
 * the edge is inverted rather than registered.
 */
interface SalesOrderFulfilmentStatus
{
    /**
     * Loads the order inside the caller's transaction (which already holds its row
     * lock), re-reads it, and applies the transition. Written when the caller flushes.
     *
     * @throws \App\Platform\Domain\IllegalTransition when the order is not `confirmed` or `partially_fulfilled`
     */
    public function recordFulfilment(EntityManagerInterface $em, string $orderId, bool $allLinesFulfilled): void;
}
