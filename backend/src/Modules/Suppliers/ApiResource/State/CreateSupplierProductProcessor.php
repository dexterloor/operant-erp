<?php

declare(strict_types=1);

namespace App\Modules\Suppliers\ApiResource\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Modules\Suppliers\ApiResource\CreateSupplierProductInput;
use App\Modules\Suppliers\ApiResource\SupplierProductResource;
use App\Modules\Suppliers\Application\SupplierProductWriter;

/**
 * `CreateSupplierProduct` — API-21 branch 4: 201, the created entry as the body, `Location` from `CreatedRowLocation`. The
 * `Idempotency-Key` header is demanded by `IdempotencyKeyGuard` and not read here: the §4.2 mechanism is the (supplier,
 * item) unique, refused, never replayed (`CreateSupplierProcessor`'s precedent).
 *
 * @implements ProcessorInterface<CreateSupplierProductInput, SupplierProductResource>
 */
final readonly class CreateSupplierProductProcessor implements ProcessorInterface
{
    public function __construct(private SupplierProductWriter $writer)
    {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): SupplierProductResource
    {
        \assert($data instanceof CreateSupplierProductInput);

        return SupplierProductResource::fromView($this->writer->create((string) ($uriVariables['id'] ?? ''), $data->submitted()));
    }
}
