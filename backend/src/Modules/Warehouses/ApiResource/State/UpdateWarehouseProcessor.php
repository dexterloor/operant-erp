<?php

declare(strict_types=1);

namespace App\Modules\Warehouses\ApiResource\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Modules\Warehouses\ApiResource\UpdateWarehouseInput;
use App\Modules\Warehouses\ApiResource\WarehouseResource;
use App\Modules\Warehouses\Application\WarehouseWriter;

/**
 * `UpdateWarehouse`: exactly the submitted fields to `WarehouseWriter::update()`, the row read back as the 200 body.
 *
 * @implements ProcessorInterface<UpdateWarehouseInput, WarehouseResource>
 */
final readonly class UpdateWarehouseProcessor implements ProcessorInterface
{
    public function __construct(private WarehouseWriter $writer)
    {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): WarehouseResource
    {
        \assert($data instanceof UpdateWarehouseInput);

        return WarehouseResource::fromView($this->writer->update((string) ($uriVariables['id'] ?? ''), $data->submitted()));
    }
}
