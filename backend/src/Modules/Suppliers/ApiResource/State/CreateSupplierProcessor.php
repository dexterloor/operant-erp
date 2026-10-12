<?php

declare(strict_types=1);

namespace App\Modules\Suppliers\ApiResource\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Modules\Suppliers\ApiResource\CreateSupplierInput;
use App\Modules\Suppliers\ApiResource\SupplierResource;
use App\Modules\Suppliers\Application\SupplierWriter;

/**
 * `CreateSupplier`: the body's values to `SupplierWriter::create()`, its view back as the 201 body (`GetSupplier`'s).
 *
 * @implements ProcessorInterface<CreateSupplierInput, SupplierResource>
 */
final readonly class CreateSupplierProcessor implements ProcessorInterface
{
    public function __construct(private SupplierWriter $writer)
    {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): SupplierResource
    {
        \assert($data instanceof CreateSupplierInput);

        return SupplierResource::fromView($this->writer->create($data->submitted()));
    }
}
