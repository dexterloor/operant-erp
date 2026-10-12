<?php

declare(strict_types=1);

namespace App\Modules\Warehouses\Application;

use App\Platform\Tenancy\CurrentTenant;
use App\Platform\Tenancy\TenantContext;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * **`ListStorageLocations`** (warehouses slice 1, C1; design §1.6, §2.1 and FINAL item 2; `SupplierProductReader`'s
 * shape) — one warehouse's locations, as {@see StorageLocationView}s.
 *
 * *(bounded)*, TB-1c: the whole set, ordered `code, id` (`storage_location_tenant_warehouse_code_live` keeps the order;
 * the `id` makes it total across the archived view). `archived=true` returns the archived locations **instead of** the
 * live ones (API-19c limb 2: a bounded sub-list of a `SchemaManifest::SOFT` table — its text names this operation).
 *
 * **The parent resolves first and includes an archived warehouse** (§5.2a: reading an archived row's data is the
 * direct-hit case); an unknown or another workspace's warehouse is null, the caller's 404. The `warehouse_id` predicate is
 * the ONE place a location is tied to its path's warehouse: another warehouse's locations never appear under this path.
 * Every statement runs under `TenantContext::run()` on the entity manager's own connection (`erp_app`, under RLS) (A-10).
 */
final readonly class StorageLocationReader
{
    private const SELECT = <<<'SQL'
        SELECT l.id, l.code, l.kind, l.deleted_at
          FROM storage_location l
        SQL;

    public function __construct(
        private TenantContext $tenants,
        private CurrentTenant $tenant,
    ) {
    }

    /**
     * @return ?array{items: list<StorageLocationView>, tenantHasRows: bool} null when the warehouse is not visible (404);
     *                                                                       archived warehouses included
     */
    public function list(string $warehouseId, bool $archived): ?array
    {
        if (!Uuid::isValid($warehouseId)) {
            return null;
        }
        $warehouseId = Uuid::fromString($warehouseId)->toRfc4122();

        return $this->tenants->run($this->tenant->tenantId(), static function (EntityManagerInterface $em) use ($warehouseId, $archived): ?array {
            $connection = $em->getConnection();
            // No `deleted_at` predicate: an archived warehouse's locations are read (§5.2a).
            if (false === $connection->fetchOne('SELECT 1 FROM warehouse WHERE id = ?::uuid', [$warehouseId])) {
                return null;
            }

            $rows = $connection->fetchAllAssociative(
                self::SELECT.' WHERE l.warehouse_id = ?::uuid AND l.deleted_at IS '.($archived ? 'NOT NULL' : 'NULL').' ORDER BY l.code, l.id',
                [$warehouseId],
            );
            // API-22: probed only on an empty set, against the resource's table with no filter predicate.
            $tenantHasRows = [] !== $rows || (bool) $connection->fetchOne('SELECT EXISTS (SELECT 1 FROM storage_location)');

            return ['items' => array_map(self::view(...), $rows), 'tenantHasRows' => $tenantHasRows];
        });
    }

    /** @param array<string, mixed> $row */
    private static function view(array $row): StorageLocationView
    {
        return new StorageLocationView(
            id: (string) $row['id'],
            code: (string) $row['code'],
            kind: (string) $row['kind'],
            archivedAt: null === $row['deleted_at']
                ? null
                : new \DateTimeImmutable((string) $row['deleted_at'])->format(\DateTimeInterface::RFC3339_EXTENDED),
        );
    }
}
