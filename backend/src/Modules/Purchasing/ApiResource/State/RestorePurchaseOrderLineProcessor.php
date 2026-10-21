<?php

declare(strict_types=1);

namespace App\Modules\Purchasing\ApiResource\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Modules\Purchasing\ApiResource\PurchaseOrderLineResource;
use App\Modules\Purchasing\Application\PurchaseOrderWriter;

/**
 * `RestorePurchaseOrderLine` — clears `deleted_at` on a line the caller already holds the id of, so **200 with the
 * resource in its new state, and no `Location`**. `RestoreSalesOrderLineProcessor` is the shipped twin.
 *
 * Until order-restores C0 (2026-10-21) this operation was blocked on a coherence argument rather than on
 * obtainability (`ListPurchaseOrderLines` already offers `archived`, so E9-3 was met): *"shipping the child restore
 * alone would leave a line restorable inside an order that is not."* **That argument is answered by guards, as on
 * the sales side:** {@see PurchaseOrderWriter::restoreLine()} refuses an archived order and a non-draft order before
 * it looks at the line, so a cascade-archived line cannot be restored alone.
 *
 * @implements ProcessorInterface<mixed, PurchaseOrderLineResource>
 */
final readonly class RestorePurchaseOrderLineProcessor implements ProcessorInterface
{
    public function __construct(private PurchaseOrderWriter $writer)
    {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): PurchaseOrderLineResource
    {
        return $this->writer->restoreLine(
            (string) ($uriVariables['id'] ?? ''),
            (string) ($uriVariables['lineId'] ?? ''),
        );
    }
}
