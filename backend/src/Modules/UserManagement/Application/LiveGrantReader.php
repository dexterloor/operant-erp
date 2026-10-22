<?php

declare(strict_types=1);

namespace App\Modules\UserManagement\Application;

use App\Modules\UserManagement\Domain\OwnerMarker;
use App\Platform\Tenancy\SchemaManifest;
use Doctrine\ORM\EntityManagerInterface;

/**
 * **The grants as they stand under the role-administration lock — the one reader every role-administration decision
 * reads** (roles slice 2, C2; `docs/session13/roles-s2-design.md` §4.2, response R-5; `SECURITY_MODEL.md` §5.2.4–§5.2.5).
 *
 * The request's `Identity` was read by `AuthenticationGate` BEFORE the lock, in an earlier transaction. Under the lock it is
 * a hint; this is the fact (S-6): a demotion, deactivation or grant another administrator committed while this request
 * waited is seen here and not there. Every method takes the entity manager whose connection holds the lock, so the reads
 * run in the locked transaction (READ COMMITTED: each sees what the previous holder committed).
 *
 * `grant()` answers "does this user hold this role row at all" (live or archived — `AssignRole`'s C12 `already_held` reads
 * it too), `role()` answers "what is this role" for a grant (C3).
 *
 * **Why it is not `IdentityReader`:** PC-13 (C3) needs the codes **per role** (a split is refused only if no single member
 * spans it), E-15c (C3) needs `is_system`, the actor re-check needs `is_active`, and E-15b on revoke needs a role's STORED
 * codes whether or not it is archived. `IdentityReader` returns the union of live codes only. **It is a second derivation of
 * the same set, so it is cross-pinned:** `LiveGrantReaderTest` asserts the union of {@see self::rolesHeldBy()} equals
 * `IdentityReader::read()->permissionCodes` and `/me`'s `permission_codes`, and `PermissionEnforcementTest` holds
 * {@see self::joinedTablesRequiringNoDeletionPredicate()} equal to the other readers'.
 *
 * **The marker count is API-20's floor AND reconciliation arm F's predicate — one query for both**
 * ({@see self::ACTIVE_MARKER_HOLDERS}): users not deleted, `is_active`, holding {@see OwnerMarker::CODE} through a live
 * role. It reads `OwnerMarker::CODE` to COUNT holders; it decides nothing about the caller — every caller decision goes
 * through `OwnerPredicate::holdsMarker()` over the set this class re-derived (`OwnerPredicateSingleSourceTest`'s
 * `MARKER_READERS` entry says so).
 *
 * Tenant scoping is RLS's (forced on all four tables): every statement runs inside `TenantContext::run()` on `erp_app`.
 * Joins carry `tenant_id` equality as well, which is how the composite keys are written.
 */
final class LiveGrantReader
{
    /**
     * Users that are not deleted, `is_active`, and hold the marker through a live role. `%s` is an optional extra predicate
     * on the grant row (the "excluding this grant" form); it is one of two literal strings in this class, never input.
     */
    private const ACTIVE_MARKER_HOLDERS = <<<'SQL'
        SELECT count(DISTINCT u.id)
          FROM app_user u
          JOIN user_role ur ON ur.tenant_id = u.tenant_id AND ur.user_id = u.id
          JOIN role r ON r.tenant_id = ur.tenant_id AND r.id = ur.role_id AND r.deleted_at IS NULL
          JOIN role_permission rp ON rp.tenant_id = r.tenant_id AND rp.role_id = r.id
         WHERE u.deleted_at IS NULL AND u.is_active AND rp.permission_code = ?
        SQL;

    /**
     * The user as the lock sees it, or null when no row exists in this workspace (another workspace's id is not-found
     * exactly as a random one: RLS).
     *
     * @return array{deleted: bool, is_active: bool}|null
     */
    public static function user(EntityManagerInterface $em, string $userId): ?array
    {
        $connection = $em->getConnection();

        $row = $connection->fetchAssociative(
            'SELECT deleted_at IS NOT NULL AS deleted, is_active FROM app_user WHERE id = ?::uuid',
            [$userId],
        );

        return false === $row ? null : ['deleted' => (bool) $row['deleted'], 'is_active' => (bool) $row['is_active']];
    }

    /**
     * The user's LIVE roles (`role.deleted_at IS NULL`), each with its codes sorted ascending. A deleted user holds
     * nothing: a soft-deleted principal holding a live token is not a principal (`IdentityReader`'s rule).
     *
     * @return list<array{role_id: string, is_system: bool, codes: list<string>}>
     */
    public static function rolesHeldBy(EntityManagerInterface $em, string $userId): array
    {
        $connection = $em->getConnection();

        $rows = $connection->fetchAllAssociative(<<<'SQL'
            SELECT r.id::text AS role_id, r.is_system,
                   COALESCE((SELECT array_to_json(array_agg(rp.permission_code ORDER BY rp.permission_code))
                               FROM role_permission rp
                              WHERE rp.tenant_id = r.tenant_id AND rp.role_id = r.id), '[]') AS codes
              FROM user_role ur
              JOIN app_user u ON u.tenant_id = ur.tenant_id AND u.id = ur.user_id AND u.deleted_at IS NULL
              JOIN role r ON r.tenant_id = ur.tenant_id AND r.id = ur.role_id AND r.deleted_at IS NULL
             WHERE ur.user_id = ?::uuid
             ORDER BY r.id
            SQL, [$userId]);

        return array_map(static fn (array $row): array => [
            'role_id' => (string) $row['role_id'],
            'is_system' => (bool) $row['is_system'],
            'codes' => self::codes((string) $row['codes']),
        ], $rows);
    }

