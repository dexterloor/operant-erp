<?php

declare(strict_types=1);

namespace App\Modules\Warehouses\ApiResource\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Modules\Warehouses\ApiResource\WarehouseResource;
use App\Modules\Warehouses\Application\WarehouseReader;
use App\Platform\Http\Problem\ApiProblem;

/**
 * `GetWarehouse`. The gate has already refused a caller without `warehouse.view` (E-1). A non-uuid, absent or another
 * workspace's id is the same 404 (RLS); an archived warehouse is returned.
 *
 * @implements ProviderInterface<WarehouseResource>
 */
final readonly class WarehouseProvider implements ProviderInterface
{
    public function __construct(private WarehouseReader $reader)
    {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): WarehouseResource
    {
        $view = $this->reader->get((string) ($uriVariables['id'] ?? '')) ?? throw ApiProblem::notFound();

        return WarehouseResource::fromView($view);
    }
}
