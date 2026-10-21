<?php

declare(strict_types=1);

namespace App\Tests\Tenancy;

use App\Modules\AuditLog\Application\AuditLogEntryReader;
use App\Modules\AuditLog\Application\AuditLogListCriteria;
use App\Modules\AuditLog\Application\AuditRecordCriteria;
use App\Modules\AuditLog\Application\AuditRecordReader;
use App\Modules\Customers\Application\CustomerListCriteria;
use App\Modules\Customers\Application\CustomerReader;
use App\Modules\Inventory\Application\StockLevelReader;
use App\Modules\Inventory\Application\StockMovementListCriteria;
use App\Modules\Inventory\Application\StockMovementReader;
use App\Modules\Purchasing\Application\WarehouseOpenPurchaseOrdersCount;
use App\Modules\Sales\Application\SalesReturnReader;
use App\Modules\Sales\Application\WarehouseOpenSalesOrdersCount;
use App\Modules\Suppliers\Application\SupplierListCriteria;
use App\Modules\Suppliers\Application\SupplierReader;
use App\Platform\Http\Pagination\Cursor;
use PHPUnit\Framework\Attributes\Test;

/**
 * Every index in the schema, against the query it was created for.
 *
 * The ledger's requirement for Output 2 is "indexes chosen from actual query
 * shapes, not guessed". An index list cannot demonstrate that; a plan can. Each
 * test below names the question a user asks, runs it against two tenants' worth
 * of data as `erp_app`, and asserts three things:
 *
 *   1. the intended index is used                    -- the index earns its cost
 *   2. no sequential scan                            -- it is actually reachable
 *   3. the RLS predicate is an `Index Cond`          -- ADR-002 §5, and ADR-007 §3's
 *      and not a post-scan `Filter`                     explicit re-verification
 *      requirement once the index also became partial
 *
 * (3) is the one that is easy to lose. `current_setting()` is STABLE, not
 * IMMUTABLE, and the predicate is injected by the policy rather than written by
 * the application, so whether the planner can use it as an index condition is
 * not obvious -- and if it cannot, every tenant-scoped read degrades to reading
 * the whole table and discarding other tenants' rows after the fact. W0-a
 * proved it holds for a total index. ADR-007 then made most of these indexes
 * partial, which is a different question.
 */
final class SchemaQueryPlanTest extends TenancyTestCase
{
    private const ITEMS = 500;
    private const ORDERS = 1000;
    private const EVENTS = 5000;

    /**
     * Stock reads C2 (`DATA` §1.4): a TIE GROUP of movements for one item at one instant, per tenant — what every document
     * back-dated to the same whole second produces, unbounded in size. The movement-ledger tests page inside it.
     */
    private const TIE = 5000;
    private const TIE_SKU = 'SKU-7';
    private const TIE_INSTANT = '2025-06-01 00:00:00+00';

    /**
     * Audit reads C1 (design §5.4 T-1/T-2): a TIE GROUP of trail rows at one instant, per tenant — what one transaction's
     * writes produce (every writer stamps `now()`, the transaction start), unbounded in size. All by the seeded user, all of
     * one event type, so the whole-trail, the actor and the event-type views each page inside the same group.
     */
    private const AUDIT_TIE = 5000;
    private const AUDIT_TIE_INSTANT = '2025-07-01 00:00:00+00';
    private const AUDIT_TIE_EVENT = 'purchase_order.cancelled';
    /**
     * Audit reads C2 (design §5.4 T-3): the tie group's rows all name ONE subject, so a record's History pages inside the same
     * group. The same id in both tenants (no FK on `subject_id`), so the tenant bound is part of what the plan must honour.
     */
    private const AUDIT_TIE_SUBJECT = '0198a5d0-0000-7000-8000-00000000c2c2';
    /**
     * Audit reads C2 (T-3): other records' rows AT THE SAME INSTANT, so the subject is a fifth of its tie group's instant — a
     * scan of the whole-trail index filtering on the subject would read past them, and only the History index pages the
     * subject's rows directly. Measured: with the subject alone at the instant, the planner (rightly) served the History
     * page from `audit_log_tenant_occurred_id_idx`, and the plan test could not tell the indexes apart.
     */
    private const AUDIT_TIE_NOISE = 20000;

    private bool $seeded = false;

