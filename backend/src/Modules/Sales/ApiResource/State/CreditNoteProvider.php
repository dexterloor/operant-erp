<?php

declare(strict_types=1);

namespace App\Modules\Sales\ApiResource\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Modules\Sales\ApiResource\CreditNoteResource;
use App\Modules\Sales\Application\CreditNoteReader;
use App\Platform\Http\Problem\ApiProblem;

/**
 * `GetCreditNote` — another workspace's credit note and a random id are the same 404 (E-2 / ST-5).
 *
 * @implements ProviderInterface<CreditNoteResource>
 */
final readonly class CreditNoteProvider implements ProviderInterface
{
    public function __construct(private CreditNoteReader $reader)
    {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): CreditNoteResource
    {
        $view = $this->reader->get((string) ($uriVariables['id'] ?? '')) ?? throw ApiProblem::notFound();

        return CreditNoteResource::fromView($view);
    }
}
