<?php

declare(strict_types=1);

namespace App\Api\Security;

/**
 * The caller, resolved once per request from the signed token.
 *
 * ---------------------------------------------------------------------------
 * `permissionCodes` is what `role_permission` holds, and nothing else
 * ---------------------------------------------------------------------------
 * PC-6's load-bearing half is *"the sidebar is not the control; every endpoint
 * enforces independently"*, and this is the value the endpoint enforces against.
 * It is resolved per request by `IdentityReader` from `user_role → role →
 * role_permission` — never from a role's **name** (`SECURITY_MODEL.md` §4 seeds
 * eight roles per tenant and a tenant may edit them), never cached, never a
 * constant.
 *
 * It is the same derivation `/me` publishes, and that is deliberate: PC-6 makes
 * the two the *same* set, so a client rendering a sidebar from `/me` and an
 * endpoint refusing a call cannot disagree. `PermissionEnforcementTest::
 * theSetTheApiEnforcesIsTheSetMeAdvertises` is where the two are compared over
 * one request pair rather than asserted to be equal by inspection.
 *
 * `actorLabel` is `app_user.display_name`, captured here because E-3's audit row
 * needs it and `audit_log.actor_label` is `NOT NULL` — the trail has to stay
 * readable after the principal is deleted.
 */
final readonly class Identity
{
    /**
     * @param list<string> $permissionCodes the caller's effective set, flattened across live roles
     */
    public function __construct(
        public string $tenantId,
        public string $userId,
        public string $actorLabel,
        public array $permissionCodes,
    ) {
    }

    public function holds(string $permissionCode): bool
    {
        return \in_array($permissionCode, $this->permissionCodes, true);
    }
}
