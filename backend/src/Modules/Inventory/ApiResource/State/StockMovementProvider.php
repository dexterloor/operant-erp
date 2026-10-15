<?php

declare(strict_types=1);

namespace App\Modules\Inventory\ApiResource\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Modules\Inventory\ApiResource\StockMovementResource;
use App\Modules\Inventory\Application\StockMovementReader;
use App\Platform\Http\Problem\ApiProblem;

/**
 * `GetStockMovement`. The gate has already refused a caller without `stock_movement.view` (E-1). A non-uuid, absent or
 * another workspace's id is the same 404 (RLS).
 *
 * @implements ProviderInterface<StockMovementResource>
 */
final readonly class StockMovementProvider implements ProviderInterface
{
    public function __construct(private StockMovementReader $reader)
    {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): StockMovementResource
    {
        $view = $this->reader->get((string) ($uriVariables['id'] ?? '')) ?? throw ApiProblem::notFound();

        return StockMovementResource::fromView($view);
    }
}