    /**
     * Two tenants of comparable size. One tenant would make every plan trivially
     * correct: with nothing to exclude, an index scan and a sequential scan
     * return the same rows and the planner's choice says nothing.
     */
    private function seedWorld(): void
    {
        if ($this->seeded) {
            return;
        }

        foreach ([self::TENANT_A, self::TENANT_B] as $tenant) {
            $this->tenantContext()->run($tenant, function () use ($tenant): void {
                $c = $this->app();
                // ADR-002 Amendment 4: `erp_app` writes only what a `src/` caller
                // writes. warehouse, storage_location, supplier, customer,
                // sales_order and invoice have no such caller, so those six seeds
                // run as the owner in this tenant's context (committed, so the
                // `erp_app` INSERTs below that read them see them). `app_user` and
                // `fulfilment` (sales step 6 C2) are owner-seeded too: the first has
                // no `erp_app` INSERT, and the second must be committed before the
                // owner-seeded invoice reads it. The plans under test are all read
                // as `erp_app`.

                $this->insertAsOwnerForTenant($tenant, <<<'SQL'
                    INSERT INTO warehouse (id, tenant_id, code, name, country_code)
                    SELECT gen_random_uuid(), ?::uuid, 'WH' || g, 'Warehouse ' || g, 'DE'
                    FROM generate_series(1, 2) g
                    SQL, [$tenant]);

                $this->insertAsOwnerForTenant($tenant, <<<'SQL'
                    INSERT INTO storage_location (id, tenant_id, warehouse_id, code)
                    SELECT gen_random_uuid(), w.tenant_id, w.id, 'BIN' || g
                    FROM warehouse w CROSS JOIN generate_series(1, 2) g
                    SQL);

                $c->executeStatement(<<<'SQL'
                    INSERT INTO inventory_item (id, tenant_id, sku, name, unit_of_measure_code, currency_code)
                    SELECT gen_random_uuid(), ?::uuid, 'SKU-' || g, 'Item ' || g, 'EA', 'EUR'
                    FROM generate_series(1, ?) g
                    SQL, [$tenant, self::ITEMS]);

                $this->insertAsOwnerForTenant($tenant, <<<'SQL'
                    INSERT INTO supplier (id, tenant_id, name, code, country_code, currency_code)
                    SELECT gen_random_uuid(), ?::uuid, 'Supplier ' || lpad(g::text, 4, '0'), 'SUP' || g, 'GB', 'EUR'
                    FROM generate_series(1, 200) g
                    SQL, [$tenant]);

                $this->insertAsOwnerForTenant($tenant, <<<'SQL'
                    INSERT INTO customer (id, tenant_id, code, name, country_code, currency_code)
                    SELECT gen_random_uuid(), ?::uuid, 'CUS' || g, 'Customer ' || lpad(g::text, 4, '0'), 'GB', 'EUR'
                    FROM generate_series(1, 200) g
                    SQL, [$tenant]);

                // One purchase order in ten is still open. The rest are received,
                // which is what makes the partial index worth having: the query
                // that matters reads a tenth of the table.
                $c->executeStatement(<<<'SQL'
                    INSERT INTO purchase_order (id, tenant_id, order_number, supplier_id, warehouse_id, currency_code, status, ordered_at, idempotency_key, request_digest)
                    SELECT gen_random_uuid(), ?::uuid, 'PO-' || lpad(g::text, 6, '0'), -- six digits: `purchase_order_number_shape` (CD-7)
                           (SELECT id FROM supplier  ORDER BY code LIMIT 1 OFFSET (g % 20)),
                           (SELECT id FROM warehouse ORDER BY code LIMIT 1 OFFSET (g % 2)),
                           'EUR',
                           CASE WHEN g % 10 = 0 THEN 'ordered' ELSE 'received' END,
                           now() - (g || ' hours')::interval,
                           -- Per row, because the column is on a live-row unique
                           -- per tenant: one literal here would collide on the
                           -- second row. `Version20260911000200`'s BEFORE INSERT
                           -- trigger is what makes the column non-optional.
                           -- Since Version20260911000300 it is hex SHA-256, and
                           -- carries a digest beside it.
                           encode(sha256(gen_random_uuid()::text::bytea), 'hex'),
                           encode(sha256(gen_random_uuid()::text::bytea), 'hex')
                    FROM generate_series(1, ?) g
                    SQL, [$tenant, self::ORDERS]);

                $this->insertAsOwnerForTenant($tenant, <<<'SQL'
                    INSERT INTO sales_order (id, tenant_id, order_number, customer_id, warehouse_id, currency_code, status, ordered_at, ship_to_country_code,
                                             idempotency_key, request_digest)
                    SELECT gen_random_uuid(), ?::uuid, 'SO-' || lpad(g::text, 6, '0'), -- six digits: `sales_order_number_shape` (CD-7)
                           (SELECT id FROM customer  ORDER BY code LIMIT 1 OFFSET (g % 200)),
                           (SELECT id FROM warehouse ORDER BY code LIMIT 1 OFFSET (g % 2)),
                           'EUR',
                           -- 'fulfilled' with nothing shipped: a state the API cannot produce (RED P9) -- volume
                           -- for the plan, not a behaviour claim.
                           CASE WHEN g % 10 = 0 THEN 'confirmed' ELSE 'fulfilled' END,
                           now() - (g || ' hours')::interval, 'DE',
                           -- NOT NULL since Version20260919000100, hex SHA-256 by CHECK. Per row,
                           -- because sales_order_tenant_idem_live is per tenant: one literal here
                           -- would collide on the second row, exactly as on the purchase side above.
                           encode(sha256(gen_random_uuid()::text::bytea), 'hex'),
                           encode(sha256(gen_random_uuid()::text::bytea), 'hex')
                    FROM generate_series(1, ?) g
                    SQL, [$tenant, self::ORDERS]);

                // Sales step 6 C2 (`Version20260923000100`): an invoice now bills a FULFILMENT and names its ISSUER,
                // both NOT NULL. One shipper, as the owner -- `app_user` has no `erp_app` INSERT (ADR-002 A4; H0) --
                // with a lower-case email (`app_user_email_normalised`).
                $this->insertAsOwnerForTenant($tenant, <<<'SQL'
                    INSERT INTO app_user (id, tenant_id, email, password_hash, display_name)
                    VALUES (gen_random_uuid(), ?::uuid, 'plan-seed@example.com', 'x', 'Plan Seed')
                    SQL, [$tenant]);

                // One fulfilment per order. **As the owner, not `erp_app`** (which does hold INSERT on `fulfilment`
                // since `Version20260922000200`): the owner-seeded invoice below JOINs these rows, and `erp_app`'s
                // insert would still be uncommitted inside this `run()` -- invisible to the owner's connection, so
                // the invoice seed would silently insert nothing (measured: the count assertion read `invoice => 0`
                // on the first run). The number reuses the order's own digits, padded to six: `g <= ORDERS`
                // (< 1,000,000), so SQL `lpad`'s truncation cannot bite (`ARCH` §2).
                $this->insertAsOwnerForTenant($tenant, <<<'SQL'
                    INSERT INTO fulfilment (id, tenant_id, sales_order_id, warehouse_id, fulfilment_number,
                                            shipped_by_user_id, idempotency_key, request_digest)
                    SELECT gen_random_uuid(), s.tenant_id, s.id, s.warehouse_id,
                           'FUL-' || lpad(substring(s.order_number FROM 4), 6, '0'),
                           (SELECT id FROM app_user WHERE email = 'plan-seed@example.com'),
                           encode(sha256(gen_random_uuid()::text::bytea), 'hex'),
                           encode(sha256(gen_random_uuid()::text::bytea), 'hex')
                    FROM sales_order s
                    SQL);

                // Joined to its fulfilment, so a dropped join shows in the count assertion below. The number
                // satisfies `invoice_number_shape` (`^INV-[0-9]{6}$`, owner ruling Q12); the due date sits inside
                // `invoice_due_window` in any session zone; the total is not negative.
                $this->insertAsOwnerForTenant($tenant, <<<'SQL'
                    INSERT INTO invoice (id, tenant_id, sales_order_id, customer_id, fulfilment_id, issued_by_user_id,
                                         invoice_number, status, currency_code, total_amount, issued_at, due_at, paid_at,
                                         paid_by_user_id)
                    SELECT gen_random_uuid(), s.tenant_id, s.id, s.customer_id, f.id, f.shipped_by_user_id,
                           'INV-' || lpad(substring(s.order_number FROM 4), 6, '0'),
                           CASE WHEN right(s.order_number, 1) = '0' THEN 'issued' ELSE 'paid' END,
                           'EUR', 100.00, s.ordered_at, s.ordered_at + interval '30 days',
                           -- invoice_paid_at_matches_status (was invoice_paid_at_set): a paid invoice must say when.
                           -- The constraint caught this seed the first time it ran.
                           CASE WHEN right(s.order_number, 1) = '0' THEN NULL ELSE s.ordered_at + interval '10 days' END,
                           -- invoice_paid_by_matches_status (sales step 6 C5): a paid invoice names who recorded it,
                           -- and an unpaid one names nobody.
                           CASE WHEN right(s.order_number, 1) = '0' THEN NULL ELSE f.shipped_by_user_id END
                    FROM sales_order s
                    JOIN fulfilment f ON f.tenant_id = s.tenant_id AND f.sales_order_id = s.id
                    SQL);

                $c->executeStatement(<<<'SQL'
                    INSERT INTO stock_level (id, tenant_id, inventory_item_id, storage_location_id, qty_on_hand)
                    SELECT gen_random_uuid(), i.tenant_id, i.id, l.id, 10
                    FROM inventory_item i CROSS JOIN storage_location l
                    WHERE l.code = 'BIN1'
                    SQL);

                $c->executeStatement(<<<'SQL'
                    INSERT INTO stock_movement (id, tenant_id, inventory_item_id, storage_location_id, quantity, reason, occurred_at)
                    SELECT gen_random_uuid(), ?::uuid,
                           (SELECT id FROM inventory_item ORDER BY sku LIMIT 1 OFFSET (g % ?)),
                           (SELECT id FROM storage_location ORDER BY code LIMIT 1),
                           1, 'adjustment', now() - (g || ' minutes')::interval
                    FROM generate_series(1, ?) g
                    SQL, [$tenant, self::ITEMS, self::EVENTS]);

                // Stock reads C2: the tie group — one item, one instant, TIE rows (`DATA` §1.4 item 1).
                $c->executeStatement(<<<'SQL'
                    INSERT INTO stock_movement (id, tenant_id, inventory_item_id, storage_location_id, quantity, reason, occurred_at)
                    SELECT gen_random_uuid(), ?::uuid,
                           (SELECT id FROM inventory_item WHERE sku = ?),
                           (SELECT id FROM storage_location ORDER BY code LIMIT 1),
                           1, 'adjustment', ?::timestamptz
                    FROM generate_series(1, ?) g
                    SQL, [$tenant, self::TIE_SKU, self::TIE_INSTANT, self::TIE]);

                $c->executeStatement(<<<'SQL'
                    INSERT INTO audit_log (id, tenant_id, event_type, actor_label, occurred_at)
                    SELECT gen_random_uuid(), ?::uuid,
                           -- B-4: both literals are now App\SharedKernel\ValueObject\AuditEvent values, because
                           -- audit_log_event_type_values constrains the column. The 1-in-50
                           -- distribution is unchanged, so the plan this file measures is
                           -- unchanged; 'purchase_order.updated' was a placeholder naming no
                           -- specified write path, and 'purchase_order.placed' is FLOWS.md's
                           -- F-2 -- Raise a purchase order, step 8.
                           CASE WHEN g % 50 = 0 THEN 'auth.login.failure' ELSE 'purchase_order.placed' END,
                           'system', now() - (g || ' seconds')::interval
                    FROM generate_series(1, ?) g
                    SQL, [$tenant, self::EVENTS]);

                // Audit reads C1: the audit tie group — one actor, one event type, one instant, AUDIT_TIE rows; since C2 also one
                // subject (T-3).
                $c->executeStatement(<<<'SQL'
                    INSERT INTO audit_log (id, tenant_id, event_type, actor_user_id, actor_label, subject_type, subject_id, occurred_at)
                    SELECT gen_random_uuid(), ?::uuid, ?,
                           (SELECT id FROM app_user WHERE email = 'plan-seed@example.com'), 'Plan Seed',
                           'purchase_order', ?::uuid, ?::timestamptz
                    FROM generate_series(1, ?) g
                    SQL, [$tenant, self::AUDIT_TIE_EVENT, self::AUDIT_TIE_SUBJECT, self::AUDIT_TIE_INSTANT, self::AUDIT_TIE]);

                // Audit reads C2 (T-3): other records' rows at the same instant — no actor, another event type and subject type.
                $c->executeStatement(<<<'SQL'
                    INSERT INTO audit_log (id, tenant_id, event_type, actor_label, subject_type, subject_id, occurred_at)
                    SELECT gen_random_uuid(), ?::uuid, 'sales_order.cancelled', 'system', 'sales_order', gen_random_uuid(), ?::timestamptz
                    FROM generate_series(1, ?) g
                    SQL, [$tenant, self::AUDIT_TIE_INSTANT, self::AUDIT_TIE_NOISE]);
            });
        }

        // A seeder that silently seeds nothing is R-3 in its purest form: every
        // plan below would come back as a sequential scan over an empty table
        // and the assertions would be about nothing. Several of these INSERTs
        // read a parent table under RLS, so a lost tenant context makes them a
        // no-op rather than an error -- checked, not assumed.
        foreach ([self::TENANT_A, self::TENANT_B] as $tenant) {
            $counts = $this->tenantContext()->run($tenant, fn (): array => [
                'inventory_item' => (int) $this->app()->fetchOne('SELECT count(*) FROM inventory_item'),
                'storage_location' => (int) $this->app()->fetchOne('SELECT count(*) FROM storage_location'),
                'purchase_order' => (int) $this->app()->fetchOne('SELECT count(*) FROM purchase_order'),
                'fulfilment' => (int) $this->app()->fetchOne('SELECT count(*) FROM fulfilment'),
                'invoice' => (int) $this->app()->fetchOne('SELECT count(*) FROM invoice'),
                'stock_level' => (int) $this->app()->fetchOne('SELECT count(*) FROM stock_level'),
                'stock_movement' => (int) $this->app()->fetchOne('SELECT count(*) FROM stock_movement'),
                'audit_log' => (int) $this->app()->fetchOne('SELECT count(*) FROM audit_log'),
            ]);

            self::assertSame(
                ['inventory_item' => self::ITEMS, 'storage_location' => 4, 'purchase_order' => self::ORDERS,
                    'fulfilment' => self::ORDERS, 'invoice' => self::ORDERS, 'stock_level' => 2 * self::ITEMS,
                    'stock_movement' => self::EVENTS + self::TIE, 'audit_log' => self::EVENTS + self::AUDIT_TIE + self::AUDIT_TIE_NOISE],
                $counts,
                'The seed did not produce the world these plans are measured against.');
        }

        // ANALYZE is an owner operation. Without it the planner works from
        // defaults and every assertion below would be about a guess.
        $this->owner()->executeStatement(
            'ANALYZE warehouse, storage_location, inventory_item, supplier, customer, purchase_order, '
            .'sales_order, invoice, stock_level, stock_movement, audit_log');

        $this->seeded = true;
    }

