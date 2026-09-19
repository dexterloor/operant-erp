<?php

declare(strict_types=1);

namespace App\Modules\Sales\ApiResource\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Modules\Sales\ApiResource\SalesOrderResource;
use App\Modules\Sales\Application\SalesOrderConfirmation;

/**
 * `ConfirmSalesOrder` — API-21 branch 5: 200 with the resource in its **new** state and no `Location`,
 * because this transition creates no row.
 *
 * There is nothing here but the delegation, and that is deliberate, for
 * `ApprovePurchaseOrderProcessor`'s reason: the confirmation's steps are one sequence inside one
 * transaction, and a processor that did any part of it — a row load, a line count — would put a step of
 * a fixed order outside the transaction the rest of it runs in.
 *
 * **It takes no `$data`**, and that is §4.2's cell rather than a simplification: `ConfirmSalesOrder`'s
 * `Accepted request fields` is `—`, so the operation declares `input: false` and `deserialize: false`
 * and `BodylessWriteRefusal` answers a caller who sends one.
 *
 * @implements ProcessorInterface<mixed, SalesOrderResource>
 */
final readonly class ConfirmSalesOrderProcessor implements ProcessorInterface
{
    public function __construct(private SalesOrderConfirmation $confirmation)
    {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): SalesOrderResource
    {
        return $this->confirmation->confirm((string) ($uriVariables['id'] ?? ''));
    }
}
