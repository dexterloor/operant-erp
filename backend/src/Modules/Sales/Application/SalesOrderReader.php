<?php

declare(strict_types=1);

namespace App\Modules\Sales\Application;

use App\Modules\Sales\ApiResource\SalesOrderCollection;
use App\Modules\Sales\ApiResource\SalesOrderLineCollection;
use App\Modules\Sales\ApiResource\SalesOrderLineResource;
use App\Modules\Sales\ApiResource\SalesOrderResource;
use App\Platform\Http\Pagination\Cursor;
use App\Platform\Tenancy\CurrentTenant;
use App\Platform\Tenancy\TenantContext;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * The read half of the `/sales-orders` slice.
 *
 * ---------------------------------------------------------------------------
 * P-7 — the transaction does not span serialization
 * ---------------------------------------------------------------------------
 * Every method here opens exactly one tenancy transaction, hydrates completely into DTOs of scalars,
 * and returns. Nothing it hands back can issue a query, so the response is serialized after the commit.
 * ADR-002 A1.7 measured 1505 ms of pooled-backend pinning when the transaction was held across the rest
 * of the request; and this entity has **three** tenant-scoped associations (customer, warehouse, creator)
 * plus a currency, so an entity-graph response would have four ways to raise after the commit rather
 * than one.
 *
 * ---------------------------------------------------------------------------
 * Why DBAL rather than DQL
 * ---------------------------------------------------------------------------
 * `PurchaseOrderReader`'s reasons, which are not weaker here: API-13a requires the soft-delete predicate
 * on a list and **not** on a direct hit, which one globally enabled Doctrine filter cannot express; and
 * the creator join is an outer join onto a table this module has no mapping for. Tenant scoping is
 * unaffected — RLS is the boundary and it binds at table level, underneath whatever the ORM believes
 * (ADR-002 §4).
 *
 * **`erp_app` needs nothing new for this.** It already holds `SELECT` on `sales_order`, `customer`,
 * `warehouse` and `app_user` — the three sales tables fall to `TENANT_READ_DEFAULT`
 * (`SchemaManifest.php`, no `grants` key) and `GrantFootprintTest` measures the whole footprint against
 * `has_table_privilege()` every run. A read ships no migration.
 */
