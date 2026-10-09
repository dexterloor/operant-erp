<?php

declare(strict_types=1);

namespace App\Modules\Customers\ApiResource\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Modules\Customers\ApiResource\CreateCustomerAddressInput;
use App\Modules\Customers\ApiResource\CustomerAddressResource;
use App\Modules\Customers\Application\CustomerAddressWriter;
use App\Platform\Http\Idempotency\IdempotencyKeyGuard;
use Symfony\Component\HttpFoundation\Request;

/**
 * `CreateCustomerAddress` — API-21 branch 4: 201, the created (or replayed) address as the body, `Location` from
 * `CreatedRowLocation`. Reads the `Idempotency-Key` header and hands it to the writer (A-11 keeps `Request` out of the
 * application layer; `IdempotencyKeyGuard` has already refused a missing or over-long key on `kernel.controller`).
 *
 * @implements ProcessorInterface<CreateCustomerAddressInput, CustomerAddressResource>
 */
final readonly class CreateCustomerAddressProcessor implements ProcessorInterface
{
    public function __construct(private CustomerAddressWriter $writer)
    {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): CustomerAddressResource
    {
        \assert($data instanceof CreateCustomerAddressInput);

        $request = $context['request'] ?? null;
        $key = $request instanceof Request ? (string) $request->headers->get(IdempotencyKeyGuard::HEADER) : '';

        return CustomerAddressResource::fromView($this->writer->create((string) ($uriVariables['id'] ?? ''), $data->submitted(), $key));
    }
}
