<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Application;

use App\Platform\Http\Pagination\Cursor;
use App\Platform\Tenancy\CurrentTenant;
use App\Platform\Tenancy\TenantContext;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * **The read behind `/stock-movements` — `GetStockMovement` and `ListStockMovements`** (stock reads C2,
 * `docs/session13/stock-reads-design.md` §2.2–§2.3 and Revision 1; `DATA` / `SEC` / `UX` role files of 2026-10-14).
 *
 * **Every column the table has** (§4.4.3), with the item and the bin as `{id, sku, name}` / `{id, code}` through inner,
 * unfiltered joins — a movement keeps naming what it moved even after the item or bin is archived. `source_type` and
 * `source_id` are served **bare**: the table's own columns under its own code (PC-9 not engaged), never resolved to the
 * document (`SEC` §2, Low; a composed role without the document's `.view` sees the kind and the id, never the document).
 *
 * **Order: newest first by BUSINESS time** (`UX` §6), `occurred_at DESC, id DESC`, in both views. `occurred_at` is the
 * document's own, caller-supplied instant at whole-second precision — every writer binds `->format(\DATE_ATOM)`
 * (`StockReceiver`, `StockDepleter`, `StockReturner`) — so a document back-dated to one second shares a TIE GROUP with
 * every other movement of that second, and nothing bounds its size. The keyset is therefore the pair, as ONE row
 * comparison, `(m.occurred_at, m.id) < (?::timestamptz, ?::uuid)` forward, `>` backward (reversed in PHP), served by
 * `stock_movement_tenant_occurred_id_idx` / `stock_movement_tenant_item_occurred_id_idx`, which end `(occurred_at, id)`
 * so the whole comparison is an index condition and `LIMIT` stops the scan inside a group (`DATA` §1; C2's migration).
 *
 * **The page is cut from `stock_movement` ALONE, and only then joined to its names** (shipped-defect fix, 2026-10-16,
 * `docs/session13/ledger-plan-fix-progress.md`). With the joins beside the `LIMIT`, whether the index's order reached the
 * `LIMIT` depended on the join the planner chose: Postgres estimates a row comparison from its FIRST column alone, so a
 * cursor inside a tie group (`occurred_at < T` where T is the group's own instant) is estimated at ONE row while thousands
 * match. At one row a sort costs nothing on paper, and the planner flipped, seed by seed, between a nested loop that keeps
 * the index order and stops at `LIMIT`, and a merge join with `storage_location` that read and sorted the WHOLE tie group
 * before a top-N sort (measured: 1 run in 10 of `SchemaQueryPlanTest`'s per-item tie-group test). With `ORDER BY … LIMIT`
 * inside the derived table, the scan is stopped by `LIMIT` whatever join follows; the outer `ORDER BY` restores the order
 * over at most `limit + 1` rows. **Same rows:** both joins are inner on `stock_movement_item_fk` /
 * `stock_movement_location_fk` — validated, not deferrable, `(tenant_id, x) → (tenant_id, id)`, columns `NOT NULL` — and the
 * three tables' RLS is the same tenant predicate, so every visible movement joins exactly one item and one bin: joining
 * after the cut neither drops nor multiplies a row.
 *
 * **The cursor key is the column's text exactly as Postgres returns it** (microseconds and offset), never the wire's
 * millisecond rendering: no row carries a fraction today, but `DEFAULT now()` would give one to a writer that omits the
 * value, and a millisecond key would then skip or repeat rows. **Assumption, stated (L-5, `DATA` §2):** the session's
 * `DateStyle` is ISO and its `TimeZone` is fixed for the connection, so the text round-trips through `?::timestamptz`
 * exactly; a non-ISO `DateStyle` such as `SQL, DMY` could parse ambiguously. The provider refuses a cursor whose key is not
 * such a timestamp (`malformed_cursor`) before the cast can raise.
 *
 * An `inventory_item_id` naming no visible item — unknown, or another workspace's — is a predicate nothing satisfies under
 * RLS: the same empty page, never a 404.
 */
final readonly class StockMovementReader
{
    public function __construct(
        private TenantContext $tenants,
        private CurrentTenant $tenant,
    ) {
    }

    public function get(string $id): ?StockMovementView
    {
        if (!Uuid::isValid($id)) {
            return null;
        }

        return $this->tenants->run($this->tenant->tenantId(), static function (EntityManagerInterface $em) use ($id): ?StockMovementView {
            $row = $em->getConnection()->fetchAssociative(
                self::SELECT.' FROM stock_movement m '.self::JOINS.' WHERE m.id = ?::uuid',
                [Uuid::fromString($id)->toRfc4122()],
            );

            return false === $row ? null : self::view($row);
        });
    }

    public function list(StockMovementListCriteria $criteria): StockMovementPage
    {
        return $this->tenants->run($this->tenant->tenantId(), static function (EntityManagerInterface $em) use ($criteria): StockMovementPage {
            $connection = $em->getConnection();
            [$sql, $parameters] = self::listStatement($criteria);

            // One row more than the page answers "is there another page" without a COUNT (TB-2b).
            $rows = $connection->fetchAllAssociative($sql, $parameters);
            $hasMore = \count($rows) > $criteria->limit;
            if ($hasMore) {
                array_splice($rows, $criteria->limit);
            }
            if ($criteria->isBackwards()) {
                $rows = array_reverse($rows);
            }

            [$next, $previous] = self::cursors($criteria, $rows, $hasMore);

            return new StockMovementPage(
                items: array_map(self::view(...), $rows),
                nextCursor: $next,
                previousCursor: $previous,
                // API-22: an EXISTS probe with no filter, only when the page is empty.
                tenantHasRows: [] === $rows ? (bool) $connection->fetchOne('SELECT EXISTS (SELECT 1 FROM stock_movement)') : true,
            );
        });
    }

    /**
     * The one statement a `ListStockMovements` page runs — public so `SchemaQueryPlanTest`'s movement-ledger tests explain
     * **this** statement, never a hand copy of it (customers slice 1 RED L-4; `DATA` §1.4 item 2).
     *
     * @return array{string, list<mixed>}
     */
    public static function listStatement(StockMovementListCriteria $criteria): array
    {
        $where = [];
        $parameters = [];

        if (null !== $criteria->inventoryItemId) {
            $where[] = 'm.inventory_item_id = ?::uuid';
            $parameters[] = $criteria->inventoryItemId;
        }

        $descending = !$criteria->isBackwards();
        $cursor = $criteria->after ?? $criteria->before;
        if (null !== $cursor) {
            // API-18: the pair as ONE row comparison, never the key alone — a tie group shares its `occurred_at`.
            $where[] = \sprintf('(m.occurred_at, m.id) %s (?::timestamptz, ?::uuid)', $descending ? '<' : '>');
            $parameters[] = $cursor->sortKey;
            $parameters[] = $cursor->id;
        }

        $direction = $descending ? 'DESC' : 'ASC';
        $order = \sprintf(' ORDER BY m.occurred_at %s, m.id %s', $direction, $direction);
        // The page first, from `stock_movement` alone, so `LIMIT` stops the index scan whatever join follows; then the
        // names, and the order again over at most `limit + 1` rows (class docblock, 2026-10-16).
        $sql = self::SELECT
            .' FROM (SELECT '.self::MOVEMENT_COLUMNS.' FROM stock_movement m'
            .([] === $where ? '' : ' WHERE '.implode(' AND ', $where))
            .$order.' LIMIT ?) m '
            .self::JOINS
            .$order;
        $parameters[] = $criteria->limit + 1;

        return [$sql, $parameters];
    }

    private const SELECT = <<<'SQL'
        SELECT m.id, m.quantity, m.occurred_at, m.reason, m.source_type, m.source_id,
               i.id AS inventory_item_id, i.sku AS inventory_item_sku, i.name AS inventory_item_name,
               loc.id AS storage_location_id, loc.code AS storage_location_code
        SQL;

    /** What the derived page carries: everything {@see self::SELECT} and {@see self::JOINS} read from `m`. */
    private const MOVEMENT_COLUMNS = 'm.id, m.tenant_id, m.inventory_item_id, m.storage_location_id, m.quantity, m.occurred_at, m.reason, m.source_type, m.source_id';

    private const JOINS = <<<'SQL'
        JOIN inventory_item i ON i.tenant_id = m.tenant_id AND i.id = m.inventory_item_id
          JOIN storage_location loc ON loc.tenant_id = m.tenant_id AND loc.id = m.storage_location_id
        SQL;

    /**
     * @param list<array<string, mixed>> $rows in the list's own order
     *
     * @return array{?string, ?string} next, previous
     */
    private static function cursors(StockMovementListCriteria $criteria, array $rows, bool $hasMore): array
    {
        if ([] === $rows) {
            return [null, null];
        }

        // `occurred_at` is NOT NULL, so `6fe8b7d`'s empty-key cursor cannot arise. The key is the raw column text.
        $first = new Cursor((string) $rows[0]['occurred_at'], (string) $rows[0]['id']);
        $lastRow = $rows[array_key_last($rows)];
        $last = new Cursor((string) $lastRow['occurred_at'], (string) $lastRow['id']);

        if ($criteria->isBackwards()) {
            return [$last->encode(), $hasMore ? $first->encode() : null];
        }

        return [$hasMore ? $last->encode() : null, null !== $criteria->after ? $first->encode() : null];
    }

    /** @param array<string, mixed> $row */
    private static function view(array $row): StockMovementView
    {
        return new StockMovementView(
            id: (string) $row['id'],
            quantity: (string) $row['quantity'],
            occurredAt: new \DateTimeImmutable((string) $row['occurred_at'])->format(\DateTimeInterface::RFC3339_EXTENDED),
            reason: (string) $row['reason'],
            sourceType: null === $row['source_type'] ? null : (string) $row['source_type'],
            sourceId: null === $row['source_id'] ? null : (string) $row['source_id'],
            inventoryItem: new StockItemRef((string) $row['inventory_item_id'], (string) $row['inventory_item_sku'], (string) $row['inventory_item_name']),
            storageLocation: new StockLocationRef((string) $row['storage_location_id'], (string) $row['storage_location_code']),
        );
    }
}
