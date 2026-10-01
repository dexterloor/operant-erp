<?php

declare(strict_types=1);

namespace App\Modules\Sales\Application;

/**
 * One sales return, as `SalesReturnReader` reads it. `SalesReturnResource` serializes it. **No money field.**
 *
 * `$creditBlockedByPayment` is `null` when the caller does not hold `invoice.view`: the field is then ABSENT from the
 * body, not null (`RED-4` M4-1; R-14's hold-test shape) — `SalesReturnResource` drops it.
 */
final readonly class SalesReturnView
{
    /**
     * @param list<SalesReturnLineView> $lines
     */
    public function __construct(
        public string $id,
        public string $salesReturnNumber,
        public string $reason,
        public string $returnedAt,
        public FulfilmentRef $fulfilment,
        public ?InvoiceNumberRef $invoice,
        public ?InvoiceNumberRef $creditTarget,
        public bool $deducts,
        public SalesActorRef $recordedBy,
        public string $createdAt,
        public array $lines,
        public ?bool $creditBlockedByPayment,
    ) {
    }
}
