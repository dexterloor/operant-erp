<?php

declare(strict_types=1);

namespace App\Modules\Suppliers\Application;

use App\Platform\Http\Pagination\Cursor;
use App\Platform\Tenancy\CurrentTenant;
use App\Platform\Tenancy\TenantContext;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * **`GetSupplier` and `ListSuppliers`** (suppliers slice 1, C1; `CustomerReader`'s shape, file for file) — the supplier
 * reads, as {@see SupplierView}s.
 *
 * Every statement runs inside `TenantContext::run()` on the entity manager's own connection (`erp_app`, under RLS), so
 * another workspace's id is not-found exactly as a random one. **Never** a `Doctrine\DBAL\Connection` in the
 * constructor: an unbound autowired `Connection` is the schema owner (A-10; `UserReader`'s rule).
 *
 * The select names the seven served columns and nothing else — never `email`, `phone` or `is_active`, which no
 * operation writes (owner Q-S6, 2026-10-12; `RESIDUAL_GAPS.md` G-36).
 */
final readonly class SupplierReader
{
    private const SELECT = <<<'SQL'
        SELECT s.id, s.code, s.name, s.country_code, s.currency_code, s.payment_terms_days, s.deleted_at
          FROM supplier s
        SQL;

    public function __construct(
        private TenantContext $tenants,
        private CurrentTenant $tenant,
    ) {
    }

    /**
     * `GetSupplier`: **no `deleted_at` predicate** — an archived supplier in the caller's workspace is returned with a
     * non-null `archived_at` (§5.2a's direct hit; ST-9). A non-uuid, absent or foreign id is null (404).
     */
    public function get(string $id): ?SupplierView
    {
        if (!Uuid::isValid($id)) {
            return null;
        }

        return $this->tenants->run(
            $this->tenant->tenantId(),
            static fn (EntityManagerInterface $em): ?SupplierView => self::readInside($em, Uuid::fromString($id)->toRfc4122()),
        );
    }

    /** Inside a transaction the caller already holds — the writers' read-back (C2). */
    public static function readInside(EntityManagerInterface $em, string $id): ?SupplierView
    {
        $row = $em->getConnection()->fetchAssociative(self::SELECT.' WHERE s.id = ?::uuid', [$id]);

        return false === $row ? null : self::view($row);
    }

    /**
     * **`ListSuppliers`** — one keyset page (API-17/API-18) of the **live** suppliers (`deleted_at IS NULL`), or, with
     * `archived=true`, of the **archived** ones instead (`deleted_at IS NOT NULL`, TB-3; C5 C2a — the `/suppliers` Archived
     * view, `IA.md` §5.6, served by `supplier_tenant_archived_idx`), in the requested order, the cursor always
     * `(sort key, id)`. The Archived view's one order is `archived_at` = `deleted_at DESC, id DESC`, most recently
     * archived first (D-a); its cursor key is the column's text **as Postgres returns it** (microseconds and offset,
     * `2026-10-13 02:38:16.744164+00`), never the millisecond `archived_at` the wire shows — a millisecond key would skip
     * or repeat suppliers archived within one millisecond (design §4.2 trap 1).
     *
     * `q` (API-19d / TB-5 / TB-5a, `supplier.name` decided 2026-10-12, signed by `UX`): a **prefix** on `supplier.name`, **case-insensitive**, OR on
     * `supplier.code`, **as stored** — two separate leaves, so the search folds case on one column, never two (TB-5).
     * `%`, `_` and `\` are escaped **before** `lower()`, and `lower()` is applied **in SQL to the bound value**, never in
     * PHP, so the bound value still contains what the caller typed. **Residual (carried from `ListRoles`):** `lower()`
     * folds by the cluster's `LC_CTYPE`; non-ASCII names may fold differently on another cluster.
     *
     * API-22: `tenant_has_rows` is probed only on an empty page, as `EXISTS` against `supplier` under RLS with no filter
     * predicate.
     */
    public function list(SupplierListCriteria $criteria): SupplierPage
    {
        return $this->tenants->run($this->tenant->tenantId(), static function (EntityManagerInterface $em) use ($criteria): SupplierPage {
            $connection = $em->getConnection();
            [$sql, $parameters] = self::listStatement($criteria);

            $rows = $connection->fetchAllAssociative($sql, $parameters);
            $hasMore = \count($rows) > $criteria->limit;
            if ($hasMore) {
                array_splice($rows, $criteria->limit);
            }
            if ($criteria->isBackwards()) {
                $rows = array_reverse($rows);
            }

            [$next, $previous] = self::cursors($criteria, $rows, $hasMore);

            return new SupplierPage(
                items: array_map(self::view(...), $rows),
                nextCursor: $next,
                previousCursor: $previous,
                tenantHasRows: [] === $rows ? (bool) $connection->fetchOne('SELECT EXISTS (SELECT 1 FROM supplier)') : true,
            );
        });
    }

    /**
     * The one statement a `ListSuppliers` page runs — public so `SchemaQueryPlanTest::theSupplierListUsesTheLiveNameIndex`
     * explains **this** statement rather than a hand-written copy of it (customers slice 1 RED L-4's rule, applied here).
     *
     * @return array{string, list<mixed>}
     */
    public static function listStatement(SupplierListCriteria $criteria): array
    {
        // TB-3: a live list never returns a soft-deleted row, and the Archived view returns only those — instead of the
        // live set, never beside it. Each predicate is also what lets its partial index (`_live` / `_archived_idx`) be chosen.
        $where = [$criteria->archived ? 's.deleted_at IS NOT NULL' : 's.deleted_at IS NULL'];
        $parameters = [];

        if (null !== $criteria->search) {
            // TB-5a: two leaves, one folded. `lower()` is applied in SQL to the bound value, never in PHP.
            $prefix = addcslashes($criteria->search, '%_\\').'%';
            $where[] = '(lower(s.name) LIKE lower(?) OR s.code LIKE ?)';
            $parameters[] = $prefix;
            $parameters[] = $prefix;
        }

        $cursor = $criteria->after ?? $criteria->before;

        if (SupplierListCriteria::SORT_ARCHIVED_AT === $criteria->sort) {
            // The Archived view: newest first, so forward is DESC and the keyset comparison is `<`. The provider has
            // already refused a cursor whose key is not a timestamp (a live-view cursor sent here), so the cast cannot 500.
            $descending = !$criteria->isBackwards();
            if (null !== $cursor) {
                $where[] = \sprintf('(s.deleted_at, s.id) %s (?::timestamptz, ?::uuid)', $descending ? '<' : '>');
                $parameters[] = $cursor->sortKey;
                $parameters[] = $cursor->id;
            }
            $direction = $descending ? 'DESC' : 'ASC';
            $sql = self::SELECT.' WHERE '.implode(' AND ', $where)
                .\sprintf(' ORDER BY s.deleted_at %s, s.id %s LIMIT ?', $direction, $direction);
            $parameters[] = $criteria->limit + 1;

            return [$sql, $parameters];
        }

        // Never interpolated from the request: the column is chosen from the criteria's own constant set.
        $column = SupplierListCriteria::SORT_CODE === $criteria->sort ? 's.code' : 's.name';
        $ascending = !$criteria->isBackwards();

        if (null !== $cursor) {
            // API-18: the pair, never the key alone. Both keys are unique among LIVE suppliers today
            // (`supplier_tenant_name_live`, `supplier_tenant_code_live`), so the id tie-break cannot be observed through
            // this list — it is kept so the cursor shape is the house one and survives a later non-unique key.
            $where[] = \sprintf('(%s, s.id) %s (?, ?::uuid)', $column, $ascending ? '>' : '<');
            $parameters[] = $cursor->sortKey;
            $parameters[] = $cursor->id;
        }

        $direction = $ascending ? 'ASC' : 'DESC';
        $sql = self::SELECT.' WHERE '.implode(' AND ', $where)
            .\sprintf(' ORDER BY %s %s, s.id %s LIMIT ?', $column, $direction, $direction);
        $parameters[] = $criteria->limit + 1;

        return [$sql, $parameters];
    }

    /**
     * @param list<array<string, mixed>> $rows in the list's own order
     *
     * @return array{?string, ?string} next, previous
     */
    private static function cursors(SupplierListCriteria $criteria, array $rows, bool $hasMore): array
    {
        if ([] === $rows) {
            return [null, null];
        }

        // The live keys are NOT NULL text, and the Archived view's key is `deleted_at`, NOT NULL by its own predicate, so
        // `6fe8b7d`'s empty-key cursor cannot arise here. `archived_at` reads the raw column text (microseconds), never
        // the view's millisecond rendering.
        $key = SupplierListCriteria::SORT_ARCHIVED_AT === $criteria->sort ? 'deleted_at' : $criteria->sort;
        $first = new Cursor((string) $rows[0][$key], (string) $rows[0]['id']);
        $lastRow = $rows[array_key_last($rows)];
        $last = new Cursor((string) $lastRow[$key], (string) $lastRow['id']);

        if ($criteria->isBackwards()) {
            return [$last->encode(), $hasMore ? $first->encode() : null];
        }

        return [$hasMore ? $last->encode() : null, null !== $criteria->after ? $first->encode() : null];
    }

    /** @param array<string, mixed> $row */
    private static function view(array $row): SupplierView
    {
        return new SupplierView(
            id: (string) $row['id'],
            code: (string) $row['code'],
            name: (string) $row['name'],
            countryCode: (string) $row['country_code'],
            currencyCode: (string) $row['currency_code'],
            paymentTermsDays: (int) $row['payment_terms_days'],
            archivedAt: null === $row['deleted_at']
                ? null
                : new \DateTimeImmutable((string) $row['deleted_at'])->format(\DateTimeInterface::RFC3339_EXTENDED),
        );
    }
}
