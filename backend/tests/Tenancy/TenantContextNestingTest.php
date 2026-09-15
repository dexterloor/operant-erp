<?php

declare(strict_types=1);

namespace App\Tests\Tenancy;

use App\Platform\Tenancy\TenantContextViolation;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;

/**
 * R-1 through the sanctioned API.
 *
 * TenantContext::run() calls itself the only supported way to establish tenant
 * context, so whatever it permits is the design. wrapInTransaction() nests into
 * an already-open transaction, and set_config(..., true) is scoped to the
 * OUTERMOST transaction -- so an inner run() for a different tenant does not end
 * when its block returns. It repoints the rest of the outer block at the inner
 * tenant.
 *
 * The three tests below are RED's, in the order it reported them, and they are
 * written to exhibit the damage rather than merely to report "no exception".
 * The last two cover the cases a fix can plausibly break or miss. (A fourth, the
 * DELETE variant of the third, was retired in V4 batch B7; see the note where it
 * stood.)
 */
final class TenantContextNestingTest extends TenancyTestCase
{
    /** @return \Throwable|null whatever the nested call did, so the test can carry on and show the damage */
    private function attemptNested(string $tenantId): ?\Throwable
    {
        try {
            $this->tenantContext()->run($tenantId, static fn (): null => null);

            return null;
        } catch (\Throwable $e) {
            return $e;
        }
    }

    /**
     * RED severity 1 -- fail-closed, an outage.
     *
     * After a nested call for another tenant, an explicit INSERT for the tenant
     * the outer block believes it is running as is rejected by WITH CHECK.
     */
    #[Test]
    public function anExplicitInsertForTheOuterTenantStillSucceedsAfterARefusedNestedCall(): void
    {
        $thrown = $this->tenantContext()->run(self::TENANT_A, function (): ?\Throwable {
            $thrown = $this->attemptNested(self::TENANT_B);

            // If the nested call had been allowed to take effect, the connection
            // would now be running as B and this row -- explicitly tenant A --
            // would be rejected: "new row violates row-level security policy".
            $this->app()->executeStatement(
                "INSERT INTO inventory_item (id, tenant_id, sku, name, unit_of_measure_code, currency_code)
                 VALUES (gen_random_uuid(), ?::uuid, ?, 'x', 'EA', 'EUR')",
                [self::TENANT_A, 'AFTER-NESTED'],
            );

            return $thrown;
        });

        self::assertInstanceOf(TenantContextViolation::class, $thrown,
            'A nested run() for a different tenant must be refused.');
        self::assertSame(1, $this->countAsOwnerForTenant(
            self::TENANT_A, 'SELECT count(*) FROM inventory_item WHERE sku = ?', ['AFTER-NESTED']));
    }

    /** RED severity 2 -- a silent read leak. */
    #[Test]
    public function readsAfterARefusedNestedCallStillSeeOnlyTheOuterTenant(): void
    {
        $this->seedItem(self::TENANT_A, 'SKU-A');
        $this->seedItem(self::TENANT_B, 'SKU-B');

        $skus = $this->tenantContext()->run(self::TENANT_A, function (): array {
            $this->attemptNested(self::TENANT_B);

            return $this->app()->fetchFirstColumn('SELECT sku FROM inventory_item ORDER BY sku');
        });

        self::assertSame(['SKU-A'], $skus,
            'The outer block read the inner tenant\'s rows -- cross-tenant leak through the sanctioned API.');
    }

