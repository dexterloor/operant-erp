<?php

declare(strict_types=1);

namespace App\Modules\Purchasing\ApiResource\State;

use ApiPlatform\Metadata\Operation;
use App\Platform\Http\Problem\ApiProblem;

/**
 * API-19b — *"§4.2's `Query parameters` column is exhaustive per row"*, enforced
 * from the operation's own declared parameters rather than from a list in a
 * provider.
 *
 * `—` in that column means *this operation takes no query parameter*, never *the
 * rest are obvious*, and the refusal is what makes the difference observable: a
 * parameter accepted here but undocumented, or documented and refused, is not
 * expressible, because both sides read the same declaration.
 *
 * It is a small shared class rather than a copy in each of this module's two
 * collection providers because there are two of them and they have very different
 * parameter sets — `ListPurchaseOrders` has seven and `ListPurchaseOrderLines`
 * has one — which is exactly the arrangement where a second copy drifts.
 * `ItemCollectionProvider` carries its own copy and predates this class; it is
 * left alone, because relocating a shipped check is a change to the Inventory
 * slice and this package does not own it.
 */
final class QueryParameters
{
    /** @param array<string, mixed> $query */
    public static function refuseUndeclared(Operation $operation, array $query): void
    {
        $declared = [];
        foreach ($operation->getParameters() ?? [] as $key => $parameter) {
            $declared[] = (string) ($parameter->getKey() ?? $key);
        }

        $undeclared = array_diff(array_keys($query), $declared);

        if ([] === $undeclared) {
            return;
        }

        throw ApiProblem::validationFailed(array_map(
            static fn (string $name): array => [
                // A query parameter is not addressable by a JSON pointer into a
                // body this operation does not have, so it is named with the `?`
                // form -- the same form `ItemCollectionProvider` uses.
                'pointer' => '?'.$name,
                'code' => 'undeclared_parameter',
                'message' => \sprintf('This operation declares no query parameter named "%s".', $name),
            ],
            array_values($undeclared),
        ));
    }
}
