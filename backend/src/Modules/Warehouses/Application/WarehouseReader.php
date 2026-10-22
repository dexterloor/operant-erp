<?php

declare(strict_types=1);

namespace App\Modules\Warehouses\Application;

use App\Platform\Tenancy\CurrentTenant;
use App\Platform\Tenancy\TenantContext;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * **`GetWarehouse` and `ListWarehouses`** (warehouses slice 1, C1; `docs/session13/warehouses-s1-design.md` §2 and FINAL
 * item 2; `SupplierReader`'s and `RoleReader`'s shapes) — the warehouse reads, as {@see WarehouseView}s.
 *
 * Every statement runs inside `TenantContext::run()` on the entity manager's own connection (`erp_app`, under RLS), so
 * another workspace's id is not-found exactly as a random one. **Never** a `Doctrine\DBAL\Connection` in the constructor:
 * an unbound autowired `Connection` is the schema owner (A-10). Raw SQL, no entity: `WarehousesApplication` may not reach
 * `WarehousesInfrastructure` (`deptrac.yaml`).
 *
 * The select names the five served columns and nothing else — never `address_line`, `city`, `postal_code` or `is_active`,
 * which no operation writes (Q-W4).
 */
final readonly class WarehouseReader
{
    private const SELECT = <<<'SQL'
        SELECT w.id, w.code, w.name, w.country_code, w.deleted_at
          FROM warehouse w
        SQL;

    public function __construct(
        private TenantContext $tenants,
        private CurrentTenant $tenant,
    ) {
    }

    /**
     * `GetWarehouse`: **no `deleted_at` predicate** — an archived warehouse in the caller's workspace is returned with a
     * non-null `archived_at` (§5.2a's direct hit; ST-9). A non-uuid, absent or foreign id is null (404).
     */
    public function get(string $id): ?WarehouseView
    {
        if (!Uuid::isValid($id)) {
            return null;
        }

        return $this->tenants->run(
            $this->tenant->tenantId(),
            static fn (EntityManagerInterface $em): ?WarehouseView => self::readInside($em, Uuid::fromString($id)->toRfc4122()),
        );
    }

    /** Inside a transaction the caller already holds — the writers' read-back (C2). */
    public static function readInside(EntityManagerInterface $em, string $id): ?WarehouseView
    {
        $row = $em->getConnection()->fetchAssociative(self::SELECT.' WHERE w.id = ?::uuid', [$id]);

        return false === $row ? null : self::view($row);
    }

    /**
     * **`ListWarehouses`** — *(bounded)*, TB-1c: the whole set in one response, ordered `code, id` (the order
     * `warehouse_tenant_code_live` keeps; `code` is live-unique, the `id` only makes the order total across the archived
     * view, where codes may repeat).
     *
     * `archived=true` returns the **archived set instead of** the live one (`ListRoles`' semantics, API-19c limb 1: `IA.md`
     * §5.6 has a `/warehouses Archived` row). `q` (API-19d / TB-5 `warehouse.code`, TB-5a: codes match **as stored**) is a
     * **case-sensitive prefix on `code`** and nothing else — `warehouse.name` is filtered in the client (TB-5). `%`, `_` and
     * `\` are escaped, so they match themselves.
     *
     * API-22: `tenant_has_rows` is probed only on an empty set, as `EXISTS` against `warehouse` under RLS with no filter
     * predicate.
     *
     * `$limit` is `CreateExport`'s cap (G6-C1, design M-7: "bounded lists also read `LIMIT CAP+1`"), never the wire's: when
     * given, the statement is the list's own with `LIMIT limit + 1` appended, so the export learns in the same statement
     * whether the view is over the cap. The list itself passes none and reads the whole bounded view.
     *
     * @return array{items: list<WarehouseView>, tenantHasRows: bool}
     */
    public function list(bool $archived, ?string $search, ?int $limit = null): array
    {
        return $this->tenants->run($this->tenant->tenantId(), static function (EntityManagerInterface $em) use ($archived, $search, $limit): array {
            $connection = $em->getConnection();
            $where = ['w.deleted_at IS '.($archived ? 'NOT NULL' : 'NULL')];
            $parameters = [];
            if (null !== $search) {
                $where[] = 'w.code LIKE ?';
                $parameters[] = addcslashes($search, '%_\\').'%';
            }

            $rows = $connection->fetchAllAssociative(self::SELECT.' WHERE '.implode(' AND ', $where).' ORDER BY w.code, w.id'.(null === $limit ? '' : ' LIMIT ?'), null === $limit ? $parameters : [...$parameters, $limit + 1]);
            $tenantHasRows = [] !== $rows || (bool) $connection->fetchOne('SELECT EXISTS (SELECT 1 FROM warehouse)');

            return ['items' => array_map(self::view(...), $rows), 'tenantHasRows' => $tenantHasRows];
        });
    }

    /** @param array<string, mixed> $row */
    private static function view(array $row): WarehouseView
    {
        return new WarehouseView(
            id: (string) $row['id'],
            code: (string) $row['code'],
            name: (string) $row['name'],
            countryCode: (string) $row['country_code'],
            archivedAt: null === $row['deleted_at']
                ? null
                : new \DateTimeImmutable((string) $row['deleted_at'])->format(\DateTimeInterface::RFC3339_EXTENDED),
        );
    }
}