    private function plan(string $sql, array $params = []): string
    {
        $this->seedWorld();

        return $this->tenantContext()->run(self::TENANT_A, fn (): string => implode(
            "\n", $this->app()->fetchFirstColumn('EXPLAIN (ANALYZE, COSTS OFF) '.$sql, $params)));
    }

    /**
     * {@see self::plan()} with planner settings applied `SET LOCAL`, inside the same tenant transaction, so they end with it.
     * **A planner-size accommodation, never the thing under test:** this seeded world is small, and on a small table the
     * planner may prefer a plan that real volume would not (a bitmap scan plus a sort, or a sequential scan of an empty
     * table). Every caller says which setting it uses and why.
     *
     * @param array<string, 'off'> $settings
     */
    private function planWith(array $settings, string $sql, array $params = []): string
    {
        $this->seedWorld();

        return $this->tenantContext()->run(self::TENANT_A, function () use ($settings, $sql, $params): string {
            foreach ($settings as $name => $value) {
                self::assertMatchesRegularExpression('/^enable_[a-z]+$/', $name);
                $this->app()->executeStatement(\sprintf('SET LOCAL %s = %s', $name, $value));
            }

            return implode("\n", $this->app()->fetchFirstColumn('EXPLAIN (ANALYZE, COSTS OFF) '.$sql, $params));
        });
    }

    /**
     * **The archived page is served in index order, with no sort** (`docs/session13/owed-data-c5.md` follow-up 3, RED H-1):
     * `assertPlan()` alone passes a bitmap scan plus a sort, and a regression to `IA.md` D-a's two-column
     * `(tenant_id, deleted_at)` shape with the ORM attribute moved to match. Only a three-column index ending in `id` can
     * serve `ORDER BY deleted_at DESC, id DESC` with `(deleted_at, id) < (…)` as an index condition, read backward.
     */
    private function assertServedInIndexOrder(string $plan, string $index): void
    {
        self::assertStringContainsString('Index Scan Backward using '.$index, $plan,
            "The archived page is not read backward along $index, newest first:\n$plan");
        self::assertMatchesRegularExpression('/Index Cond:[^\n]*ROW\((?:\w+\.)?deleted_at, (?:\w+\.)?id\) < ROW\(/', $plan,
            "The keyset (deleted_at, id) < (…) is not an index condition, so the page is filtered, not sought:\n$plan");
        self::assertStringNotContainsString('Sort', $plan,
            "The archived page is sorted after it is read (this also catches an Incremental Sort, which a two-column "
            ."(tenant_id, deleted_at) index forces):\n$plan");
    }

