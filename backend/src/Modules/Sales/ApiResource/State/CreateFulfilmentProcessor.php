<?php

declare(strict_types=1);

namespace App\Modules\Sales\ApiResource\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Modules\Sales\ApiResource\CreateFulfilmentInput;
use App\Modules\Sales\ApiResource\FulfilmentResource;
use App\Modules\Sales\Application\FulfilmentWriter;
use App\Platform\Http\Idempotency\IdempotencyKeyGuard;
use Symfony\Component\HttpFoundation\Request;

/**
 * `CreateFulfilment`. Reads the `Idempotency-Key` header, whose hash is this operation's retry key, and hands it
 * to the writer as a string — read here and not in the writer because of A-11, as `CreateGoodsReceiptProcessor`
 * explains. `IdempotencyKeyGuard` has already refused a missing or over-long header by the time this runs.
 *
 * @implements ProcessorInterface<CreateFulfilmentInput, FulfilmentResource>
 */
final readonly class CreateFulfilmentProcessor implements ProcessorInterface
{
    public function __construct(private FulfilmentWriter $writer)
    {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): FulfilmentResource
    {
        \assert($data instanceof CreateFulfilmentInput);

        $request = $context['request'] ?? null;
        $key = $request instanceof Request
            ? (string) $request->headers->get(IdempotencyKeyGuard::HEADER)
            : '';

        return FulfilmentResource::fromView(
            $this->writer->create((string) ($uriVariables['id'] ?? ''), $data->shippedAt, $data->lines, $key),
        );
    }
}
