<?php

declare(strict_types=1);

namespace App\Modules\Customers\ApiResource\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Modules\Customers\ApiResource\CustomerAddressResource;
use App\Modules\Customers\ApiResource\UpdateCustomerAddressInput;
use App\Modules\Customers\Application\CustomerAddressWriter;

/**
 * `UpdateCustomerAddress` — API-21 branch 2: 200 with the address read back. Exactly the submitted fields reach the writer.
 *
 * @implements ProcessorInterface<UpdateCustomerAddressInput, CustomerAddressResource>
 */
final readonly class UpdateCustomerAddressProcessor implements ProcessorInterface
{
    public function __construct(private CustomerAddressWriter $writer)
    {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): CustomerAddressResource
    {
        \assert($data instanceof UpdateCustomerAddressInput);

        return CustomerAddressResource::fromView($this->writer->update(
            (string) ($uriVariables['id'] ?? ''),
            (string) ($uriVariables['addressId'] ?? ''),
            $data->submitted(),
        ));
    }
}
