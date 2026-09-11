<?php

declare(strict_types=1);

namespace App\Modules\Purchasing\Application;

use Doctrine\ORM\EntityManagerInterface;

/**
 * **The order's side of a goods receipt: §8.3's receipt-posting transition.**
 *
 * An interface here, implemented in `Infrastructure`, because the transition is
 * `PurchaseOrder::recordReceipt()` on the entity (API-15 puts the guard there)
 * and ARCHITECTURE A-7 forbids this layer to reach its own `Infrastructure`.
 * `PurchaseOrderApproval` and `PurchaseOrderCancellation` reach the entity
 * directly and are registered A-7 deviations from Output 4; a new edge of that
 * shape fails the build, so this one is inverted instead.
 */
interface PurchaseOrderReceiptStatus
{
    /**
     * Loads the order inside the caller's transaction (which already holds its
     * row lock), re-reads it so a status loaded earlier cannot answer, and
     * applies the transition. The change is written when the caller flushes.
     *
     * @throws \App\Platform\Domain\IllegalTransition when the order is not `ordered` or `partially_received`
     */
    public function recordReceipt(EntityManagerInterface $em, string $orderId, bool $allLinesReceived): void;
}
