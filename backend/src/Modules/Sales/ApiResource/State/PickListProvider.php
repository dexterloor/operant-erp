<?php

declare(strict_types=1);

namespace App\Modules\Sales\ApiResource\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Modules\Sales\ApiResource\PickListResource;
use App\Modules\Sales\Application\PickListReader;
use App\Platform\Http\Problem\ApiProblem;

/**
 * `GetPickList`. An order this caller may not see as a pick list — another
 * workspace's, a draft, an archived one, one cancelled while a draft, or no
 * order at all — is the same not-found (E-2, §5.3).
 *
 * @implements ProviderInterface<PickListResource>
 */
final readonly class PickListProvider implements ProviderInterface
{
    public function __construct(private PickListReader $reader)
    {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): PickListResource
    {
        $view = $this->reader->get((string) ($uriVariables['id'] ?? '')) ?? throw ApiProblem::notFound();

        return PickListResource::fromView($view);
    }
}
