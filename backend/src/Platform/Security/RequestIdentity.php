<?php

declare(strict_types=1);

namespace App\Platform\Security;

/**
 * The one place the current request's `Identity` lives.
 *
 * ---------------------------------------------------------------------------
 * Why a holder rather than passing the identity down
 * ---------------------------------------------------------------------------
 * Three collaborators need it and none of them is on a call path from another:
 * `AuthenticationGate` sets it, `PermissionGate` reads it, and
 * `App\Platform\Tenancy\AuthenticatedTenant` reads it to answer `tenantId()` for
 * every reader and writer in the request. Threading it through would mean a
 * tenant parameter on every application service, which is the shape T-1 exists
 * to keep out of the codebase.
 *
 * ---------------------------------------------------------------------------
 * It is cleared before it is set, on every request
 * ---------------------------------------------------------------------------
 * `AuthenticationGate` clears first and sets second, and clears again on
 * `kernel.finish_request`. A holder that only ever *sets* would serve the
 * previous request's caller to the next one on any path that reaches a
 * controller without passing the gate — under `disableReboot()` in this suite,
 * and under any long-running runtime in production. The failure mode is a
 * cross-tenant read, so the clear is not hygiene.
 */
final class RequestIdentity
{
    private ?Identity $identity = null;

    public function set(Identity $identity): void
    {
        $this->identity = $identity;
    }

    public function clear(): void
    {
        $this->identity = null;
    }

    public function current(): ?Identity
    {
        return $this->identity;
    }

    /**
     * The fail-closed accessor.
     *
     * Deliberately not "fall back to a configured tenant" or to a null identity:
     * a default is the shape that survives into production and serves one
     * tenant's rows to everybody, and an exception is the shape somebody has to
     * remove on purpose. This is the argument `UnauthenticatedTenant` carried
     * before there was anything to authenticate.
     */
    public function authenticated(): Identity
    {
        return $this->identity ?? throw new \LogicException(
            'No identity is resolved for this request. Every operation outside /auth/* passes '
            .'App\Platform\Security\AuthenticationGate before its controller runs, so reaching here means '
            .'either the gate was bypassed or a service ran outside a request without one being set.'
        );
    }
}
