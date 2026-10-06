<?php

declare(strict_types=1);

namespace App\Modules\Sales\ApiResource\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Modules\Sales\ApiResource\CreditNoteResource;
use App\Modules\Sales\ApiResource\IssueAdjustmentCreditNoteInput;
use App\Modules\Sales\Application\CreditNoteWriter;
use App\Platform\Http\Idempotency\IdempotencyKeyGuard;
use Symfony\Component\HttpFoundation\Request;

/**
 * `IssueAdjustmentCreditNote` — hands the body and the `Idempotency-Key` header to `CreditNoteWriter::adjust()`, which
 * owns the whole transaction (design §4.4b).
 *
 * @implements ProcessorInterface<IssueAdjustmentCreditNoteInput, CreditNoteResource>
 */
final readonly class IssueAdjustmentCreditNoteProcessor implements ProcessorInterface
{
    public function __construct(private CreditNoteWriter $writer)
    {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): CreditNoteResource
    {
        \assert($data instanceof IssueAdjustmentCreditNoteInput);

        $request = $context['request'] ?? null;
        $key = $request instanceof Request
            ? (string) $request->headers->get(IdempotencyKeyGuard::HEADER)
            : '';

        return CreditNoteResource::fromView(
            $this->writer->adjust((string) ($uriVariables['id'] ?? ''), $data->reason, $data->lines, $data->acknowledged_return_ids, $key),
        );
    }
}