    private function assertPlan(string $label, string $plan, string $expectedIndex): void
    {
        fwrite(STDERR, "\n  [EXPLAIN] $label\n    ".str_replace("\n", "\n    ", $plan)."\n");

        self::assertStringContainsString($expectedIndex, $plan,
            "The index this query exists for was not used, so it is cost without benefit:\n$plan");
        self::assertStringNotContainsString('Seq Scan', $plan);
        self::assertMatchesRegularExpression('/Index Cond:[^\n]*tenant_id/', $plan,
            "The RLS predicate is not an index condition. It is being applied after rows are read, which means "
            ."every tenant-scoped read of this table scans other tenants' rows and discards them:\n$plan");
    }

    /**
     * {@see self::assertPlan()} with its "no `Seq Scan`" narrowed to ONE table — for the stock reads' joined statements only
     * (stock reads C1/C2; final RED M-3: *"never to `assertPlan()` globally"*), each caller recording why: the display joins
     * read seeded reference tables small enough that a sequential scan of them is the right plan. Everything else is
     * `assertPlan()`'s: the intended index, and the RLS predicate an `Index Cond`.
     */
    private function assertPlanOn(string $table, string $label, string $plan, string $expectedIndex): void
    {
        fwrite(STDERR, "\n  [EXPLAIN] $label\n    ".str_replace("\n", "\n    ", $plan)."\n");

        self::assertStringContainsString($expectedIndex, $plan,
            "The index this query exists for was not used, so it is cost without benefit:\n$plan");
        self::assertDoesNotMatchRegularExpression('/Seq Scan on '.$table.'\\b/', $plan, "$table is read by a sequential scan:\n$plan");
        self::assertMatchesRegularExpression('/Index Cond:[^\n]*tenant_id/', $plan,
            "The RLS predicate is not an index condition:\n$plan");
    }

    /** Purchasing: "the purchase orders still waiting on a delivery, oldest first". */
    #[Test]
    public function theBuyersWorkQueueUsesThePartialOpenOrderIndex(): void
    {
        $this->assertPlan(
            'Purchasing — open purchase orders, oldest first',
            $this->plan(<<<'SQL'
                SELECT id, order_number FROM purchase_order
                WHERE deleted_at IS NULL AND status IN ('ordered', 'partially_received')
                ORDER BY ordered_at
                LIMIT 25
                SQL),
            'purchase_order_tenant_open_idx');
    }

    /** Inventory / ADR-004: "the movement history of this SKU", the forecast input. */
    #[Test]
    public function theForecastInputUsesTheItemHistoryIndex(): void
    {
        // Seeded outside the context on purpose: seedWorld() opens a unit of
        // work per tenant, and TenantContext refuses a tenant switch inside one
        // transaction -- which is A1.9 working exactly as intended, and is worth
        // knowing before a fixture builder is written for Output 16.
        $this->seedWorld();

        $itemId = $this->tenantContext()->run(self::TENANT_A, fn (): string =>
            (string) $this->app()->fetchOne("SELECT id FROM inventory_item WHERE sku = 'SKU-42'"));

        $this->assertPlan(
            'Inventory — movement history for one SKU (ADR-004 forecast input)',
            $this->plan(<<<'SQL'
                SELECT quantity, occurred_at FROM stock_movement
                WHERE inventory_item_id = ?::uuid
                ORDER BY occurred_at DESC
                LIMIT 100
                SQL, [$itemId]),
            // Stock reads C2 (`Version20261015000100`): the extended index, read on its `(tenant_id, inventory_item_id,
            // occurred_at)` prefix. Not a substring of the old name, so this pin cannot pass by accident.
            'stock_movement_tenant_item_occurred_id_idx');
    }

    /**
     * Customers: "the customer list, alphabetical" — the module's landing screen.
     *
     * ⚠ **Repointed 2026-10-09 (customers slice 1 C1, RED L-4).** Until then this explained a hand-written
     * `ORDER BY name LIMIT 50` that no code ran. It now explains the statement `ListCustomers` actually issues, taken from
     * {@see CustomerReader::listStatement()} — the keyset page `(name, id) > (?, ?)` ordered `name, id` — so the plan
     * pinned here cannot drift from the reader's. A second page (a cursor is present) is the harder case: the first page
     * is the same statement without the row comparison.
     */
    #[Test]
    public function theCustomerListUsesTheLiveNameIndex(): void
    {
        [$sql, $parameters] = CustomerReader::listStatement(new CustomerListCriteria(
            limit: CustomerListCriteria::DEFAULT_LIMIT,
            after: new Cursor('A', '00000000-0000-4000-8000-000000000000'),
        ));
        self::assertStringContainsString('(c.name, c.id) > (?, ?::uuid)', $sql, 'The statement explained is not the name keyset page.');
        self::assertStringContainsString('ORDER BY c.name ASC, c.id ASC', $sql);

        $this->assertPlan(
            'Customers — alphabetical list, live rows only (the reader\'s keyset page)',
            $this->plan($sql, $parameters),
            'customer_tenant_name_live');
    }

    /**
     * Customers, the Archived view (C5 C4a; `IA.md` §5.6 / D-a): "what was archived, most recently first" — the statement
     * `ListCustomers?archived=true` issues, from {@see CustomerReader::listStatement()}, on its second page, with and without
     * `q` (design to-measure 5; `theSupplierArchivedViewUsesTheArchivedIndex`' twin). Mutation C4a-M6: drop
     * `customer_tenant_archived_idx` → red here and in `CustomerOperationContractTest`'s D-a tie.
     */
    #[Test]
    public function theCustomerArchivedViewUsesTheArchivedIndex(): void
    {
        foreach ([null, 'Acme'] as $search) {
            [$sql, $parameters] = CustomerReader::listStatement(new CustomerListCriteria(
                limit: CustomerListCriteria::DEFAULT_LIMIT,
                sort: CustomerListCriteria::SORT_ARCHIVED_AT,
                search: $search,
                after: new Cursor('2026-10-13 02:38:16.744164+00', '00000000-0000-4000-8000-000000000000'),
                archived: true,
            ));
            self::assertStringContainsString('c.deleted_at IS NOT NULL', $sql, 'The statement explained is not the Archived view.');
            self::assertStringContainsString('(c.deleted_at, c.id) < (?::timestamptz, ?::uuid)', $sql, 'The statement explained is not the archived keyset page.');
            self::assertStringContainsString('ORDER BY c.deleted_at DESC, c.id DESC', $sql);

            // The plain page also carries the plan-shape pin (follow-up 3). `enable_bitmapscan = off` is the planner-size
            // accommodation `planWith()` describes: the seeded world holds few archived rows, and a bitmap scan plus a
            // sort would be a fact about this world's size, not about the index.
            $plan = null === $search ? $this->planWith(['enable_bitmapscan' => 'off'], $sql, $parameters) : $this->plan($sql, $parameters);
            $this->assertPlan(
                'Customers — the Archived view, newest first (the reader\'s keyset page'.(null === $search ? '' : ', with q').')',
                $plan,
                'customer_tenant_archived_idx');
            if (null === $search) {
                $this->assertServedInIndexOrder($plan, 'customer_tenant_archived_idx');
            }
        }
    }

