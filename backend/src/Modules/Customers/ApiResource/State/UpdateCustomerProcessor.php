<?php

declare(strict_types=1);

namespace App\Modules\Customers\ApiResource\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Modules\Customers\ApiResource\CustomerResource;
use App\Modules\Customers\ApiResource\UpdateCustomerInput;
use App\Modules\Customers\Application\CustomerWriter;
use App\Modules\Customers\Application\SharedNameSwitch;
use Symfony\Component\HttpFoundation\Request;

/**
 * `UpdateCustomer`: exactly the submitted fields to `CustomerWriter::update()`, the row read back as the 200 body. The
 * query's `allow_shared_name` (BK-47) is handed over raw, as a {@see SharedNameSwitch} — never a body field, so the PATCH
 * body stays a merge patch of the customer.
 *
 * @implements ProcessorInterface<UpdateCustomerInput, CustomerResource>
 */
final readonly class UpdateCustomerProcessor implements ProcessorInterface
{
    public function __construct(private CustomerWriter $writer)
    {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): CustomerResource
    {
        \assert($data instanceof UpdateCustomerInput);

        $request = $context['request'] ?? null;
        $switch = SharedNameSwitch::fromQuery($request instanceof Request ? ($request->query->all()[SharedNameSwitch::PARAMETER] ?? null) : null);

        return CustomerResource::fromView($this->writer->update((string) ($uriVariables['id'] ?? ''), $data->submitted(), $switch));
    }
}
