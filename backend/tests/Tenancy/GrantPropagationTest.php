<?php

declare(strict_types=1);

namespace App\Tests\Tenancy;

use App\Tenancy\DatabaseRoles;
use App\Tenancy\SchemaManifest;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Uid\Uuid;

/**
 * R-34, and the policy reversal Output 2 makes.
 *
 * W0-a proved the mechanism A1.1 describes: `GRANT … ON ALL TABLES` is
 * point-in-time and ran before any table existed, and `ALTER DEFAULT
 * PRIVILEGES` is what carries rights to tables a later migration creates. That
 * finding stands. The *policy* it implemented does not: default privileges
 * cannot tell a tenant-scoped table from `messenger_messages`, so every future
 * table arrived pre-granted to erp_app — which is precisely ADR-006 V-10 /
 * ARCHITECTURE S-13, the exposure Condition 2 promotes to equal priority.
 *
 * Version20260906000100 therefore withdraws the default privileges and grants
 * every table explicitly. This class asserts the new property in the only way
 * that is not circular: it creates a table and looks.
 *
 * R-34 does not come back. A migration that forgets a grant now fails
 * GrantFootprintTest loudly instead of producing a runtime 500 in a wave's
 * time — and a missing grant is a far better failure than an inherited one.
 */
final class GrantPropagationTest extends TenancyTestCase
{
    /**
     * The proof that deny-by-default is real, rather than an intention recorded
     * in a migration comment.
     *
     * If any `ALTER DEFAULT PRIVILEGES … GRANT` survives anywhere in the schema,
     * this table picks the privileges up at CREATE time and the test fails.
     */
    #[Test]
    public function aTableCreatedWithNoExplicitGrantIsReachableByNoApplicationRole(): void
    {
        $this->owner()->executeStatement('CREATE TABLE grant_probe (id uuid NOT NULL PRIMARY KEY)');

        try {
            $held = [];
            foreach (DatabaseRoles::APPLICATION_ROLES as $role) {
                foreach (SchemaManifest::TABLE_PRIVILEGES as $priv) {
                    if ((bool) $this->owner()->fetchOne('SELECT has_table_privilege(?, ?, ?)', [$role, 'grant_probe', $priv])) {
                        $held[] = "$role holds $priv";
                    }
                }
            }

            self::assertSame([], $held,
                "A table created with no GRANT of any kind was still reachable:\n  ".implode("\n  ", $held)
                ."\nSome ALTER DEFAULT PRIVILEGES is still in force, which means the next migration to add a "
                .'non-tenant table hands it to erp_app before anyone decides whether it should.');
        } finally {
            $this->owner()->executeStatement('DROP TABLE grant_probe');
        }
    }

    /** And the same for sequences, which R-34's original form also missed. */
    #[Test]
    public function aSequenceCreatedWithNoExplicitGrantIsReachableByNoApplicationRole(): void
    {
        $this->owner()->executeStatement('CREATE SEQUENCE grant_probe_seq');

        try {
            foreach (DatabaseRoles::APPLICATION_ROLES as $role) {
                foreach (['USAGE', 'SELECT', 'UPDATE'] as $priv) {
                    self::assertFalse((bool) $this->owner()->fetchOne(
                        'SELECT has_sequence_privilege(?, ?, ?)', [$role, 'grant_probe_seq', $priv]),
                        "$role holds $priv on a sequence created with no grant.");
                }
            }
        } finally {
            $this->owner()->executeStatement('DROP SEQUENCE grant_probe_seq');
        }
    }

    /**
     * The positive half: the explicit grants actually work, end to end, as the
     * role the application really uses.
     *
     * `audit_log` is the interesting case because its footprint is deliberately
     * asymmetric — erp_app may append and read and may not edit or remove
     * (S-9) — so a single test exercises both the grant and the revoke.
     */
    #[Test]
    public function erpAppCanAppendToAndReadTheAuditLogAndCannotChangeIt(): void
    {
        $id = Uuid::v4()->toRfc4122();

        $count = $this->tenantContext()->run(self::TENANT_A, function () use ($id): int {
            $this->app()->executeStatement(
                // B-4: 'test.event' was a placeholder from before event_type had a
                // domain. This test is about the grant, not the value, but the value
                // now has to be a real one -- audit_log_event_type_values refuses
                // anything else. The UPDATE below still fails on the grant and not on
                // the CHECK: PostgreSQL raises the ACL denial before it evaluates any
                // constraint, which was verified against this database, not assumed.
                "INSERT INTO audit_log (id, tenant_id, event_type, actor_label) VALUES (?::uuid, ?::uuid, 'purchase_order.placed', 'system')",
                [$id, self::TENANT_A],
            );

            return (int) $this->app()->fetchOne('SELECT count(*) FROM audit_log');
        });

        self::assertSame(1, $count);

        foreach (['UPDATE audit_log SET event_type = %s', 'DELETE FROM audit_log'] as $sql) {
            $thrown = null;
            try {
                $this->tenantContext()->run(self::TENANT_A, function () use ($sql): void {
                    $this->app()->executeStatement(sprintf($sql, "'tampered'"));
                });
            } catch (\Throwable $e) {
                $thrown = $e;
            }

            self::assertNotNull($thrown, "erp_app was able to run: $sql");
            self::assertStringContainsString('permission denied', $thrown->getMessage(),
                'The audit log was protected by something other than a grant: '.$thrown->getMessage());
        }
    }

