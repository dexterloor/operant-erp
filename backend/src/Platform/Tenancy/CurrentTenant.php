<?php

declare(strict_types=1);

namespace App\Platform\Tenancy;

/**
 * The tenant the current request acts as.
 *
 * T-1 and API-7: no route, query parameter, header or request-body field may
 * carry a tenant identifier. The tenant comes from the signed JWT claim, read
 * server-side.
 *
 * The seam is filled by `AuthenticatedTenant`, which reads the claim
 * `App\Platform\Security\AuthenticationGate` resolved before the controller ran. It
 * stays an interface because it is the one place a reader or writer asks *which
 * tenant*, and keeping the question behind it is what stops a request-borne
 * tenant parameter growing onto any of them.
 */
interface CurrentTenant
{
    /** @return string the tenant id, in RFC 4122 form */
    public function tenantId(): string;
}
