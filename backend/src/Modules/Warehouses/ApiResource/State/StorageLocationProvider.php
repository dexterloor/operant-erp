<?php

declare(strict_types=1);

namespace App\Modules\Warehouses\ApiResource\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Modules\Warehouses\ApiResource\StorageLocationResource;
use App\Modules\Warehouses\Application\StorageLocationReader;
use App\Platform\Http\Problem\ApiProblem;

/**
 * `UpdateStorageLocation`'s row load (`SupplierProductProvider`'s reason: without it API Platform's default provider answers
 * an unknown id with a bare `NotFoundHttpException` rather than §5.2's `not-found` body). **The pair `(id, locationId)` names
 * the row**, so another warehouse's location is the same 404 as a random id. The writer re-reads the pair, locked, inside
 * its transaction.
 *
 * @implements ProviderInterface<StorageLocationResource>
 */
final readonly class StorageLocationProvider implements ProviderInterface
{
    public function __construct(private StorageLocationReader $reader)
    {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): StorageLocationResource
    {
        $view = $this->reader->get((string) ($uriVariables['id'] ?? ''), (string) ($uriVariables['locationId'] ?? ''))
            ?? throw ApiProblem::notFound();

        return StorageLocationResource::fromView($view);
    }
}