    /**
     * B-1, as behaviour rather than as a catalogue reading.
     *
     * This test previously asserted the opposite. It was called
     * `erpAppCanInsertIntoABigserialTableAndSoHoldsUsageOnItsSequence` and it
     * existed to prove that under deny-by-default the BIGSERIAL enqueue path
     * still worked -- because ADR-002 §7 would otherwise be dead on arrival.
     * That reasoning assumed the queue would be written by `erp_app` directly.
     * ADR-006 V-10 says it must not be: an INSERT into `messenger_messages` is
     * worker code execution by another name, and the grant reached `erp_app`
     * from the blanket `ALTER DEFAULT PRIVILEGES` rather than from a decision.
     *
     * `symfony/messenger` is not installed -- 14 packages in composer.json,
     * nothing in vendor/symfony, no messenger.yaml -- so nothing dispatches and
     * nothing consumes. The grant was dead, which is what made it cheap to
     * close and expensive to defer: deferring means it ships by default the
     * moment Messenger is installed.
     *
     * Whatever replaces it is B-1 action 2 and is deliberately not built here:
     * a `SECURITY DEFINER` dispatch function owned by the schema owner, with
     * `EXECUTE` to `erp_app` and typed parameters rather than a caller-supplied
     * body. Revoking now means Messenger cannot be installed without that
     * design being done, which is the point.
     *
     * `SELECT` stays, and not because it keeps consumption working -- it does
     * not. `DELIVERY_LEDGER.md:113`: the transport dispatches with `INSERT` and
     * consumes with `SELECT … FOR UPDATE`, `UPDATE` and `DELETE`, so revoking
     * the writes already makes `messenger:consume` impossible for `erp_app`.
     * The retained `SELECT` is a **named residual** -- a cross-tenant read of
     * serialized payloads on a table with no RLS -- declared in the manifest so
     * it is pinned rather than drifting, and left to B-1 action 4, which is
     * where the worker's principal is decided. This test asserts it is still
     * there for that reason, not as a claim that consumption survives.
     */
    #[Test]
    public function erpAppCannotEnqueueAndHoldsNoPrivilegeOnTheQueueSequence(): void
    {
        // The anti-vacuous half: the table and its sequence exist and work, so
        // this test cannot pass because the queue was quietly dropped.
        $this->owner()->executeStatement('TRUNCATE messenger_messages RESTART IDENTITY');
        $this->owner()->executeStatement(
            "INSERT INTO messenger_messages (body, headers, queue_name) VALUES ('{}', '{}', 'default')"
        );
        self::assertSame(1, (int) $this->owner()->fetchOne('SELECT id FROM messenger_messages'),
            'The BIGSERIAL default did not fire, so nothing below is about privileges.');

        // The declared residual, asserted as present so it cannot drift away
        // unnoticed -- and so that B-1 action 4 has something concrete to close.
        self::assertSame(1, (int) $this->app()->fetchOne('SELECT count(*) FROM messenger_messages'));

        $thrown = null;
        try {
            $this->app()->executeStatement(
                "INSERT INTO messenger_messages (body, headers, queue_name) VALUES ('{}', '{}', 'default')"
            );
        } catch (\Throwable $e) {
            $thrown = $e;
        }

        self::assertNotNull($thrown, 'erp_app enqueued a message, which is worker code execution by another name.');
        self::assertStringContainsString('permission denied', $thrown->getMessage(),
            'The queue was protected by something other than a grant: '.$thrown->getMessage());

        // And the sequence goes with the INSERT. A sequence grant without table
        // INSERT is useless but not harmless -- it is a live privilege on a
        // dead path, and it is exactly what a reintroduced INSERT would ride on
        // without anyone noticing the sequence had never been reviewed.
        foreach (['USAGE', 'SELECT', 'UPDATE'] as $priv) {
            self::assertFalse((bool) $this->owner()->fetchOne(
                'SELECT has_sequence_privilege(?, ?, ?)', [DatabaseRoles::APP, 'messenger_messages_id_seq', $priv]),
                "erp_app still holds $priv on messenger_messages_id_seq.");
        }

        $this->owner()->executeStatement('TRUNCATE messenger_messages RESTART IDENTITY');
    }

    /**
     * ADR-002 §7 propagates tenancy through a TenantIdStamp, not a column, and
     * ARCHITECTURE S-8 corrects that to say M-6 will add a real `tenant_id`
     * column so mismatches are loud and the rows are queryable -- while the
     * queue stays outside RLS, because a worker must select the next message
     * before it knows the tenant.
     *
     * Those two facts pull in opposite directions, and W0-a asserted the queue
     * has no tenant_id, which would fail the moment M-6 lands. The property that
     * actually matters is neither: it is that the exemption is **declared**.
     * A table that carries a tenant_id and is not RLS'd has to be a decision
     * someone wrote down, and SchemaManifestTest asserts the manifest and the
     * catalogue agree in both directions.
     */
    #[Test]
    public function theQueueIsExemptFromRowLevelSecurityByDeclarationRatherThanByAccident(): void
    {
        self::assertFalse(SchemaManifest::TABLES['messenger_messages']['tenant'],
            'messenger_messages must be declared non-tenant-scoped, with S-8\'s reason attached.');

        self::assertFalse((bool) $this->owner()->fetchOne(<<<'SQL'
            SELECT c.relrowsecurity FROM pg_class c JOIN pg_namespace n ON n.oid = c.relnamespace
            WHERE n.nspname = 'public' AND c.relname = 'messenger_messages'
            SQL));

        // And the consequence that makes S-8's M-2 rule load-bearing rather than
        // stylistic: with no policy, the only thing keeping another tenant's
        // identifiers out of a payload is the code rule.
        self::assertSame([], $this->owner()->fetchFirstColumn(<<<'SQL'
            SELECT polname FROM pg_policy p JOIN pg_class c ON c.oid = p.polrelid
            WHERE c.relname = 'messenger_messages'
            SQL));
    }
}
