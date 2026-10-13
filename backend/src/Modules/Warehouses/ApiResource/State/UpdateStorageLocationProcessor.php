<?php

declare(strict_types=1);

namespace App\Modules\Warehouses\ApiResource\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Modules\Warehouses\ApiResource\StorageLocationResource;
use App\Modules\Warehouses\ApiResource\UpdateStorageLocationInput;
use App\Modules\Warehouses\Application\StorageLocationWriter;

/**
 * `UpdateStorageLocation` — API-21 branch 2: 200 with the location read back. Exactly the submitted fields reach the writer.
 *
 * @implements ProcessorInterface<UpdateStorageLocationInput, StorageLocationResource>
 */
final readonly class UpdateStorageLocationProcessor implements ProcessorInterface
{
    public function __construct(private StorageLocationWriter $writer)
    {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): StorageLocationResource
    {
        \assert($data instanceof UpdateStorageLocationInput);

        return StorageLocationResource::fromView($this->writer->update(
            (string) ($uriVariables['id'] ?? ''),
            (string) ($uriVariables['locationId'] ?? ''),
            $data->submitted(),
        ));
    }
}
