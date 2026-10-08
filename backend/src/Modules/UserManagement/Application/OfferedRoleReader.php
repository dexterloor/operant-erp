<?php

declare(strict_types=1);

namespace App\Modules\UserManagement\Application;

use App\Platform\Http\Problem\ApiProblem;
use App\Platform\Security\RequestIdentity;
use App\Platform\Tenancy\CurrentTenant;
use App\Platform\Tenancy\SchemaManifest;
use App\Platform\Tenancy\TenantContext;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * **`ListOfferedRoles` — the F-7 picker's data for one subject, for one caller** (users slice 1 C3;
 * `docs/session13/users-s1-design.md` rev 3 §2.2, §6 C3; owner ruling Q-U2, 2026-10-08).
 *
 * The order of the request, and why:
 *  1. **The gate** (`role.assign`, `PermissionGate`, before this runs; E-1) — re-derived from `role_permission` per request,
 *     never the token (`SEC` V-3).
 *  2. **An inactive caller is refused for the whole request**, before the subject is resolved: 409
 *     `user_role.actor_is_inactive`, the same body for an absent id and a real one — `AssignRole`'s order (RED-2 M-1), and
 *     `RoleAdministrationActor::isInactive()`, the one predicate.
 *  3. **The subject:** `{id}` not a uuid, absent or another workspace's → 404 (RLS).
 *  4. **The body, ONE statement** ({@see self::BODY}): the caller's live codes, the subject's state, its grant rows (live
 *     or archived roles — phase 3's input) and the codes of its grants on LIVE roles (phase 4's), every live role with its
 *     codes (the pair lists' partners — never filtered by `archived` or `q`, RED-3 F-3), and the roles to return, read in
 *     the statement's **main `SELECT … FROM role r`** with `r.deleted_at IS [NOT] NULL` and, under `q`,
 *     `lower(r.name) LIKE lower(?)` in its **top-level `WHERE`** — alias `r` bound to `role` and to nothing else, because
 *     `SearchContractTest` reads only the first depth-0 `WHERE` and maps aliases from the `FROM`/`JOIN` text (RED-3 F-2).
 *     One statement, so the body is one snapshot under READ COMMITTED (`RoleReader`'s F15). Steps 2–3 read through
 *     `LiveGrantReader::user()` first (two short statements; DEVIATION 2 in the build note: the body statement returns no
 *     row on an empty page, so it cannot carry the whole-request decisions).
 *  5. The per-role verdicts are {@see OfferedRoleEvaluator}'s, pure.
 *  6. `tenant_has_rows` is probed only on an empty page, against `role` with no filter predicate (API-22).
 *
 * **Never named here:** `role_grant_proposal` (owner ruling Q-1; `SEC` V-4 — slot B is omitted, so no pending state is
 * read; `PermissionDerivationTableSetTest` refuses a literal naming both it and `user_role`), `user_role.inserted_by`, and
 * any `app_user` column but `id`, `tenant_id`, `is_active`, `deleted_at`. No `Infrastructure\Entity` import (`SEC` E-5).
 * *(Measured under `erp_app`: the joined `app_user` scans below may return the whole stored tuple to skip a projection
 * — Postgres' physical-tlist optimisation — so `EXPLAIN (VERBOSE)` lists every column there although none but the join
 * keys and `deleted_at` is read; `UserReadTest`'s column allowlist reads the parents' references for such a scan. The
 * subject's state is read through `json_build_object`, never a whole-row `row_to_json`, which would read every column.)*
 * DBAL on the `erp_app` entity manager inside `TenantContext::run()`; never an autowired `Connection` (the schema owner).
 */
final readonly class OfferedRoleReader
{
    /**
     * The body. Parameters, in order: caller id, subject id ×3, then the main `WHERE`'s `q` pattern when searching.
     * `%s` is the main `WHERE`'s literal predicate text, one of four fixed strings built in {@see self::list()}, never input.
     */
    private const BODY = <<<'SQL'
        WITH caller_codes AS (
            SELECT COALESCE(array_to_json(array_agg(DISTINCT cp.permission_code ORDER BY cp.permission_code)), '[]'::json) AS codes
              FROM user_role cu
              JOIN app_user ca ON ca.tenant_id = cu.tenant_id AND ca.id = cu.user_id AND ca.deleted_at IS NULL
              JOIN role cr ON cr.tenant_id = cu.tenant_id AND cr.id = cu.role_id AND cr.deleted_at IS NULL
              JOIN role_permission cp ON cp.tenant_id = cr.tenant_id AND cp.role_id = cr.id
             WHERE cu.user_id = ?::uuid
        ), subject_state AS (
            SELECT sa.deleted_at IS NOT NULL AS deleted, sa.is_active
              FROM app_user sa
             WHERE sa.id = ?::uuid
        ), held_grants AS (
            SELECT COALESCE(array_to_json(array_agg(hu.role_id::text ORDER BY hu.role_id)), '[]'::json) AS role_ids
              FROM user_role hu
             WHERE hu.user_id = ?::uuid
        ), held_live_codes AS (
            SELECT COALESCE(array_to_json(array_agg(DISTINCT lp.permission_code ORDER BY lp.permission_code)), '[]'::json) AS codes
              FROM user_role lu
              JOIN app_user la ON la.tenant_id = lu.tenant_id AND la.id = lu.user_id AND la.deleted_at IS NULL
              JOIN role lr ON lr.tenant_id = lu.tenant_id AND lr.id = lu.role_id AND lr.deleted_at IS NULL
              JOIN role_permission lp ON lp.tenant_id = lr.tenant_id AND lp.role_id = lr.id
             WHERE lu.user_id = ?::uuid
        ), live_roles AS (
            SELECT COALESCE(json_agg(json_build_object(
                       'id', wr.id,
                       'name', wr.name,
                       'codes', COALESCE((SELECT array_to_json(array_agg(wp.permission_code ORDER BY wp.permission_code))
                                            FROM role_permission wp
                                           WHERE wp.tenant_id = wr.tenant_id AND wp.role_id = wr.id), '[]'::json))
                       ORDER BY wr.name, wr.id), '[]'::json) AS roles
              FROM role wr
             WHERE wr.deleted_at IS NULL
        )
        SELECT r.id, r.name, r.is_system, r.deleted_at IS NULL AS live,
               COALESCE((SELECT array_to_json(array_agg(rp.permission_code ORDER BY rp.permission_code))
                           FROM role_permission rp
                          WHERE rp.tenant_id = r.tenant_id AND rp.role_id = r.id), '[]'::json) AS codes,
               (SELECT codes FROM caller_codes) AS caller_codes,
               (SELECT json_build_object('deleted', ss.deleted, 'is_active', ss.is_active) FROM subject_state ss) AS subject,
               (SELECT role_ids FROM held_grants) AS held_role_ids,
               (SELECT codes FROM held_live_codes) AS held_live_codes,
               (SELECT roles FROM live_roles) AS live_roles
          FROM role r
         WHERE %s
         ORDER BY r.name, r.id
        SQL;

    public function __construct(
        private TenantContext $tenants,
        private CurrentTenant $tenant,
        private RequestIdentity $identities,
    ) {
    }

    /**
     * @return array{items: list<OfferedRoleView>, tenantHasRows: bool}
     */
    public function list(string $subjectId, bool $archived, ?string $search): array
    {
        $callerId = $this->identities->authenticated()->userId;

        return $this->tenants->run($this->tenant->tenantId(), static function (EntityManagerInterface $em) use ($callerId, $subjectId, $archived, $search): array {
            // 2. The whole-request refusal, before the subject is resolved (E-1; RED-2 M-1).
            if (RoleAdministrationActor::isInactive(LiveGrantReader::user($em, $callerId))) {
                throw RoleAdministrationActor::inactiveRefusal('role.assign');
            }

            // 3.
            if (!Uuid::isValid($subjectId)) {
                throw ApiProblem::notFound();
            }
            $subjectId = Uuid::fromString($subjectId)->toRfc4122();
            if (null === LiveGrantReader::user($em, $subjectId)) {
                throw ApiProblem::notFound();
            }

            // 4.
            $where = 'r.deleted_at IS '.($archived ? 'NOT NULL' : 'NULL');
            $parameters = [$callerId, $subjectId, $subjectId, $subjectId];
            if (null !== $search) {
                $where .= ' AND lower(r.name) LIKE lower(?)';
                $parameters[] = addcslashes($search, '%_\\').'%';
            }
            $connection = $em->getConnection();
            $rows = $connection->fetchAllAssociative(\sprintf(self::BODY, $where), $parameters);

            // 6.
            if ([] === $rows) {
                return ['items' => [], 'tenantHasRows' => (bool) $connection->fetchOne('SELECT EXISTS (SELECT 1 FROM role)')];
            }

            // 5. The statement's InitPlans are the same on every row; read them from the first.
            $first = $rows[0];
            $subject = self::json($first['subject']);
            $items = array_map(static fn (array $row): array => [
                'id' => (string) $row['id'],
                'name' => (string) $row['name'],
                'is_system' => (bool) $row['is_system'],
                'live' => (bool) $row['live'],
                'codes' => array_map(strval(...), self::json($row['codes'])),
            ], $rows);
            $liveRoles = array_map(static fn (array $role): array => [
                'id' => (string) $role['id'],
                'name' => (string) $role['name'],
                'codes' => array_map(strval(...), $role['codes']),
            ], self::json($first['live_roles']));

            return ['items' => OfferedRoleEvaluator::evaluate(
                $items,
                $liveRoles,
                ['deleted' => (bool) $subject['deleted'], 'is_active' => (bool) $subject['is_active']],
                $subjectId,
                $callerId,
                array_map(strval(...), self::json($first['caller_codes'])),
                array_map(strval(...), self::json($first['held_role_ids'])),
                array_map(strval(...), self::json($first['held_live_codes'])),
            ), 'tenantHasRows' => true];
        });
    }

    /**
     * The joins above that carry no deletion predicate (`user_role`, `role_permission`), correct only while both stay
     * deletion class `hard`. Held equal to the other readers' by `PermissionEnforcementTest`.
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

    /** @return array<array-key, mixed> */
    private static function json(mixed $value): array
    {
        $decoded = json_decode((string) $value, true, 8, \JSON_THROW_ON_ERROR);
        \assert(\is_array($decoded));

        return $decoded;
    }
}
