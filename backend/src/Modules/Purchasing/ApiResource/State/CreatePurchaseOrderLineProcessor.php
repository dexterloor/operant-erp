<?php

declare(strict_types=1);

namespace App\Modules\Purchasing\ApiResource\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Modules\Purchasing\ApiResource\CreatePurchaseOrderLineInput;
use App\Modules\Purchasing\ApiResource\PurchaseOrderLineResource;
use App\Modules\Purchasing\Application\PurchaseOrderWriter;
use App\Platform\Http\Idempotency\IdempotencyKeyGuard;
use Symfony\Component\HttpFoundation\Request;

/**
 * `CreatePurchaseOrderLine` — API-21 branch 4: §4.2 declares operations at
 * `/purchase-orders/{id}/lines/{lineId}`, which is the row-level URI of the row
 * this POST creates, so the answer is **201** with a `Location` naming it and the
 * created resource as the body.
 *
 * It reads the `Idempotency-Key` header and hands it to the writer, for
 * `CreatePurchaseOrderProcessor`'s reasons (A-11 keeps `Request` out of the
 * application layer; `IdempotencyKeyGuard` has already refused a missing or
 * over-long key on `kernel.controller`). V2-R-12, 2026-09-16.
 *
 * @implements ProcessorInterface<CreatePurchaseOrderLineInput, PurchaseOrderLineResource>
 */
final readonly class CreatePurchaseOrderLineProcessor implements ProcessorInterface
{
    public function __construct(private PurchaseOrderWriter $writer)
    {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): PurchaseOrderLineResource
    {
        \assert($data instanceof CreatePurchaseOrderLineInput);

        $request = $context['request'] ?? null;
        $key = $request instanceof Request
            ? (string) $request->headers->get(IdempotencyKeyGuard::HEADER)
            : '';

        return $this->writer->createLine((string) ($uriVariables['id'] ?? ''), $data, $key);
    }
}
