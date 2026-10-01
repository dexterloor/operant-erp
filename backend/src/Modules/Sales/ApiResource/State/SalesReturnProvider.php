<?php

declare(strict_types=1);

namespace App\Modules\Sales\ApiResource\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Modules\Sales\ApiResource\SalesReturnResource;
use App\Modules\Sales\Application\SalesReturnReader;
use App\Platform\Http\Problem\ApiProblem;

/**
 * `GetSalesReturn`. A return this caller cannot see — another workspace's, or none — is not-found.
 *
 * @implements ProviderInterface<SalesReturnResource>
 */
final readonly class SalesReturnProvider implements ProviderInterface
{
    public function __construct(private SalesReturnReader $reader)
    {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): SalesReturnResource
    {
        $view = $this->reader->get((string) ($uriVariables['id'] ?? '')) ?? throw ApiProblem::notFound();

        return SalesReturnResource::fromView($view);
    }
}
