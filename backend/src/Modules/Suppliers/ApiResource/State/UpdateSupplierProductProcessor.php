<?php

declare(strict_types=1);

namespace App\Modules\Suppliers\ApiResource\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Modules\Suppliers\ApiResource\SupplierProductResource;
use App\Modules\Suppliers\ApiResource\UpdateSupplierProductInput;
use App\Modules\Suppliers\Application\SupplierProductWriter;

/**
 * `UpdateSupplierProduct` — API-21 branch 2: 200 with the entry read back. Exactly the submitted fields reach the writer.
 *
 * @implements ProcessorInterface<UpdateSupplierProductInput, SupplierProductResource>
 */
final readonly class UpdateSupplierProductProcessor implements ProcessorInterface
{
    public function __construct(private SupplierProductWriter $writer)
    {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): SupplierProductResource
    {
        \assert($data instanceof UpdateSupplierProductInput);

        return SupplierProductResource::fromView($this->writer->update(
            (string) ($uriVariables['id'] ?? ''),
            (string) ($uriVariables['productId'] ?? ''),
            $data->submitted(),
        ));
    }
}
