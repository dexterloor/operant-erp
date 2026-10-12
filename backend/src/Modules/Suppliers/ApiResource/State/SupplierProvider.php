<?php

declare(strict_types=1);

namespace App\Modules\Suppliers\ApiResource\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Modules\Suppliers\ApiResource\SupplierResource;
use App\Modules\Suppliers\Application\SupplierReader;
use App\Platform\Http\Problem\ApiProblem;

/**
 * `GetSupplier`. The gate has already refused a caller without `supplier.view` (E-1). A non-uuid, absent or another
 * workspace's id is the same 404 (RLS); an archived supplier is returned.
 *
 * @implements ProviderInterface<SupplierResource>
 */
final readonly class SupplierProvider implements ProviderInterface
{
    public function __construct(private SupplierReader $reader)
    {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): SupplierResource
    {
        $view = $this->reader->get((string) ($uriVariables['id'] ?? '')) ?? throw ApiProblem::notFound();

        return SupplierResource::fromView($view);
    }
}
