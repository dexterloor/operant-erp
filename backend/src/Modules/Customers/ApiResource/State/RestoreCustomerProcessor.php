<?php

declare(strict_types=1);

namespace App\Modules\Customers\ApiResource\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Modules\Customers\ApiResource\CustomerResource;
use App\Modules\Customers\Application\CustomerWriter;
use App\Modules\Customers\Application\SharedNameSwitch;
use Symfony\Component\HttpFoundation\Request;

/**
 * `RestoreCustomer` (C5 C4a) — API-12a / ST-9. The permission is the one that archives (`customer.delete`, §4.2), and the
 * response is API-21 branch 5: 200 with the customer in its **new** state (`RestoreSupplierProcessor`'s shape). The operation
 * stays body-less (BK-47): the only input is the query's `allow_shared_name`, handed over raw as a {@see SharedNameSwitch}.
 *
 * @implements ProcessorInterface<mixed, CustomerResource>
 */
final readonly class RestoreCustomerProcessor implements ProcessorInterface
{
    public function __construct(private CustomerWriter $writer)
    {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): CustomerResource
    {
        $request = $context['request'] ?? null;
        $switch = SharedNameSwitch::fromQuery($request instanceof Request ? ($request->query->all()[SharedNameSwitch::PARAMETER] ?? null) : null);

        return CustomerResource::fromView($this->writer->restore((string) ($uriVariables['id'] ?? ''), $switch));
    }
}
