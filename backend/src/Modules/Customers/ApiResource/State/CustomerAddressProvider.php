<?php

declare(strict_types=1);

namespace App\Modules\Customers\ApiResource\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Modules\Customers\ApiResource\CustomerAddressResource;
use App\Modules\Customers\Application\CustomerAddressReader;
use App\Platform\Http\Problem\ApiProblem;

/**
 * `UpdateCustomerAddress`'s row load (`SalesOrderLineProvider`'s reason: without it, API Platform's default provider answers
 * an unknown id with a bare `NotFoundHttpException` rather than §5.2's `not-found` body). **The pair `(id, addressId)` names
 * the row**, so another customer's address is the same 404 as a random id. The writer re-reads the pair inside its
 * transaction.
 *
 * @implements ProviderInterface<CustomerAddressResource>
 */
final readonly class CustomerAddressProvider implements ProviderInterface
{
    public function __construct(private CustomerAddressReader $reader)
    {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): CustomerAddressResource
    {
        $view = $this->reader->get((string) ($uriVariables['id'] ?? ''), (string) ($uriVariables['addressId'] ?? ''))
            ?? throw ApiProblem::notFound();

        return CustomerAddressResource::fromView($view);
    }
}
