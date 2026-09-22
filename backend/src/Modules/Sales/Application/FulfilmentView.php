<?php

declare(strict_types=1);

namespace App\Modules\Sales\Application;

/** One fulfilment, as `FulfilmentReader` reads it. `FulfilmentResource` serializes it. No money field. */
final readonly class FulfilmentView
{
    /**
     * @param list<FulfilmentLineView> $lines
     */
    public function __construct(
        public string $id,
        public string $fulfilmentNumber,
        public string $shippedAt,
        public SalesOrderRef $salesOrder,
        public SalesActorRef $shippedBy,
        public array $lines,
    ) {
    }
}
