<?php

declare(strict_types=1);

namespace App\Modules\Customers\Application;

use App\Platform\Http\Pagination\Cursor;
use App\Platform\Tenancy\CurrentTenant;
use App\Platform\Tenancy\TenantContext;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * **`GetCustomer` and `ListCustomers`** (customers slice 1, C1) — the customer reads, as {@see CustomerView}s.
 *
 * Every statement runs inside `TenantContext::run()` on the entity manager's own connection (`erp_app`, under RLS), so
 * another workspace's id is not-found exactly as a random one. **Never** a `Doctrine\DBAL\Connection` in the
 * constructor: an unbound autowired `Connection` is the schema owner (A-10; `UserReader`'s rule).
 *
 * The select names the seven served columns and nothing else — never `email`, `phone`, `credit_limit` or `is_active`,
 * which no operation writes (owner Q-C2).
 */
final readonly class CustomerReader
{
    private const SELECT = <<<'SQL'
        SELECT c.id, c.code, c.name, c.country_code, c.currency_code, c.payment_terms_days, c.deleted_at
          FROM customer c
        SQL;

    public function __construct(
        private TenantContext $tenants,
        private CurrentTenant $tenant,
    ) {
    }

    /**
     * `GetCustomer`: **no `deleted_at` predicate** — an archived customer in the caller's workspace is returned with a
     * non-null `archived_at` (§5.2a's direct hit; ST-9). A non-uuid, absent or foreign id is null (404).
     */
    public function get(string $id): ?CustomerView
    {
        if (!Uuid::isValid($id)) {
            return null;
        }

        return $this->tenants->run(
            $this->tenant->tenantId(),
            static fn (EntityManagerInterface $em): ?CustomerView => self::readInside($em, Uuid::fromString($id)->toRfc4122()),
        );
    }

    /** Inside a transaction the caller already holds — the writers' read-back (C2, C3). */
    public static function readInside(EntityManagerInterface $em, string $id): ?CustomerView
    {
        $row = $em->getConnection()->fetchAssociative(self::SELECT.' WHERE c.id = ?::uuid', [$id]);

        return false === $row ? null : self::view($row);
    }

    /**
     * **`ListCustomers`** — one keyset page (API-17/API-18) of the **live** customers only (`deleted_at IS NULL`; the
     * `/customers` Archived view is `⛔ D-a`, so `archived` is not offered — API-19c limb 1), in the requested order,
     * the cursor always `(sort key, id)`.
     *
     * `q` (API-19d / TB-5 / TB-5a, decided 2026-10-09): a **prefix** on `customer.name`, **case-insensitive**, OR on
     * `customer.code`, **as stored** — two separate leaves, so the search folds case on one column, never two (TB-5).
     * `%`, `_` and `\` are escaped **before** `lower()`, and `lower()` is applied **in SQL to the bound value**, never in
     * PHP, so the bound value still contains what the caller typed. **Residual (carried from `ListRoles`):** `lower()`
     * folds by the cluster's `LC_CTYPE`; non-ASCII names may fold differently on another cluster.
     *
     * API-22: `tenant_has_rows` is probed only on an empty page, as `EXISTS` against `customer` under RLS with no filter
     * predicate.
     */
    public function list(CustomerListCriteria $criteria): CustomerPage
    {
        return $this->tenants->run($this->tenant->tenantId(), static function (EntityManagerInterface $em) use ($criteria): CustomerPage {
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

            return new CustomerPage(
                items: array_map(self::view(...), $rows),
                nextCursor: $next,
                previousCursor: $previous,
                tenantHasRows: [] === $rows ? (bool) $connection->fetchOne('SELECT EXISTS (SELECT 1 FROM customer)') : true,
            );
        });
    }

    /**
     * The one statement a `ListCustomers` page runs — public so `SchemaQueryPlanTest::theCustomerListUsesTheLiveNameIndex`
     * explains **this** statement rather than a hand-written copy of it (customers slice 1 RED L-4).
     *
     * @return array{string, list<mixed>}
     */
    public static function listStatement(CustomerListCriteria $criteria): array
    {
        // TB-3: a live list never returns a soft-deleted row; the predicate is also what lets the `_live` partial indexes
        // be chosen.
        $where = ['c.deleted_at IS NULL'];
        $parameters = [];

        if (null !== $criteria->search) {
            // TB-5a: two leaves, one folded. `lower()` is applied in SQL to the bound value, never in PHP.
            $prefix = addcslashes($criteria->search, '%_\\').'%';
            $where[] = '(lower(c.name) LIKE lower(?) OR c.code LIKE ?)';
            $parameters[] = $prefix;
            $parameters[] = $prefix;
        }

        // Never interpolated from the request: the column is chosen from the criteria's own constant set.
        $column = CustomerListCriteria::SORT_CODE === $criteria->sort ? 'c.code' : 'c.name';
        $ascending = !$criteria->isBackwards();

        $cursor = $criteria->after ?? $criteria->before;
        if (null !== $cursor) {
            // API-18: the pair, never the key alone — names repeat.
            $where[] = \sprintf('(%s, c.id) %s (?, ?::uuid)', $column, $ascending ? '>' : '<');
            $parameters[] = $cursor->sortKey;
            $parameters[] = $cursor->id;
        }

        $direction = $ascending ? 'ASC' : 'DESC';
        $sql = self::SELECT.' WHERE '.implode(' AND ', $where)
            .\sprintf(' ORDER BY %s %s, c.id %s LIMIT ?', $column, $direction, $direction);
        $parameters[] = $criteria->limit + 1;

        return [$sql, $parameters];
    }

    /**
     * @param list<array<string, mixed>> $rows in the list's own order
     *
     * @return array{?string, ?string} next, previous
     */
    private static function cursors(CustomerListCriteria $criteria, array $rows, bool $hasMore): array
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
    private static function view(array $row): CustomerView
    {
        return new CustomerView(
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
