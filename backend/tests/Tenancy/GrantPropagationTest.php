<?php

declare(strict_types=1);

namespace App\Tests\Tenancy;

use PHPUnit\Framework\Attributes\Test;

/**
 * Extension from RED via LEAD, tests 1 and 2 -- R-34.
 *
 * ADR-002 §1 grants with `GRANT ... ON ALL TABLES IN SCHEMA public`. That is
 * point-in-time. It runs when the roles are created, which is before any table
 * exists, so it grants nothing; and every later migration adds a table neither
 * application role can touch.
 *
 * `stock_movement` and `messenger_messages` are created by Version20260905000200,
 * which contains no GRANT of its own. If these tests pass, ALTER DEFAULT
 * PRIVILEGES is what carried the rights across, and the ordering defect is closed.
 */
final class GrantPropagationTest extends TenancyTestCase
{
    #[Test]
    public function aTableCreatedByALaterMigrationIsReadableByErpAppWithoutAnyExplicitGrant(): void
    {
        // Deliberately NOT information_schema.table_privileges. That view resolves
        // neither PUBLIC grants nor role inheritance: after
        // GRANT INSERT ON stock_movement TO PUBLIC it returned zero rows naming
        // erp_ai while erp_ai could in fact insert. has_table_privilege resolves
        // both. (SEC, verified on this PG17 container.)
        foreach (['TRUNCATE', 'REFERENCES', 'TRIGGER'] as $priv) {
            self::assertFalse((bool) $this->owner()->fetchOne(
                'SELECT has_table_privilege(?, ?, ?)', ['erp_app', 'stock_movement', $priv]),
                "erp_app unexpectedly holds $priv on stock_movement.");
        }

        foreach (['SELECT', 'INSERT', 'UPDATE', 'DELETE'] as $priv) {
            self::assertTrue((bool) $this->owner()->fetchOne(
                'SELECT has_table_privilege(?, ?, ?)', ['erp_app', 'stock_movement', $priv]),
                "erp_app lost $priv on a table added after the grant block (R-34).");
        }

        self::assertTrue((bool) $this->owner()->fetchOne(
            'SELECT has_table_privilege(?, ?, ?)', ['erp_ai', 'stock_movement', 'SELECT']));
        self::assertFalse((bool) $this->owner()->fetchOne(
            'SELECT has_table_privilege(?, ?, ?)', ['erp_ai', 'stock_movement', 'INSERT']));
    }

    #[Test]
    public function erpAppCanActuallyReadAndWriteThatLaterTable(): void
    {
        $written = $this->tenantContext()->run(self::TENANT_A, function (): int {
            $this->app()->executeStatement(
                'INSERT INTO stock_movement (id, tenant_id, quantity) VALUES (gen_random_uuid(), ?::uuid, ?)',
                [self::TENANT_A, 7],
            );

            return (int) $this->app()->fetchOne('SELECT sum(quantity) FROM stock_movement');
        });

        self::assertSame(7, $written);
    }

    /**
     * Sequence grants. `messenger_messages` uses a BIGSERIAL id, so without
     * USAGE on its sequence erp_app cannot enqueue anything at all -- the whole
     * of ADR-002 §7 would be dead on arrival.
     */
    #[Test]
    public function erpAppCanInsertIntoABigserialTableAndSoHoldsUsageOnItsSequence(): void
    {
        $this->owner()->executeStatement('TRUNCATE messenger_messages RESTART IDENTITY');

        $this->app()->executeStatement(
            "INSERT INTO messenger_messages (body, headers, queue_name) VALUES ('{}', '{}', 'default')"
        );

        self::assertSame(1, (int) $this->app()->fetchOne('SELECT count(*) FROM messenger_messages'));
        self::assertTrue((bool) $this->owner()->fetchOne(
            'SELECT has_sequence_privilege(?, ?, ?)', ['erp_app', 'messenger_messages_id_seq', 'USAGE']));
    }

    #[Test]
    public function messengerMessagesIsNotTenantScopedAndSoIsNotSubjectToTheDriftCheck(): void
    {
        // ADR-002 §7 propagates tenancy through a TenantIdStamp, not a column.
        // Recording that here so a future migration cannot quietly add tenant_id
        // to the queue table and inherit an unenforced policy.
        self::assertFalse((bool) $this->owner()->fetchOne(<<<'SQL'
            SELECT EXISTS (SELECT 1 FROM information_schema.columns
                           WHERE table_name = 'messenger_messages' AND column_name = 'tenant_id')
            SQL));
    }
}
