<?php

declare(strict_types=1);

namespace App\Modules\Warehouses\ApiResource\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Modules\Warehouses\Application\WarehouseWriter;

/**
 * `DeleteWarehouse` (warehouse/location delete-restore C4) — API-12: `warehouse` is `SchemaManifest::SOFT`, so this sets
 * `deleted_at` (`WarehouseWriter::delete()`, which refuses a warehouse with open purchase or sales orders or any stock,
 * API-O-34). API-21 branch 3: **204, and no body** (`DeleteSupplierProcessor`'s shape); the archived warehouse stays readable
 * by `GetWarehouse`, listed by `ListWarehouses?archived=true` and is restored by `RestoreWarehouse`. Its locations are left
 * as they are (owner Q-2 (a)).
 *
 * @implements ProcessorInterface<mixed, null>
 */
final readonly class DeleteWarehouseProcessor implements ProcessorInterface
{
    public function __construct(private WarehouseWriter $writer)
    {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): null
    {
        $this->writer->delete((string) ($uriVariables['id'] ?? ''));

        return null;
    }
}
