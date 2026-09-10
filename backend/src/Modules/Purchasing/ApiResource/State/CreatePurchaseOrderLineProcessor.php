<?php

declare(strict_types=1);

namespace App\Modules\Purchasing\ApiResource\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Modules\Purchasing\ApiResource\CreatePurchaseOrderLineInput;
use App\Modules\Purchasing\ApiResource\PurchaseOrderLineResource;
use App\Modules\Purchasing\Application\PurchaseOrderWriter;

/**
 * `CreatePurchaseOrderLine` — API-21 branch 4: §4.2 declares operations at
 * `/purchase-orders/{id}/lines/{lineId}`, which is the row-level URI of the row
 * this POST creates, so the answer is **201** with a `Location` naming it and the
 * created resource as the body.
 *
 * @implements ProcessorInterface<CreatePurchaseOrderLineInput, PurchaseOrderLineResource>
 */
final readonly class CreatePurchaseOrderLineProcessor implements ProcessorInterface
{
    public function __construct(private PurchaseOrderWriter $writer)
    {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): PurchaseOrderLineResource
    {
        \assert($data instanceof CreatePurchaseOrderLineInput);

        return $this->writer->createLine((string) ($uriVariables['id'] ?? ''), $data);
    }
}
