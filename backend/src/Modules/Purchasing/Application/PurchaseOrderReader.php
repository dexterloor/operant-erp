<?php

declare(strict_types=1);

namespace App\Modules\Purchasing\Application;

use App\Modules\Purchasing\ApiResource\PurchaseOrderCollection;
use App\Modules\Purchasing\ApiResource\PurchaseOrderLineCollection;
use App\Modules\Purchasing\ApiResource\PurchaseOrderLineResource;
use App\Modules\Purchasing\ApiResource\PurchaseOrderResource;
use App\Platform\Http\Pagination\Cursor;
use App\Platform\Tenancy\CurrentTenant;
use App\Platform\Tenancy\TenantContext;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * The read half of the `/purchase-orders` slice.
 *
 * ---------------------------------------------------------------------------
 * P-7 — the transaction does not span serialization
 * ---------------------------------------------------------------------------
 * Every method here opens exactly one tenancy transaction, hydrates completely
 * into DTOs of scalars, and returns. Nothing it hands back can issue a query, so
 * the response can be serialized after the commit. ADR-002 A1.7 measured 1505 ms
 * of pooled-backend pinning when the transaction was held across the rest of the
 * request; this entity has three tenant-scoped associations, so an entity-graph
 * response would have three ways to raise after the commit rather than one.
 *
 * ---------------------------------------------------------------------------
 * Why DBAL rather than DQL
 * ---------------------------------------------------------------------------
 * `ItemReader`'s two reasons hold here unchanged — API-17's keyset predicate is a
 * **row comparison** DQL has no syntax for, and API-13a requires the soft-delete
 * predicate on every list and **not** on a direct hit, which one globally enabled
 * filter cannot express — and this slice adds a third: `quantity_outstanding` is
 * a `numeric` expression over two columns, and computing it in PHP would mean
 * either `bcsub` or a float.
 *
 * Tenant scoping is unaffected: RLS is the boundary and it binds at table level,
 * underneath whatever the ORM believes (ADR-002 §4).
 */
