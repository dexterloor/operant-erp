<?php

declare(strict_types=1);

namespace App\Modules\Sales\ApiResource\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Modules\Sales\ApiResource\CancelSalesOrderInput;
use App\Modules\Sales\ApiResource\SalesOrderResource;
use App\Modules\Sales\Application\SalesOrderCancellation;

/**
 * `CancelSalesOrder` — API-21 branch 5: 200 with the resource in its **new**
 * state and no `Location`, because this transition creates no row.
 *
 * There is nothing here but the delegation, and that is deliberate, for
 * `ConfirmSalesOrderProcessor`'s and `CancelPurchaseOrderProcessor`'s reason: the
 * steps run inside one transaction in a fixed order, and a processor that did any
 * part of it — a row load, a status read — would put a step of that order outside
 * the transaction the rest of it runs in.
 *
 * @implements ProcessorInterface<CancelSalesOrderInput, SalesOrderResource>
 */
final readonly class CancelSalesOrderProcessor implements ProcessorInterface
{
    public function __construct(private SalesOrderCancellation $cancellation)
    {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): SalesOrderResource
    {
        \assert($data instanceof CancelSalesOrderInput);

        return $this->cancellation->cancel((string) ($uriVariables['id'] ?? ''), $data);
    }
}
