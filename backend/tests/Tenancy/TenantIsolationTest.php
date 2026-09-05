<?php

declare(strict_types=1);

namespace App\Tests\Tenancy;

use Doctrine\DBAL\Exception\DriverException;
use PHPUnit\Framework\Attributes\Test;

/**
 * RLS is the security boundary (ADR-002). These all run as erp_app through the
 * pooled endpoint.
 */
final class TenantIsolationTest extends TenancyTestCase
{
    /**
     * The genuinely-unset case, on a backend that has never seen app.tenant_id.
     *
     * current_setting() returns NULL, the policy comparison is NULL, and the
     * correct answer is nothing at all. ADR-002 §2: never
     * `current_setting(...) IS NULL OR ...`, which fails open. This test is the
     * one that catches that mutation behaviourally.
     */
    #[Test]
    public function readsReturnZeroRowsWhenTenantContextWasNeverSet(): void
    {
        $this->seedItem(self::TENANT_A, 'SKU-A');

        $fresh = $this->freshUnpooledClient();
        self::assertNull($fresh->fetchOne("SELECT current_setting('app.tenant_id', true)"),
            'This backend has already had the setting touched, so the NULL path is not being tested.');

        self::assertSame(0, (int) $fresh->fetchOne('SELECT count(*) FROM inventory_item'));
        self::assertSame(0, (int) $fresh->fetchOne('SELECT count(*) FROM supplier'));
        $fresh->close();
    }

    #[Test]
    public function readsReturnZeroRowsOnThePooledEndpointWithNoTenantContext(): void
    {
        $this->seedItem(self::TENANT_A, 'SKU-A');

        self::assertSame(0, (int) $this->app()->fetchOne('SELECT count(*) FROM inventory_item'));
        self::assertSame(0, (int) $this->app()->fetchOne('SELECT count(*) FROM supplier'));
    }

    /**
     * The same fail-closed requirement, for the *other* value the GUC can hold.
     *
     * `RESET app.tenant_id` -- and RESET ALL inside DISCARD ALL, which is
     * PgBouncer's own server_reset_query -- leaves a custom setting at the empty
     * string rather than unset. ADR-002 §2's expression as written casts '' to
     * uuid and raises 22P02 on every query against the table. That is
     * fail-closed but it is an outage, not a policy decision, so the policies
     * here wrap the setting in NULLIF.
     */
    #[Test]
    public function readsReturnZeroRowsWhenTenantContextIsTheEmptyString(): void
    {
        $this->seedItem(self::TENANT_A, 'SKU-A');

        $this->app()->executeStatement("SELECT set_config('app.tenant_id', '', false)");

        self::assertSame(0, (int) $this->app()->fetchOne('SELECT count(*) FROM inventory_item'));
        self::assertSame(0, (int) $this->app()->fetchOne('SELECT count(*) FROM supplier'));
    }

    #[Test]
    public function aTenantSeesOnlyItsOwnRows(): void
    {
        $this->seedItem(self::TENANT_A, 'SKU-A');
        $this->seedItem(self::TENANT_B, 'SKU-B');

        $skus = $this->tenantContext()->run(self::TENANT_A, fn (): array =>
            $this->app()->fetchFirstColumn('SELECT sku FROM inventory_item ORDER BY sku'));

        self::assertSame(['SKU-A'], $skus);
    }

    /**
     * ADR-002 "Verification" #2, the canary. The Doctrine filter is ergonomics,
     * not security (§4). Switch it off and isolation must be exactly as strong.
     * If rows come back here, RLS is off and the filter was the only thing
     * holding the line.
     */
    #[Test]
    public function isolationHoldsWithTheDoctrineFilterDisabled(): void
    {
        $this->seedItem(self::TENANT_A, 'SKU-A');
        $this->seedItem(self::TENANT_B, 'SKU-B');

        $this->em()->getFilters()->disable('tenant');
        self::assertFalse($this->em()->getFilters()->isEnabled('tenant'));

        $rows = $this->tenantContext()->run(self::TENANT_A, fn (): array =>
            $this->em()->getRepository(\App\Entity\InventoryItem::class)->findAll());

        self::assertCount(1, $rows, 'With the Doctrine filter off, RLS must still return exactly tenant A.');
        self::assertSame('SKU-A', $rows[0]->getSku());
    }

