<?php

declare(strict_types=1);

namespace App\Tests\Tenancy;

use Doctrine\DBAL\Exception\DriverException;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Uid\Uuid;

/**
 * E4-3 / `ROADMAP.md` F-11 / E9-1 — the storage half of `tenant_settings`.
 *
 * ---------------------------------------------------------------------------
 * What this file does NOT assert, and why that is not a gap
 * ---------------------------------------------------------------------------
 * Nothing here checks that the table has RLS enabled and forced, carries a
 * `USING`/`WITH CHECK` policy pair, pins its primary key to UUIDv4, references
 * the registry, exposes `UNIQUE (tenant_id, id)`, leads every unique index with
 * `tenant_id`, or that `erp_app` holds exactly `SELECT` on it. Every one of
 * those is already asserted for **every** table by `SchemaManifestTest`,
 * `SchemaGuardTest`, `SchemaOwnershipTest` and `GrantFootprintTest`, all of
 * which derive their domain from `SchemaManifest::TABLES` and the live
 * catalogue and none of which has an allow-list. Restating them here would be
 * a second, weaker copy of a check that already covers the case — the failure
 * mode this project has hit repeatedly.
 *
 * What is left is what those cannot see: a property of the column rather than
 * of the table, the isolation as *behaviour* rather than as catalogue state,
 * the write refusal as a *statement* rather than as `has_table_privilege`, and
 * the deliberate absence of any mechanism that would create a settings row on
 * its own.
 */
final class TenantSettingsStorageTest extends TenancyTestCase
{
    /**
     * E4-3's second *fails if* clause, at the database.
     *
     * A `DEFAULT 'EUR'` here would be `app.inventory.default_currency_code`
     * relocated one layer down: every workspace priced in euros unless somebody
     * remembered to say otherwise, which is the state the initial migration
     * dropped `inventory_item.currency_code`'s default to prevent.
     *
     * Mutation: `ALTER TABLE tenant_settings ALTER COLUMN default_currency_code
     * SET DEFAULT 'EUR'` — red.
     */
    #[Test]
    public function theCurrencyColumnHasNoDatabaseDefaultAndIsNotNullable(): void
    {
        $column = $this->owner()->fetchAssociative(<<<'SQL'
            SELECT a.attnotnull AS not_null,
                   pg_get_expr(d.adbin, d.adrelid) AS default_expr
            FROM pg_attribute a
            LEFT JOIN pg_attrdef d ON d.adrelid = a.attrelid AND d.adnum = a.attnum
            WHERE a.attrelid = 'tenant_settings'::regclass
              AND a.attname = 'default_currency_code'
            SQL);

        self::assertIsArray($column, 'tenant_settings.default_currency_code does not exist.');
        self::assertNull($column['default_expr'],
            'tenant_settings.default_currency_code carries a database default. That is the instance-wide '
            .'currency constant this table exists to remove, moved one layer down: every workspace would '
            .'again be priced in a currency nobody stated. Version20260906000100 dropped '
            .'inventory_item.currency_code\'s default for exactly this reason.');
        self::assertTrue((bool) $column['not_null'],
            'A nullable functional currency is the same defect as a defaulted one wearing a different hat: '
            .'the reader would have to decide what NULL means, and the only answers are "fail" (which '
            .'NOT NULL already says, earlier and at the database) or "substitute".');
    }

    /**
     * The isolation as behaviour. `SchemaManifestTest` proves the policy exists;
     * this proves it answers.
     *
     * Mutation, run: `ALTER POLICY tenant_isolation ON tenant_settings USING
     * (true)` — tenant A's read returns `['USD', 'JPY']` and the assertion
     * fails on the extra element. That is the leak, exactly.
     *
     * `DROP POLICY` was tried first and is the weaker mutation: with RLS still
     * forced and no policy at all, *nothing* is permitted, so the fixture's own
     * seed raises and the test errors before it asserts anything. Red either
     * way, but only the widened policy makes it red for the reason claimed.
     */
    #[Test]
    public function eachWorkspaceReadsItsOwnCurrencyAndNoOther(): void
    {
        $this->seedTenantSettings(self::TENANT_A, 'USD');
        $this->seedTenantSettings(self::TENANT_B, 'JPY');

        $read = fn (string $tenantId): array => $this->tenantContext()->run(
            $tenantId,
            fn (): array => $this->app()->fetchFirstColumn('SELECT default_currency_code FROM tenant_settings'),
        );

        self::assertSame(['USD'], $read(self::TENANT_A));
        self::assertSame(['JPY'], $read(self::TENANT_B));
    }

