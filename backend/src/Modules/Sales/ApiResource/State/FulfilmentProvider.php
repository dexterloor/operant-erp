<?php

declare(strict_types=1);

namespace App\Modules\Sales\ApiResource\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Modules\Sales\ApiResource\FulfilmentResource;
use App\Modules\Sales\Application\FulfilmentReader;
use App\Platform\Http\Problem\ApiProblem;

/**
 * `GetFulfilment`. A fulfilment this caller cannot see — another workspace's, or none — is not-found.
 *
 * @implements ProviderInterface<FulfilmentResource>
 */
final readonly class FulfilmentProvider implements ProviderInterface
{
    public function __construct(private FulfilmentReader $reader)
    {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): FulfilmentResource
    {
        $view = $this->reader->get((string) ($uriVariables['id'] ?? '')) ?? throw ApiProblem::notFound();

        return FulfilmentResource::fromView($view);
    }
}
