<?php

declare(strict_types=1);

namespace App\Modules\Warehouses\ApiResource\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Modules\Warehouses\ApiResource\WarehouseResource;
use App\Modules\Warehouses\Application\WarehouseWriter;

/**
 * `RestoreWarehouse` (warehouse/location delete-restore C1) — API-12a / ST-9. The permission is the one that archives
 * (`warehouse.delete`, §4.2), and the response is API-21 branch 5: 200 with the warehouse in its **new** state
 * (`RestoreSupplierProcessor`'s shape).
 *
 * @implements ProcessorInterface<mixed, WarehouseResource>
 */
final readonly class RestoreWarehouseProcessor implements ProcessorInterface
{
    public function __construct(private WarehouseWriter $writer)
    {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): WarehouseResource
    {
        return WarehouseResource::fromView($this->writer->restore((string) ($uriVariables['id'] ?? '')));
    }
}
