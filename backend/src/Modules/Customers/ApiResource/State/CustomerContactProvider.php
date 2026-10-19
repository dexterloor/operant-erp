<?php

declare(strict_types=1);

namespace App\Modules\Customers\ApiResource\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Modules\Customers\ApiResource\CustomerContactResource;
use App\Modules\Customers\Application\CustomerContactReader;
use App\Platform\Http\Problem\ApiProblem;

/**
 * `UpdateCustomerContact`'s row load (`SalesOrderLineProvider`'s reason: without it, API Platform's default provider answers
 * an unknown id with a bare `NotFoundHttpException` rather than §5.2's `not-found` body). **The pair `(id, contactId)` names
 * the row**, so another customer's contact is the same 404 as a random id. The writer re-reads the pair inside its
 * transaction.
 *
 * @implements ProviderInterface<CustomerContactResource>
 */
final readonly class CustomerContactProvider implements ProviderInterface
{
    public function __construct(private CustomerContactReader $reader)
    {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): CustomerContactResource
    {
        $view = $this->reader->get((string) ($uriVariables['id'] ?? ''), (string) ($uriVariables['contactId'] ?? ''))
            ?? throw ApiProblem::notFound();

        return CustomerContactResource::fromView($view);
    }
}
