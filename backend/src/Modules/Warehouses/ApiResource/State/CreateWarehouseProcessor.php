<?php

declare(strict_types=1);

namespace App\Modules\Warehouses\ApiResource\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Modules\Warehouses\ApiResource\CreateWarehouseInput;
use App\Modules\Warehouses\ApiResource\WarehouseResource;
use App\Modules\Warehouses\Application\WarehouseWriter;

/**
 * `CreateWarehouse`: the body's values to `WarehouseWriter::create()`, its view back as the 201 body (`GetWarehouse`'s).
 *
 * @implements ProcessorInterface<CreateWarehouseInput, WarehouseResource>
 */
final readonly class CreateWarehouseProcessor implements ProcessorInterface
{
    public function __construct(private WarehouseWriter $writer)
    {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): WarehouseResource
    {
        \assert($data instanceof CreateWarehouseInput);

        return WarehouseResource::fromView($this->writer->create($data->submitted()));
    }
}
