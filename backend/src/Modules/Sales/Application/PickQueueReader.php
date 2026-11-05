<?php

declare(strict_types=1);

namespace App\Modules\Sales\Application;

use App\Platform\Http\Pagination\Cursor;
use App\Platform\Tenancy\CurrentTenant;
use App\Platform\Tenancy\TenantContext;
use Doctrine\ORM\EntityManagerInterface;

/**
 * **`ListPickQueueEntries` — the sales orders waiting to be picked** (BK-21; owner D-5 of 2026-11-03, approved in
 * principle; `docs/design/bk21-design.md` Revision 1, final review `bk21-final-red.md`).
 *
 * The rows are the open segment (`SalesOrderViews::SEGMENTS['open']`: confirmed or partially fulfilled), live, with
 * `ordered_at` set, oldest first by `(ordered_at, id)`. Each carries only the order's number, status and date and its
 * customer's and warehouse's `{id, name}` — `SECURITY_MODEL.md` PC-9's fifth exception.
 *
 * **Four things about the `ordered_at IS NOT NULL` predicate:**
 *  1. It guards against rows only owner SQL can write. `sales_order` has no `ordered_at` CHECK; `confirm()` is the only
 *     writer of the column and both open statuses are reached only through it. A confirmed row given a NULL date by direct
 *     SQL would otherwise be dropped from every cursored page, and a page ending on it would emit a cursor keyed on `''`
 *     (the `6fe8b7d` shape; `RESIDUAL_GAPS.md` G-94 records the same exposure on the shipped `ListSalesOrders` open view).
 *  2. `GetPickList` still reads such a row by id, because its visibility is status-first (`PickListReader`). The
 *     asymmetry is deliberate: the queue's job is paging, and a NULL key cannot be paged.
 *  3. It is not derived from `SalesOrderViews::legalSorts()`: the queue has one view and no `sort` parameter.
 *  4. The cursor's `(string)` cast below is safe only because of it.
 *
 * **The page is cut from `sales_order` alone, then joined and ordered again** (`ed3dede`'s rule, final review M-1): the
 * inner statement walks a partial index in `(ordered_at, id)` order and stops at `limit + 1`, so the planner's join choice
 * for the two display joins cannot decide whether the scan stops at the page. The joins are inner and unfiltered, as
 * `SalesOrderRow::FROM`'s: both FKs are NOT NULL, and an archived customer or warehouse still names the order's own.
 *
 * The unfiltered view uses `sales_order_tenant_open_idx (tenant_id, ordered_at)`, which has no `id`, so an Incremental
 * Sort on `id` inside each `ordered_at` tie group is accepted there (the `ed3dede` precedent): real tie groups are one
 * order wide, because `confirm()` stamps each order with its own transaction's clock. The view filtered to a warehouse
 * uses `sales_order_tenant_warehouse_awaiting_pick_idx (tenant_id, warehouse_id, ordered_at, id)` and needs no sort at all.
 *
 * `deleted_at IS NULL` is redundant (an archived order is always a draft by CHECK), but it is both partial indexes'
 * predicate, and the planner needs it to choose them. The statuses are bound as two placeholders, never literals (API-15),
 * in the `IN (?, ?)` form that matches the indexes' `= ANY (ARRAY[...])` predicate.
 */
