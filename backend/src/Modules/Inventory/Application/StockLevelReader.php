<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Application;

use App\Platform\Tenancy\CurrentTenant;
use App\Platform\Tenancy\TenantContext;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * **The read behind `ListStockLevels` — `GET /stock`, scoped to exactly one bin or one item** (stock reads C1,
 * `docs/session13/stock-reads-design.md` §2.1 / §4.1; owner ruling Q1 of 2026-10-14).
 *
 * **Bounded (TB-1c):** the whole scope in one page, both cursors null. Server order is the manifest's client order made
 * stable: by item `sku` in a bin, by bin `code` for an item, then the row id.
 *
 * **Joins, all inner and all UNFILTERED.** `inventory_item`, `storage_location` and `warehouse` are reached on NOT NULL
 * keys, and none carries a `deleted_at` or `kind` predicate: stock physically in an archived item, an archived or quarantine
 * bin, or a bin of an archived warehouse is still stock someone has to count (PC-15 condition 3's "a document keeps naming
 * what it named"; `SEC` §1.5 Edit B: *"filtering the join so an archived warehouse's row disappears"* violates PC-9's fourth
 * exception). Such rows are **unmarked** — `RESIDUAL_GAPS.md` G-47. Rows at `qty_on_hand = 0` are served (`UX`, 2026-10-14):
 * an emptied bin must stay on screen to be counted into.
 *
 * The bin and warehouse tables are `Modules\Warehouses`'; reading them by SQL here is the A-2 residual
 * `PickableStockReader` already carries — no PHP edge to that module.
 *
 * **An unknown id, another workspace's id, and a malformed one are the same empty page.** The scope is a filter, not a
 * path parent, so nothing is probed that could 404 (the V2-C-11 / `SEC` §9.6 precedent); RLS bounds every table to the
 * caller's tenant. API Platform refuses a malformed uuid against the parameter schema before the provider runs; the
 * `isValid` guard below keeps a cast error out of reach if that ever stops being true.
 */
final readonly class StockLevelReader
{
    public const SCOPE_LOCATION = 'storage_location_id';
    public const SCOPE_ITEM = 'inventory_item_id';

    public function __construct(
        private TenantContext $tenants,
        private CurrentTenant $tenant,
    ) {
    }

    /**
     * The one statement each scope runs, public so `SchemaQueryPlanTest` explains the real query, never a hand copy of it
     * (customers slice 1 L-4).
     */
    public static function listStatement(string $scope, bool $limited = false): string
    {
        // `$limited` (G6-C0, `CreateExport`'s cap; `g6-c0-red.md` L-4): the same statement with `LIMIT ?` appended, from this
        // one source, so the export never runs a hand-built variant.
        return match ($scope) {
            self::SCOPE_LOCATION => self::SELECT.' WHERE sl.storage_location_id = ?::uuid ORDER BY i.sku, sl.id',
            self::SCOPE_ITEM => self::SELECT.' WHERE sl.inventory_item_id = ?::uuid ORDER BY loc.code, sl.id',
        }.($limited ? ' LIMIT ?' : '');
    }

    /**
     * `$limit` is `CreateExport`'s cap (G6-C0, design M-7: "bounded lists also read `LIMIT CAP+1`"), never the wire's:
     * when given, the statement is the list's own with `LIMIT limit + 1` appended, so the export learns in the same
     * statement whether the view is over the cap. The list itself passes none and reads the whole bounded view.
     *
     * @param self::SCOPE_* $scope
     *
     * @return array{items: list<StockLevelView>, tenantHasRows: bool}
     */
    public function list(string $scope, string $id, ?int $limit = null): array
    {
        return $this->tenants->run($this->tenant->tenantId(), static function (EntityManagerInterface $em) use ($scope, $id, $limit): array {
            $connection = $em->getConnection();

            $statement = self::listStatement($scope, null !== $limit);
            $parameters = null === $limit ? [] : [$limit + 1];
            $rows = Uuid::isValid($id)
                ? $connection->fetchAllAssociative($statement, [Uuid::fromString($id)->toRfc4122(), ...$parameters])
                : [];

            return [
                'items' => array_map(self::view(...), $rows),
                // API-22: an EXISTS probe with no filter, only when the page is empty.
                'tenantHasRows' => [] !== $rows || (bool) $connection->fetchOne('SELECT EXISTS (SELECT 1 FROM stock_level)'),
            ];
        });
    }

    private const SELECT = <<<'SQL'
        SELECT sl.id, sl.qty_on_hand, sl.version,
               i.id AS inventory_item_id, i.sku AS inventory_item_sku, i.name AS inventory_item_name,
               loc.id AS storage_location_id, loc.code AS storage_location_code,
               w.id AS warehouse_id, w.name AS warehouse_name
          FROM stock_level sl
          JOIN inventory_item i ON i.tenant_id = sl.tenant_id AND i.id = sl.inventory_item_id
          JOIN storage_location loc ON loc.tenant_id = sl.tenant_id AND loc.id = sl.storage_location_id
          JOIN warehouse w ON w.tenant_id = loc.tenant_id AND w.id = loc.warehouse_id
        SQL;

    /** @param array<string, mixed> $row */
    private static function view(array $row): StockLevelView
    {
        return new StockLevelView(
            id: (string) $row['id'],
            inventoryItem: new StockItemRef((string) $row['inventory_item_id'], (string) $row['inventory_item_sku'], (string) $row['inventory_item_name']),
            storageLocation: new StockLocationRef((string) $row['storage_location_id'], (string) $row['storage_location_code']),
            warehouse: new StockWarehouseRef((string) $row['warehouse_id'], (string) $row['warehouse_name']),
            qtyOnHand: (string) $row['qty_on_hand'],
            version: (int) $row['version'],
        );
    }
}
