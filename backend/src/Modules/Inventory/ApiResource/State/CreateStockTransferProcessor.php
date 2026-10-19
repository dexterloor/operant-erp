<?php

declare(strict_types=1);

namespace App\Modules\Inventory\ApiResource\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Modules\Inventory\ApiResource\CreateStockTransferInput;
use App\Modules\Inventory\ApiResource\StockMovementResource;
use App\Modules\Inventory\Application\Contract\TransferStock;
use App\Modules\Inventory\Application\StockMovementReader;
use App\Platform\Http\Idempotency\IdempotencyKeyGuard;
use Symfony\Component\HttpFoundation\Request;

/**
 * `CreateStockTransfer` — API-21 branch 4: 201, the OUT leg recorded (or, on a replay, the first request's) in §4.4.3's
 * `StockMovement` shape (API-23a), `Location` `/stock-movements/{id}`; its `source_id` names the in leg. The one registered
 * consumer of {@see TransferStock} (E-26, `WriteOwnershipTest::STOCK_WRITE_CONTRACTS`). Reads the `Idempotency-Key` header
 * and hands it on (A-11; `IdempotencyKeyGuard` already refused a missing one with 428).
 *
 * The body is read after the write's commit, by `GetStockMovement`'s own reader: PC-17 serves the write's read shape to the
 * write's holder, without `stock_movement.view` (`SEC` `stock-transfer-sec.md` §6: the in leg's id is then one that seat
 * cannot open — a notice, Low).
 *
 * @implements ProcessorInterface<CreateStockTransferInput, StockMovementResource>
 */
final readonly class CreateStockTransferProcessor implements ProcessorInterface
{
    public function __construct(
        private TransferStock $transfers,
        private StockMovementReader $reader,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): StockMovementResource
    {
        \assert($data instanceof CreateStockTransferInput);

        $request = $context['request'] ?? null;
        $key = $request instanceof Request ? (string) $request->headers->get(IdempotencyKeyGuard::HEADER) : '';

        $id = $this->transfers->transfer($data->submitted(), $key);

        return StockMovementResource::fromView($this->reader->get($id)
            ?? throw new \LogicException('The movement just recorded is not readable.'));
    }
}
