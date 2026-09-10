<?php

declare(strict_types=1);

namespace App\Modules\Purchasing\ApiResource\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Modules\Purchasing\ApiResource\PurchaseOrderResource;
use App\Modules\Purchasing\ApiResource\UpdatePurchaseOrderInput;
use App\Modules\Purchasing\Application\PurchaseOrderWriter;

/**
 * `UpdatePurchaseOrder` — API-21 branch 2: 200 with the updated resource.
 *
 * @implements ProcessorInterface<UpdatePurchaseOrderInput, PurchaseOrderResource>
 */
final readonly class UpdatePurchaseOrderProcessor implements ProcessorInterface
{
    public function __construct(private PurchaseOrderWriter $writer)
    {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): PurchaseOrderResource
    {
        \assert($data instanceof UpdatePurchaseOrderInput);

        return $this->writer->update((string) ($uriVariables['id'] ?? ''), $data);
    }
}
