<?php

declare(strict_types=1);

namespace App\Modules\UserManagement\ApiResource;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\Link;
use ApiPlatform\Metadata\Post;
use ApiPlatform\OpenApi\Model\Operation as OpenApiOperation;
use App\Modules\UserManagement\ApiResource\State\AssignRoleProcessor;
use App\Modules\UserManagement\ApiResource\State\CreateRoleProcessor;
use App\Modules\UserManagement\ApiResource\State\RevokeRoleProcessor;
use App\Modules\UserManagement\ApiResource\State\RoleProvider;
use App\Modules\UserManagement\Application\RoleView;
use App\Platform\Http\Idempotency\IdempotencyKeyGuard;
use App\Platform\Http\Location\CreatedRowLocation;
use App\Platform\Security\PermissionGate;
use Symfony\Component\Serializer\Attribute\SerializedName;

/**
 * `GetRole` — `GET /api/v1/roles/{id}`, `role.view`. Roles slice 1, C3. Also carries `RevokeRole` (roles slice 2, C2),
 * `DELETE /api/v1/users/{id}/roles/{roleId}` under `role.assign` — 204, no body, so it adds nothing to this shape — and
 * `AssignRole` (C3), `POST /api/v1/users/{id}/roles`, whose 201 body IS this shape: the granted role (`SEC` S-1 (b)), so
 * `Assign / Role` and `Get / Role` resolve to one DTO class and `ResponseShapeTest`'s one-DTO-per-resource holds.
 *
 * The shape is API-23's derivation for `Role`:
 *   1. `CreateRole`'s accepted fields — `name`, `description`, `permission_codes`;
 *   2. plus `id`;
 *   3. plus `archived_at`, because `role` is `SchemaManifest::SOFT`;
 *   4. plus §4.4.3's additions row — **`is_system`** and **`holder_count`** (response R-10, ratified), and since roles
 *      slice 4 C1a **`margin_exposure`** — PC-12 clause 3's warning at definition (`MarginExposure::of(permission_codes)`):
 *      `true` when the role holds a cost source and a sale source without every `.view`. A **warning, never a refusal**
 *      (owner 2026-09-16, *"Default strict, custom warned"*). A pure function of `permission_codes`, which the same body
 *      serves, so it discloses nothing the caller cannot already compute. It is on every serving of the shape — `GetRole`,
 *      `ListRoles` items, and `AssignRole`'s 201 (a G-28 trigger; the owner was told 2026-10-07; G-28 NARROWED, not closed:
 *      the pair warning at assignment, PC-12 clause 4, stays owed to `GetUser`'s picker).
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
        // Roles slice 2, C3 (2026-09-30). API-21 branch 4: 201, `Location` = the grant's URI, which answers `DELETE`
        // (`RevokeRole`) and not `GET` — §4.4.1's own precedent. The IRI of this resource is `/roles/{id}`, which is not the
        // created row, so `CreatedRowLocation` fills `/users/{id}/roles/{roleId}` from the route's `{id}` and the body's `id`
        // (the granted role's). §4.2's `Idem` cell is `unique:user_role_unique`: the header is demanded (428 without it) and
        // the natural pair is what collides. `read: false`, as `RevokeRole`: nothing is read before the lock.
        new Post(
            uriTemplate: '/users/{id}/roles',
            uriVariables: [
                'id' => new Link(fromClass: RoleResource::class, identifiers: [], parameterName: 'id'),
            ],
            name: 'AssignRole',
            status: 201,
            openapi: new OpenApiOperation(operationId: 'AssignRole'),
            input: AssignRoleInput::class,
            denormalizationContext: [
                'allow_extra_attributes' => false,
                'api_assign_object_to_populate' => false,
            ],
            read: false,
            processor: AssignRoleProcessor::class,
            extraProperties: [
                IdempotencyKeyGuard::OPERATION_FLAG => true,
                PermissionGate::OPERATION_PERMISSION => 'role.assign',
                // F-1 / `SEC` Acknowledgement B.3 item 1: `{id}` addresses the USER whose privileges change, not a role.
                PermissionGate::OPERATION_DENIAL_SUBJECT => ['app_user', 'id'],
                CreatedRowLocation::OPERATION_URI_TEMPLATE => '/users/{id}/roles/{roleId}',
            ],
        ),
        // Roles slice 4, C1 (2026-10-07). API-21 branch 4: 201, the created role in `GetRole`'s shape, `Location` = its
        // `GetRole` URI (this resource's own IRI). §4.2's `Idem` cell is `unique:role_tenant_name_live`: the header is
        // demanded (428 without it) and not stored; a retry meets the name rule (`role.name_taken`). `read: false`: nothing
        // is read before the role-administration lock. The denial subject is the default derivation (no `{id}`).
        new Post(
            uriTemplate: '/roles',
            name: 'CreateRole',
            status: 201,
            openapi: new OpenApiOperation(operationId: 'CreateRole'),
            input: CreateRoleInput::class,
            denormalizationContext: [
                'allow_extra_attributes' => false,
                'api_assign_object_to_populate' => false,
            ],
            read: false,
            processor: CreateRoleProcessor::class,
            extraProperties: [
                IdempotencyKeyGuard::OPERATION_FLAG => true,
                PermissionGate::OPERATION_PERMISSION => 'role.create',
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
        #[SerializedName('margin_exposure')]
        public bool $marginExposure,
        #[SerializedName('archived_at')]
        public ?string $archivedAt,
    ) {
    }

    public static function fromView(RoleView $view): self
    {
        return new self($view->id, $view->name, $view->description, $view->permissionCodes, $view->isSystem, $view->holderCount, $view->marginExposure, $view->archivedAt);
    }
}
