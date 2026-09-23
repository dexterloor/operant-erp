<?php

declare(strict_types=1);

namespace App\Modules\UserManagement\Application;

use App\Platform\Tenancy\CurrentTenant;
use App\Platform\Tenancy\SchemaManifest;
use App\Platform\Tenancy\TenantContext;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * `GetRole` and `ListRoles` — the role reads of roles slice 1 (`docs/session13/roles-s1-design.md` §2).
 *
 * ---------------------------------------------------------------------------
 * One statement per read, and only four tables
 * ---------------------------------------------------------------------------
 * The row, its permission codes (`array_agg … ORDER BY`) and `holder_count` come from **one** statement,
 * so the three are one snapshot under READ COMMITTED (RED F15). The statement touches exactly `role`,
 * `role_permission`, `user_role` and `app_user`: `RoleReadTest::theRoleReadsTouchOnlyTheirOwnTables`
 * holds that set by EXPLAIN **and** by text, because nothing served under `role.view` may carry privilege
 * history — `viewer` holds `role.view` (PC-16 condition 1, IA §6.8). **A future proposal table must never
 * be joined here**; response R-10 removed `pending_proposal_count` from the shape for everyone.
 *
 * `holder_count` counts `user_role` rows whose user is not deleted, **regardless of `is_active`**: until
 * E-15g's stripping is built a deactivated user still holds the grant and regains it on reactivation, so
 * the grant exists and is counted. **It is a display predicate only** — it does NOT define E-15h's "live
 * holder" for `DeleteRole`, which must fail closed and is `SEC`'s to rule (RED F10). It counts whatever
 * is in `user_role`, so a grant inserted directly as `erp_app` (G-10) is counted like any other: this read
 * neither detects nor hides one. Cite G-10's reconciliation, never this number, as evidence of a clean set.
 *
 * Scoping: every statement runs inside `TenantContext::run()` on the `erp_app` entity manager, and the
 * four tables' **forced** RLS decides the tenant — so another tenant's role id is not-found exactly as a
 * random one is. **Never** take a `Doctrine\DBAL\Connection` in the constructor: an unbound autowired
 * `Connection` is the schema owner. **No `Infrastructure\Entity` import** — the design's constraint, so
 * this class costs no A-7 register line (RED F13).
 */
final readonly class RoleReader
{
    /** The select list both reads share: one row, its codes, its holder count. */
    private const SELECT = <<<'SQL'
        SELECT r.id, r.name, r.description, r.is_system, r.deleted_at,
               COALESCE((SELECT array_to_json(array_agg(rp.permission_code ORDER BY rp.permission_code))
                           FROM role_permission rp
                          WHERE rp.tenant_id = r.tenant_id AND rp.role_id = r.id), '[]') AS permission_codes,
               (SELECT count(*)
                  FROM user_role ur
                  JOIN app_user u ON u.tenant_id = ur.tenant_id AND u.id = ur.user_id
                 WHERE ur.tenant_id = r.tenant_id AND ur.role_id = r.id AND u.deleted_at IS NULL) AS holder_count
          FROM role r
        SQL;

    public function __construct(
        private TenantContext $tenants,
        private CurrentTenant $tenant,
    ) {
    }

    /**
     * §5.2a / API-13a: **no `deleted_at` predicate**. An archived role in the caller's tenant is returned
     * with a non-null `archived_at`, read as a record — `SalesOrderReader::get()`'s precedent.
     */
    public function get(string $id): ?RoleView
    {
        if (!Uuid::isValid($id)) {
            return null;
        }

        return $this->tenants->run($this->tenant->tenantId(), static function (EntityManagerInterface $em) use ($id): ?RoleView {
            $row = $em->getConnection()->fetchAssociative(self::SELECT.' WHERE r.id = ?::uuid', [Uuid::fromString($id)->toRfc4122()]);

            return false === $row ? null : self::view($row);
        });
    }

    /**
     * `ListRoles` — the live view (`deleted_at IS NULL`) or the archived one (`IS NOT NULL`), optionally
     * narrowed by `q`, a **case-sensitive prefix of `role.name`** and of nothing else (TB-5 names `role.name`;
     * `%` and `_` escaped, so they match themselves). One statement for the page, ordered by `name`, then `id`
     * (the manifest's *"client-side by name"*; *(bounded)*, so no cursor).
     *
     * API-22: `tenant_has_rows` is probed **only on an empty page**, as `EXISTS` against `role` under RLS with
     * no filter predicate — archived and `q` are filters, so neither is in the probe.
     *
     * @return array{items: list<RoleView>, tenantHasRows: bool}
     */
    public function list(bool $archived, ?string $search): array
    {
        return $this->tenants->run($this->tenant->tenantId(), static function (EntityManagerInterface $em) use ($archived, $search): array {
            $connection = $em->getConnection();
            $where = ['r.deleted_at IS '.($archived ? 'NOT NULL' : 'NULL')];
            $parameters = [];
            if (null !== $search) {
                $where[] = 'r.name LIKE ?';
                $parameters[] = addcslashes($search, '%_\\').'%';
            }

            $rows = $connection->fetchAllAssociative(self::SELECT.' WHERE '.implode(' AND ', $where).' ORDER BY r.name, r.id', $parameters);
            $tenantHasRows = [] !== $rows || (bool) $connection->fetchOne('SELECT EXISTS (SELECT 1 FROM role)');

            return ['items' => array_map(self::view(...), $rows), 'tenantHasRows' => $tenantHasRows];
        });
    }

    /**
     * The two joins inside {@see self::SELECT} carry no deletion predicate, which is correct only while
     * `user_role` and `role_permission` stay deletion class `hard`. Held equal to `CurrentUserReader`'s and
     * `IdentityReader`'s by `PermissionEnforcementTest` (RED F9), so the three readers cannot drift apart.
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
    private static function view(array $row): RoleView
    {
        $codes = json_decode((string) $row['permission_codes'], true, 4, \JSON_THROW_ON_ERROR);
        \assert(\is_array($codes));

        return new RoleView(
            id: (string) $row['id'],
            name: (string) $row['name'],
            description: (string) $row['description'],
            permissionCodes: array_map(strval(...), array_values($codes)),
            isSystem: (bool) $row['is_system'],
            holderCount: (int) $row['holder_count'],
            archivedAt: null === $row['deleted_at']
                ? null
                : new \DateTimeImmutable((string) $row['deleted_at'])->format(\DateTimeInterface::RFC3339_EXTENDED),
        );
    }
}
