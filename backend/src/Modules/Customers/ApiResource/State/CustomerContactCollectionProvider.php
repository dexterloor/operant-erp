<?php

declare(strict_types=1);

namespace App\Modules\Customers\ApiResource\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Modules\Customers\ApiResource\CustomerContactCollection;
use App\Modules\Customers\ApiResource\CustomerContactResource;
use App\Modules\Customers\Application\CustomerContactReader;
use App\Platform\Http\Problem\ApiProblem;

/**
 * `ListCustomerContacts`. The gate has already refused a caller without `customer.view` (E-1). The operation declares no
 * query parameter, so any parameter is refused before this class runs (`UndeclaredQueryParameterRefusal`).
 *
 * @implements ProviderInterface<CustomerContactCollection>
 */
final readonly class CustomerContactCollectionProvider implements ProviderInterface
{
    public function __construct(private CustomerContactReader $reader)
    {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): CustomerContactCollection
    {
        $contacts = $this->reader->list((string) ($uriVariables['id'] ?? '')) ?? throw ApiProblem::notFound();

        return new CustomerContactCollection(
            items: array_map(CustomerContactResource::fromView(...), $contacts),
            // §4.4.2: a bounded list carries both cursors null always.
            nextCursor: null,
            previousCursor: null,
            // API-22: probed only when the set is empty, against the resource's table with no filter predicate.
            tenantHasRows: [] === $contacts ? $this->reader->tenantHasRows() : true,
        );
    }
}
