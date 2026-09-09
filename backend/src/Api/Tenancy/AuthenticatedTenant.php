<?php

declare(strict_types=1);

namespace App\Api\Tenancy;

use App\Api\Security\RequestIdentity;

/**
 * The tenant seam, filled — ADR-005 D-5 and T-1 / API-7.
 *
 * The tenant is the signed `tenant_id` claim, read server-side by
 * `App\Api\Security\AuthenticationGate` before the controller runs, and never
 * accepted from a route, a query parameter, a header or a request body.
 *
 * This class replaces `UnauthenticatedTenant`, which threw on every call because
 * there was nothing to authenticate. It keeps that class's one real property: it
 * **fails closed**. `RequestIdentity::authenticated()` throws when no identity is
 * resolved, so a reader or writer that somehow runs outside the gate raises
 * rather than defaulting to a tenant — which was the whole argument against a
 * configured default, and it is unchanged.
 */
final readonly class AuthenticatedTenant implements CurrentTenant
{
    public function __construct(private RequestIdentity $identities)
    {
    }

    public function tenantId(): string
    {
        return $this->identities->authenticated()->tenantId;
    }
}
