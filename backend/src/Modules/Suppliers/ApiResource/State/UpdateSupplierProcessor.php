<?php

declare(strict_types=1);

namespace App\Modules\Suppliers\ApiResource\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Modules\Suppliers\ApiResource\SupplierResource;
use App\Modules\Suppliers\ApiResource\UpdateSupplierInput;
use App\Modules\Suppliers\Application\SupplierWriter;

/**
 * `UpdateSupplier`: exactly the submitted fields to `SupplierWriter::update()`, the row read back as the 200 body.
 *
 * @implements ProcessorInterface<UpdateSupplierInput, SupplierResource>
 */
final readonly class UpdateSupplierProcessor implements ProcessorInterface
{
    public function __construct(private SupplierWriter $writer)
    {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): SupplierResource
    {
        \assert($data instanceof UpdateSupplierInput);

        return SupplierResource::fromView($this->writer->update((string) ($uriVariables['id'] ?? ''), $data->submitted()));
    }
}
