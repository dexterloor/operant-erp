<?php

declare(strict_types=1);

namespace App\Modules\Sales\Application;

/**
 * One row of the pick queue (BK-21), as `PickQueueReader` reads it: an open, released sales order's number, status,
 * confirmation date, customer and warehouse. No money, quantity, bin or ship-to column (`SECURITY_MODEL.md` PC-9's fifth
 * exception). An `Application` read model, so the reader never reaches into `ApiResource` (A-7).
 */
final readonly class PickQueueEntryView
{
    public function __construct(
        public string $id,
        public string $orderNumber,
        public string $status,
        public string $orderedAt,
        public SalesNamedRef $customer,
        public SalesNamedRef $warehouse,
    ) {
    }
}
