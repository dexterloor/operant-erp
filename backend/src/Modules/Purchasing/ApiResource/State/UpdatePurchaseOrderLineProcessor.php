<?php

declare(strict_types=1);

namespace App\Modules\Purchasing\ApiResource\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Modules\Purchasing\ApiResource\PurchaseOrderLineResource;
use App\Modules\Purchasing\ApiResource\UpdatePurchaseOrderLineInput;
use App\Modules\Purchasing\Application\PurchaseOrderWriter;

/**
 * `UpdatePurchaseOrderLine` — API-21 branch 2: 200 with the updated resource.
 *
 * @implements ProcessorInterface<UpdatePurchaseOrderLineInput, PurchaseOrderLineResource>
 */
final readonly class UpdatePurchaseOrderLineProcessor implements ProcessorInterface
{
    public function __construct(private PurchaseOrderWriter $writer)
    {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): PurchaseOrderLineResource
    {
        \assert($data instanceof UpdatePurchaseOrderLineInput);

        return $this->writer->updateLine(
            (string) ($uriVariables['id'] ?? ''),
            (string) ($uriVariables['lineId'] ?? ''),
            $data,
        );
    }
}