    /**
     * Suppliers: "the supplier list, alphabetical" (suppliers slice 1 C1, design FINAL F-2) — the statement `ListSuppliers`
     * issues, taken from {@see SupplierReader::listStatement()} (customers' L-4 rule), on its harder second page.
     */
    #[Test]
    public function theSupplierListUsesTheLiveNameIndex(): void
    {
        [$sql, $parameters] = SupplierReader::listStatement(new SupplierListCriteria(
            limit: SupplierListCriteria::DEFAULT_LIMIT,
            after: new Cursor('A', '00000000-0000-4000-8000-000000000000'),
        ));
        self::assertStringContainsString('(s.name, s.id) > (?, ?::uuid)', $sql, 'The statement explained is not the name keyset page.');
        self::assertStringContainsString('ORDER BY s.name ASC, s.id ASC', $sql);

        $this->assertPlan(
            'Suppliers — alphabetical list, live rows only (the reader\'s keyset page)',
            $this->plan($sql, $parameters),
            'supplier_tenant_name_live');
    }

    /**
     * Suppliers, the Archived view (C5 C2a; `IA.md` §5.6 / D-a): "what was archived, most recently first" — the statement
     * `ListSuppliers?archived=true` issues, from {@see SupplierReader::listStatement()}, on its second page, with and without
     * `q` (design to-measure 5). Mutation M6: drop `supplier_tenant_archived_idx` → red here and in
     * `SupplierOperationContractTest`'s D-a tie.
     */
    #[Test]
    public function theSupplierArchivedViewUsesTheArchivedIndex(): void
    {
        foreach ([null, 'Acme'] as $search) {
            [$sql, $parameters] = SupplierReader::listStatement(new SupplierListCriteria(
                limit: SupplierListCriteria::DEFAULT_LIMIT,
                sort: SupplierListCriteria::SORT_ARCHIVED_AT,
                search: $search,
                after: new Cursor('2026-10-13 02:38:16.744164+00', '00000000-0000-4000-8000-000000000000'),
                archived: true,
            ));
            self::assertStringContainsString('s.deleted_at IS NOT NULL', $sql, 'The statement explained is not the Archived view.');
            self::assertStringContainsString('(s.deleted_at, s.id) < (?::timestamptz, ?::uuid)', $sql, 'The statement explained is not the archived keyset page.');
            self::assertStringContainsString('ORDER BY s.deleted_at DESC, s.id DESC', $sql);

            // The plain page also carries the plan-shape pin (follow-up 3). `enable_bitmapscan = off` is the planner-size
            // accommodation `planWith()` describes: the seeded world holds few archived rows, and a bitmap scan plus a
            // sort would be a fact about this world's size, not about the index.
            $plan = null === $search ? $this->planWith(['enable_bitmapscan' => 'off'], $sql, $parameters) : $this->plan($sql, $parameters);
            $this->assertPlan(
                'Suppliers — the Archived view, newest first (the reader\'s keyset page'.(null === $search ? '' : ', with q').')',
                $plan,
                'supplier_tenant_archived_idx');
            if (null === $search) {
                $this->assertServedInIndexOrder($plan, 'supplier_tenant_archived_idx');
            }
        }
    }

    /**
     * Sales: "what has already been credited against this return line" — `SalesReturnReader::creditedOnReturnLine()`, the
     * term `owesACredit()`, the return-line reads and `CreditNoteWriter` all embed (`docs/session13/owed-data-c5.md`
     * follow-up 2, `Version20261015120000`). Measured by `DATA` before the index: every evaluation read every workspace's
     * `credit_note_line` rows and applied RLS as a **Filter**; `assertPlan()`'s third assertion is that failure's refusal.
     *
     * The term is taken from the source, never transcribed, and correlated to a one-row derived table carrying this
     * tenant's id, so no outer table scan enters the plan. **`enable_seqscan = off`** is the planner-size accommodation
     * `planWith()` describes: this world seeds no credit notes, and on an empty table a sequential scan is the cheapest
     * plan whatever indexes exist. With it off, the planner still chooses between this index and the shipped
     * `credit_note_line_tenant_fulfilment_line_idx` / `(tenant_id, id)` unique, which can only take `tenant_id` as an
     * index condition — so **mutation: drop the index from the migration (and the ORM attribute) → red** on the index
     * name, and the plan shows `sales_return_line_id` as a Filter.
     */
    #[Test]
    public function theCreditedOnReturnLineSubqueryUsesTheReturnLineIndex(): void
    {
        $term = SalesReturnReader::creditedOnReturnLine('rl');
        self::assertStringContainsString('cnl.sales_return_line_id = rl.id', $term, 'The term explained is not the credited-on-return-line term.');

        $plan = $this->planWith(['enable_seqscan' => 'off'], 'SELECT '.$term.' AS credited FROM '
            ."(SELECT ?::uuid AS id, NULLIF(current_setting('app.tenant_id', true), '')::uuid AS tenant_id) rl",
            ['00000000-0000-4000-8000-000000000000']);

        $this->assertPlan('Sales — credited so far against one return line', $plan, 'credit_note_line_tenant_return_line_idx');
        self::assertMatchesRegularExpression('/Index Cond:[^\n]*sales_return_line_id/', $plan,
            "The return line is not an index condition, so every credit-note line of the workspace is read:\n$plan");
    }

    /**
     * **`DeleteWarehouse`'s open purchase-order count uses the warehouse index, `status` included** (warehouse/location
     * delete-restore C4; `DATA` `wh-loc-delete-roles.md` D-2/D-3, corrected by `wh-loc-delete-roles-red.md` L-2). The Port's
     * public `COUNT` statement, read from the class (never transcribed), with a real warehouse of the seeded world and the
     * Port's own status array. Asserted: the index name with `tenant_id` an `Index Cond` (`assertPlan()`); **`status = ANY`
     * inside an `Index Cond` line** — a two-column `(tenant_id, warehouse_id)` index, which `assertPlan()` alone would accept,
     * is red; and no `Filter:` line names `warehouse_id`. The scan node is NOT asserted (L-2 measured a Bitmap Index Scan):
     * index name and `Index Cond` lines only. **`enable_seqscan = off` is a planner-size accommodation**, never the thing under
     * test: this proves the index CAN serve the statement, not that the planner picks it at volume. Mutations D-M1 (drop the
     * index) and D-M2 (two columns) → red; D-M3 (literal statuses, no `deleted_at IS NULL`) → red (the partial index cannot be
     * proven implied).
     */
    #[Test]
    public function theOpenPurchaseOrderCountForAWarehouseUsesTheWarehouseIndex(): void
    {
        $this->assertTheOpenOrderCountUsesItsIndex('purchase_order', WarehouseOpenPurchaseOrdersCount::COUNT,
            WarehouseOpenPurchaseOrdersCount::OPEN_STATUSES, 'purchase_order_tenant_warehouse_open_idx');
    }

    /** The sales twin of {@see self::theOpenPurchaseOrderCountForAWarehouseUsesTheWarehouseIndex()} (D-2). */
    #[Test]
    public function theOpenSalesOrderCountForAWarehouseUsesTheWarehouseIndex(): void
    {
        $this->assertTheOpenOrderCountUsesItsIndex('sales_order', WarehouseOpenSalesOrdersCount::COUNT,
            WarehouseOpenSalesOrdersCount::OPEN_STATUSES, 'sales_order_tenant_warehouse_open_idx');
    }

