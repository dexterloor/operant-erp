<?php

declare(strict_types=1);

namespace App\Modules\Warehouses\ApiResource\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Modules\Warehouses\ApiResource\CreateStorageLocationInput;
use App\Modules\Warehouses\ApiResource\StorageLocationResource;
use App\Modules\Warehouses\Application\StorageLocationWriter;

/**
 * `CreateStorageLocation` — API-21 branch 4: 201, the created location as the body, `Location` from `CreatedRowLocation`.
 * The `Idempotency-Key` header is demanded by `IdempotencyKeyGuard` and not read here: the §4.2 mechanism is the live
 * (warehouse, code) unique, refused, never replayed.
 *
 * @implements ProcessorInterface<CreateStorageLocationInput, StorageLocationResource>
 */
final readonly class CreateStorageLocationProcessor implements ProcessorInterface
{
    public function __construct(private StorageLocationWriter $writer)
    {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): StorageLocationResource
    {
        \assert($data instanceof CreateStorageLocationInput);

        return StorageLocationResource::fromView($this->writer->create((string) ($uriVariables['id'] ?? ''), $data->submitted()));
    }
}
