<?php

declare(strict_types=1);

namespace App\Modules\Customers\ApiResource\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Modules\Customers\ApiResource\CustomerAddressCollection;
use App\Modules\Customers\ApiResource\CustomerAddressResource;
use App\Modules\Customers\Application\CustomerAddressReader;
use App\Platform\Http\Problem\ApiProblem;

/**
 * `ListCustomerAddresses`. The gate has already refused a caller without `customer.view` (E-1). The operation declares no
 * query parameter, so any parameter is refused before this class runs (`UndeclaredQueryParameterRefusal`).
 *
 * @implements ProviderInterface<CustomerAddressCollection>
 */
final readonly class CustomerAddressCollectionProvider implements ProviderInterface
{
    public function __construct(private CustomerAddressReader $reader)
    {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): CustomerAddressCollection
    {
        $addresses = $this->reader->list((string) ($uriVariables['id'] ?? '')) ?? throw ApiProblem::notFound();

        return new CustomerAddressCollection(
            items: array_map(CustomerAddressResource::fromView(...), $addresses),
            // §4.4.2: a bounded list carries both cursors null always.
            nextCursor: null,
            previousCursor: null,
            // API-22: probed only when the set is empty, against the resource's table with no filter predicate.
            tenantHasRows: [] === $addresses ? $this->reader->tenantHasRows() : true,
        );
    }
}
