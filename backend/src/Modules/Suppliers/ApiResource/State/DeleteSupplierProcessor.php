<?php

declare(strict_types=1);

namespace App\Modules\Suppliers\ApiResource\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Modules\Suppliers\Application\SupplierWriter;

/**
 * `DeleteSupplier` (C5 C2b) — API-12: `supplier` is `SchemaManifest::SOFT`, so this sets `deleted_at`
 * (`SupplierWriter::delete()`, which refuses a supplier with open purchase orders, owner ruling Q-S2). API-21 branch 3:
 * **204, and no body** (`DeleteItemProcessor`'s shape); the archived supplier stays readable by `GetSupplier` and listed by
 * `ListSuppliers?archived=true`.
 *
 * @implements ProcessorInterface<mixed, null>
 */
final readonly class DeleteSupplierProcessor implements ProcessorInterface
{
    public function __construct(private SupplierWriter $writer)
    {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): null
    {
        $this->writer->delete((string) ($uriVariables['id'] ?? ''));

        return null;
    }
}
