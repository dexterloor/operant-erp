<?php

declare(strict_types=1);

namespace App\Modules\Sales\ApiResource\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Modules\Sales\ApiResource\CreateSalesReturnInput;
use App\Modules\Sales\ApiResource\SalesReturnResource;
use App\Modules\Sales\Application\SalesReturnWriter;
use App\Platform\Http\Idempotency\IdempotencyKeyGuard;
use Symfony\Component\HttpFoundation\Request;

/**
 * `CreateSalesReturn`. Reads the `Idempotency-Key` header and hands it to the writer as a string — read here and not
 * in the writer because of A-11, as `CreateFulfilmentProcessor` explains.
 *
 * @implements ProcessorInterface<CreateSalesReturnInput, SalesReturnResource>
 */
final readonly class CreateSalesReturnProcessor implements ProcessorInterface
{
    public function __construct(private SalesReturnWriter $writer)
    {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): SalesReturnResource
    {
        \assert($data instanceof CreateSalesReturnInput);

        $request = $context['request'] ?? null;
        $key = $request instanceof Request
            ? (string) $request->headers->get(IdempotencyKeyGuard::HEADER)
            : '';

        return SalesReturnResource::fromView(
            $this->writer->create((string) ($uriVariables['id'] ?? ''), $data->returnedAt, $data->reason, $data->lines, $key),
        );
    }
}