    /**
     * RED severity 3 -- the one that blocks.
     *
     * An UPDATE with no tenant predicate is the normal shape in this design,
     * because RLS is supposed to supply the predicate. If the context has been
     * repointed, that statement rewrites another tenant's rows and reports
     * success.
     */
    #[Test]
    public function anUpdateWithNoTenantPredicateAfterARefusedNestedCallCannotReachTheOtherTenant(): void
    {
        $this->seedItem(self::TENANT_A, 'SKU-A');
        $this->seedItem(self::TENANT_B, 'SKU-B');

        $affected = $this->tenantContext()->run(self::TENANT_A, function (): int {
            $this->attemptNested(self::TENANT_B);

            return $this->app()->executeStatement('UPDATE inventory_item SET reorder_point = 999');
        });

        self::assertSame(1, $affected);
        self::assertSame(1, $this->countAsOwnerForTenant(
            self::TENANT_A, 'SELECT count(*) FROM inventory_item WHERE reorder_point = 999'));
        self::assertSame(0, $this->countAsOwnerForTenant(
            self::TENANT_B, 'SELECT count(*) FROM inventory_item WHERE reorder_point = 999'),
            'A tenant-predicate-free UPDATE silently rewrote another tenant\'s rows.');
    }

    // `aDeleteWithNoTenantPredicateAfterARefusedNestedCallCannotReachTheOtherTenant` was RETIRED in V4 batch B7
    // (2026-09-16), with a note to LEAD. ADR-002 Amendment 4 leaves erp_app holding DELETE on no table, so
    // its statement can no longer run; re-framed as a refusal it would lose its positive control (tenant A's
    // row really removed) and prove nothing about re-entrancy. The mechanism it exhibited -- a refused nested
    // run() must not repoint the outer block -- is carried by the UPDATE sibling above, whose verb erp_app
    // keeps on inventory_item.

    /**
     * The case that rules out forbidding nesting outright.
     *
     * Two services each legitimately wrapping their own unit of work, one calling
     * the other, same tenant. This is the ordinary shape in a service graph, and
     * a fix that throws here would be worked around by deleting run() calls --
     * which erodes the invariant instead of enforcing it.
     */
    #[Test]
    public function nestingForTheSameTenantIsPermittedAndDoesNotDisturbTheOuterContext(): void
    {
        $this->seedItem(self::TENANT_A, 'SKU-A');

        $result = $this->tenantContext()->run(self::TENANT_A, function (): array {
            $inner = $this->tenantContext()->run(self::TENANT_A, fn (): array =>
                $this->app()->fetchFirstColumn('SELECT sku FROM inventory_item'));

            $outerAfter = $this->app()->fetchOne("SELECT current_setting('app.tenant_id', true)");

            $this->app()->executeStatement(
                "INSERT INTO inventory_item (id, tenant_id, sku, name, unit_of_measure_code, currency_code)
                 VALUES (gen_random_uuid(), ?::uuid, ?, 'x', 'EA', 'EUR')",
                [self::TENANT_A, 'STILL-A'],
            );

            return ['inner' => $inner, 'context' => $outerAfter];
        });

        self::assertSame(['SKU-A'], $result['inner']);
        self::assertSame(self::TENANT_A, $result['context'], 'The outer context was disturbed by a same-tenant nested call.');
        self::assertSame(1, $this->countAsOwnerForTenant(
            self::TENANT_A, 'SELECT count(*) FROM inventory_item WHERE sku = ?', ['STILL-A']));
    }

    /**
     * The hole a same-tenant re-entrancy rule does not close on its own.
     *
     * If something other than TenantContext opened the transaction, then
     * set_config(..., true) binds to that transaction and outlives run() exactly
     * as a nested switch would -- and no depth counter inside this class can see
     * it. The connection's own nesting level can.
     */
    #[Test]
    public function establishingContextInsideAForeignTransactionIsRefused(): void
    {
        $connection = $this->em()->getConnection();
        $connection->beginTransaction();

        try {
            $this->expectException(TenantContextViolation::class);
            $this->tenantContext()->run(self::TENANT_A, static fn (): null => null);
        } finally {
            $connection->rollBack();
        }
    }

    /** The refusal must not poison the caller's transaction: it happens before any statement. */
    #[Test]
    public function aRefusedNestedCallLeavesTheOuterTransactionUsable(): void
    {
        $ok = $this->tenantContext()->run(self::TENANT_A, function (EntityManagerInterface $em): bool {
            $this->attemptNested(self::TENANT_B);

            return 1 === (int) $this->app()->fetchOne('SELECT 1');
        });

        self::assertTrue($ok);
    }
}