final readonly class PurchaseOrderReader
{
    public function __construct(
        private TenantContext $tenants,
        private CurrentTenant $tenant,
    ) {
    }

    /**
     * API-13a / §5.2a — a direct hit on a soft-deleted row inside the caller's
     * own tenant returns the row with a non-null `archived_at`, never a 404.
     * There is deliberately no `deleted_at` predicate here.
     *
     * **On this resource that carve-out currently leads nowhere, and the reason
     * is recorded rather than left to be discovered.** §5.2a calls the direct hit
     * *"the only path by which a caller can obtain the id `Restore*` takes"*, and
     * `RestorePurchaseOrder` is blocked by **E9-3 / API-O-11** because
     * `/purchase-orders` Archived is `⛔ D-a` and this list therefore carries no
     * `archived` parameter. The carve-out is still right — ST-9's *"documents
     * legitimately reference it"* is about a `goods_receipt` pointing at an
     * archived order, not about restoring one.
     */
    public function get(string $id): ?PurchaseOrderResource
    {
        if (!Uuid::isValid($id)) {
            // Not a uuid, so it names no row in any tenant. §5.3 clause 2: not
            // visible means not found, and the answer must be byte-identical to
            // the one an id in another tenant gets.
            return null;
        }

        return $this->tenants->run($this->tenant->tenantId(), static function (EntityManagerInterface $em) use ($id): ?PurchaseOrderResource {
            $row = $em->getConnection()->fetchAssociative(
                'SELECT '.PurchaseOrderRow::COLUMNS.' '.PurchaseOrderRow::FROM.' WHERE po.id = ?::uuid',
                [$id],
            );

            return false === $row ? null : PurchaseOrderRow::toResource($row);
        });
    }

    /**
     * One line, addressed by the pair `(orderId, lineId)` the path carries.
     *
     * §5.3 clause 2 applies to the **pair**: a `lineId` that names a live line of
     * a *different* order is not visible at this address, so it is `not-found`
     * rather than an edit of somebody else's line. And §5.2a applies as it does
     * to any direct hit — there is no `deleted_at` predicate, so an archived line
     * is read with a non-null `archived_at` rather than refused.
     */
    public function getLine(string $orderId, string $lineId): ?PurchaseOrderLineResource
    {
        if (!Uuid::isValid($orderId) || !Uuid::isValid($lineId)) {
            return null;
        }

        return $this->tenants->run($this->tenant->tenantId(), static function (EntityManagerInterface $em) use ($orderId, $lineId): ?PurchaseOrderLineResource {
            $row = $em->getConnection()->fetchAssociative(
                'SELECT '.PurchaseOrderLineRow::COLUMNS.' '.PurchaseOrderLineRow::FROM
                .' WHERE pol.id = ?::uuid AND pol.purchase_order_id = ?::uuid',
                [$lineId, $orderId],
            );

            return false === $row ? null : PurchaseOrderLineRow::toResource($row);
        });
    }

    public function list(PurchaseOrderListCriteria $criteria): PurchaseOrderCollection
    {
        return $this->tenants->run($this->tenant->tenantId(), function (EntityManagerInterface $em) use ($criteria): PurchaseOrderCollection {
            $connection = $em->getConnection();

            [$sql, $parameters] = $this->listStatement($criteria);

            // One row more than the page, so "is there a next page" is answered
            // by the rows themselves rather than by a COUNT(*) -- which TB-2b
            // forbids on any keyset-paginated list.
            $rows = $connection->fetchAllAssociative($sql, $parameters);

            $hasMore = \count($rows) > $criteria->limit;
            if ($hasMore) {
                array_splice($rows, $criteria->limit);
            }

            if ($criteria->isBackwards()) {
                // The backward page was read in descending order; the client is
                // owed it in the list's own order.
                $rows = array_reverse($rows);
            }

            [$next, $previous] = $this->cursors($criteria, $rows, $hasMore);

            return new PurchaseOrderCollection(
                items: array_map(PurchaseOrderRow::toResource(...), $rows),
                nextCursor: $next,
                previousCursor: $previous,
                // API-22: computed only when the page is empty, as an EXISTS
                // probe with no filter predicate and no ordering. On a page with
                // rows the answer is `true` and costs no query at all.
                tenantHasRows: [] === $rows ? self::anyPurchaseOrderExists($em) : true,
            );
        });
    }

    /**
     * `ListPurchaseOrderLines` — bounded (TB-1c), so the whole set comes back in
     * one statement with no `LIMIT` and no cursor.
     *
     * The parent is resolved first and a parent the caller cannot see is
     * `not-found` (§5.3 clause 2) — the same 404 `GetPurchaseOrder` returns for
     * that id, so a sub-list is not an oracle for the existence of its parent.
     *
     * @return PurchaseOrderLineCollection|null null when the order is not visible
     */
    public function listLines(string $orderId, bool $archived): ?PurchaseOrderLineCollection
    {
        if (!Uuid::isValid($orderId)) {
            return null;
        }

        return $this->tenants->run($this->tenant->tenantId(), function (EntityManagerInterface $em) use ($orderId, $archived): ?PurchaseOrderLineCollection {
            $connection = $em->getConnection();

            if (false === $connection->fetchOne('SELECT 1 FROM purchase_order WHERE id = ?::uuid', [$orderId])) {
                return null;
            }

            // API-19c limb 2: `archived` selects *which* whole set is returned.
            // TB-3's rule still holds in the default direction -- a live list
            // never returns a soft-deleted row.
            $rows = $connection->fetchAllAssociative(
                'SELECT '.PurchaseOrderLineRow::COLUMNS.' '.PurchaseOrderLineRow::FROM
                .' WHERE pol.purchase_order_id = ?::uuid AND pol.deleted_at IS '.($archived ? 'NOT NULL' : 'NULL')
                // The order `purchase_order_line_unique` already maintains, and
                // the order the supplier's paperwork is written in. Not a `sort`
                // control: §10.2's exclusion 2 takes a bounded list out of
                // TB-1's scope entirely.
                .' ORDER BY pol.line_number ASC',
                [$orderId],
            );

            /** @var list<PurchaseOrderLineResource> $items */
            $items = array_map(PurchaseOrderLineRow::toResource(...), $rows);

            return new PurchaseOrderLineCollection(
                items: $items,
                // §4.4.2: a bounded list carries both cursors null **always**,
                // because the whole set is in `items`. It is the same envelope,
                // so a client that pages correctly needs no special case.
                nextCursor: null,
                previousCursor: null,
                tenantHasRows: [] === $items ? self::anyPurchaseOrderLineExists($em) : true,
            );
        });
    }

    /**
     * The statement, and the whole of API-17/API-18 on this list.
     *
     * @return array{string, list<mixed>}
     */
    private function listStatement(PurchaseOrderListCriteria $criteria): array
    {
        // TB-3: a live list never returns a soft-deleted row. This predicate is
        // also what makes every one of this table's three partial indexes usable
        // -- a partial index cannot be chosen for a query that does not carry its
        // predicate.
        $where = ['po.deleted_at IS NULL'];
        $parameters = [];

        if ([] !== $criteria->statuses) {
            // §10.3 step 2's segment, already expanded by the provider. Written
            // as an equality or an IN according to the segment's own cardinality,
            // because `purchase_order_tenant_open_idx`'s predicate is the
            // two-value IN and a rewritten form would not match it.
            $where[] = 1 === \count($criteria->statuses)
                ? 'po.status = ?'
                : 'po.status IN ('.implode(', ', array_fill(0, \count($criteria->statuses), '?')).')';
            $parameters = [...$parameters, ...$criteria->statuses];
        }

        if (null !== $criteria->supplierId) {
            $where[] = 'po.supplier_id = ?::uuid';
            $parameters[] = $criteria->supplierId;
        }

        if (null !== $criteria->search) {
            // API-19d / TB-5: one column, the one an index covers.
            // `purchase_order_tenant_number_live` is a unique index on
            // `(tenant_id, order_number)` among live rows, and a prefix `LIKE`
            // is the only form it can serve. A second column here would make
            // this the cross-column match TB-5 forbids.
            $where[] = 'po.order_number LIKE ?';
            $parameters[] = addcslashes($criteria->search, '%_\\').'%';
        }

        $column = 'po.'.$criteria->sort;

        $cursor = $criteria->after ?? $criteria->before;
        if (null !== $cursor) {
            // API-18: the cursor is (sort key, id), never the sort key alone. A
            // row comparison is what makes the pair a single ordering predicate
            // rather than two independent ones -- two purchase orders can carry
            // the same `ordered_at`, and a cursor on the key alone either skips
            // or repeats rows across the tie group.
            //
            // The sort key is bound as text and cast to the column's own type.
            // `ordered_at` is `timestamptz` and its cursor carries the value
            // Postgres itself rendered, so the cast is a round trip rather than a
            // parse of something a client composed.
            $cast = self::SORT_KEY_CASTS[$criteria->sort];
            $where[] = \sprintf(
                '(%s, po.id) %s (?%s, ?::uuid)',
                $column,
                $criteria->isBackwards() ? '<' : '>',
                $cast,
            );
            $parameters[] = $cursor->sortKey;
            $parameters[] = $cursor->id;
        }

        $direction = $criteria->isBackwards() ? 'DESC' : 'ASC';

        $sql = 'SELECT '.PurchaseOrderRow::COLUMNS.' '.PurchaseOrderRow::FROM
            .' WHERE '.implode(' AND ', $where)
            .\sprintf(' ORDER BY %s %s, po.id %s', $column, $direction, $direction)
            .' LIMIT ?';

        $parameters[] = $criteria->limit + 1;

        return [$sql, $parameters];
    }

    /**
     * The database type each sort key is compared as.
     *
     * `ordered_at` is `timestamptz`; an uncast text comparison against it is an
     * error rather than a silent wrong answer, but naming the cast here is what
     * keeps the two sort keys' handling in one place rather than in a branch.
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
    private function cursors(PurchaseOrderListCriteria $criteria, array $rows, bool $hasMore): array
    {
        if ([] === $rows) {
            return [null, null];
        }

        $first = new Cursor((string) $rows[0][$criteria->sort], (string) $rows[0]['id']);
        $lastRow = $rows[array_key_last($rows)];
        $last = new Cursor((string) $lastRow[$criteria->sort], (string) $lastRow['id']);

        if ($criteria->isBackwards()) {
            // We arrived here from a later page, so a next page exists by
            // construction. Whether an earlier one does is what the extra row
            // answered.
            return [$last->encode(), $hasMore ? $first->encode() : null];
        }

        return [
            $hasMore ? $last->encode() : null,
            // A previous page exists exactly when this one was reached by a
            // cursor. The first page has none, and returning one would let a
            // client walk backwards off the front.
            null !== $criteria->after ? $first->encode() : null,
        ];
    }

    /**
     * ST-2's two empty states. The probe carries **no** filter predicate and no
     * ordering: the question is *"does this workspace have any rows at all"*,
     * which is what decides whether the screen offers "raise your first order" or
     * "clear your filters".
     *
     * Two methods rather than one taking a table name, so that no value a request
     * can reach ever decides which table is read. The `EntityManagerInterface` is
     * the same seam every other read here uses -- A-10 keeps a bare
     * `Doctrine\DBAL\Connection` out of a module's signatures.
     */
    private static function anyPurchaseOrderExists(EntityManagerInterface $em): bool
    {
        return (bool) $em->getConnection()->fetchOne('SELECT EXISTS (SELECT 1 FROM purchase_order)');
    }

    private static function anyPurchaseOrderLineExists(EntityManagerInterface $em): bool
    {
        return (bool) $em->getConnection()->fetchOne('SELECT EXISTS (SELECT 1 FROM purchase_order_line)');
    }
}
