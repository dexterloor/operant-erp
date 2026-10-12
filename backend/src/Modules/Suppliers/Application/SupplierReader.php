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
     * **`ListSuppliers`** — one keyset page (API-17/API-18) of the **live** suppliers only (`deleted_at IS NULL`; the
     * `/suppliers` Archived view is `⛔ D-a`, so `archived` is not offered — API-19c limb 1), in the requested order,
     * the cursor always `(sort key, id)`.
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
        // TB-3: a live list never returns a soft-deleted row; the predicate is also what lets the `_live` partial indexes
        // be chosen.
        $where = ['s.deleted_at IS NULL'];
        $parameters = [];

        if (null !== $criteria->search) {
            // TB-5a: two leaves, one folded. `lower()` is applied in SQL to the bound value, never in PHP.
            $prefix = addcslashes($criteria->search, '%_\\').'%';
            $where[] = '(lower(s.name) LIKE lower(?) OR s.code LIKE ?)';
            $parameters[] = $prefix;
            $parameters[] = $prefix;
        }

        // Never interpolated from the request: the column is chosen from the criteria's own constant set.
        $column = SupplierListCriteria::SORT_CODE === $criteria->sort ? 's.code' : 's.name';
        $ascending = !$criteria->isBackwards();

        $cursor = $criteria->after ?? $criteria->before;
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

        // Both sort keys are NOT NULL text, so `6fe8b7d`'s empty-key cursor cannot arise here.
        $first = new Cursor((string) $rows[0][$criteria->sort], (string) $rows[0]['id']);
        $lastRow = $rows[array_key_last($rows)];
        $last = new Cursor((string) $lastRow[$criteria->sort], (string) $lastRow['id']);

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
