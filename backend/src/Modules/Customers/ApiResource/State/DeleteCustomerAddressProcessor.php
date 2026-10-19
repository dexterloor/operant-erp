<?php

declare(strict_types=1);

namespace App\Modules\Customers\ApiResource\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Modules\Customers\Application\CustomerAddressWriter;

/**
 * `DeleteCustomerAddress` — `DELETE /api/v1/customers/{id}/addresses/{addressId}` (customers slice 2 C3). API-21 branch 3:
 * **204, and no body**. `customer_address` is `SchemaManifest::HARD`, so this is a real `DELETE`. Declared `read: false`
 * (`DeleteSupplierProductProcessor`'s reason): nothing is read outside the writer's transaction.
 *
 * @implements ProcessorInterface<mixed, null>
 */
final readonly class DeleteCustomerAddressProcessor implements ProcessorInterface
{
    public function __construct(private CustomerAddressWriter $writer)
    {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): null
    {
        $this->writer->delete(
            (string) ($uriVariables['id'] ?? ''),
            (string) ($uriVariables['addressId'] ?? ''),
        );

        return null;
    }
}
