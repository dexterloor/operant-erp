<?php

declare(strict_types=1);

namespace App\Modules\UserManagement\ApiResource;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\Link;
use ApiPlatform\OpenApi\Model\Operation as OpenApiOperation;
use App\Modules\UserManagement\ApiResource\State\RevokeRoleProcessor;
use App\Modules\UserManagement\ApiResource\State\RoleProvider;
use App\Modules\UserManagement\Application\RoleView;
use App\Platform\Security\PermissionGate;
use Symfony\Component\Serializer\Attribute\SerializedName;

/**
 * `GetRole` — `GET /api/v1/roles/{id}`, `role.view`. Roles slice 1, C3. Also carries `RevokeRole` (roles slice 2, C2),
 * `DELETE /api/v1/users/{id}/roles/{roleId}` under `role.assign` — 204, no body, so it adds nothing to this shape.
 *
 * The shape is API-23's derivation for `Role`:
 *   1. `CreateRole`'s accepted fields — `name`, `description`, `permission_codes`;
 *   2. plus `id`;
 *   3. plus `archived_at`, because `role` is `SchemaManifest::SOFT`;
 *   4. plus §4.4.3's additions row — **`is_system`** and **`holder_count`** (response R-10, ratified).
 *
 * **Deliberately absent, and pinned** (`RoleReadTest::theRoleShapeCarriesNoPrivilegeHistory`):
 * `pending_proposal_count` and any `pending_*`/`proposal*` member. `viewer` holds `role.view`, so nothing
 * served here may reveal a pending privilege change (PC-16 condition 1, IA §6.8); R-10 dropped the field
 * for **everyone** rather than conditioning it on the caller's permissions, which would also break
 * `ResponseShapeTest`'s one-DTO contract. Also absent: `tenant_id`, `created_at`/`updated_at` (not in the
 * derivation), holder identities (V2-R-25, unruled), and any "needs owner confirmation" flag (`AssignRole`).
 *
 * `holder_count`'s disclosure ground is carried by V2-R-25 (`SEC`); a single-owner tenant is visible to
 * `viewer` through it (SEC-O-19's subject). Recorded in §15.1.
 */
#[ApiResource(
    shortName: 'Role',
    operations: [
        new Get(
            uriTemplate: '/roles/{id}',
            name: 'GetRole',
            openapi: new OpenApiOperation(operationId: 'GetRole'),
            provider: RoleProvider::class,
            // §4.2's `Perm` cell. Enforced by `PermissionGate` on `kernel.controller`, before the provider
            // loads anything — E-1.
            extraProperties: [PermissionGate::OPERATION_PERMISSION => 'role.view'],
        ),
        // Roles slice 2, C2 (2026-09-29). API-21 branch 3: 204, no body. §4.2's `Idem` cell is `no`: a repeat meets no
        // grant and is 404. `read: false` — the grant is read under the role-administration lock inside the revoking
        // transaction, never by a provider before it opens (design D-4). Declared on `RoleResource` (D-4): there is no
        // user resource yet (`GetUser` is unbuilt), and `ResponseShapeTest` keys one DTO per resource.
        new Delete(
            uriTemplate: '/users/{id}/roles/{roleId}',
            uriVariables: [
                'id' => new Link(fromClass: RoleResource::class, identifiers: [], parameterName: 'id'),
                'roleId' => new Link(fromClass: RoleResource::class, identifiers: ['id'], parameterName: 'roleId'),
            ],
            name: 'RevokeRole',
            status: 204,
            openapi: new OpenApiOperation(operationId: 'RevokeRole'),
            read: false,
            processor: RevokeRoleProcessor::class,
            extraProperties: [
                PermissionGate::OPERATION_PERMISSION => 'role.assign',
                // F-1 / `SEC` Acknowledgement B.3 item 1: `{id}` addresses the USER whose privileges change, not a role.
                // Without this the default derivation writes ('role', <user id>). The lock-time denial reads it too.
                PermissionGate::OPERATION_DENIAL_SUBJECT => ['app_user', 'id'],
            ],
        ),
    ],
)]
final class RoleResource
{
    /**
     * @param list<string> $permissionCodes codes, not references (nothing here is an `_id`), sorted ascending
     */
    public function __construct(
        public string $id,
        public string $name,
        public string $description,
        #[SerializedName('permission_codes')]
        public array $permissionCodes,
        #[SerializedName('is_system')]
        public bool $isSystem,
        #[SerializedName('holder_count')]
        public int $holderCount,
        #[SerializedName('archived_at')]
        public ?string $archivedAt,
    ) {
    }

    public static function fromView(RoleView $view): self
    {
        return new self($view->id, $view->name, $view->description, $view->permissionCodes, $view->isSystem, $view->holderCount, $view->archivedAt);
    }
}
