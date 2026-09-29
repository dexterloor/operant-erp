<?php

declare(strict_types=1);

namespace App\Tests\Tenancy;

use App\Platform\Tenancy\DatabaseRoles;
use Doctrine\DBAL\Exception\DriverException;
use PHPUnit\Framework\Attributes\Test;

/**
 * RLS is the security boundary (ADR-002). These all run as erp_app through the
 * pooled endpoint.
 *
 * **Privilege refusal vs RLS refusal (ADR-002 Amendment 4, rule R3; V4 batch B7,
 * 2026-09-16).** Both raise SQLSTATE `42501`, so the code alone cannot say which
 * control fired: a test asserting only `42501` for a `WITH CHECK` refusal stays
 * green if the privilege is later revoked and proves nothing about RLS. An RLS
 * refusal here therefore asserts `row-level security` in the message **and**,
 * in the same test, that `erp_app` holds the verb (`has_table_privilege` = true).
 * A privilege refusal asserts `permission denied for table`.
 */
final class TenantIsolationTest extends TenancyTestCase
{
    /**
     * R3's positive precondition: the refusal that follows can only be RLS if the
     * principal holds the verb. If a later narrowing revokes it, this fails first
     * and names why, instead of the WITH CHECK test going quietly fake-green.
     */
    private function assertApplicationRoleHolds(string $privilege, string $table): void
    {
        self::assertTrue((bool) $this->owner()->fetchOne(
            'SELECT has_table_privilege(?, ?, ?)', [DatabaseRoles::APP, $table, $privilege]),
            sprintf('%s no longer holds %s on %s, so a refusal of that statement is a privilege refusal and says '
                .'nothing about row-level security. Move this proof to a table and verb erp_app still writes.',
                DatabaseRoles::APP, $privilege, $table));
    }

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
            $this->em()->getRepository(\App\Modules\Inventory\Infrastructure\Entity\InventoryItem::class)->findAll());

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
        $this->assertApplicationRoleHolds('INSERT', 'inventory_item');

        try {
            $this->tenantContext()->run(self::TENANT_A, function (): void {
                $this->app()->executeStatement(
                    "INSERT INTO inventory_item (id, tenant_id, sku, name, unit_of_measure_code, currency_code)
             VALUES (gen_random_uuid(), ?::uuid, ?, 'x', 'EA', 'EUR')",
                    [self::TENANT_B, 'SMUGGLED'],
                );
            });
            self::fail('WITH CHECK did not stop a cross-tenant INSERT.');
        } catch (DriverException $e) {
            self::assertSame('42501', $e->getSQLState(), $e->getMessage());
            self::assertStringContainsString('row-level security', $e->getMessage(),
                'The INSERT was refused, but not by the tenant policy.');
        }

        self::assertSame(0, $this->countAsOwnerForTenant(self::TENANT_B, 'SELECT count(*) FROM inventory_item WHERE sku = ?', ['SMUGGLED']));
        self::assertSame(0, $this->countAsOwnerForTenant(self::TENANT_A, 'SELECT count(*) FROM inventory_item WHERE sku = ?', ['SMUGGLED']));
    }

    #[Test]
    public function aRowCannotBeMovedToAnotherTenantByUpdate(): void
    {
        $this->assertApplicationRoleHolds('UPDATE', 'inventory_item');
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
            self::assertStringContainsString('row-level security', $e->getMessage(),
                'The UPDATE was refused, but not by the tenant policy.');
        }
    }

    // `deleteCannotReachAnotherTenantsRow` was RETIRED in V4 batch B7 (2026-09-16), with a note to LEAD.
    // It proved RLS bound erp_app's DELETE on inventory_item. ADR-002 Amendment 4 leaves erp_app holding
    // DELETE on no table at all, because no `src/` path deletes a row: the capability is gone, which is
    // stronger than a policy filtering it, and a `permission denied` re-frame would duplicate
    // GrantFootprintTest. It was not moved to the owner: on Neon the owner is BYPASSRLS, so it would prove
    // the policy expression rather than the principal. Duty (ADR-002 A4.4 item 2): the build that first
    // grants DELETE on any table adds an erp_app RLS-DELETE isolation test on that table in the same commit,
    // using the privilege-vs-RLS rule in this class's docblock.
    // ⟶ **Discharged 2026-09-29 by roles slice 2, C2** (`RevokeRole`, `Version20260929000300`, the first DELETE grant):
    // `erpAppCannotDeleteAnotherTenantsUserRole` below.

    /**
     * **ADR-002 A4.4 duty 2 — the first `DELETE` grant (`user_role`, roles slice 2 C2) brings its RLS-DELETE isolation
     * test.** In tenant A's context, `DELETE FROM user_role WHERE id = <B's row>` deletes nothing and B's row survives.
     * `DELETE` raises no `WITH CHECK` error — RLS filters the rows a `DELETE` can see — so the evidence is the zero, and the
     * zero is RLS only because `erp_app` holds `DELETE` (R3's precondition, asserted first). Positive control, same test:
     * the same statement against A's own row deletes exactly one.
     */
    #[Test]
    public function erpAppCannotDeleteAnotherTenantsUserRole(): void
    {
        $this->assertApplicationRoleHolds('DELETE', 'user_role');
        $grants = [];
        foreach ([self::TENANT_A, self::TENANT_B] as $tenant) {
            [$user, $role, $grant] = [\Symfony\Component\Uid\Uuid::v4()->toRfc4122(), \Symfony\Component\Uid\Uuid::v4()->toRfc4122(), \Symfony\Component\Uid\Uuid::v4()->toRfc4122()];
            $this->insertAsOwnerForTenant($tenant, "INSERT INTO app_user (id, tenant_id, email, password_hash, display_name) VALUES (?::uuid, ?::uuid, ?, 'not-a-hash', 'U')", [$user, $tenant, 'u-'.$user.'@example.test']);
            $this->insertAsOwnerForTenant($tenant, "INSERT INTO role (id, tenant_id, name) VALUES (?::uuid, ?::uuid, 'R')", [$role, $tenant]);
            $this->insertAsOwnerForTenant($tenant, 'INSERT INTO user_role (id, tenant_id, user_id, role_id) VALUES (?::uuid, ?::uuid, ?::uuid, ?::uuid)', [$grant, $tenant, $user, $role]);
            $grants[$tenant] = $grant;
        }

        $deleted = $this->tenantContext()->run(self::TENANT_A, fn (): int => (int) $this->app()->executeStatement(
            'DELETE FROM user_role WHERE id = ?::uuid', [$grants[self::TENANT_B]]));
        self::assertSame(0, $deleted, 'erp_app, in tenant A\'s context, deleted tenant B\'s grant.');
        self::assertSame(1, $this->countAsOwnerForTenant(self::TENANT_B, 'SELECT count(*) FROM user_role WHERE id = ?::uuid', [$grants[self::TENANT_B]]));

        $own = $this->tenantContext()->run(self::TENANT_A, fn (): int => (int) $this->app()->executeStatement(
            'DELETE FROM user_role WHERE id = ?::uuid', [$grants[self::TENANT_A]]));
        self::assertSame(1, $own, 'Control: erp_app could not delete its own tenant\'s grant, so the zero above proves nothing.');
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
            "INSERT INTO inventory_item (id, tenant_id, sku, name, unit_of_measure_code, currency_code)
             VALUES (gen_random_uuid(), ?::uuid, ?, 'x', 'EA', 'EUR')",
            [self::TENANT_A, 'AI-WRITE'],
        );
    }
}
