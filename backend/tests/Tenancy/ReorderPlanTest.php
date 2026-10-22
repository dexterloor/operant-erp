<?php

declare(strict_types=1);

namespace App\Tests\Tenancy;

use App\Modules\Inventory\Application\ReorderStockReader;
use App\Modules\Purchasing\Application\OpenPurchaseSupplyReader;
use PHPUnit\Framework\Attributes\Test;

/**
 * **The reorder computation's two plan tests** (remaining-ops G3-C1, 2026-10-22; `docs/session13/g3-g7b-reorder-settings-design.md`
 * §2.6, P-1 and P-2). Each EXPLAINs the statement the reader actually issues (its public constant), as `erp_app` under RLS,
 * against two tenants of comparable size, and each can go red under a named mutation:
 *
 *  - **P-1 (S2, `OpenPurchaseSupplyReader::ON_ORDER`):** 3,000 closed orders (received / cancelled) per tenant with lines and
 *    5 open ones — the open set is reached through `purchase_order_tenant_open_idx`, and `purchase_order_line` is never read
 *    by a sequential scan. Red under M-C1-5 (the status predicate written as `NOT IN ('draft','received','cancelled')`, which
 *    the partial index's predicate no longer implies).
 *  - **P-2 (S1, `ReorderStockReader::MANAGED_ITEMS`):** 2,000 managed items × 3 bins per tenant — the plan carries no
 *    `SubPlan`: the stock is aggregated once and joined, never probed per item. Red under M-C1-6 (on hand as a correlated
 *    scalar subquery).
 *
 * S3 (`PreferredTermsReader::TERMS`) is a unique-key probe; it has no plan test because no assertion about it could fail.
 */
final class ReorderPlanTest extends TenancyTestCase
{
    private const CLOSED_ORDERS = 3000;
    private const OPEN_ORDERS = 5;
    private const ITEMS = 2000;

    #[Test]
    public function theOnOrderSupplyIsReadThroughTheOpenOrderIndex(): void
    {
        foreach ([self::TENANT_A, self::TENANT_B] as $tenant) {
            $this->insertAsOwnerForTenant($tenant, "INSERT INTO warehouse (id, tenant_id, code, name, country_code) VALUES (gen_random_uuid(), ?::uuid, 'W-PLAN', 'Plan WH', 'DE')", [$tenant]);
            $this->insertAsOwnerForTenant($tenant, "INSERT INTO supplier (id, tenant_id, name, code, country_code, currency_code) VALUES (gen_random_uuid(), ?::uuid, 'Plan Supplier', 'S-PLAN', 'GB', 'EUR')", [$tenant]);
            $this->insertAsOwnerForTenant($tenant, <<<'SQL'
                INSERT INTO inventory_item (id, tenant_id, sku, name, unit_of_measure_code, currency_code)
                SELECT gen_random_uuid(), ?::uuid, 'SKU-P1-' || g, 'Item ' || g, 'EA', 'EUR' FROM generate_series(1, 50) g
                SQL, [$tenant]);
            $this->insertAsOwnerForTenant($tenant, <<<'SQL'
                INSERT INTO purchase_order (id, tenant_id, order_number, supplier_id, warehouse_id, currency_code, status, ordered_at,
                                            idempotency_key, request_digest)
                SELECT gen_random_uuid(), ?::uuid, 'PO-' || lpad(g::text, 6, '0'),
                       (SELECT id FROM supplier LIMIT 1), (SELECT id FROM warehouse LIMIT 1), 'EUR',
                       CASE WHEN g <= ? THEN 'ordered' WHEN g % 2 = 0 THEN 'received' ELSE 'cancelled' END,
                       now() - (g || ' hours')::interval,
                       encode(sha256(gen_random_uuid()::text::bytea), 'hex'), encode(sha256(gen_random_uuid()::text::bytea), 'hex')
                  FROM generate_series(1, ?) g
                SQL, [$tenant, self::OPEN_ORDERS, self::OPEN_ORDERS + self::CLOSED_ORDERS]);
            $this->insertAsOwnerForTenant($tenant, <<<'SQL'
                INSERT INTO purchase_order_line (id, tenant_id, purchase_order_id, inventory_item_id, line_number,
                                                 quantity_ordered, quantity_received, unit_cost, lead_time_days)
                SELECT gen_random_uuid(), po.tenant_id, po.id, (SELECT id FROM inventory_item ORDER BY sku LIMIT 1 OFFSET (n % 50)), n,
                       10, CASE WHEN po.status = 'received' THEN 10 ELSE 0 END, 1, 7
                  FROM purchase_order po CROSS JOIN generate_series(1, 2) n
                SQL);
        }
        $this->analyze();

        $plan = $this->plan(OpenPurchaseSupplyReader::ON_ORDER);

        self::assertStringContainsString('purchase_order_tenant_open_idx', $plan,
            "The open purchase orders are not reached through their partial index, so the reorder reads every closed order:\n$plan");
        self::assertDoesNotMatchRegularExpression('/Seq Scan on purchase_order_line\b/', $plan,
            "Every purchase-order line in the workspace is read to find the open ones:\n$plan");
        self::assertMatchesRegularExpression('/Index Cond:[^\n]*tenant_id/', $plan, "The RLS predicate is not an index condition:\n$plan");
    }

