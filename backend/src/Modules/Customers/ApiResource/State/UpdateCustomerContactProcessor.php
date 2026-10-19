<?php

declare(strict_types=1);

namespace App\Modules\Customers\ApiResource\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Modules\Customers\ApiResource\CustomerContactResource;
use App\Modules\Customers\ApiResource\UpdateCustomerContactInput;
use App\Modules\Customers\Application\CustomerContactWriter;

/**
 * `UpdateCustomerContact` — API-21 branch 2: 200 with the contact read back. Exactly the submitted fields reach the writer.
 *
 * @implements ProcessorInterface<UpdateCustomerContactInput, CustomerContactResource>
 */
final readonly class UpdateCustomerContactProcessor implements ProcessorInterface
{
    public function __construct(private CustomerContactWriter $writer)
    {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): CustomerContactResource
    {
        \assert($data instanceof UpdateCustomerContactInput);

        return CustomerContactResource::fromView($this->writer->update(
            (string) ($uriVariables['id'] ?? ''),
            (string) ($uriVariables['contactId'] ?? ''),
            $data->submitted(),
        ));
    }
}
