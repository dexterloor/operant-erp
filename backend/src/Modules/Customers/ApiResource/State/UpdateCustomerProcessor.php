<?php

declare(strict_types=1);

namespace App\Modules\Customers\ApiResource\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Modules\Customers\ApiResource\CustomerResource;
use App\Modules\Customers\ApiResource\UpdateCustomerInput;
use App\Modules\Customers\Application\CustomerWriter;

/**
 * `UpdateCustomer`: exactly the submitted fields to `CustomerWriter::update()`, the row read back as the 200 body.
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

        return CustomerResource::fromView($this->writer->update((string) ($uriVariables['id'] ?? ''), $data->submitted()));
    }
}