    /** @param list<string> $statuses */
    private function assertTheOpenOrderCountUsesItsIndex(string $table, string $statement, array $statuses, string $index): void
    {
        $this->seedWorld();
        $warehouse = (string) $this->tenantContext()->run(self::TENANT_A, fn (): string => (string) $this->app()->fetchOne(
            'SELECT warehouse_id::text FROM '.$table.' ORDER BY order_number LIMIT 1'));
        self::assertNotSame('', $warehouse, 'Fixture: the seeded world has no '.$table.' row to take a warehouse from.');

        $plan = $this->planWith(['enable_seqscan' => 'off'], $statement, [$warehouse, '{'.implode(',', $statuses).'}']);

        $this->assertPlan('Warehouses — open '.$table.' rows of one warehouse (DeleteWarehouse, API-O-34)', $plan, $index);
        self::assertMatchesRegularExpression('/Index Cond:[^\n]*status = ANY/', $plan,
            "The open-status set is not an index condition, so every live order of the warehouse — history included — is read:\n$plan");
        self::assertDoesNotMatchRegularExpression('/Filter:[^\n]*warehouse_id/', $plan,
            "The warehouse is a Filter, not an index condition, so every live order of the workspace is read:\n$plan");
    }

    /** AuditLog: "what happened in this tenant, newest first". */
    #[Test]
    public function theAuditTrailScreenUsesTheOccurredAtIndex(): void
    {
        $this->assertPlan(
            'AuditLog — the tenant trail, newest first',
            $this->plan('SELECT id, event_type FROM audit_log ORDER BY occurred_at DESC LIMIT 50'),
            // Audit reads C1: renamed, deliberately, with the rebuild (`Version20261021000100`); was `audit_log_tenant_occurred_idx`.
            'audit_log_tenant_occurred_id_idx');
    }

    /** AuditLog / ADR-006 §2.2: "every failed login", which is why event_type is indexed at all. */
    #[Test]
    public function theFailedLoginQueryUsesTheEventTypeIndex(): void
    {
        $this->assertPlan(
            'AuditLog — failed logins (ADR-006 §2.2)',
            $this->plan(<<<'SQL'
                SELECT id, occurred_at FROM audit_log
                WHERE event_type = 'auth.login.failure'
                ORDER BY occurred_at DESC
                LIMIT 50
                SQL),
            // Audit reads C1: renamed, deliberately, with the rebuild; was `audit_log_tenant_event_idx`.
            'audit_log_tenant_event_occurred_id_idx');
    }

    /**
     * AuditLog, `/audit-log` — **the trail pages inside a 5,000-row tie group without a sort** (audit reads C1, design §5.4
     * T-1/T-2; `Version20261021000100`). The statement is {@see AuditLogEntryReader::listStatement()}'s, for each of the
     * three views, with the cursor in the middle of the group; the assertions are the stock ledger's (a)–(d) under three
     * join strategies (`assertTieGroupPage()`'s docblock): the page's `Limit` is the backward index scan's direct parent,
     * the WHOLE row comparison is an index condition, the scan stops at `limit + 1`. The display join reads `app_user`
     * (seeded: one user per tenant), so "no `Seq Scan`" is narrowed to `audit_log` (`assertPlanOn()`). **Expected red under
     * mutation M1** (the old two-column index back): only `occurred_at <= ?` is indexable and every strategy must sort.
     */
    #[Test]
    public function theAuditTrailPagesInsideATieGroupWithoutASort(): void
    {
        $this->assertAuditTieGroupPage(null, 'audit_log_tenant_occurred_id_idx', 'AuditLog — the whole trail, page inside a tie group');
    }

    /** As above, for one actor's events — `ListAuditLogEntries?actor_user_id=` on the actor index (T-2). */
    #[Test]
    public function theAuditTrailForOneActorPagesInsideATieGroupWithoutASort(): void
    {
        $this->assertAuditTieGroupPage('actor', 'audit_log_tenant_actor_occurred_id_idx', 'AuditLog — one actor\'s events, page inside a tie group');
    }

    /** As above, for one event type — `ListAuditLogEntries?event_type=` on the event index (T-2). */
    #[Test]
    public function theAuditTrailForOneEventTypePagesInsideATieGroupWithoutASort(): void
    {
        $this->assertAuditTieGroupPage('event', 'audit_log_tenant_event_occurred_id_idx', 'AuditLog — one event type, page inside a tie group');
    }

    /**
     * `/audit-log/records`, the History section (L-7) — **one record's History pages inside a 5,000-row tie group without a
     * sort** (audit reads C2, design §5.4 T-3; `Version20261021000200`). The statement is
     * {@see AuditRecordReader::listStatement()}'s: `subject_type` and `subject_id` and the row comparison in the index
     * condition, the allowlist's `IN` a `Filter`. **Expected red under the old `audit_log_tenant_subject_idx`** (it ends
     * `occurred_at`, so only `occurred_at <= ?` is indexable and the group is sorted — measured before the migration).
     */
    #[Test]
    public function aRecordsHistoryPagesInsideATieGroupWithoutASort(): void
    {
        $this->seedWorld();

        [$key, $id] = $this->tenantContext()->run(self::TENANT_A, fn (): array => [
            (string) $this->app()->fetchOne('SELECT DISTINCT occurred_at::text FROM audit_log WHERE subject_id = ?::uuid AND occurred_at = ?::timestamptz', [self::AUDIT_TIE_SUBJECT, self::AUDIT_TIE_INSTANT]),
            (string) $this->app()->fetchOne('SELECT id FROM audit_log WHERE subject_id = ?::uuid AND occurred_at = ?::timestamptz ORDER BY id DESC OFFSET 2499 LIMIT 1', [self::AUDIT_TIE_SUBJECT, self::AUDIT_TIE_INSTANT]),
        ]);
        self::assertNotSame('', $id, 'The History tie group was not seeded on its subject, so the page would be over nothing.');

        $criteria = new AuditRecordCriteria(
            subjectType: 'purchase_order',
            subjectId: self::AUDIT_TIE_SUBJECT,
            limit: AuditLogListCriteria::DEFAULT_LIMIT,
            after: new Cursor($key, $id),
        );
        [$sql, $parameters] = AuditRecordReader::listStatement($criteria);
        self::assertStringContainsString('(a.occurred_at, a.id) < (?::timestamptz, ?::uuid)', $sql, 'The statement explained is not the History keyset page.');
        self::assertSame(2, substr_count($sql, 'ORDER BY a.occurred_at DESC, a.id DESC'),
            'The order must be applied inside the page AND after the join, or the page order rests on the join strategy.');

        $this->assertAuditTieScan($sql, $parameters, 'audit_log_tenant_subject_occurred_id_idx', 'AuditLog — one record\'s History, page inside a tie group', $criteria->limit);

        $plan = $this->planWith([], $sql, $parameters);
        self::assertMatchesRegularExpression('/Index Cond:[^\n]*subject_type[^\n]*subject_id/', $plan, "The subject bound is not an index condition:\n$plan");
        self::assertMatchesRegularExpression('/Filter:[^\n]*event_type = ANY/', $plan, "The allowlist is expected as a Filter on the index scan (design §5.3):\n$plan");
    }

