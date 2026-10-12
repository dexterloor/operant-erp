<?php

declare(strict_types=1);

namespace App\Modules\Warehouses\ApiResource\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Modules\Warehouses\ApiResource\StorageLocationCollection;
use App\Modules\Warehouses\ApiResource\StorageLocationResource;
use App\Modules\Warehouses\Application\StorageLocationReader;
use App\Platform\Http\Problem\ApiProblem;

/**
 * `ListStorageLocations`. The gate has already refused a caller without `storage_location.view` (E-1). One parameter,
 * `archived`; anything else is refused before this class runs (`UndeclaredQueryParameterRefusal`). An unknown or foreign
 * warehouse is a 404.
 *
 * @implements ProviderInterface<StorageLocationCollection>
 */
final readonly class StorageLocationCollectionProvider implements ProviderInterface
{
    public function __construct(private StorageLocationReader $reader)
    {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): StorageLocationCollection
    {
        $read = $this->reader->list((string) ($uriVariables['id'] ?? ''), WarehouseCollectionProvider::archived($context))
            ?? throw ApiProblem::notFound();

        return new StorageLocationCollection(
            items: array_map(StorageLocationResource::fromView(...), $read['items']),
            // §4.4.2: a bounded list carries both cursors null always.
            nextCursor: null,
            previousCursor: null,
            tenantHasRows: $read['tenantHasRows'],
        );
    }
}