    /**
     * The grant footprint as a statement rather than as a catalogue lookup.
     *
     * `GrantFootprintTest::everyRoleHoldsExactlyTheTablePrivilegesTheManifestDeclares`
     * asserts `has_table_privilege` is `SELECT` and nothing else, in both
     * directions, and that is the exhaustive check. This one asserts the thing
     * a reader actually wants to know — that the three writes *fail* — because
     * a privilege check and a refused statement have come apart before in this
     * project (a privilege check written against `information_schema` resolves
     * neither `PUBLIC` grants nor role inheritance).
     *
     * Each verb gets its own connection, and that is not tidiness. A refused
     * statement inside `TenantContext::run()` closes the entity manager, so
     * three verbs sharing one unit of work measure the first refusal and then
     * an `EntityManagerClosed` — the second and third would report a failure
     * they never reached, which is a fake-green wearing a red hat. Measured:
     * the shared-unit-of-work version of this test reported
     * `EntityManagerClosed` when `UPDATE` was granted, instead of reporting
     * that `UPDATE` was accepted.
     *
     * Mutation, run: `GRANT UPDATE ON tenant_settings TO erp_app` — UPDATE
     * reads `accepted` and the assertion fails naming it. It also turns
     * `GrantFootprintTest` red, which is the point of having both.
     */
    #[Test]
    public function theApplicationPrincipalCannotWriteTheSettingsRowAtAll(): void
    {
        $refusals = [];

        foreach ([
            'INSERT' => "INSERT INTO tenant_settings (id, tenant_id, default_currency_code) VALUES ('11111111-1111-4111-8111-11111111aaaa'::uuid, '".self::TENANT_A."'::uuid, 'USD')",
            'UPDATE' => "UPDATE tenant_settings SET default_currency_code = 'USD'",
            'DELETE' => 'DELETE FROM tenant_settings',
        ] as $verb => $sql) {
            $refusals[$verb] = $this->attemptAsApplicationRole($sql);
        }

        self::assertSame(
            ['INSERT' => 'permission denied', 'UPDATE' => 'permission denied', 'DELETE' => 'permission denied'],
            $refusals,
            'erp_app can write tenant_settings. It holds SELECT and nothing else by design: it cannot '
            .'create a workspace (S-10 / V-10) so it must not create a workspace\'s settings; UpdateSettings '
            .'is not shippable (API-O-14) so UPDATE has no user; and the deletion class is `none`, because '
            .'removing the row silently restores the "no stated currency" state.');
    }

    /**
     * One statement, as `erp_app`, in this workspace's context, on a connection
     * of its own, always rolled back.
     *
     * Tenant context is established through `TenantContext::establishOn()`
     * rather than by hand: that is the sanctioned writer
     * (`TenantContextContainmentTest` allows exactly one production file), and
     * routing through it keeps this file off that test's allow-list.
     */
    private function attemptAsApplicationRole(string $sql): string
    {
        $connection = $this->secondClient();
        $connection->beginTransaction();

        try {
            $this->tenantContext()->establishOn($connection, self::TENANT_A);
            $connection->executeStatement($sql);

            return 'accepted';
        } catch (DriverException $e) {
            return str_contains($e->getMessage(), 'permission denied') ? 'permission denied' : $e->getMessage();
        } finally {
            $connection->rollBack();
            $connection->close();
        }
    }

    /**
     * E4-3's residual, asserted rather than described.
     *
     * There is deliberately **no** `AFTER INSERT ON tenant` trigger creating a
     * settings row, because a trigger would have to invent a currency — which
     * is the defect, not the fix. So a workspace registered without one has
     * none, and the writer's refusal (see `ItemCurrencyTest`) is what that
     * costs. If somebody later adds the convenience trigger, this test is what
     * they have to argue with.
     *
     * Mutation, run: an `AFTER INSERT ON tenant` trigger inserting a settings
     * row at `'EUR'`. Red — and it is worth recording *how*. The trigger's own
     * INSERT is refused by this table's `WITH CHECK` policy, because a registry
     * insert establishes no tenant context, so the registry insert on the line
     * below fails and the test errors. The convenience trigger is not merely
     * absent; RLS actively resists it, and any future version would have to
     * establish tenant context to work at all.
     */
    #[Test]
    public function registeringAWorkspaceDoesNotCreateItsSettingsRow(): void
    {
        $tenantId = Uuid::v4()->toRfc4122();

        $this->owner()->executeStatement(
            "INSERT INTO tenant (id, slug, name, status) VALUES (?::uuid, ?, 'Unconfigured', 'active')",
            [$tenantId, 'unconfigured-'.substr($tenantId, 0, 8)],
        );

        try {
            $rows = $this->tenantContext()->run(
                $tenantId,
                fn (): array => $this->app()->fetchFirstColumn('SELECT default_currency_code FROM tenant_settings'),
            );

            self::assertSame([], $rows,
                'A newly registered workspace already has a functional currency, which means something '
                .'invented one. Nothing may: the whole content of E4-3 is that the value is stated.');
        } finally {
            $this->owner()->executeStatement('DELETE FROM tenant WHERE id = ?::uuid', [$tenantId]);
        }
    }
}
