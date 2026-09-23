<?php

declare(strict_types=1);

namespace App\Modules\Sales\Application;

use App\Platform\Http\Pagination\Cursor;
use App\Platform\Tenancy\CurrentTenant;
use App\Platform\Tenancy\TenantContext;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * **The read half of `/fulfilments` — `GetFulfilment` (owner ruling R9: built in step 5, so the 201's
 * `Location` names a route that answers).** `GoodsReceiptReader`'s shape, one module over: one statement
 * for the fulfilment and its order and shipper, one for its lines, both inside the tenancy transaction.
 *
 * **Joins, and why each is what it is.** The order join is inner (`fulfilment.sales_order_id` is `NOT NULL`
 * under a composite key) and unfiltered — a fulfilment of an order archived later is still that order's (an
 * archived order is always a draft, so this cannot happen today, but the read does not lean on that). The
 * shipper join is **inner** — `shipped_by_user_id` is `NOT NULL` (owner ruling R6) — and has no `deleted_at`
 * predicate: archiving a person does not un-ship what they shipped. The line joins are `GoodsReceiptReader`'s:
 * the order line (for `line_number`), the item through it (`fulfilment_line` has no item column), and the bin,
 * all unfiltered, because a retired bin or an archived item still names where the goods left from and what
 * they were. Lines are listed in the order's line-number order, then by bin.
 *
 * **`ListFulfilments` (sales step 6, C1)** reads the same row through the same statements: one for the page's
 * headers, one for all of the page's lines (`= ANY`), so a list row is byte-for-byte `GetFulfilment`'s document.
 * Its two orders are `IA.md` §5.6's, both newest first: the register by `fulfilment_number` over
 * `fulfilment_number_unique` (R-1), and one order's shipments by `(shipped_at, id)` over
 * `fulfilment_tenant_order_idx`. **No new index** (D-d: *"measure first"*).
 *
 * ⚠ **The register's number order is a TEXT order, and it is only right while every number has six digits.**
 * `FulfilmentWriter` pads to six and does not cap, so `FUL-1000000` would sort before `FUL-999999`. This is the
 * shape the owner ruled *"record, fix after slice 1"* for the shipped `SO-`/`PO-` order-number sort (`LEAD`'s
 * `RESIDUAL_GAPS.md` row); `FUL-` inherits it and is cited there, not fixed here alone. D-f's fixed-width
 * `CHECK` is what closes it.
 */
