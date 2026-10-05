<?php

declare(strict_types=1);

namespace App\Modules\Sales\ApiResource\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Modules\Sales\ApiResource\CreateAdjustmentDecisionInput;
use App\Modules\Sales\ApiResource\SalesReturnResource;
use App\Modules\Sales\Application\AdjustmentDecisionWriter;
use App\Platform\Http\Idempotency\IdempotencyKeyGuard;
use Symfony\Component\HttpFoundation\Request;

/**
 * `CreateAdjustmentDecision` — `POST /api/v1/sales-returns/{id}/adjustment-decisions` (G-29 / G-30 part 3, C1). API-21
 * branch 5: **200**, the return as it now reads. Nothing here but the delegation: the decision is one ordered sequence in
 * one locked transaction (`AdjustmentDecisionWriter`).
 *
 * @implements ProcessorInterface<CreateAdjustmentDecisionInput, SalesReturnResource>
 */
final readonly class CreateAdjustmentDecisionProcessor implements ProcessorInterface
{
    public function __construct(private AdjustmentDecisionWriter $writer)
    {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): SalesReturnResource
    {
        \assert($data instanceof CreateAdjustmentDecisionInput);

        $request = $context['request'] ?? null;
        $key = $request instanceof Request
            ? (string) $request->headers->get(IdempotencyKeyGuard::HEADER)
            : '';

        return SalesReturnResource::fromView($this->writer->decide(
            (string) ($uriVariables['id'] ?? ''),
            $data->kind,
            $data->salesReturnLineId,
            $data->creditNoteLineId,
            $data->quantity,
            $data->reversesDecisionId,
            $data->acknowledgedReturnIds,
            $key,
        ));
    }
}
