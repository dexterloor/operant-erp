<?php

declare(strict_types=1);

namespace App\Modules\Warehouses\ApiResource\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Modules\Warehouses\Application\StorageLocationWriter;

/**
 * `DeleteStorageLocation` (warehouse/location delete-restore C3) — API-12: `storage_location` is `SchemaManifest::SOFT`, so
 * this sets `deleted_at` (`StorageLocationWriter::delete()`, which refuses a location holding stock, owner Q-1, and any
 * location of an archived warehouse). API-21 branch 3: **204, and no body** (`DeleteSupplierProcessor`'s shape); the
 * archived location stays listed by `ListStorageLocations?archived=true` and is restored by `RestoreStorageLocation`.
 *
 * @implements ProcessorInterface<mixed, null>
 */
final readonly class DeleteStorageLocationProcessor implements ProcessorInterface
{
    public function __construct(private StorageLocationWriter $writer)
    {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): null
    {
        $this->writer->delete((string) ($uriVariables['id'] ?? ''), (string) ($uriVariables['locationId'] ?? ''));

        return null;
    }
}
