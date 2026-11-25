<?php

declare(strict_types=1);

namespace App\Modules\Customers\ApiResource\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Modules\Customers\ApiResource\CreateCustomerInput;
use App\Modules\Customers\ApiResource\CustomerResource;
use App\Modules\Customers\Application\CustomerWriter;
use App\Modules\Customers\Application\SharedNameSwitch;
use Symfony\Component\HttpFoundation\Request;

/**
 * `CreateCustomer`: the body's values to `CustomerWriter::create()`, its view back as the 201 body (`GetCustomer`'s). The
 * query's `allow_shared_name` (BK-47) is handed over raw, as a {@see SharedNameSwitch}: the writer refuses a malformed one in
 * the same 422 as the body's field errors.
 *
 * @implements ProcessorInterface<CreateCustomerInput, CustomerResource>
 */
final readonly class CreateCustomerProcessor implements ProcessorInterface
{
    public function __construct(private CustomerWriter $writer)
    {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): CustomerResource
    {
        \assert($data instanceof CreateCustomerInput);

        $request = $context['request'] ?? null;
        $switch = SharedNameSwitch::fromQuery($request instanceof Request ? ($request->query->all()[SharedNameSwitch::PARAMETER] ?? null) : null);

        return CustomerResource::fromView($this->writer->create($data->submitted(), $switch));
    }
}
