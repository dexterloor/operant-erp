<?php

declare(strict_types=1);

namespace App\Modules\Customers\ApiResource\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Modules\Customers\ApiResource\CustomerResource;
use App\Modules\Customers\Application\CustomerReader;
use App\Platform\Http\Problem\ApiProblem;

/**
 * `GetCustomer`. The gate has already refused a caller without `customer.view` (E-1). A non-uuid, absent or another
 * workspace's id is the same 404 (RLS); an archived customer is returned.
 *
 * @implements ProviderInterface<CustomerResource>
 */
final readonly class CustomerProvider implements ProviderInterface
{
    public function __construct(private CustomerReader $reader)
    {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): CustomerResource
    {
        $view = $this->reader->get((string) ($uriVariables['id'] ?? '')) ?? throw ApiProblem::notFound();

        return CustomerResource::fromView($view);
    }
}
