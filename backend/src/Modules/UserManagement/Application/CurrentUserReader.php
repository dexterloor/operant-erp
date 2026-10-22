<?php

declare(strict_types=1);

namespace App\Modules\UserManagement\Application;

use App\Modules\UserManagement\ApiResource\CurrentUserResource;
use App\Modules\UserManagement\ApiResource\RoleReference;
use App\Platform\Tenancy\SchemaManifest;
use App\Platform\Tenancy\TenantContext;
use Doctrine\DBAL\Connection;

/**
 * `GET /api/v1/me` — §4.4.3's `CurrentUser` row, read on **`erp_app`** inside
 * one tenancy transaction.
 *
 * ---------------------------------------------------------------------------
 * The permission set is DERIVED, and here is what that word is doing
 * ---------------------------------------------------------------------------
 * It is `role_permission`, joined through the caller's own `user_role` rows,
 * resolved on this request. **Not** a constant, not a cached copy, and above all
 * **not derived from the role's name** — `SECURITY_MODEL.md` §4 seeds eight
 * default roles per tenant and a tenant may edit them, so a set inferred from
 * *"this user is an `admin`"* would describe the seed rather than the grant.
 * `CurrentUserTest::thePermissionSetIsWhatRolePermissionHolds` pins the
 * derivation by adding a row to `role_permission` and asserting the response
 * changes **with no code edit**; a test that would pass against a hardcoded set
 * is the wrong test.
 *
 * ---------------------------------------------------------------------------
 * Soft deletion is applied here by hand, and that is not an oversight
 * ---------------------------------------------------------------------------
 * `SoftDeleteFilter` is an ORM filter and this is DBAL, so the predicate is
 * written out. `role` is class `soft` (`SchemaManifest`), so a deleted role
 * grants nothing and appears in no list; `user_role` and `role_permission` are
 * class `hard` and carry no `deleted_at` to test. Deriving that from the
 * manifest at runtime would be a query per request to answer a question the
 * schema settles at migration time — `CurrentUserTest::aDeletedRoleGrantsNothing`
 * is where it is checked instead.
 *
 * ---------------------------------------------------------------------------
 * P-7 / API-8 — the transaction does not span serialization
 * ---------------------------------------------------------------------------
 * Everything below is a scalar or an array of scalars by the time `run()`
 * returns, so serializing the result cannot reach the database. ADR-002 A1.7
 * measured what the alternative costs: 1505 ms of pooled-backend pinning.
 */
final readonly class CurrentUserReader
{
    public function __construct(private TenantContext $tenants, private Connection $app)
    {
    }

    public function read(string $tenantId, string $userId): ?CurrentUserResource
    {
        /** @var CurrentUserResource|null */
        return $this->tenants->run($tenantId, function () use ($userId): ?CurrentUserResource {
            // RLS supplies the tenant predicate. `deleted_at IS NULL` is the
            // application's: a soft-deleted principal is not a principal. The
            // liveness check (is_active, sessions_valid_after) is the gate's,
            // `IdentityReader` (G4-C0), not this profile read's: /me is
            // unreachable for an inactive or stamped-out principal.
            $user = $this->app->fetchAssociative(
                'SELECT id, email, display_name FROM app_user WHERE id = ?::uuid AND deleted_at IS NULL',
                [$userId],
            );

            if (!\is_array($user)) {
                return null;
            }

            $roles = $this->app->fetchAllAssociative(<<<'SQL'
                SELECT r.id, r.name
                FROM user_role ur
                JOIN role r ON r.id = ur.role_id AND r.deleted_at IS NULL
                WHERE ur.user_id = ?::uuid
                ORDER BY r.name
                SQL, [$userId]);

            $permissionCodes = $this->app->fetchFirstColumn(<<<'SQL'
                SELECT DISTINCT rp.permission_code
                FROM user_role ur
                JOIN role r ON r.id = ur.role_id AND r.deleted_at IS NULL
                JOIN role_permission rp ON rp.role_id = r.id
                WHERE ur.user_id = ?::uuid
                ORDER BY 1
                SQL, [$userId]);

            return new CurrentUserResource(
                id: (string) $user['id'],
                email: (string) $user['email'],
                displayName: (string) $user['display_name'],
                roles: array_map(
                    static fn (array $role): RoleReference => new RoleReference((string) $role['id'], (string) $role['name']),
                    $roles,
                ),
                permissionCodes: array_map(strval(...), $permissionCodes),
            );
        });
    }

    /**
     * Named so the manifest constant has a reader in this file: the two joins
     * above are correct only while `user_role` and `role_permission` stay
     * deletion class `hard`. `CurrentUserTest::theJoinedTablesAreStillHardDeleted`
     * asserts it against the manifest and goes red the day either changes.
     */
    public static function joinedTablesRequiringNoDeletionPredicate(): array
    {
        return [
            'user_role' => SchemaManifest::TABLES['user_role']['deletion'],
            'role_permission' => SchemaManifest::TABLES['role_permission']['deletion'],
        ];
    }
}
