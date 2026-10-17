<?php

declare(strict_types=1);

namespace App\Modules\Inventory\ApiResource\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Modules\Inventory\ApiResource\CreateStockAdjustmentInput;
use App\Modules\Inventory\ApiResource\StockMovementResource;
use App\Modules\Inventory\Application\Contract\AdjustStock;
use App\Modules\Inventory\Application\StockMovementReader;
use App\Platform\Http\Idempotency\IdempotencyKeyGuard;
use Symfony\Component\HttpFoundation\Request;

/**
 * `CreateStockAdjustment` — API-21 branch 4: 201, the movement recorded (or, on a replay, the first request's) in
 * §4.4.3's `StockMovement` shape (API-23a), `Location` `/stock-movements/{id}`. The one registered consumer of
 * {@see AdjustStock} (E-26, `WriteOwnershipTest::STOCK_WRITE_CONTRACTS`). Reads the `Idempotency-Key` header and hands it
 * on (A-11 keeps `Request` out of the application layer; `IdempotencyKeyGuard` already refused a missing one with 428).
 *
 * The body is read after the write's commit, by `GetStockMovement`'s own reader: PC-17 serves the write's read shape to
 * the write's holder, without `stock_movement.view` (`SEC` §4).
 *
 * @implements ProcessorInterface<CreateStockAdjustmentInput, StockMovementResource>
 */
final readonly class CreateStockAdjustmentProcessor implements ProcessorInterface
{
    public function __construct(
        private AdjustStock $adjustments,
        private StockMovementReader $reader,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): StockMovementResource
    {
        \assert($data instanceof CreateStockAdjustmentInput);

        $request = $context['request'] ?? null;
        $key = $request instanceof Request ? (string) $request->headers->get(IdempotencyKeyGuard::HEADER) : '';

        $id = $this->adjustments->adjust($data->submitted(), $key);

        return StockMovementResource::fromView($this->reader->get($id)
            ?? throw new \LogicException('The movement just recorded is not readable.'));
    }
}
