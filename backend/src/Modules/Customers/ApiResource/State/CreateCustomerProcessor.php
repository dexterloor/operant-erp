<?php

declare(strict_types=1);

namespace App\Modules\Customers\ApiResource\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Modules\Customers\ApiResource\CreateCustomerInput;
use App\Modules\Customers\ApiResource\CustomerResource;
use App\Modules\Customers\Application\CustomerWriter;

/**
 * `CreateCustomer`: the body's values to `CustomerWriter::create()`, its view back as the 201 body (`GetCustomer`'s).
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

        return CustomerResource::fromView($this->writer->create($data->submitted()));
    }
}
