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
     * **`ListCustomers`** — one keyset page (API-17/API-18) of the **live** customers (`deleted_at IS NULL`), or, with
     * `archived=true`, of the **archived** ones instead (`deleted_at IS NOT NULL`, TB-3; C5 C4a — the `/customers` Archived
     * view, `IA.md` §5.6, served by `customer_tenant_archived_idx`), in the requested order, the cursor always
     * `(sort key, id)`. The Archived view's one order is `archived_at` = `deleted_at DESC, id DESC`, most recently
     * archived first (D-a); its cursor key is the column's text **as Postgres returns it** (microseconds and offset,
     * `2026-10-13 02:38:16.744164+00`), never the millisecond `archived_at` the wire shows — a millisecond key would skip
     * or repeat customers archived within one millisecond (design §4.2 trap 1).
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
        // TB-3: a live list never returns a soft-deleted row, and the Archived view returns only those — instead of the
        // live set, never beside it. Each predicate is also what lets its partial index (`_live` / `_archived_idx`) be chosen.
        $where = [$criteria->archived ? 'c.deleted_at IS NOT NULL' : 'c.deleted_at IS NULL'];
        $parameters = [];

        if (null !== $criteria->search) {
            // TB-5a: two leaves, one folded. `lower()` is applied in SQL to the bound value, never in PHP.
            $prefix = addcslashes($criteria->search, '%_\\').'%';
            $where[] = '(lower(c.name) LIKE lower(?) OR c.code LIKE ?)';
            $parameters[] = $prefix;
            $parameters[] = $prefix;
        }

        $cursor = $criteria->after ?? $criteria->before;

        if (CustomerListCriteria::SORT_ARCHIVED_AT === $criteria->sort) {
            // The Archived view: newest first, so forward is DESC and the keyset comparison is `<`. The provider has
            // already refused a cursor whose key is not a timestamp (a live-view cursor sent here), so the cast cannot 500.
            $descending = !$criteria->isBackwards();
            if (null !== $cursor) {
                $where[] = \sprintf('(c.deleted_at, c.id) %s (?::timestamptz, ?::uuid)', $descending ? '<' : '>');
                $parameters[] = $cursor->sortKey;
                $parameters[] = $cursor->id;
            }
            $direction = $descending ? 'DESC' : 'ASC';
            $sql = self::SELECT.' WHERE '.implode(' AND ', $where)
                .\sprintf(' ORDER BY c.deleted_at %s, c.id %s LIMIT ?', $direction, $direction);
            $parameters[] = $criteria->limit + 1;

            return [$sql, $parameters];
        }

        // Never interpolated from the request: the column is chosen from the criteria's own constant set.
        $column = CustomerListCriteria::SORT_CODE === $criteria->sort ? 'c.code' : 'c.name';
        $ascending = !$criteria->isBackwards();

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

        // The live keys are NOT NULL text, and the Archived view's key is `deleted_at`, NOT NULL by its own predicate, so
        // `6fe8b7d`'s empty-key cursor cannot arise here. `archived_at` reads the raw column text (microseconds), never
        // the view's millisecond rendering.
        $key = CustomerListCriteria::SORT_ARCHIVED_AT === $criteria->sort ? 'deleted_at' : $criteria->sort;
        $first = new Cursor((string) $rows[0][$key], (string) $rows[0]['id']);
        $lastRow = $rows[array_key_last($rows)];
        $last = new Cursor((string) $lastRow[$key], (string) $lastRow['id']);

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