final readonly class SalesOrderReader
{
    public function __construct(
        private TenantContext $tenants,
        private CurrentTenant $tenant,
    ) {
    }

    /**
     * API-13a / §5.2a — a direct hit on a soft-deleted row inside the caller's own tenant returns the row
     * with a non-null `archived_at`, never a 404. There is deliberately no `deleted_at` predicate here.
     *
     * §5.3 clause 2: a row RLS does not return is `not-found`, never `permission-denied`, and an id that
     * is not a uuid at all takes the same path — so "another tenant's", "hard-deleted" and "never
     * existed" answer identically.
     *
     * **`DeleteSalesOrder` archives orders since 2026-09-21, and `RestoreSalesOrder` is still unbuilt** (E9-3;
     * owner ruling Q1, `RESIDUAL_GAPS.md` G-8), so the direct hit is how a holder of a discarded draft's id
     * can still read it. It is right for ST-9's reason too — an archived order stays readable because a
     * fulfilment or an invoice legitimately references it.
     */
    public function get(string $id): ?SalesOrderResource
    {
        if (!Uuid::isValid($id)) {
            return null;
        }

        return $this->tenants->run($this->tenant->tenantId(), static function (EntityManagerInterface $em) use ($id): ?SalesOrderResource {
            $row = $em->getConnection()->fetchAssociative(
                'SELECT '.SalesOrderRow::COLUMNS.' '.SalesOrderRow::FROM.' WHERE so.id = ?::uuid',
                [$id],
            );

            return false === $row ? null : SalesOrderRow::toResource($row);
        });
    }

    /**
     * **One line of one order, by the pair `(orderId, lineId)`** — the row load behind the line's
     * item-level write operations, through `SalesOrderLineProvider`.
     *
     * The pair is what names the row, so a `lineId` belonging to a different order reads as absent and
     * the operation answers `not-found` rather than editing somebody else's line.
     *
     * §5.2a: there is **no `deleted_at` predicate**, deliberately. An archived line inside the caller's
     * tenant is read, and the refusal on writing it is the writer's `business-rule-violated` — which is
     * also what makes `RestoreSalesOrderLine` able to address its own subject at all.
     */
    public function getLine(string $orderId, string $lineId): ?SalesOrderLineResource
    {
        if (!Uuid::isValid($orderId) || !Uuid::isValid($lineId)) {
            return null;
        }

        return $this->tenants->run($this->tenant->tenantId(), static function (EntityManagerInterface $em) use ($orderId, $lineId): ?SalesOrderLineResource {
            $row = $em->getConnection()->fetchAssociative(
                'SELECT '.SalesOrderLineRow::COLUMNS.' '.SalesOrderLineRow::FROM
                .' WHERE sol.sales_order_id = ?::uuid AND sol.id = ?::uuid',
                [...SalesOrderLineRow::columnParameters(), $orderId, $lineId],
            );

            return false === $row ? null : SalesOrderLineRow::toResource($row);
        });
    }


    public function list(SalesOrderListCriteria $criteria): SalesOrderCollection
    {
        return $this->tenants->run($this->tenant->tenantId(), function (EntityManagerInterface $em) use ($criteria): SalesOrderCollection {
            $connection = $em->getConnection();

            [$sql, $parameters] = $this->listStatement($criteria);

            // One row more than the page, so "is there a next page" is answered by the rows themselves
            // rather than by a COUNT(*) -- which TB-2b forbids on any keyset-paginated list.
            $rows = $connection->fetchAllAssociative($sql, $parameters);

            $hasMore = \count($rows) > $criteria->limit;
            if ($hasMore) {
                array_splice($rows, $criteria->limit);
            }

            if ($criteria->isBackwards()) {
                // The backward page was read in descending order; the client is owed it in the list's
                // own order.
                $rows = array_reverse($rows);
            }

            [$next, $previous] = $this->cursors($criteria, $rows, $hasMore);

            return new SalesOrderCollection(
                items: array_map(SalesOrderRow::toResource(...), $rows),
                nextCursor: $next,
                previousCursor: $previous,
                // API-22: computed only when the page is empty, as an EXISTS probe with no filter
                // predicate and no ordering. On a page with rows the answer is `true` and costs no
                // query at all.
                tenantHasRows: [] === $rows ? self::anySalesOrderExists($em) : true,
            );
        });
    }

    /**
     * The statement, and the whole of API-17/API-18 on this list.
     *
     * @return array{string, list<mixed>}
     */
    private function listStatement(SalesOrderListCriteria $criteria): array
    {
        // TB-3: a live list never returns a soft-deleted row. This predicate is also what makes every
        // one of this table's three partial indexes usable -- a partial index cannot be chosen for a
        // query that does not carry its predicate.
        $where = ['so.deleted_at IS NULL'];
        $parameters = [];

        if ([] !== $criteria->statuses) {
            // §10.3 step 2's segment, already expanded by the provider. Written as an equality or an IN
            // according to the segment's own cardinality, because `sales_order_tenant_open_idx`'s
            // predicate is the two-value `= ANY (ARRAY[...])` and a rewritten form would not match it.
            $where[] = 1 === \count($criteria->statuses)
                ? 'so.status = ?'
                : 'so.status IN ('.implode(', ', array_fill(0, \count($criteria->statuses), '?')).')';
            $parameters = [...$parameters, ...$criteria->statuses];
        }

        if (null !== $criteria->customerId) {
            $where[] = 'so.customer_id = ?::uuid';
            $parameters[] = $criteria->customerId;
        }

        if (null !== $criteria->search) {
            // API-19d / TB-5: one column, the one an index covers. `sales_order_tenant_number_live` is a
            // unique index on `(tenant_id, order_number)` among live rows, and a prefix `LIKE` is the
            // only form it can serve. A second column here would make this the cross-column match TB-5
            // forbids.
            $where[] = 'so.order_number LIKE ?';
            $parameters[] = addcslashes($criteria->search, '%_\\').'%';
        }

        $column = 'so.'.$criteria->sort;

        $cursor = $criteria->after ?? $criteria->before;
        if (null !== $cursor) {
            // API-18: the cursor is (sort key, id), never the sort key alone. A row comparison is what
            // makes the pair a single ordering predicate rather than two independent ones -- two sales
            // orders can carry the same `ordered_at`, and a cursor on the key alone either skips or
            // repeats rows across the tie group.
            //
            // The sort key is bound as text and cast to the column's own type. `ordered_at` is
            // `timestamptz` and its cursor carries the value Postgres itself rendered, so the cast is a
            // round trip rather than a parse of something a client composed.
            $cast = self::SORT_KEY_CASTS[$criteria->sort];
            $where[] = \sprintf(
                '(%s, so.id) %s (?%s, ?::uuid)',
                $column,
                $criteria->isBackwards() ? '<' : '>',
                $cast,
            );
            $parameters[] = $cursor->sortKey;
            $parameters[] = $cursor->id;
        }

        $direction = $criteria->isBackwards() ? 'DESC' : 'ASC';

        $sql = 'SELECT '.SalesOrderRow::COLUMNS.' '.SalesOrderRow::FROM
            .' WHERE '.implode(' AND ', $where)
            .\sprintf(' ORDER BY %s %s, so.id %s', $column, $direction, $direction)
            .' LIMIT ?';

        $parameters[] = $criteria->limit + 1;

        return [$sql, $parameters];
    }

    /**
     * The database type each sort key is compared as.
     *
     * `ordered_at` is `timestamptz`; an uncast text comparison against it is an error rather than a
     * silent wrong answer, but naming the cast here is what keeps the two sort keys' handling in one
     * place rather than in a branch.
     */
    private const SORT_KEY_CASTS = [
        'order_number' => '',
        'ordered_at' => '::timestamptz',
    ];

    /**
     * @param list<array<string, mixed>> $rows in the list's own order
     *
     * @return array{?string, ?string} next, previous
     */
    private function cursors(SalesOrderListCriteria $criteria, array $rows, bool $hasMore): array
    {
        if ([] === $rows) {
            return [null, null];
        }

        // ⚠ **The `(string)` cast is safe here only because `SalesOrderViews::legalSorts()` never offers
        // a nullable sort key**, and that is the whole reason its `cancelled` clause exists. `(string)
        // null` is `''`, `Cursor` encodes and decodes `''` happily, and `''::timestamptz` raises -- so a
        // view offering `ordered_at` over rows that may carry none would hand out a `next_cursor` that
        // answers 500 when the client follows it. That is not hypothetical: it is half of the defect
        // `6fe8b7d` repaired in `PurchaseOrderReader` on 2026-09-20. **Hardening the cast is a separate
        // decision nobody has taken**; what holds it here is the view derivation, and `SalesOrderListTest`
        // pages the one view where a null key would otherwise appear.
        $first = new Cursor((string) $rows[0][$criteria->sort], (string) $rows[0]['id']);
        $lastRow = $rows[array_key_last($rows)];
        $last = new Cursor((string) $lastRow[$criteria->sort], (string) $lastRow['id']);

        if ($criteria->isBackwards()) {
            // We arrived here from a later page, so a next page exists by construction. Whether an
            // earlier one does is what the extra row answered.
            return [$last->encode(), $hasMore ? $first->encode() : null];
        }

        return [
            $hasMore ? $last->encode() : null,
            // A previous page exists exactly when this one was reached by a cursor. The first page has
            // none, and returning one would let a client walk backwards off the front.
            null !== $criteria->after ? $first->encode() : null,
        ];
    }

    private static function anySalesOrderExists(EntityManagerInterface $em): bool
    {
        return (bool) $em->getConnection()->fetchOne('SELECT EXISTS (SELECT 1 FROM sales_order)');
    }

    /**
     * `ListSalesOrderLines` — bounded (TB-1c), so the whole set comes back in one statement with no
     * `LIMIT` and no cursor.
     *
     * The parent is resolved first and a parent the caller cannot see is `not-found` (§5.3 clause 2) —
     * the same 404 `GetSalesOrder` returns for that id, so a sub-list is not an oracle for the existence
     * of its parent.
     *
     * **The parent probe deliberately carries no `deleted_at` predicate**, which is §5.2a's direct-hit
     * carve-out: an archived order's lines are still readable, because a fulfilment or an invoice
     * legitimately references the order.
     *
     * @return SalesOrderLineCollection|null null when the order is not visible
     */
    public function listLines(string $orderId, bool $archived): ?SalesOrderLineCollection
    {
        if (!Uuid::isValid($orderId)) {
            return null;
        }

        return $this->tenants->run($this->tenant->tenantId(), static function (EntityManagerInterface $em) use ($orderId, $archived): ?SalesOrderLineCollection {
            $connection = $em->getConnection();

            if (false === $connection->fetchOne('SELECT 1 FROM sales_order WHERE id = ?::uuid', [$orderId])) {
                return null;
            }

            // API-19c limb 2: `archived` selects *which* whole set is returned. TB-3's rule still holds
            // in the default direction -- a live list never returns a soft-deleted row.
            $rows = $connection->fetchAllAssociative(
                'SELECT '.SalesOrderLineRow::COLUMNS.' '.SalesOrderLineRow::FROM
                .' WHERE sol.sales_order_id = ?::uuid AND sol.deleted_at IS '.($archived ? 'NOT NULL' : 'NULL')
                // The order `sales_order_line_unique` already maintains. Not a `sort` control: §10.2's
                // exclusion 2 takes a bounded list out of TB-1's scope entirely.
                .' ORDER BY sol.line_number ASC',
                // The SELECT list binds first -- `SalesOrderLineRow::COLUMNS` carries the cancelled
                // status the V2-C-14(b) derivation compares against, and it precedes the WHERE.
                [...SalesOrderLineRow::columnParameters(), $orderId],
            );

            /** @var list<SalesOrderLineResource> $items */
            $items = array_map(SalesOrderLineRow::toResource(...), $rows);

            return new SalesOrderLineCollection(
                items: $items,
                // §4.4.2: a bounded list carries both cursors null **always**, because the whole set is
                // in `items`. It is the same envelope, so a client that pages correctly needs no
                // special case.
                nextCursor: null,
                previousCursor: null,
                tenantHasRows: [] === $items ? self::anySalesOrderLineExists($em) : true,
            );
        });
    }

    /**
     * ST-2's two empty states. The probe carries **no** filter predicate and no ordering: the question
     * is *"does this workspace have any rows at all"*, which is what decides whether the screen offers
     * "raise your first order" or "clear your filters".
     *
     * A method per table rather than one taking a table name, so that no value a request can reach ever
     * decides which table is read. The `EntityManagerInterface` is the same seam every other read here
     * uses — A-10 keeps a bare `Doctrine\DBAL\Connection` out of a module's signatures.
     */
    private static function anySalesOrderLineExists(EntityManagerInterface $em): bool
    {
        return (bool) $em->getConnection()->fetchOne('SELECT EXISTS (SELECT 1 FROM sales_order_line)');
    }
}
