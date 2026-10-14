<?php

declare(strict_types=1);

namespace App\Modules\Customers\ApiResource\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Modules\Customers\Application\CustomerWriter;

/**
 * `DeleteCustomer` (C5 C4b) — API-12: `customer` is `SchemaManifest::SOFT`, so this sets `deleted_at`
 * (`CustomerWriter::delete()`, which refuses a customer with open work, ruling Q-1 (b′)). API-21 branch 3: **204, and no
 * body** (`DeleteSupplierProcessor`'s shape); the archived customer stays readable by `GetCustomer` and listed by
 * `ListCustomers?archived=true`.
 *
 * @implements ProcessorInterface<mixed, null>
 */
final readonly class DeleteCustomerProcessor implements ProcessorInterface
{
    public function __construct(private CustomerWriter $writer)
    {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): null
    {
        $this->writer->delete((string) ($uriVariables['id'] ?? ''));

        return null;
    }
}
