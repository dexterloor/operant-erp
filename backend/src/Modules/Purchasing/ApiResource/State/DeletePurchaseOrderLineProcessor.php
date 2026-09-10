<?php

declare(strict_types=1);

namespace App\Modules\Purchasing\ApiResource\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Modules\Purchasing\Application\PurchaseOrderWriter;

/**
 * `DeletePurchaseOrderLine` — `purchase_order_line` is `SchemaManifest::SOFT`, so
 * this sets `deleted_at`. API-21 branch 3: **204, and no body**.
 *
 * **There is no `RestorePurchaseOrderLine` in this package**, and it is blocked
 * rather than skipped: E9-3 / API-O-11 — `ListPurchaseOrderLines` does offer
 * `archived`, so an archived line's id *is* obtainable, but the restore itself is
 * `RestorePurchaseOrderLine`'s and that operation is scheduled with its parent's.
 * Shipping the child restore alone would leave a line restorable inside an order
 * that is not.
 *
 * @implements ProcessorInterface<mixed, null>
 */
final readonly class DeletePurchaseOrderLineProcessor implements ProcessorInterface
{
    public function __construct(private PurchaseOrderWriter $writer)
    {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): null
    {
        $this->writer->archiveLine(
            (string) ($uriVariables['id'] ?? ''),
            (string) ($uriVariables['lineId'] ?? ''),
        );

        return null;
    }
}
