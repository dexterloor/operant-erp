<?php

declare(strict_types=1);

namespace App\Modules\UserManagement\Application;

use App\Modules\UserManagement\Domain\MarginExposure;
use App\Platform\Tenancy\CurrentTenant;
use App\Platform\Tenancy\SchemaManifest;
use App\Platform\Tenancy\TenantContext;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * **`GetUser` and `ListUsers` — the user reads of users slice 1** (C1/C2; `docs/session13/users-s1-design.md` rev 3 §2.1,
 * §2.3, §2.4; `SEC` `users-s1-sec.md` §1.3, E-3, E-5).
 *
 * **One statement for the body** (`RoleReader`'s F15 precedent; RED M-6): the user row, its grants on LIVE roles and those
 * roles' codes come from one statement, so the body is one snapshot under READ COMMITTED and cannot contradict itself.
 * The empty-page `tenant_has_rows` probe (`ListUsers`) is a second statement (API-22; RED-2 L-4).
 *
 * **What is read from `app_user`, and what is never read** (`SEC` E-5; RED M-5): `erp_app` holds SELECT on every column
 * of `app_user`, `password_hash` included (the ORM maps it), so the control is here, not in the grant: the statement
 * names its columns — `id`, `email`, `display_name`, `is_active`, `deleted_at`, and `tenant_id` as a join key — and
 * never `*`, `password_hash`, `last_login_at`, `created_at` or `updated_at`. This class imports no
 * `Infrastructure\Entity`: an `AppUser` hydration would load `password_hash` into memory and into any error path.
 * `UserReadTest::theUserReadsReadOnlyTheirAllowedColumns` derives the column set from `EXPLAIN (VERBOSE)`.
 *
 * **`roles[]` reports what the user holds now** (§2.1): grants on live roles only (`role.deleted_at IS NULL`), and `[]`
 * for a soft-deleted user, because a removed principal holds nothing and cannot authenticate (`LiveGrantReader::
 * rolesHeldBy`, `IdentityReader`). An INACTIVE user's grants ARE listed (stored rows; a deactivation strips them from G4-C1), though since G4-C0 its token no longer authenticates (API-O-22 closed (a), `IdentityReader`).
 * A grant written directly by SQL (G-10) is displayed exactly as a real one — this read is not a detector, and nobody may
 * cite it as one (`SEC` E-3). `user_role.inserted_by` is never read. No pending or proposal state: `role_grant_proposal`
 * is never named here (owner ruling Q-1, 2026-10-01).
 *
 * `margin_exposure` is PC-12 clause 3's union warning over the codes of `roles[]` (`MarginExposure::of`), computed from
 * the codes the same statement read; a warning, never a refusal.
 *
 * Scoping: inside `TenantContext::run()` on the `erp_app` entity manager; forced RLS on `app_user`, `user_role`, `role`
 * and `role_permission` decides the tenant, so another workspace's id is not-found exactly as a random one. **Never** a
 * `Doctrine\DBAL\Connection` in the constructor: an unbound autowired `Connection` is the schema owner.
 */
final readonly class UserReader
{
    /**
     * The select both reads share. The roles subquery carries `u.deleted_at IS NULL`, so a soft-deleted user's grants
     * contribute nothing (`roles[] = []`).
     */
    private const SELECT = <<<'SQL'
        SELECT u.id, u.email, u.display_name, u.is_active, u.deleted_at,
               COALESCE((SELECT json_agg(json_build_object(
                                     'id', r.id,
                                     'name', r.name,
                                     'codes', COALESCE((SELECT array_to_json(array_agg(rp.permission_code ORDER BY rp.permission_code))
                                                          FROM role_permission rp
                                                         WHERE rp.tenant_id = r.tenant_id AND rp.role_id = r.id), '[]'::json))
                                 ORDER BY r.name, r.id)
                           FROM user_role ur
                           JOIN role r ON r.tenant_id = ur.tenant_id AND r.id = ur.role_id AND r.deleted_at IS NULL
                          WHERE ur.tenant_id = u.tenant_id AND ur.user_id = u.id AND u.deleted_at IS NULL), '[]'::json) AS roles
          FROM app_user u
        SQL;

    public function __construct(
        private TenantContext $tenants,
        private CurrentTenant $tenant,
    ) {
    }

    /**
     * `GetUser`: **no `deleted_at` predicate** — a soft-deleted user in the caller's workspace is returned with a non-null
     * `archived_at` (§2.4; `RoleReader::get()`'s precedent).
     */
    public function get(string $id): ?UserView
    {
        if (!Uuid::isValid($id)) {
            return null;
        }

        return $this->tenants->run($this->tenant->tenantId(), static function (EntityManagerInterface $em) use ($id): ?UserView {
            $row = $em->getConnection()->fetchAssociative(self::SELECT.' WHERE u.id = ?::uuid', [Uuid::fromString($id)->toRfc4122()]);

            return false === $row ? null : self::view($row);
        });
    }

    /**
     * `ListUsers`: the live view (`deleted_at IS NULL`) or the archived one, optionally narrowed by `q`, a
     * **case-insensitive prefix of `app_user.email`** and of nothing else (TB-5 names `app_user.email`; API-19d) — never
     * `display_name`, never a substring. `%`, `_` and `\` are escaped before `lower()`; `email` is stored lower-case, so
     * `email LIKE lower(?)` is case-insensitive. Ordered `email, id` (both NOT NULL: no nullable-sort-key trap). *(bounded)*,
     * so no cursor. `tenant_has_rows` is probed only on an empty page, against `app_user` with no filter predicate (API-22).
     *
     * `$limit` is `CreateExport`'s cap (G6-C1, design M-7: "bounded lists also read `LIMIT CAP+1`"), never the wire's: when
     * given, the statement is the list's own with `LIMIT limit + 1` appended, so the export learns in the same statement
     * whether the view is over the cap. The list itself passes none and reads the whole bounded view.
     *
     * @return array{items: list<UserView>, tenantHasRows: bool}
     */
    public function list(bool $archived, ?string $search, ?int $limit = null): array
    {
        return $this->tenants->run($this->tenant->tenantId(), static function (EntityManagerInterface $em) use ($archived, $search, $limit): array {
            $connection = $em->getConnection();
            $where = ['u.deleted_at IS '.($archived ? 'NOT NULL' : 'NULL')];
            $parameters = [];
            if (null !== $search) {
                $where[] = 'u.email LIKE lower(?)';
                $parameters[] = addcslashes($search, '%_\\').'%';
            }

            $rows = $connection->fetchAllAssociative(self::SELECT.' WHERE '.implode(' AND ', $where).' ORDER BY u.email, u.id'.(null === $limit ? '' : ' LIMIT ?'), null === $limit ? $parameters : [...$parameters, $limit + 1]);
            $tenantHasRows = [] !== $rows || (bool) $connection->fetchOne('SELECT EXISTS (SELECT 1 FROM app_user)');

            return ['items' => array_map(self::view(...), $rows), 'tenantHasRows' => $tenantHasRows];
        });
    }

    /**
     * The joins inside {@see self::SELECT} that carry no deletion predicate (`user_role`, `role_permission`), correct only
     * while both stay deletion class `hard`. Held equal to the other readers' by `PermissionEnforcementTest`.
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

    /** @param array<string, mixed> $row */
    private static function view(array $row): UserView
    {
        $roles = json_decode((string) $row['roles'], true, 6, \JSON_THROW_ON_ERROR);
        \assert(\is_array($roles));
        $codes = [];
        $references = [];
        foreach ($roles as $role) {
            \assert(\is_array($role) && \is_array($role['codes']));
            $references[] = ['id' => (string) $role['id'], 'name' => (string) $role['name']];
            foreach ($role['codes'] as $code) {
                $codes[] = (string) $code;
            }
        }

        return new UserView(
            id: (string) $row['id'],
            email: (string) $row['email'],
            displayName: (string) $row['display_name'],
            isActive: (bool) $row['is_active'],
            archivedAt: null === $row['deleted_at']
                ? null
                : new \DateTimeImmutable((string) $row['deleted_at'])->format(\DateTimeInterface::RFC3339_EXTENDED),
            roles: $references,
            // PC-12 clause 3, the union already held (design §5.1): derived from the codes this same statement read.
            marginExposure: MarginExposure::of(array_values(array_unique($codes))),
        );
    }
}
