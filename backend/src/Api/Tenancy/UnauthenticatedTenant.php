<?php

declare(strict_types=1);

namespace App\Api\Tenancy;

/**
 * The default implementation, and it fails closed.
 *
 * Deliberately not "fall back to a configured tenant". A default tenant is the
 * shape that survives into production and serves one tenant's rows to everybody;
 * an exception is the shape somebody has to remove on purpose.
 */
final class UnauthenticatedTenant implements CurrentTenant
{
    public function tenantId(): string
    {
        throw new \LogicException(
            'No tenant is resolved for this request. The JWT claim reader is Output 4 §7 and is not '
            .'built yet; until it is, every operation that needs a tenant fails here rather than '
            .'defaulting to one.'
        );
    }
}