    #[Test]
    public function theOnHandIsAggregatedOnceAndNeverProbedPerItem(): void
    {
        foreach ([self::TENANT_A, self::TENANT_B] as $tenant) {
            $this->insertAsOwnerForTenant($tenant, "INSERT INTO warehouse (id, tenant_id, code, name, country_code) VALUES (gen_random_uuid(), ?::uuid, 'W-PLAN', 'Plan WH', 'DE')", [$tenant]);
            $this->insertAsOwnerForTenant($tenant, <<<'SQL'
                INSERT INTO storage_location (id, tenant_id, warehouse_id, code, kind)
                SELECT gen_random_uuid(), w.tenant_id, w.id, 'BIN-' || g, CASE WHEN g = 3 THEN 'quarantine' ELSE 'bin' END
                  FROM warehouse w CROSS JOIN generate_series(1, 3) g
                SQL);
            $this->insertAsOwnerForTenant($tenant, <<<'SQL'
                INSERT INTO inventory_item (id, tenant_id, sku, name, unit_of_measure_code, currency_code, reorder_point, reorder_quantity)
                SELECT gen_random_uuid(), ?::uuid, 'SKU-P2-' || lpad(g::text, 5, '0'), 'Item ' || g, 'EA', 'EUR', 10, 5
                  FROM generate_series(1, ?) g
                SQL, [$tenant, self::ITEMS]);
            $this->insertAsOwnerForTenant($tenant, <<<'SQL'
                INSERT INTO stock_level (id, tenant_id, inventory_item_id, storage_location_id, qty_on_hand)
                SELECT gen_random_uuid(), i.tenant_id, i.id, l.id, 4 FROM inventory_item i CROSS JOIN storage_location l
                SQL);
        }
        $this->analyze();

        $plan = $this->plan(ReorderStockReader::MANAGED_ITEMS);

        self::assertStringNotContainsString('SubPlan', $plan, "On hand is probed once per item instead of aggregated once:\n$plan");
        self::assertStringContainsString('Aggregate', $plan, "Control: the plan aggregates nothing, so it is not the statement under test:\n$plan");
        self::assertMatchesRegularExpression('/(Index Cond|Filter):[^\n]*tenant_id/', $plan, "The RLS predicate does not appear in the plan:\n$plan");
    }

    private function analyze(): void
    {
        // ANALYZE is an owner operation (SchemaQueryPlanTest's reason): without it the planner reasons from empty-table guesses.
        $this->owner()->executeStatement('ANALYZE warehouse, storage_location, supplier, inventory_item, stock_level, purchase_order, purchase_order_line');
    }

    private function plan(string $sql): string
    {
        $plan = $this->tenantContext()->run(self::TENANT_A, fn (): string => implode("\n", $this->app()->fetchFirstColumn('EXPLAIN (ANALYZE, COSTS OFF) '.$sql)));
        fwrite(STDERR, "\n  [EXPLAIN] reorder\n    ".str_replace("\n", "\n    ", $plan)."\n");

        return $plan;
    }
}
