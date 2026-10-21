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
 * Its inverse is `RestorePurchaseOrderLine` (order-restores C0, 2026-10-21), which
 * restores only a line deleted individually while its order is a live draft.
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