    /**
     * The union of {@see self::rolesHeldBy()}'s codes, sorted — the set the lock-time permission re-check reads.
     *
     * @return list<string>
     */
    public static function codesHeldBy(EntityManagerInterface $em, string $userId): array
    {
        $codes = array_merge([], ...array_map(static fn (array $role): array => $role['codes'], self::rolesHeldBy($em, $userId)));
        $codes = array_values(array_unique($codes));
        sort($codes);

        return $codes;
    }

    /**
     * The one grant `(user, role)` in this workspace, with the role's **stored** codes and whether the role is live —
     * whether or not it is archived (D-15: E-15b on a revoke reads the stored codes, because a grant planted on an archived
     * role would be live again were the role restored — `RestoreRole` (G2) refuses a held role, and SQL can still clear `deleted_at`). Null when there is no such grant.
     *
     * @return array{id: string, role_live: bool, role_codes: list<string>}|null
     */
    public static function grant(EntityManagerInterface $em, string $userId, string $roleId): ?array
    {
        $connection = $em->getConnection();

        $row = $connection->fetchAssociative(<<<'SQL'
            SELECT ur.id::text AS id, r.deleted_at IS NULL AS role_live,
                   COALESCE((SELECT array_to_json(array_agg(rp.permission_code ORDER BY rp.permission_code))
                               FROM role_permission rp
                              WHERE rp.tenant_id = r.tenant_id AND rp.role_id = r.id), '[]') AS role_codes
              FROM user_role ur
              JOIN role r ON r.tenant_id = ur.tenant_id AND r.id = ur.role_id
             WHERE ur.user_id = ?::uuid AND ur.role_id = ?::uuid
            SQL, [$userId, $roleId]);

        return false === $row ? null : [
            'id' => (string) $row['id'],
            'role_live' => (bool) $row['role_live'],
            'role_codes' => self::codes((string) $row['role_codes']),
        ];
    }

    /**
     * The role as the lock sees it (roles slice 2, C3), **archived or not** (no `deleted_at` predicate: an archived role must
     * reach V2-R-6's 409 `role_is_archived`, never fall into `unknown_reference`'s 422 — `UX` copy §3.1 item 2): its name,
     * whether it is live, whether it is a system role (E-15c), and its
     * **stored** codes. Null when no role row with this id exists in this workspace (another workspace's is null, by RLS).
     * `AssignRole` refuses an archived role (V2-R-6) before it reads the codes for any decision, so every decision it takes
     * on these codes is on a LIVE role's codes.
     *
     * `description` joined at roles slice 4 C2 (`UpdateRole` compares it to tell a re-description from a no-op).
     *
     * @return array{name: string, description: string, live: bool, is_system: bool, codes: list<string>}|null
     */
    public static function role(EntityManagerInterface $em, string $roleId): ?array
    {
        $connection = $em->getConnection();

        $row = $connection->fetchAssociative(<<<'SQL'
            SELECT r.name, r.description, r.deleted_at IS NULL AS live, r.is_system,
                   COALESCE((SELECT array_to_json(array_agg(rp.permission_code ORDER BY rp.permission_code))
                               FROM role_permission rp
                              WHERE rp.tenant_id = r.tenant_id AND rp.role_id = r.id), '[]') AS codes
              FROM role r
             WHERE r.id = ?::uuid
            SQL, [$roleId]);

        return false === $row ? null : [
            'name' => (string) $row['name'],
            'description' => (string) $row['description'],
            'live' => (bool) $row['live'],
            'is_system' => (bool) $row['is_system'],
            'codes' => self::codes((string) $row['codes']),
        ];
    }

    /** API-20's `before` and arm F's predicate: the workspace's active marker holders. */
    public static function activeMarkerHolderCount(EntityManagerInterface $em): int
    {
        $connection = $em->getConnection();

        return (int) $connection->fetchOne(self::ACTIVE_MARKER_HOLDERS, [OwnerMarker::CODE]);
    }

    /**
     * API-20's `after` for one revoke: the same count with one grant row taken away. It counts USERS, so a subject who also
     * holds the marker through a second live role still counts.
     */
    public static function activeMarkerHolderCountExcluding(EntityManagerInterface $em, string $userRoleId): int
    {
        $connection = $em->getConnection();

        return (int) $connection->fetchOne(self::ACTIVE_MARKER_HOLDERS.' AND ur.id <> ?::uuid', [OwnerMarker::CODE, $userRoleId]);
    }

