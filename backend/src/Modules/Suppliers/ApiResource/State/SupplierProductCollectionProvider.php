<?php

declare(strict_types=1);

namespace App\Modules\Suppliers\ApiResource\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Modules\Suppliers\ApiResource\SupplierProductCollection;
use App\Modules\Suppliers\ApiResource\SupplierProductResource;
use App\Modules\Suppliers\Application\SupplierProductReader;
use App\Platform\Http\Problem\ApiProblem;

/**
 * `ListSupplierProducts`. The gate has already refused a caller without `supplier_product.view` (E-1). The operation
 * declares no query parameter, so any parameter is refused before this class runs (`UndeclaredQueryParameterRefusal`).
 *
 * @implements ProviderInterface<SupplierProductCollection>
 */
final readonly class SupplierProductCollectionProvider implements ProviderInterface
{
    public function __construct(private SupplierProductReader $reader)
    {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): SupplierProductCollection
    {
        $products = $this->reader->list((string) ($uriVariables['id'] ?? '')) ?? throw ApiProblem::notFound();

        return new SupplierProductCollection(
            items: array_map(SupplierProductResource::fromView(...), $products),
            // §4.4.2: a bounded list carries both cursors null always.
            nextCursor: null,
            previousCursor: null,
            // API-22: probed only when the set is empty, against the resource's table with no filter predicate.
            tenantHasRows: [] === $products ? $this->reader->tenantHasRows() : true,
        );
    }
}
