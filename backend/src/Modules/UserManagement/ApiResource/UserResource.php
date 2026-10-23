<?php

declare(strict_types=1);

namespace App\Modules\UserManagement\ApiResource;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\Metadata\Post;
use ApiPlatform\OpenApi\Model\Operation as OpenApiOperation;
use App\Modules\UserManagement\ApiResource\State\DeleteUserProcessor;
use App\Modules\UserManagement\ApiResource\State\RestoreUserProcessor;
use App\Modules\UserManagement\ApiResource\State\UpdateUserProcessor;
use App\Modules\UserManagement\ApiResource\State\UserProvider;
use App\Modules\UserManagement\Application\UserView;
use App\Platform\Security\PermissionGate;
use Symfony\Component\Serializer\Attribute\SerializedName;

/**
 * **`GetUser` — `GET /api/v1/users/{id}`, `app_user.view`** (users slice 1 C1; `docs/session13/users-s1-design.md` rev 3
 * §2.1, §2.4). §4.4.3's `User` row as rewritten at C1 (K-1): `id`, `email`, `display_name`, `is_active`, `archived_at`,
 * `roles[] = {id, name}`, `margin_exposure`.
 *
 * **The same for every caller** (design §0 item 2; owner ruling Q-U2, 2026-10-08): nothing here depends on who asks, as
 * `Role` does not (`RoleReadTest::theRoleReadIsTheSameForEveryCaller`). The role picker's per-caller data is its own read,
 * `ListOfferedRoles` (C3), gated `role.assign`.
 *
 * **Disclosure** (`SEC` `users-s1-sec.md` §1.3, V2-R-25 closed for this resource): `roles[]` is PC-15's reference under
 * `app_user.view` — owner, admin and viewer among the defaults (owner notice N-1, 2026-10-08). Never served:
 * `password_hash`, `last_login_at` (`SEC` §3), `created_at`/`updated_at`, `tenant_id`, `user_role.inserted_by` (E-3), any
 * pending or proposal state (Q-1).
 *
 * The gate refuses before the provider loads anything (E-1), so a seat without `app_user.view` gets byte-equal 403s for
 * an existing, absent or foreign id (`SEC` E-4). A non-uuid, absent or another workspace's id is the same 404; an archived
 * user in the caller's workspace is returned with `archived_at` set. The denial subject is the default derivation,
 * `('app_user', {id})`, path-first.
 */
#[ApiResource(
    shortName: 'User',
    operations: [
        new Get(
            uriTemplate: '/users/{id}',
            name: 'GetUser',
            openapi: new OpenApiOperation(operationId: 'GetUser'),
            provider: UserProvider::class,
            // §4.2's `Perm` cell. Enforced by `PermissionGate` on `kernel.controller`, before the provider loads anything.
            extraProperties: [PermissionGate::OPERATION_PERMISSION => 'app_user.view'],
        ),
        // G4-C1 (2026-10-23): rename, switch off, switch back on (E-15g; `docs/session13/g4-users-design.md` §5). §4.2's
        // `Idem` cell is `no`: a repeat computes an empty delta and writes nothing. `read: false` (final RED L-2): the person is
        // read under the role-administration lock only, so a 404 cannot precede the lock-time 403 (E-1) and a deleted person
        // meets 409 `app_user.is_archived`. The denial subject is the default derivation, `('app_user', {id})`, path-first.
        new Patch(
            uriTemplate: '/users/{id}',
            name: 'UpdateUser',
            status: 200,
            openapi: new OpenApiOperation(operationId: 'UpdateUser'),
            input: UpdateUserInput::class,
            denormalizationContext: [
                'allow_extra_attributes' => false,
                'api_assign_object_to_populate' => false,
                // The row is named by the path and by nothing else (§3.1 case 2) — `UpdateRole`'s reason.
                'api_allow_update' => false,
            ],
            read: false,
            processor: UpdateUserProcessor::class,
            extraProperties: [PermissionGate::OPERATION_PERMISSION => 'app_user.edit'],
        ),
        // G4-C2 (2026-10-23): soft delete (E-15g strip + lapse; `docs/session13/g4-users-design.md` §6). API-21 branch 3: 204.
        // §4.2's `Idem` is `no`: a repeat meets a deleted person, 409 `app_user.is_archived`. `read: false` (final RED L-2).
        // The denial subject is the default derivation, `('app_user', {id})`.
        new Delete(
            uriTemplate: '/users/{id}',
            name: 'DeleteUser',
            status: 204,
            openapi: new OpenApiOperation(operationId: 'DeleteUser'),
            read: false,
            processor: DeleteUserProcessor::class,
            extraProperties: [PermissionGate::OPERATION_PERMISSION => 'app_user.delete'],
        ),
        // G4-C3 (2026-10-23): bring a deleted person back, with no roles and no old sessions (`g4-users-design.md` §7). API-21
        // branch 5: 200 + the person, no `Location` (`RestoreRole`'s shape). §4.2: `app_user.delete`, `guard:app_user.deleted_at`
        // — a replay meets a live person, 409 `app_user.is_not_archived`; no `Idempotency-Key`. `read: false` (final RED L-2).
        // E9-3 does not block it: `ListUsers` declares `archived`. The denial subject is the default derivation.
        new Post(
            uriTemplate: '/users/{id}/restoration',
            name: 'RestoreUser',
            status: 200,
            openapi: new OpenApiOperation(operationId: 'RestoreUser'),
            input: false,
            deserialize: false,
            read: false,
            processor: RestoreUserProcessor::class,
            extraProperties: [PermissionGate::OPERATION_PERMISSION => 'app_user.delete'],
        ),
    ],
)]
final class UserResource
{
    /**
     * @param list<RoleReference> $roles
     */
    public function __construct(
        public string $id,
        public string $email,
        #[SerializedName('display_name')]
        public string $displayName,
        #[SerializedName('is_active')]
        public bool $isActive,
        #[SerializedName('archived_at')]
        public ?string $archivedAt,
        public array $roles,
        /** PC-12 clause 3's union warning over `roles[]` (`MarginExposure::of`): a warning, never a refusal. */
        #[SerializedName('margin_exposure')]
        public bool $marginExposure,
    ) {
    }

    public static function fromView(UserView $view): self
    {
        return new self(
            $view->id,
            $view->email,
            $view->displayName,
            $view->isActive,
            $view->archivedAt,
            array_map(static fn (array $role): RoleReference => new RoleReference($role['id'], $role['name']), $view->roles),
            $view->marginExposure,
        );
    }
}
