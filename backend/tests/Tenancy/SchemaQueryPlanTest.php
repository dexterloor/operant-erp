<?php

declare(strict_types=1);

namespace App\Tests\Tenancy;

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
                    INSERT INTO supplier (id, tenant_id, name, code, currency_code)
                    SELECT gen_random_uuid(), ?::uuid, 'Supplier ' || g, 'SUP' || g, 'EUR'
                    FROM generate_series(1, 20) g
                    SQL, [$tenant]);

                $this->insertAsOwnerForTenant($tenant, <<<'SQL'
                    INSERT INTO customer (id, tenant_id, code, name, currency_code)
                    SELECT gen_random_uuid(), ?::uuid, 'CUS' || g, 'Customer ' || lpad(g::text, 4, '0'), 'EUR'
                    FROM generate_series(1, 200) g
                    SQL, [$tenant]);

                // One purchase order in ten is still open. The rest are received,
                // which is what makes the partial index worth having: the query
                // that matters reads a tenth of the table.
                $c->executeStatement(<<<'SQL'
                    INSERT INTO purchase_order (id, tenant_id, order_number, supplier_id, warehouse_id, currency_code, status, ordered_at, idempotency_key, request_digest)
                    SELECT gen_random_uuid(), ?::uuid, 'PO-' || g,
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
                    SELECT gen_random_uuid(), ?::uuid, 'SO-' || g,
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
                                         invoice_number, status, currency_code, total_amount, issued_at, due_at, paid_at)
                    SELECT gen_random_uuid(), s.tenant_id, s.id, s.customer_id, f.id, f.shipped_by_user_id,
                           'INV-' || lpad(substring(s.order_number FROM 4), 6, '0'),
                           CASE WHEN right(s.order_number, 1) = '0' THEN 'issued' ELSE 'paid' END,
                           'EUR', 100.00, s.ordered_at, s.ordered_at + interval '30 days',
                           -- invoice_paid_at_set: a paid invoice must say when.
                           -- The constraint caught this seed the first time it ran.
                           CASE WHEN right(s.order_number, 1) = '0' THEN NULL ELSE s.ordered_at + interval '10 days' END
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
                    'stock_movement' => self::EVENTS, 'audit_log' => self::EVENTS],
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
            'stock_movement_tenant_item_idx');
    }

    /** Customers: "the customer list, alphabetical" — the module's landing screen. */
    #[Test]
    public function theCustomerListUsesTheLiveNameIndex(): void
    {
        $this->assertPlan(
            'Customers — alphabetical list, live rows only',
            $this->plan(<<<'SQL'
                SELECT id, name FROM customer
                WHERE deleted_at IS NULL
                ORDER BY name
                LIMIT 50
                SQL),
            'customer_tenant_name_live');
    }

    /** AuditLog: "what happened in this tenant, newest first". */
    #[Test]
    public function theAuditTrailScreenUsesTheOccurredAtIndex(): void
    {
        $this->assertPlan(
            'AuditLog — the tenant trail, newest first',
            $this->plan('SELECT id, event_type FROM audit_log ORDER BY occurred_at DESC LIMIT 50'),
            'audit_log_tenant_occurred_idx');
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
            'audit_log_tenant_event_idx');
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
}
