<?php

declare(strict_types=1);

namespace App\Modules\Sales\ApiResource\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Modules\Sales\ApiResource\CreateSalesOrderLineInput;
use App\Modules\Sales\ApiResource\SalesOrderLineResource;
use App\Modules\Sales\Application\SalesOrderWriter;
use App\Platform\Http\Idempotency\IdempotencyKeyGuard;
use Symfony\Component\HttpFoundation\Request;

/**
 * `CreateSalesOrderLine` — API-21 branch 4: §4.2 declares operations at
 * `/sales-orders/{id}/lines/{lineId}`, which is the row-level URI of the row this POST creates, so the
 * answer is **201** with a `Location` naming it and the created resource as the body.
 *
 * It reads the `Idempotency-Key` header and hands it to the writer, for `CreateSalesOrderProcessor`'s
 * reasons (A-11 keeps `Request` out of the application layer; `IdempotencyKeyGuard` has already refused a
 * missing or over-long key on `kernel.controller`). V2-R-12, 2026-09-21.
 *
 * @implements ProcessorInterface<CreateSalesOrderLineInput, SalesOrderLineResource>
 */
final readonly class CreateSalesOrderLineProcessor implements ProcessorInterface
{
    public function __construct(private SalesOrderWriter $writer)
    {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): SalesOrderLineResource
    {
        \assert($data instanceof CreateSalesOrderLineInput);

        $request = $context['request'] ?? null;
        $key = $request instanceof Request
            ? (string) $request->headers->get(IdempotencyKeyGuard::HEADER)
            : '';

        return $this->writer->createLine((string) ($uriVariables['id'] ?? ''), $data, $key);
    }
}
