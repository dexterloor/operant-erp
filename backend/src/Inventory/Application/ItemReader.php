<?php

declare(strict_types=1);

namespace App\Inventory\Application;

use App\Api\Pagination\Cursor;
use App\Api\Tenancy\CurrentTenant;
use App\Inventory\ApiResource\ItemCollection;
use App\Inventory\ApiResource\ItemResource;
use App\Tenancy\TenantContext;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * The read half of the `/items` slice.
 *
 * ---------------------------------------------------------------------------
 * P-7 — the transaction does not span serialization
 * ---------------------------------------------------------------------------
 * Every method here opens exactly one tenancy transaction, hydrates completely
 * into DTOs of scalars, and returns. Nothing it hands back can issue a query,
 * so the response can be serialized after the commit. This is not tidiness:
 * ADR-002 A1.7 measured 1505 ms of pooled-backend pinning when the transaction
 * was held across the rest of the request.
 *
 * ---------------------------------------------------------------------------
 * Why DBAL rather than DQL
 * ---------------------------------------------------------------------------
 * Two of this contract's rules are not expressible through the ORM's filters.
 * API-17's keyset predicate is a **row comparison** -- `(sku, id) > (?, ?)` --
 * which DQL has no syntax for, and API-13a requires the soft-delete predicate to
 * be applied to every list and **not** to a direct hit, which one globally
 * enabled `SoftDeleteFilter` cannot express. Tenant scoping is unaffected: RLS
 * is the boundary and it binds at table level, underneath whatever the ORM
 * believes (ADR-002 §4).
 */
final readonly class ItemReader
{
    public function __construct(
        private TenantContext $tenants,
        private CurrentTenant $tenant,
    ) {
    }

    /**
     * API-13a — a direct hit on a soft-deleted row inside the caller's own
     * tenant returns the row with a non-null `archived_at`, never a 404. ST-9:
     * *"Never a 404 -- documents legitimately reference it, which is the entire
     * reason it is soft-deleted."* So there is deliberately no `deleted_at`
     * predicate here, and it is the only path by which a caller can obtain the
     * id `RestoreItem` takes.
     */
    public function get(string $id): ?ItemResource
    {
        if (!Uuid::isValid($id)) {
            // Not a uuid, so it names no row in any tenant. §5.3 clause 2: not
            // visible means not found, and the answer must be the same one an
            // id in another tenant gets.
            return null;
        }

        return $this->tenants->run($this->tenant->tenantId(), static function (EntityManagerInterface $em) use ($id): ?ItemResource {
            $row = $em->getConnection()->fetchAssociative(
                'SELECT '.ItemRow::COLUMNS.' '.ItemRow::FROM.' WHERE i.id = ?::uuid',
                [$id],
            );

            return false === $row ? null : ItemRow::toResource($row);
        });
    }

    public function list(ItemListCriteria $criteria): ItemCollection
    {
        return $this->tenants->run($this->tenant->tenantId(), function (EntityManagerInterface $em) use ($criteria): ItemCollection {
            $connection = $em->getConnection();

            [$sql, $parameters, $types] = $this->listStatement($criteria);

            // One row more than the page, so "is there a next page" is answered
            // by the rows themselves rather than by a COUNT(*) -- which TB-2b
            // forbids on any keyset-paginated list.
            $rows = $connection->fetchAllAssociative($sql, $parameters, $types);

            $hasMore = \count($rows) > $criteria->limit;
            if ($hasMore) {
                array_splice($rows, $criteria->limit);
            }

            if ($criteria->isBackwards()) {
                // The backward page was read in descending order; the client is
                // owed it in the list's own order.
                $rows = array_reverse($rows);
            }

            $items = array_map(ItemRow::toResource(...), $rows);

            [$next, $previous] = $this->cursors($criteria, $rows, $hasMore);

            return new ItemCollection(
                items: $items,
                nextCursor: $next,
                previousCursor: $previous,
                // API-22: computed only when the page is empty, as an EXISTS
                // probe with no filter predicate and no ordering. On a page with
                // rows the answer is `true` and costs no query at all.
                tenantHasRows: [] === $items ? $this->tenantHasRows($em) : true,
            );
        });
    }

    /**
     * @return array{string, list<mixed>, array<int, int|string>}
     */
    private function listStatement(ItemListCriteria $criteria): array
    {
        // TB-3: a live list never returns a soft-deleted row. This predicate is
        // also what makes `inventory_item_tenant_sku_live` usable -- a partial
        // index cannot be chosen for a query that does not carry its predicate.
        $where = ['i.deleted_at IS NULL'];
        $parameters = [];

        if (null !== $criteria->supplierId) {
            $where[] = 'i.supplier_id = ?::uuid';
            $parameters[] = $criteria->supplierId;
        }

        if (null !== $criteria->search) {
            // TB-5: one column, the one an index covers. `inventory_item.name`
            // is deliberately not searched -- D-b, "the gap a user will hit
            // first" -- and a second column here would make this the
            // cross-column match TB-5 forbids.
            $where[] = 'i.sku LIKE ?';
            $parameters[] = addcslashes($criteria->search, '%_\\').'%';
        }

        $cursor = $criteria->after ?? $criteria->before;
        if (null !== $cursor) {
            // API-18: the cursor is (sort key, id), never the sort key alone.
            // A row comparison is what makes the pair a single ordering
            // predicate rather than two independent ones.
            $where[] = \sprintf('(i.sku, i.id) %s (?, ?::uuid)', $criteria->isBackwards() ? '<' : '>');
            $parameters[] = $cursor->sortKey;
            $parameters[] = $cursor->id;
        }

        $direction = $criteria->isBackwards() ? 'DESC' : 'ASC';

        $sql = 'SELECT '.ItemRow::COLUMNS.' '.ItemRow::FROM
            .' WHERE '.implode(' AND ', $where)
            .\sprintf(' ORDER BY i.sku %s, i.id %s', $direction, $direction)
            .' LIMIT ?';

        $parameters[] = $criteria->limit + 1;

        return [$sql, $parameters, []];
    }

    /**
     * @param list<array<string, mixed>> $rows in the list's own order
     *
     * @return array{?string, ?string} next, previous
     */
    private function cursors(ItemListCriteria $criteria, array $rows, bool $hasMore): array
    {
        if ([] === $rows) {
            return [null, null];
        }

        $first = new Cursor((string) $rows[0]['sku'], (string) $rows[0]['id']);
        $last = new Cursor((string) $rows[array_key_last($rows)]['sku'], (string) $rows[array_key_last($rows)]['id']);

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
     * ordering -- the question is "does this tenant have any rows at all", which
     * is what decides whether the screen offers "create your first item" or
     * "clear your filters".
     */
    private function tenantHasRows(EntityManagerInterface $em): bool
    {
        return (bool) $em->getConnection()->fetchOne('SELECT EXISTS (SELECT 1 FROM inventory_item)');
    }
}
