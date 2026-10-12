<?php

declare(strict_types=1);

namespace App\Modules\Suppliers\ApiResource\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Modules\Suppliers\Application\SupplierProductWriter;

/**
 * `DeleteSupplierProduct` — `DELETE /api/v1/suppliers/{id}/products/{productId}` (suppliers slice 1 C4). API-21 branch 3:
 * **204, and no body**, so it serves no `unit_cost` and is not a margin source.
 *
 * `supplier_product` is `SchemaManifest::HARD`, so this is a real `DELETE`. Declared `read: false` (`RevokeRole`'s reason):
 * nothing is read outside the writer's transaction — a `HARD` row read early can go stale into a wrong answer; the writer
 * reads the parent, the pair and the archived state, then deletes, in one transaction.
 *
 * @implements ProcessorInterface<mixed, null>
 */
final readonly class DeleteSupplierProductProcessor implements ProcessorInterface
{
    public function __construct(private SupplierProductWriter $writer)
    {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): null
    {
        $this->writer->delete(
            (string) ($uriVariables['id'] ?? ''),
            (string) ($uriVariables['productId'] ?? ''),
        );

        return null;
    }
}
