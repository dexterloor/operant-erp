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
                // gen_random_uuid() emits a version 4 UUID, which is what
                // D-12's CHECK constraint requires -- a bulk seed using a
                // time-ordered generator would now be refused, which is the
                // point of the constraint.
                $this->app()->executeStatement(
                    "INSERT INTO inventory_item (id, tenant_id, sku, name, unit_of_measure_code, currency_code, reorder_point)
                     SELECT gen_random_uuid(), ?::uuid, ?::text || g, 'Item ' || g, 'EA', 'EUR', g
                     FROM generate_series(1, ?) g",
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

        // The predicate is not decoration: the index is partial on
        // `deleted_at IS NULL` (ADR-007 §1), so a query that omits it cannot use
        // it. SoftDeleteFilter emits exactly this clause on every ORM read,
        // which is what makes the live-row indexes reachable at all --
        // theSameLookupWithoutTheDeletionPredicateCannotUseTheLiveIndex below is
        // the other half of that statement.
        $plan = $this->plan(self::TENANT_A,
            'SELECT id FROM inventory_item WHERE sku = ? AND deleted_at IS NULL',
            [substr(self::TENANT_A, 0, 4).'-SKU-1500']);
        fwrite(STDERR, "\n  [EXPLAIN] WHERE sku = ? AND deleted_at IS NULL  (".(2 * self::ROWS_PER_TENANT)." rows, 2 tenants)\n    ".str_replace("\n", "\n    ", $plan)."\n");

        self::assertStringContainsString('inventory_item_tenant_sku_live', $plan,
            'The (tenant_id, sku) live index was not used, so ADR-002 §5\'s index guidance buys nothing.');
        self::assertStringNotContainsString('Seq Scan', $plan);
        // ADR-007 §3's explicit requirement: the RLS predicate must still be an
        // index condition once the partial predicate is added, not a filter
        // applied after rows are read.
        self::assertMatchesRegularExpression('/Index Cond:.*tenant_id/s', $plan,
            'The RLS predicate stopped being an index condition once the index became partial.');
    }

    /**
     * The cost side of ADR-007 §3, measured rather than assumed.
     *
     * Making every business index partial buys smaller indexes and the
     * re-creatable-SKU behaviour, and it hands the whole benefit back to any
     * query that forgets the predicate. That is the concrete reason the
     * deletion filter is enabled by default rather than opt-in, and the reason
     * a raw DBAL query written by hand in a repository is a performance hazard
     * in a way it was not before this decision.
     */
    #[Test]
    public function theSameLookupWithoutTheDeletionPredicateCannotUseTheLiveIndex(): void
    {
        $this->seedBulk();

        $plan = $this->plan(self::TENANT_A, 'SELECT id FROM inventory_item WHERE sku = ?',
            [substr(self::TENANT_A, 0, 4).'-SKU-1500']);
        fwrite(STDERR, "\n  [EXPLAIN] WHERE sku = ?  (no deletion predicate -- the partial index is unusable)\n    "
            .str_replace("\n", "\n    ", $plan)."\n");

        self::assertStringNotContainsString('inventory_item_tenant_sku_live', $plan,
            'A partial index was used for a query that does not carry its predicate, which cannot happen.');
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

        $plan = $this->plan(self::TENANT_A, 'SELECT count(*) FROM inventory_item WHERE reorder_point > ?', [1900]);
        fwrite(STDERR, "\n  [EXPLAIN] no tenant predicate written by the application\n    ".str_replace("\n", "\n    ", $plan)."\n");

        self::assertStringContainsString('tenant_id', $plan,
            'RLS did not put a tenant predicate into the plan at all.');
    }
}
