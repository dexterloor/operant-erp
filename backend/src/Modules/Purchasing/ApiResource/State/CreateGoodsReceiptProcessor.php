<?php

declare(strict_types=1);

namespace App\Modules\Purchasing\ApiResource\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Modules\Purchasing\ApiResource\CreateGoodsReceiptInput;
use App\Modules\Purchasing\ApiResource\GoodsReceiptResource;
use App\Modules\Purchasing\Application\GoodsReceiptWriter;
use App\Platform\Http\Idempotency\IdempotencyKeyGuard;
use Symfony\Component\HttpFoundation\Request;

/**
 * `CreateGoodsReceipt`. Reads the `Idempotency-Key` header, whose hash is this
 * operation's retry key, and hands it to the writer as a string — the header is
 * read here and not in the writer because of A-11, exactly as
 * `CreatePurchaseOrderProcessor` explains. `IdempotencyKeyGuard` has already
 * refused a missing or over-long header by the time this runs.
 *
 * @implements ProcessorInterface<CreateGoodsReceiptInput, GoodsReceiptResource>
 */
final readonly class CreateGoodsReceiptProcessor implements ProcessorInterface
{
    public function __construct(private GoodsReceiptWriter $writer)
    {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): GoodsReceiptResource
    {
        \assert($data instanceof CreateGoodsReceiptInput);

        $request = $context['request'] ?? null;
        $key = $request instanceof Request
            ? (string) $request->headers->get(IdempotencyKeyGuard::HEADER)
            : '';

        return GoodsReceiptResource::fromView(
            $this->writer->create((string) ($uriVariables['id'] ?? ''), $data->receivedAt, $data->lines, $key),
        );
    }
}