    /**
     * API-20's `after` for an edit that REMOVES the marker from one role (roles slice 4 C2, `UpdateRole`; design §4.3 step
     * 9b): the same count with every grant of that role taken away. It counts USERS, so a holder who also holds the marker
     * through another live role still counts.
     */
    public static function activeMarkerHolderCountWithoutRole(EntityManagerInterface $em, string $roleId): int
    {
        $connection = $em->getConnection();

        return (int) $connection->fetchOne(self::ACTIVE_MARKER_HOLDERS.' AND ur.role_id <> ?::uuid', [OwnerMarker::CODE, $roleId]);
    }

    /**
     * **The ONE "live holder" predicate** (`SEC` D-7.4, `roles-s4-sec-data.md`; roles slice 4 C2): a holder of a role is ANY
     * `user_role` row for it, whatever the user's `is_active` / `deleted_at` — fail closed (E-15h). A deactivated user's row
     * is a privilege-in-waiting (E-15g's strip is unbuilt; `IdentityReader` ignores `is_active`, API-O-22), so an edit
     * changes what it would restore. Read by `UpdateRole`'s `X(admin)`-on-held-role clause and `DeleteRole`'s E-15h
     * refusal (C3, `role.has_holders`). NOT the last-owner floor's predicate, which counts ACTIVE, undeleted marker holders ({@see self::ACTIVE_MARKER_HOLDERS}):
     * that one answers "is an owner left", this one "who would be affected". `GetRole`'s `holder_count` (soft-deleted users
     * excluded) is a display figure, not this.
     */
    public static function holderRowCount(EntityManagerInterface $em, string $roleId): int
    {
        $connection = $em->getConnection();

        return (int) $connection->fetchOne('SELECT count(*) FROM user_role WHERE role_id = ?::uuid', [$roleId]);
    }

    /**
     * **E-15h's pending half for `DeleteRole`** (roles slice 4 C3; `SEC` D-7.2; design §5.2): the role's proposals with
     * `status = 'pending'` — **stale ones included** (an expired proposal not yet lapsed is still `pending`; fail closed) — on
     * a `status = 'pending'` partial index (RLS supplies the tenant predicate). Read under the role lock, so no proposal can
     * be created or decided between this count and the delete.
     */
    public static function pendingProposalCount(EntityManagerInterface $em, string $roleId): int
    {
        $connection = $em->getConnection();

        return (int) $connection->fetchOne("SELECT count(*) FROM role_grant_proposal WHERE role_id = ?::uuid AND status = 'pending'", [$roleId]);
    }

    /**
     * **The holder set beside {@see self::holderRowCount()}, for E-15f's PC-13 re-run** (design §4.7, `SEC` D-7.1): for every
     * `user_role` row of the role (the same fail-closed predicate), the union of that user's codes through their OTHER LIVE
     * roles — `H` in `edges(H∪N) − edges(H) − edges(N) − edges(H∪O)`. One statement for every holder (no N+1), on
     * `user_role_tenant_role_idx`. A role that is archived contributes nothing (it confers nothing).
     *
     * @return array<string, list<string>> user id => sorted codes held through the user's other live roles
     */
    public static function holdersOtherCodes(EntityManagerInterface $em, string $roleId): array
    {
        $connection = $em->getConnection();

        $rows = $connection->fetchAllAssociative(<<<'SQL'
            SELECT ur.user_id::text AS user_id,
                   COALESCE((SELECT array_to_json(array_agg(DISTINCT rp.permission_code ORDER BY rp.permission_code))
                               FROM user_role o
                               JOIN role r ON r.tenant_id = o.tenant_id AND r.id = o.role_id AND r.deleted_at IS NULL
                               JOIN role_permission rp ON rp.tenant_id = r.tenant_id AND rp.role_id = r.id
                              WHERE o.tenant_id = ur.tenant_id AND o.user_id = ur.user_id AND o.role_id <> ur.role_id), '[]') AS codes
              FROM user_role ur
             WHERE ur.role_id = ?::uuid
             ORDER BY ur.user_id
            SQL, [$roleId]);

        $holders = [];
        foreach ($rows as $row) {
            $holders[(string) $row['user_id']] = self::codes((string) $row['codes']);
        }

        return $holders;
    }

    /**
     * The joins above that carry no deletion predicate of their own (`role_permission`) and the one read with none
     * (`user_role`), correct only while both stay deletion class `hard`. Held equal to `IdentityReader`'s,
     * `CurrentUserReader`'s and `RoleReader`'s by `PermissionEnforcementTest`.
     *
     * @return array<string, string>
     */
    public static function joinedTablesRequiringNoDeletionPredicate(): array
    {
        return [
            'user_role' => SchemaManifest::TABLES['user_role']['deletion'],
            'role_permission' => SchemaManifest::TABLES['role_permission']['deletion'],
        ];
    }

    /** @return list<string> */
    private static function codes(string $json): array
    {
        $codes = json_decode($json, true, 4, \JSON_THROW_ON_ERROR);
        \assert(\is_array($codes));

        return array_map(strval(...), array_values($codes));
    }

    /** Not instantiable: every method reads through the locked transaction's own connection. */
    private function __construct()
    {
    }
}
