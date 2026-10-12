<?php

declare(strict_types=1);

namespace App\Modules\Suppliers\ApiResource\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Modules\Suppliers\ApiResource\SupplierProductResource;
use App\Modules\Suppliers\Application\SupplierProductReader;
use App\Platform\Http\Problem\ApiProblem;

/**
 * `UpdateSupplierProduct`'s row load (`CustomerAddressProvider`'s reason: without it, API Platform's default provider
 * answers an unknown id with a bare `NotFoundHttpException` rather than §5.2's `not-found` body). **The pair
 * `(id, productId)` names the row**, so another supplier's entry is the same 404 as a random id. The writer re-reads the
 * pair inside its transaction.
 *
 * @implements ProviderInterface<SupplierProductResource>
 */
final readonly class SupplierProductProvider implements ProviderInterface
{
    public function __construct(private SupplierProductReader $reader)
    {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): SupplierProductResource
    {
        $view = $this->reader->get((string) ($uriVariables['id'] ?? ''), (string) ($uriVariables['productId'] ?? ''))
            ?? throw ApiProblem::notFound();

        return SupplierProductResource::fromView($view);
    }
}
