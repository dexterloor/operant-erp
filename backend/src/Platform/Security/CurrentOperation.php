<?php

declare(strict_types=1);

namespace App\Platform\Security;

use ApiPlatform\Metadata\HttpOperation;
use ApiPlatform\Metadata\Resource\Factory\ResourceMetadataCollectionFactoryInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * The operation a request addresses, read out of the **operation registry**.
 *
 * `_api_operation` is resolved inside API Platform's main controller, which is
 * after the `kernel.controller` event both gates listen on. The route defaults
 * carry the two values needed to look the operation up, and they are set from
 * the same metadata this class reads — so a gate's domain is still the registry
 * and never a route pattern it matches by hand. `IdempotencyKeyGuard` makes the
 * same move for the same reason.
 *
 * A request that addresses no operation — the profiler, a static asset, a route
 * this API does not own — returns `null`, and both gates leave it alone.
 *
 * **`_api_`-prefixed operations are excluded**, which is the same filter
 * `TransactionBoundaryTest::contractOperations()` applies: those are API
 * Platform's own error and documentation shapes, not rows of §4.2's table. The
 * consequence is that the two domains are identical — every operation a gate can
 * see is an operation the contract tests can enumerate, so "every operation is
 * covered" is a claim about one set and not about two that happen to agree.
 */
final readonly class CurrentOperation
{
    public function __construct(private ResourceMetadataCollectionFactoryInterface $operations)
    {
    }

    public function of(Request $request): ?HttpOperation
    {
        $resourceClass = $request->attributes->get('_api_resource_class');
        $operationName = $request->attributes->get('_api_operation_name');

        if (!\is_string($resourceClass) || !\is_string($operationName) || str_starts_with($operationName, '_api_')) {
            return null;
        }

        $operation = $this->operations->create($resourceClass)->getOperation($operationName);

        return $operation instanceof HttpOperation ? $operation : null;
    }
}
