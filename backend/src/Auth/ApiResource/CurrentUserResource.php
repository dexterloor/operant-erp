<?php

declare(strict_types=1);

namespace App\Auth\ApiResource;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\OpenApi\Model\Operation as OpenApiOperation;
use App\Api\Security\PermissionGate;
use App\Auth\ApiResource\State\CurrentUserProvider;
use Symfony\Component\Serializer\Attribute\SerializedName;

/**
 * `GET /api/v1/me` — §4.2's `GetCurrentUser`, and §4.4.3's `CurrentUser` row:
 * `id`, `display_name`, `email`, `roles[] = {id, name}`, `permission_codes[]`.
 *
 * ===========================================================================
 * `permission_codes` is the set the API enforces — no longer advisory
 * ===========================================================================
 * PC-6's load-bearing half is *"the sidebar is not the control; every endpoint
 * enforces independently"*, and as of the enforcement slice every endpoint does:
 * `App\Api\Security\PermissionGate` refuses an operation whose declared code is
 * absent from this same set, before the row is loaded (E-1). This member and that
 * decision are **one derivation** — `role_permission`, resolved per request — so
 * a sidebar rendered from it and an endpoint refusing a call cannot disagree.
 * `PermissionEnforcementTest::theSetTheApiEnforcesIsTheSetMeAdvertises` drives
 * both over one request pair rather than asserting it by inspection.
 *
 * **It is still a rendering and not a control.** PC-6's rule is unchanged: a
 * client that hid a button on the strength of this member and skipped the call
 * would still be relying on the server to refuse, and the server does. The name
 * is part of that — `permission_codes` says what the user *holds*; `can`,
 * `allowed` or `permissions_granted` would each read as a verdict about a
 * specific action, which this response does not give.
 *
 * ===========================================================================
 * Why the set is here at all, given the above
 * ===========================================================================
 * F-1 step 5 renders the sidebar from the effective set and PC-6 makes the
 * sidebar a *rendering* of it. Without this operation the BFF would infer the
 * set from role names, which is the client-side re-derivation F-7a-1's violation
 * clause names — and inference from a name is wrong the moment a tenant edits a
 * seeded role. The set is expanded rather than left as `roles[]` for F-7 step 2's
 * reason: *"a four-role user whose effective set is invisible is a user nobody
 * can audit."*
 *
 * ===========================================================================
 * Authentication, and what `—` in §4.2's `Perm` column does not mean
 * ===========================================================================
 * §4.2: *"`—` in `Perm` means the operation requires no permission code. Whether
 * it also requires a JWT is decided by the row's own `Path` … `/auth/*` is
 * pre-authentication; everything else requires a valid token."* This path is not
 * under `/auth/`, so `CurrentUserProvider` requires a bearer token and refuses
 * with the same constant `authentication-failed` problem `IssueToken` uses.
 *
 * No `tenant_id`, at any depth (API-6, P-6, T-1): the tenant arrives in the
 * signed claim and never leaves in a response.
 */
#[ApiResource(
    shortName: 'CurrentUser',
    operations: [
        new Get(
            uriTemplate: '/me',
            name: 'GetCurrentUser',
            openapi: new OpenApiOperation(operationId: 'GetCurrentUser'),
            provider: CurrentUserProvider::class,
            // §4.2's `Perm` cell is `—`: authenticated, no code. Declared rather
            // than omitted -- `PermissionGate` refuses an operation that carries
            // no declaration. This path is not under `/auth/`, so
            // `AuthenticationGate` requires a valid token for it.
            extraProperties: [PermissionGate::OPERATION_PERMISSION => null],
        ),
    ],
)]
final class CurrentUserResource
{
    /**
     * @param list<RoleReference> $roles
     * @param list<string>        $permissionCodes the caller's effective set, flattened across live roles — the set the API enforces, see the class docblock
     */
    public function __construct(
        public string $id,
        public string $email,
        #[SerializedName('display_name')]
        public string $displayName,
        public array $roles,
        #[SerializedName('permission_codes')]
        public array $permissionCodes,
    ) {
    }
}