    /**
     * Each of C1's three (and C2's one) concurrently built indexes exists and is VALID (design R1.3; L-1): a failed `CREATE INDEX
     * CONCURRENTLY` leaves `indisvalid = false`, which an `IF NOT EXISTS` would have accepted as done. And the three indexes
     * they replace are gone (replace, not add: `audit_log` grows without bound). Read from `pg_index`, as the owner.
     */
    #[Test]
    public function theRebuiltAuditIndexesAreValidAndTheOldOnesAreGone(): void
    {
        $rows = $this->owner()->fetchAllKeyValue(<<<'SQL'
            SELECT c.relname, i.indisvalid FROM pg_index i JOIN pg_class c ON c.oid = i.indexrelid
            WHERE c.relname IN ('audit_log_tenant_occurred_id_idx', 'audit_log_tenant_actor_occurred_id_idx', 'audit_log_tenant_event_occurred_id_idx',
                                'audit_log_tenant_subject_occurred_id_idx',
                                'audit_log_tenant_occurred_idx', 'audit_log_tenant_actor_idx', 'audit_log_tenant_event_idx', 'audit_log_tenant_subject_idx')
            ORDER BY c.relname
            SQL);

        self::assertSame([
            'audit_log_tenant_actor_occurred_id_idx' => true,
            'audit_log_tenant_event_occurred_id_idx' => true,
            'audit_log_tenant_occurred_id_idx' => true,
            // Audit reads C2: the History index, rebuilt the same way (`Version20261021000200`); the old one gone.
            'audit_log_tenant_subject_occurred_id_idx' => true,
        ], array_map(static fn (mixed $valid): bool => true === $valid || 't' === $valid || 1 === $valid || '1' === $valid, $rows));
    }

    /** Sales / ADR-004: "invoices past their due date" — collections, and the at-risk-account signal. */
    #[Test]
    public function theOverdueInvoiceQueryUsesThePartialStatusIndex(): void
    {
        $this->assertPlan(
            'Sales — unpaid invoices by due date (ADR-004 at-risk signal)',
            $this->plan(<<<'SQL'
                SELECT id, invoice_number, due_at FROM invoice
                WHERE status IN ('issued', 'overdue')
                ORDER BY due_at
                LIMIT 50
                SQL),
            'invoice_tenant_due_idx');
    }

    /** Warehouses / Inventory: "what is physically in this bin" — the pick list. */
    #[Test]
    public function thePickListUsesTheLocationLedStockIndex(): void
    {
        $this->seedWorld();

        $locationId = $this->tenantContext()->run(self::TENANT_A, fn (): string =>
            (string) $this->app()->fetchOne("SELECT id FROM storage_location WHERE code = 'BIN1' LIMIT 1"));

        $this->assertPlan(
            'Inventory — everything held in one bin',
            $this->plan(<<<'SQL'
                SELECT inventory_item_id, qty_on_hand FROM stock_level
                WHERE storage_location_id = ?::uuid
                LIMIT 100
                SQL, [$locationId]),
            'stock_level_tenant_location_idx');
    }

    /**
     * Inventory, `/stock` (stock reads C1, 2026-10-14; design §4.2, T-13; `DATA` §1.4 "C1 (optional, recommended)"): the
     * statements `ListStockLevels` actually issues, from {@see StockLevelReader::listStatement()}, one per scope — a bin's
     * stock on `stock_level_tenant_location_idx`, an item's on `stock_level_unique` (`tenant_id, inventory_item_id, …`).
     * Both scopes are bounded (TB-1c), so a Sort above the scan is allowed here and not asserted against.
     *
     * **T-13, measured 2026-10-15:** the plan seq-scans `storage_location` (8 rows) and `warehouse` (4 rows) for the
     * display joins and reaches `stock_level` through the intended index, so {@see self::assertPlan()}'s blanket "no
     * `Seq Scan`" cannot hold for a joined read of two tiny reference tables. This test alone uses
     * {@see self::assertPlanOn()} — `Seq Scan on stock_level` refused — and `assertPlan()` itself is untouched.
     */
    #[Test]
    public function theStockLevelListUsesItsScopeIndexes(): void
    {
        $this->seedWorld();

        [$locationId, $itemId] = $this->tenantContext()->run(self::TENANT_A, fn (): array => [
            (string) $this->app()->fetchOne("SELECT id FROM storage_location WHERE code = 'BIN1' LIMIT 1"),
            (string) $this->app()->fetchOne("SELECT id FROM inventory_item WHERE sku = 'SKU-42'"),
        ]);

        foreach ([
            [StockLevelReader::SCOPE_LOCATION, $locationId, 'stock_level_tenant_location_idx', 'one bin\'s stock'],
            [StockLevelReader::SCOPE_ITEM, $itemId, 'stock_level_unique', 'one item\'s stock'],
        ] as [$scope, $id, $index, $label]) {
            $sql = StockLevelReader::listStatement($scope);
            self::assertStringContainsString('WHERE sl.'.$scope.' = ?::uuid', $sql, 'The statement explained is not the '.$scope.' scope.');

            $this->assertPlanOn('stock_level', 'Inventory — /stock, '.$label.' (the reader\'s statement)', $this->plan($sql, [$id]), $index);
        }
    }

    /**
     * Inventory, `/stock-movements` — **the movement ledger pages inside a 5,000-row tie group without a sort** (stock reads
     * C2; `DATA` §1.4; `Version20261015000100`). The statement is {@see StockMovementReader::listStatement()}'s, the cursor
     * sits in the middle of the group (its 2,500th row in `id DESC` order), and on top of the index and RLS checks:
     * (a) no `Sort` node of any kind (`Sort`, `Incremental Sort`, top-N); (b) a backward index scan on the new index — a
     * bitmap scan is unordered; (c) the WHOLE row comparison `ROW(occurred_at, id) < ROW(…)` is an index condition, not a
     * `Filter`; (d) the scan returns at most `limit + 1` rows, so `LIMIT` stops it inside the group.
     *
     * `assertPlan()`'s blanket "no `Seq Scan`" is narrowed to `stock_movement` for these two tests only, with this recorded
     * reason (final RED M-3; `DATA` §1.4 item 6): the display joins seq-scan the seeded `storage_location` (8 rows) —
     * measured on the first run of this test, plan in the build log. **Positive control, run (not predicted):** with the two
     * old indexes restored in place of the new ones, both tests go red on (a) and (d) (build log, C2 mutation DATA-1).
     *
     * **Every join strategy, not the one the planner happens to pick** (shipped-defect fix, 2026-10-16,
     * `docs/session13/ledger-plan-fix-progress.md`). The scan is estimated at ONE row inside a tie group (Postgres costs a
     * row comparison from its first column), so the planner's choice between a nested loop and a merge join with
     * `storage_location` was a coin toss decided by where the seed's random uuids fell: the per-item test failed 1 run in
     * 10 at `6339297`, the merge join reading and sorting all 2,500 cursor-matching rows. The statement now cuts the page
     * from `stock_movement` alone, so each test explains it three times — as planned, with a merge join forced
     * (`enable_nestloop` and `enable_hashjoin` off) and with a hash join forced (`enable_nestloop` and `enable_mergejoin` off). `planWith()`'s settings here force the
     * thing under test, a join strategy; they are not a size accommodation. **(a) is restated, not loosened:** the page's
     * `Limit` must be the index scan's DIRECT parent — no `Sort` of any kind between the scan and the `LIMIT` that stops it
     * — and with (d) bounding the scan to `limit + 1` rows, every node above that `Limit` handles at most one page of
     * movements: the only sorts a forced join may add are the re-ordering of that page and, for a merge join, of the parent
     * table it merges against (measured: the forced merge join sorts `inventory_item`), never of the tie group. The statement
     * must also carry the order TWICE (inside the page and outside the joins): without the outer `ORDER BY`, the page's
     * order would again rest on the join the planner chose. **Mutations, run (progress note):** with the old statement
     * restored, the order-twice assertion goes red; with that assertion also disabled, the as-planned pass goes red on (a)
     * on 5 runs of 5 (its scan's parent is a join, not the `LIMIT`), and the old statement under a forced merge or hash join
     * reads and sorts all 2,500 cursor-matching rows (probe) — the defect itself.
     */
    #[Test]
    public function theMovementLedgerPagesInsideATieGroupWithoutASort(): void
    {
        $this->assertTieGroupPage(null, 'stock_movement_tenant_occurred_id_idx', 'Inventory — the movement ledger, page inside a tie group');
    }

