<?php

declare(strict_types=1);

namespace App\Tests\Tenancy;

use PHPUnit\Framework\Attributes\Test;

/**
 * ADR-002 §5 asserts that tenant_id must lead every composite index, on the
 * grounds that RLS predicates are evaluated per row before user conditions.
 * That is a claim about the planner, so it is checked against the planner.
 *
 * The interesting part is that the tenant predicate is not written by the
 * application at all -- RLS injects it -- and current_setting() is STABLE, so
 * whether it can be used as an index condition rather than a post-scan filter is
 * not obvious. If it could not, every tenant-scoped read would degrade to a heap
 * scan and ADR-002's index guidance would be pointless.
 */
final class TenantIndexPlanTest extends TenancyTestCase
{
    private const ROWS_PER_TENANT = 2000;

    private function seedBulk(): void
    {
        foreach ([self::TENANT_A, self::TENANT_B] as $tenant) {
            $this->tenantContext()->run($tenant, function () use ($tenant): void {
                $this->app()->executeStatement(
                    'INSERT INTO inventory_item (id, tenant_id, sku, qty_on_hand)
                     SELECT gen_random_uuid(), ?::uuid, ?::text || g, g
                     FROM generate_series(1, ?) g',
                    [$tenant, substr($tenant, 0, 4).'-SKU-', self::ROWS_PER_TENANT],
                );
            });
        }

        // ANALYZE is an owner operation; erp_app has no right to it.
        $this->owner()->executeStatement('ANALYZE inventory_item');
    }

    private function plan(string $tenant, string $sql, array $params): string
    {
        return $this->tenantContext()->run($tenant, fn (): string => implode(
            "\n", $this->app()->fetchFirstColumn('EXPLAIN (ANALYZE, COSTS OFF) '.$sql, $params)
        ));
    }

    #[Test]
    public function aTenantScopedLookupUsesTheTenantLedIndexAndNotAHeapScan(): void
    {
        $this->seedBulk();

        $plan = $this->plan(self::TENANT_A, 'SELECT id FROM inventory_item WHERE sku = ?', [substr(self::TENANT_A, 0, 4).'-SKU-1500']);
        fwrite(STDERR, "\n  [EXPLAIN] WHERE sku = ?  (".(2 * self::ROWS_PER_TENANT)." rows, 2 tenants)\n    ".str_replace("\n", "\n    ", $plan)."\n");

        self::assertStringContainsString('inventory_item_tenant_sku_key', $plan,
            'The (tenant_id, sku) index was not used, so ADR-002 §5\'s index guidance buys nothing.');
        self::assertStringNotContainsString('Seq Scan', $plan);
    }

    /**
     * The case the mapping decision created: the ORM identifier is `id` alone, so
     * Doctrine emits WHERE id = ?, which is not led by tenant_id. Recorded rather
     * than assumed -- this is the cost side of choosing a scalar identifier.
     */
    #[Test]
    public function aLookupByTheOrmIdentifierAloneStillUsesAnIndex(): void
    {
        $this->seedBulk();

        $id = $this->tenantContext()->run(self::TENANT_A, fn (): string =>
            (string) $this->app()->fetchOne('SELECT id FROM inventory_item LIMIT 1'));

        $plan = $this->plan(self::TENANT_A, 'SELECT sku FROM inventory_item WHERE id = ?::uuid', [$id]);
        fwrite(STDERR, "\n  [EXPLAIN] WHERE id = ?  (what Doctrine's find() emits)\n    ".str_replace("\n", "\n    ", $plan)."\n");

        self::assertStringNotContainsString('Seq Scan', $plan);
    }

    /**
     * The RLS predicate itself: is the injected tenant comparison an index
     * condition, or a filter applied after rows are read?
     */
    #[Test]
    public function theInjectedTenantPredicateAppearsInThePlan(): void
    {
        $this->seedBulk();

        $plan = $this->plan(self::TENANT_A, 'SELECT count(*) FROM inventory_item WHERE qty_on_hand > ?', [1900]);
        fwrite(STDERR, "\n  [EXPLAIN] no tenant predicate written by the application\n    ".str_replace("\n", "\n    ", $plan)."\n");

        self::assertStringContainsString('tenant_id', $plan,
            'RLS did not put a tenant predicate into the plan at all.');
    }
}
