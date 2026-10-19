<?php

declare(strict_types=1);

namespace App\Modules\Customers\ApiResource\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Modules\Customers\ApiResource\CreateCustomerContactInput;
use App\Modules\Customers\ApiResource\CustomerContactResource;
use App\Modules\Customers\Application\CustomerContactWriter;
use App\Platform\Http\Idempotency\IdempotencyKeyGuard;
use Symfony\Component\HttpFoundation\Request;

/**
 * `CreateCustomerContact` — API-21 branch 4: 201, the created (or replayed) contact as the body, `Location` from
 * `CreatedRowLocation`. Reads the `Idempotency-Key` header and hands it to the writer (A-11 keeps `Request` out of the
 * application layer; `IdempotencyKeyGuard` has already refused a missing or over-long key on `kernel.controller`).
 *
 * @implements ProcessorInterface<CreateCustomerContactInput, CustomerContactResource>
 */
final readonly class CreateCustomerContactProcessor implements ProcessorInterface
{
    public function __construct(private CustomerContactWriter $writer)
    {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): CustomerContactResource
    {
        \assert($data instanceof CreateCustomerContactInput);

        $request = $context['request'] ?? null;
        $key = $request instanceof Request ? (string) $request->headers->get(IdempotencyKeyGuard::HEADER) : '';

        return CustomerContactResource::fromView($this->writer->create((string) ($uriVariables['id'] ?? ''), $data->submitted(), $key));
    }
}
