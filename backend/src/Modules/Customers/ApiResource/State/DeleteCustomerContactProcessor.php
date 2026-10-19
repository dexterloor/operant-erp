<?php

declare(strict_types=1);

namespace App\Modules\Customers\ApiResource\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Modules\Customers\Application\CustomerContactWriter;

/**
 * `DeleteCustomerContact` — `DELETE /api/v1/customers/{id}/contacts/{contactId}` (customers slice 2 C2). API-21 branch 3:
 * **204, and no body**. `customer_contact` is `SchemaManifest::HARD`, so this is a real `DELETE`. Declared `read: false`
 * (`DeleteSupplierProductProcessor`'s reason): nothing is read outside the writer's transaction.
 *
 * @implements ProcessorInterface<mixed, null>
 */
final readonly class DeleteCustomerContactProcessor implements ProcessorInterface
{
    public function __construct(private CustomerContactWriter $writer)
    {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): null
    {
        $this->writer->delete(
            (string) ($uriVariables['id'] ?? ''),
            (string) ($uriVariables['contactId'] ?? ''),
        );

        return null;
    }
}