    /** As above, for one item's movements — `ListStockMovements?inventory_item_id=` on the item index. */
    #[Test]
    public function theMovementLedgerForOneItemPagesInsideATieGroupWithoutASort(): void
    {
        $this->assertTieGroupPage(true, 'stock_movement_tenant_item_occurred_id_idx', 'Inventory — one item\'s movements, page inside a tie group');
    }

    /** @param 'actor'|'event'|null $filter */
    private function assertAuditTieGroupPage(?string $filter, string $index, string $label): void
    {
        $this->seedWorld();

        [$actor, $key, $id] = $this->tenantContext()->run(self::TENANT_A, function (): array {
            $user = (string) $this->app()->fetchOne("SELECT id FROM app_user WHERE email = 'plan-seed@example.com'");

            return [
                $user,
                (string) $this->app()->fetchOne('SELECT DISTINCT occurred_at::text FROM audit_log WHERE occurred_at = ?::timestamptz', [self::AUDIT_TIE_INSTANT]),
                (string) $this->app()->fetchOne('SELECT id FROM audit_log WHERE occurred_at = ?::timestamptz ORDER BY id DESC OFFSET 2499 LIMIT 1', [self::AUDIT_TIE_INSTANT]),
            ];
        });
        self::assertNotSame('', $actor, 'The plan seed user was not found, so the actor view would page over nothing.');

        $criteria = new AuditLogListCriteria(
            limit: AuditLogListCriteria::DEFAULT_LIMIT,
            actorUserId: 'actor' === $filter ? $actor : null,
            eventType: 'event' === $filter ? self::AUDIT_TIE_EVENT : null,
            after: new Cursor($key, $id),
        );
        [$sql, $parameters] = AuditLogEntryReader::listStatement($criteria);
        self::assertStringContainsString('(a.occurred_at, a.id) < (?::timestamptz, ?::uuid)', $sql, 'The statement explained is not the trail\'s keyset page.');
        self::assertSame(2, substr_count($sql, 'ORDER BY a.occurred_at DESC, a.id DESC'),
            'The order must be applied inside the page AND after the join, or the page order rests on the join strategy.');

        $this->assertAuditTieScan($sql, $parameters, $index, $label, $criteria->limit);
    }

    /**
     * The four assertions every audit tie-group page owes, under three join strategies — C1's, shared with C2's History
     * page (T-3) rather than copied.
     *
     * @param list<mixed> $parameters
     */
    private function assertAuditTieScan(string $sql, array $parameters, string $index, string $label, int $limit): void
    {
        foreach ([
            'as planned' => [],
            'merge join forced' => ['enable_nestloop' => 'off', 'enable_hashjoin' => 'off'],
            'hash join forced' => ['enable_nestloop' => 'off', 'enable_mergejoin' => 'off'],
        ] as $strategy => $settings) {
            $plan = $this->planWith($settings, $sql, $parameters);
            $this->assertPlanOn('audit_log', $label.' ('.$strategy.')', $plan, $index);

            self::assertMatchesRegularExpression('/(^|->)\s*Limit \(actual[^\n]*\n\s*->\s+Index Scan Backward using '.$index.' /m', $plan,
                "(a) [$strategy] Something sits between the index scan and the LIMIT that stops it — the page is sorted, not read in index order inside the tie group:\n$plan");
            self::assertStringContainsString('Index Scan Backward using '.$index, $plan, "(b) [$strategy] Not an ordered backward scan of the new index:\n$plan");
            self::assertMatchesRegularExpression('/Index Cond:[^\n]*ROW\((a\.)?occurred_at, (a\.)?id\) < ROW\(/', $plan,
                "(c) [$strategy] The row comparison is not an index condition:\n$plan");
            self::assertMatchesRegularExpression('/Index Scan Backward using '.$index.'[^\n]*rows=(\d+) loops=1/', $plan);
            preg_match('/Index Scan Backward using '.$index.'[^\n]*rows=(\d+) loops=1/', $plan, $m);
            self::assertLessThanOrEqual($limit + 1, (int) $m[1], "(d) [$strategy] The scan read past LIMIT inside the tie group:\n$plan");
        }
    }

    private function assertTieGroupPage(?bool $byItem, string $index, string $label): void
    {
        $this->seedWorld();

        [$itemId, $key, $id] = $this->tenantContext()->run(self::TENANT_A, function (): array {
            $item = (string) $this->app()->fetchOne('SELECT id FROM inventory_item WHERE sku = ?', [self::TIE_SKU]);

            return [
                $item,
                (string) $this->app()->fetchOne('SELECT DISTINCT occurred_at::text FROM stock_movement WHERE inventory_item_id = ?::uuid AND occurred_at = ?::timestamptz', [$item, self::TIE_INSTANT]),
                (string) $this->app()->fetchOne('SELECT id FROM stock_movement WHERE inventory_item_id = ?::uuid AND occurred_at = ?::timestamptz ORDER BY id DESC OFFSET 2499 LIMIT 1', [$item, self::TIE_INSTANT]),
            ];
        });

        $criteria = new StockMovementListCriteria(
            limit: StockMovementListCriteria::DEFAULT_LIMIT,
            inventoryItemId: null === $byItem ? null : $itemId,
            after: new Cursor($key, $id),
        );
        [$sql, $parameters] = StockMovementReader::listStatement($criteria);
        self::assertStringContainsString('(m.occurred_at, m.id) < (?::timestamptz, ?::uuid)', $sql, 'The statement explained is not the ledger keyset page.');
        self::assertSame(2, substr_count($sql, 'ORDER BY m.occurred_at DESC, m.id DESC'),
            'The order must be applied inside the page AND after the joins, or the page order rests on the join strategy.');

        foreach ([
            'as planned' => [],
            'merge join forced' => ['enable_nestloop' => 'off', 'enable_hashjoin' => 'off'],
            'hash join forced' => ['enable_nestloop' => 'off', 'enable_mergejoin' => 'off'],
        ] as $strategy => $settings) {
            $plan = $this->planWith($settings, $sql, $parameters);
            $this->assertPlanOn('stock_movement', $label.' ('.$strategy.')', $plan, $index);

            self::assertMatchesRegularExpression('/(^|->)\s*Limit \(actual[^\n]*\n\s*->\s+Index Scan Backward using '.$index.' /m', $plan,
                "(a) [$strategy] Something sits between the index scan and the LIMIT that stops it — the page is sorted, not read in index order inside the tie group:\n$plan");
            self::assertStringContainsString('Index Scan Backward using '.$index, $plan, "(b) [$strategy] Not an ordered backward scan of the new index:\n$plan");
            self::assertMatchesRegularExpression('/Index Cond:[^\n]*ROW\((m\.)?occurred_at, (m\.)?id\) < ROW\(/', $plan,
                "(c) [$strategy] The row comparison is not an index condition:\n$plan");
            self::assertMatchesRegularExpression('/Index Scan Backward using '.$index.'[^\n]*rows=(\d+) loops=1/', $plan);
            preg_match('/Index Scan Backward using '.$index.'[^\n]*rows=(\d+) loops=1/', $plan, $m);
            self::assertLessThanOrEqual($criteria->limit + 1, (int) $m[1], "(d) [$strategy] The scan read past LIMIT inside the tie group:\n$plan");
        }
    }
}