final readonly class FulfilmentReader
{
    public function __construct(
        private TenantContext $tenants,
        private CurrentTenant $tenant,
    ) {
    }

    public function get(string $id): ?FulfilmentView
    {
        if (!Uuid::isValid($id)) {
            return null;
        }

        return $this->tenants->run(
            $this->tenant->tenantId(),
            fn (EntityManagerInterface $em): ?FulfilmentView => $this->readInside($em, Uuid::fromString($id)->toRfc4122()),
        );
    }

    /** Inside a transaction the caller already holds. */
    public function readInside(EntityManagerInterface $em, string $id): ?FulfilmentView
    {
        $connection = $em->getConnection();

        $row = $connection->fetchAssociative(self::HEADER_SQL.' WHERE f.id = ?::uuid', [$id]);

        if (false === $row) {
            return null;
        }

        return self::view($row, $connection->fetchAllAssociative(self::LINES_SQL.' WHERE fl.fulfilment_id = ?::uuid'.self::LINES_ORDER, [$id]));
    }

    /**
     * `ListFulfilments` — one keyset page (API-17/API-18), newest first in the view's one order.
     *
     * A `sales_order_id` naming another workspace's order, or no order, is simply a predicate no visible row
     * satisfies: RLS bounds `fulfilment` to the caller's tenant, so the page is empty and the response is the same
     * empty 200 either way (`SEC` §9.1). The order is never probed, so there is nothing to 404 on.
     */
    public function list(FulfilmentListCriteria $criteria): FulfilmentPage
    {
        return $this->tenants->run($this->tenant->tenantId(), function (EntityManagerInterface $em) use ($criteria): FulfilmentPage {
            $connection = $em->getConnection();
            $sort = $criteria->sort();
            $column = 'f.'.$sort;

            $where = [];
            $parameters = [];

            if (null !== $criteria->salesOrderId) {
                $where[] = 'f.sales_order_id = ?::uuid';
                $parameters[] = $criteria->salesOrderId;
            }

            if (null !== $criteria->search) {
                // API-19d / TB-5: one column, the document number, by prefix -- the only form
                // `fulfilment_number_unique` can serve. Wildcards escaped, so `%` and `_` are characters.
                $where[] = 'f.fulfilment_number LIKE ?';
                $parameters[] = addcslashes($criteria->search, '%_\\').'%';
            }

            $cursor = $criteria->after ?? $criteria->before;
            if (null !== $cursor) {
                // API-18: (sort key, id) as ONE row comparison. The list reads newest first, so a forward page
                // continues BELOW the cursor and a backward page above it.
                $where[] = \sprintf('(%s, f.id) %s (?%s, ?::uuid)', $column, $criteria->isBackwards() ? '>' : '<',
                    FulfilmentListCriteria::SORT_SHIPPED_AT === $sort ? '::timestamptz' : '');
                $parameters[] = $cursor->sortKey;
                $parameters[] = $cursor->id;
            }

            $direction = $criteria->isBackwards() ? 'ASC' : 'DESC';
            $sql = self::HEADER_SQL
                .([] === $where ? '' : ' WHERE '.implode(' AND ', $where))
                .\sprintf(' ORDER BY %s %s, f.id %s LIMIT ?', $column, $direction, $direction);
            $parameters[] = $criteria->limit + 1;

            // One row more than the page answers "is there another page" without a COUNT (TB-2b).
            $rows = $connection->fetchAllAssociative($sql, $parameters);
            $hasMore = \count($rows) > $criteria->limit;
            if ($hasMore) {
                array_splice($rows, $criteria->limit);
            }
            if ($criteria->isBackwards()) {
                $rows = array_reverse($rows);
            }

            $linesByFulfilment = [];
            if ([] !== $rows) {
                $ids = array_map(static fn (array $row): string => (string) $row['id'], $rows);
                foreach ($connection->fetchAllAssociative(
                    self::LINES_SQL.' WHERE fl.fulfilment_id = ANY (?::uuid[])'.self::LINES_ORDER,
                    ['{'.implode(',', $ids).'}'],
                ) as $line) {
                    $linesByFulfilment[(string) $line['fulfilment_id']][] = $line;
                }
            }

            [$next, $previous] = self::cursors($criteria, $sort, $rows, $hasMore);

            return new FulfilmentPage(
                items: array_map(static fn (array $row): FulfilmentView => self::view($row, $linesByFulfilment[(string) $row['id']] ?? []), $rows),
                nextCursor: $next,
                previousCursor: $previous,
                // API-22: an EXISTS probe with no filter, only when the page is empty.
                tenantHasRows: [] === $rows ? (bool) $connection->fetchOne('SELECT EXISTS (SELECT 1 FROM fulfilment)') : true,
            );
        });
    }

    private const HEADER_SQL = <<<'SQL'
        SELECT f.id, f.fulfilment_number, f.shipped_at, so.id AS sales_order_id, so.order_number,
               u.id AS shipped_by_id, u.display_name AS shipped_by_display_name
          FROM fulfilment f
          JOIN sales_order so ON so.tenant_id = f.tenant_id AND so.id = f.sales_order_id
          JOIN app_user u ON u.tenant_id = f.tenant_id AND u.id = f.shipped_by_user_id
        SQL;

    private const LINES_SQL = <<<'SQL'
        SELECT fl.id, fl.fulfilment_id, fl.quantity, sol.id AS sales_order_line_id, sol.line_number,
               i.id AS inventory_item_id, i.sku AS inventory_item_sku, i.name AS inventory_item_name,
               sl.id AS storage_location_id, sl.code AS storage_location_code
          FROM fulfilment_line fl
          JOIN sales_order_line sol ON sol.tenant_id = fl.tenant_id AND sol.id = fl.sales_order_line_id
          JOIN inventory_item i ON i.tenant_id = sol.tenant_id AND i.id = sol.inventory_item_id
          JOIN storage_location sl ON sl.tenant_id = fl.tenant_id AND sl.id = fl.storage_location_id
        SQL;

    private const LINES_ORDER = ' ORDER BY sol.line_number, fl.storage_location_id, fl.id';

    /**
     * @param list<array<string, mixed>> $rows in the list's own order
     *
     * @return array{?string, ?string} next, previous
     */
    private static function cursors(FulfilmentListCriteria $criteria, string $sort, array $rows, bool $hasMore): array
    {
        if ([] === $rows) {
            return [null, null];
        }

        // Both sort keys are NOT NULL (`fulfilment_number`, `shipped_at`), so `6fe8b7d`'s empty-key cursor cannot
        // arise here.
        $first = new Cursor((string) $rows[0][$sort], (string) $rows[0]['id']);
        $lastRow = $rows[array_key_last($rows)];
        $last = new Cursor((string) $lastRow[$sort], (string) $lastRow['id']);

        if ($criteria->isBackwards()) {
            return [$last->encode(), $hasMore ? $first->encode() : null];
        }

        return [$hasMore ? $last->encode() : null, null !== $criteria->after ? $first->encode() : null];
    }

    /**
     * @param array<string, mixed>       $row
     * @param list<array<string, mixed>> $lines
     */
    private static function view(array $row, array $lines): FulfilmentView
    {
        return new FulfilmentView(
            id: (string) $row['id'],
            fulfilmentNumber: (string) $row['fulfilment_number'],
            shippedAt: (string) SalesOrderRow::timestamp($row['shipped_at']),
            salesOrder: new SalesOrderRef((string) $row['sales_order_id'], (string) $row['order_number']),
            shippedBy: new SalesActorRef((string) $row['shipped_by_id'], (string) $row['shipped_by_display_name']),
            lines: array_map(static fn (array $line): FulfilmentLineView => new FulfilmentLineView(
                id: (string) $line['id'],
                salesOrderLine: new SalesLineRef((string) $line['sales_order_line_id'], (int) $line['line_number']),
                inventoryItem: new SalesItemRef((string) $line['inventory_item_id'], (string) $line['inventory_item_sku'], (string) $line['inventory_item_name']),
                quantity: (string) $line['quantity'],
                storageLocation: new SalesLocationRef((string) $line['storage_location_id'], (string) $line['storage_location_code']),
            ), $lines),
        );
    }
}