    /**
     * ADR-002 §4: a filter adds to conditional clauses, and an INSERT has no
     * WHERE. WITH CHECK is the only write guard.
     */
    #[Test]
    public function writingIntoAnotherTenantIsRejectedByWithCheck(): void
    {
        try {
            $this->tenantContext()->run(self::TENANT_A, function (): void {
                $this->app()->executeStatement(
                    'INSERT INTO inventory_item (id, tenant_id, sku) VALUES (gen_random_uuid(), ?::uuid, ?)',
                    [self::TENANT_B, 'SMUGGLED'],
                );
            });
            self::fail('WITH CHECK did not stop a cross-tenant INSERT.');
        } catch (DriverException $e) {
            self::assertSame('42501', $e->getSQLState(), $e->getMessage());
        }

        self::assertSame(0, $this->countAsOwnerForTenant(self::TENANT_B, 'SELECT count(*) FROM inventory_item WHERE sku = ?', ['SMUGGLED']));
        self::assertSame(0, $this->countAsOwnerForTenant(self::TENANT_A, 'SELECT count(*) FROM inventory_item WHERE sku = ?', ['SMUGGLED']));
    }

    #[Test]
    public function aRowCannotBeMovedToAnotherTenantByUpdate(): void
    {
        $this->seedItem(self::TENANT_A, 'SKU-A');

        try {
            $this->tenantContext()->run(self::TENANT_A, function (): void {
                $this->app()->executeStatement(
                    'UPDATE inventory_item SET tenant_id = ?::uuid WHERE sku = ?', [self::TENANT_B, 'SKU-A']
                );
            });
            self::fail('WITH CHECK did not stop a cross-tenant UPDATE.');
        } catch (DriverException $e) {
            self::assertSame('42501', $e->getSQLState(), $e->getMessage());
        }
    }

    #[Test]
    public function deleteCannotReachAnotherTenantsRow(): void
    {
        $this->seedItem(self::TENANT_B, 'SKU-B');

        $affected = $this->tenantContext()->run(self::TENANT_A, fn (): int =>
            $this->app()->executeStatement('DELETE FROM inventory_item WHERE sku = ?', ['SKU-B']));

        self::assertSame(0, $affected);
        self::assertSame(1, $this->countAsOwnerForTenant(self::TENANT_B, 'SELECT count(*) FROM inventory_item WHERE sku = ?', ['SKU-B']));
    }

    /**
     * R-15. ADR-002 §5: integrity checks always bypass row security, so a bare
     * UNIQUE (sku) would answer "that SKU is taken" for a row the caller may not
     * see. With UNIQUE (tenant_id, sku) the same SKU in two tenants is simply
     * legal, and there is nothing to enumerate.
     */
    #[Test]
    public function twoTenantsMayHoldTheSameSkuWithoutACollision(): void
    {
        $this->seedItem(self::TENANT_A, 'SHARED-SKU');
        $this->seedItem(self::TENANT_B, 'SHARED-SKU');

        self::assertSame(1, $this->countAsOwnerForTenant(self::TENANT_A, 'SELECT count(*) FROM inventory_item WHERE sku = ?', ['SHARED-SKU']));
        self::assertSame(1, $this->countAsOwnerForTenant(self::TENANT_B, 'SELECT count(*) FROM inventory_item WHERE sku = ?', ['SHARED-SKU']));
    }

    #[Test]
    public function theOwnerRoleIsAlsoSubjectToThePolicies(): void
    {
        // ADR-002 §C-2: ENABLE alone does not bind the table owner; only FORCE does.
        $this->seedItem(self::TENANT_A, 'SKU-A');

        self::assertSame(0, (int) $this->owner()->fetchOne('SELECT count(*) FROM inventory_item'),
            'The owner read rows with no tenant context -- FORCE ROW LEVEL SECURITY is not in effect.');
        self::assertSame(1, $this->countAsOwnerForTenant(self::TENANT_A, 'SELECT count(*) FROM inventory_item'),
            'and with context the owner sees exactly that tenant, proving the row was really there.');
    }

    #[Test]
    public function theReadOnlyAgentRoleCannotWrite(): void
    {
        $ai = self::getContainer()->get('doctrine.dbal.ai_connection');
        self::assertSame('erp_ai', $ai->fetchOne('SELECT current_user'));

        $this->expectException(DriverException::class);
        $ai->executeStatement(
            'INSERT INTO inventory_item (id, tenant_id, sku) VALUES (gen_random_uuid(), ?::uuid, ?)',
            [self::TENANT_A, 'AI-WRITE'],
        );
    }
}
