<?php

declare(strict_types=1);

namespace App\Modules\Warehouses\ApiResource\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Modules\Warehouses\ApiResource\StorageLocationResource;
use App\Modules\Warehouses\Application\StorageLocationWriter;

/**
 * `RestoreStorageLocation` (warehouse/location delete-restore C2) — API-12a / ST-9. The permission is the one that archives
 * (`storage_location.delete`, §4.2), and the response is API-21 branch 5: 200 with the location in its **new** state
 * (`RestoreWarehouseProcessor`'s shape).
 *
 * @implements ProcessorInterface<mixed, StorageLocationResource>
 */
final readonly class RestoreStorageLocationProcessor implements ProcessorInterface
{
    public function __construct(private StorageLocationWriter $writer)
    {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): StorageLocationResource
    {
        return StorageLocationResource::fromView($this->writer->restore(
            (string) ($uriVariables['id'] ?? ''),
            (string) ($uriVariables['locationId'] ?? ''),
        ));
    }
}
