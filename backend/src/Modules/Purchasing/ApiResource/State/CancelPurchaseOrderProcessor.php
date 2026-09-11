<?php

declare(strict_types=1);

namespace App\Modules\Purchasing\ApiResource\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Modules\Purchasing\ApiResource\CancelPurchaseOrderInput;
use App\Modules\Purchasing\ApiResource\PurchaseOrderResource;
use App\Modules\Purchasing\Application\PurchaseOrderCancellation;

/**
 * `CancelPurchaseOrder` — API-21 branch 5: 200 with the resource in its **new**
 * state and no `Location`, because this transition creates no row.
 *
 * There is nothing here but the delegation, and that is deliberate, for
 * `ApprovePurchaseOrderProcessor`'s reason: E-19 fixes a four-step order that
 * runs inside one transaction, and a processor that did any part of it — a row
 * load, a DSoD-1 comparison — would put a step of a fixed order outside the
 * transaction the rest of it runs in.
 *
 * @implements ProcessorInterface<CancelPurchaseOrderInput, PurchaseOrderResource>
 */
final readonly class CancelPurchaseOrderProcessor implements ProcessorInterface
{
    public function __construct(private PurchaseOrderCancellation $cancellation)
    {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): PurchaseOrderResource
    {
        \assert($data instanceof CancelPurchaseOrderInput);

        return $this->cancellation->cancel((string) ($uriVariables['id'] ?? ''), $data);
    }
}