final readonly class PickQueueReader
{
    public function __construct(
        private TenantContext $tenants,
        private CurrentTenant $tenant,
    ) {
    }

    public function list(PickQueueCriteria $criteria): PickQueuePage
    {
        return $this->tenants->run($this->tenant->tenantId(), function (EntityManagerInterface $em) use ($criteria): PickQueuePage {
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

            return new PickQueuePage(
                items: array_map(self::view(...), $rows),
                nextCursor: $next,
                previousCursor: $previous,
                // API-22, over this read's own BASE VIEW (the row predicate without the warehouse, `q` and cursor filters),
                // never over `sales_order` as a whole: a drafts-only workspace must not tell a `fulfilment.create` holder
                // without `sales_order.view` that drafts exist (PC-9's fifth exception).
                tenantHasRows: [] === $rows ? (bool) $connection->fetchOne(
                    'SELECT EXISTS (SELECT 1 FROM sales_order so WHERE '.self::BASE_PREDICATE.')',
                    SalesOrderViews::SEGMENTS['open'],
                ) : true,
            );
        });
    }

    /**
     * The list's statement and its parameters, static so `SchemaQueryPlanTest` explains exactly this text.
     *
     * @return array{string, list<mixed>}
     */
    public static function listStatement(PickQueueCriteria $criteria): array
    {
        $where = [self::BASE_PREDICATE];
        $parameters = SalesOrderViews::SEGMENTS['open'];

        if (null !== $criteria->warehouseId) {
            $where[] = 'so.warehouse_id = ?::uuid';
            $parameters[] = $criteria->warehouseId;
        }

        if (null !== $criteria->search) {
            // API-19d / TB-5: one column, the document number, by prefix. Wildcards escaped, so `%` and `_` are characters.
            $where[] = 'so.order_number LIKE ?';
            $parameters[] = addcslashes($criteria->search, '%_\\').'%';
        }

        $cursor = $criteria->after ?? $criteria->before;
        if (null !== $cursor) {
            // API-18: (ordered_at, id) as ONE row comparison, so a tie group pages correctly. Oldest first, so a forward
            // page continues ABOVE the cursor. The key is the column's raw text, cast back to its own type; the provider
            // has already refused a key that is not a timestamp.
            $where[] = \sprintf('(so.ordered_at, so.id) %s (?::timestamptz, ?::uuid)', $criteria->isBackwards() ? '<' : '>');
            $parameters[] = $cursor->sortKey;
            $parameters[] = $cursor->id;
        }

        $direction = $criteria->isBackwards() ? 'DESC' : 'ASC';
        $sql = 'SELECT p.id, p.order_number, p.status, p.ordered_at,'
            .' c.id AS customer_id, c.name AS customer_name, w.id AS warehouse_id, w.name AS warehouse_name'
            .' FROM (SELECT so.id, so.tenant_id, so.order_number, so.status, so.ordered_at, so.customer_id, so.warehouse_id'
            .' FROM sales_order so'
            .' WHERE '.implode(' AND ', $where)
            .\sprintf(' ORDER BY so.ordered_at %s, so.id %s LIMIT ?) p', $direction, $direction)
            .' JOIN customer c ON c.tenant_id = p.tenant_id AND c.id = p.customer_id'
            .' JOIN warehouse w ON w.tenant_id = p.tenant_id AND w.id = p.warehouse_id'
            .\sprintf(' ORDER BY p.ordered_at %s, p.id %s', $direction, $direction);
        $parameters[] = $criteria->limit + 1;

        return [$sql, $parameters];
    }

    /** The queue's base view: live, open, released. Two status placeholders, bound from `SalesOrderViews::SEGMENTS['open']`. */
    private const BASE_PREDICATE = 'so.deleted_at IS NULL AND so.status IN (?, ?) AND so.ordered_at IS NOT NULL';

    /**
     * @param list<array<string, mixed>> $rows in the list's own order
     *
     * @return array{?string, ?string} next, previous
     */
    private static function cursors(PickQueueCriteria $criteria, array $rows, bool $hasMore): array
    {
        if ([] === $rows) {
            return [null, null];
        }

        // The key is `ordered_at` as Postgres rendered it (microseconds), never the millisecond wire value. NOT NULL by
        // the row predicate, which is the only thing that makes this cast safe.
        $first = new Cursor((string) $rows[0]['ordered_at'], (string) $rows[0]['id']);
        $lastRow = $rows[array_key_last($rows)];
        $last = new Cursor((string) $lastRow['ordered_at'], (string) $lastRow['id']);

        if ($criteria->isBackwards()) {
            return [$last->encode(), $hasMore ? $first->encode() : null];
        }

        return [$hasMore ? $last->encode() : null, null !== $criteria->after ? $first->encode() : null];
    }

    /** @param array<string, mixed> $row */
    private static function view(array $row): PickQueueEntryView
    {
        return new PickQueueEntryView(
            id: (string) $row['id'],
            orderNumber: (string) $row['order_number'],
            status: (string) $row['status'],
            orderedAt: (string) SalesOrderRow::timestamp($row['ordered_at']),
            customer: new SalesNamedRef((string) $row['customer_id'], (string) $row['customer_name']),
            warehouse: new SalesNamedRef((string) $row['warehouse_id'], (string) $row['warehouse_name']),
        );
    }
}
