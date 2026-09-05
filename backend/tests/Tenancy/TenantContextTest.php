<?php

declare(strict_types=1);

namespace App\Tests\Tenancy;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\Test;

/**
 * R-28 / R-14 -- the question this whole slice exists to answer.
 *
 * ADR-002 §3 says tenant context must be established with
 * set_config('app.tenant_id', ?, true) inside an explicit transaction.
 * ADR-002 §C-3 says the alternatives fail: SET LOCAL is lost under autocommit,
 * and a bare SET persists on the pooled backend and leaks into the next
 * tenant's request.
 *
 * The stack these tests run against is PgBouncer in pool_mode=transaction with
 * default_pool_size=1 -- the same mode Neon runs, with the pool shrunk to one so
 * that every client is forced onto a single shared backend.
 */
final class TenantContextTest extends TenancyTestCase
{
    #[Test]
    public function contextIsVisibleInsideItsOwnTransaction(): void
    {
        $seen = $this->tenantContext()->run(self::TENANT_A, fn (): ?string =>
            $this->app()->fetchOne("SELECT current_setting('app.tenant_id', true)") ?: null);

        self::assertSame(self::TENANT_A, $seen);
    }

    /**
     * The core R-28 assertion. Not "the value is gone" in the abstract -- gone
     * *on a backend we have proven is the same one*, which is the only version
     * of the claim that means anything under transaction pooling.
     */
    #[Test]
    public function contextIsGoneAfterCommitOnTheSamePooledBackend(): void
    {
        $firstPid = $this->tenantContext()->run(self::TENANT_A, fn (): int =>
            (int) $this->app()->fetchOne('SELECT pg_backend_pid()'));

        $second = $this->app()->fetchAssociative(
            "SELECT pg_backend_pid() AS pid, current_setting('app.tenant_id', true) AS tenant"
        );

        self::assertSame($firstPid, (int) $second['pid'], 'Pool of one should have handed back the same backend; if not, this test proves nothing.');
        self::assertContains($second['tenant'], [null, ''], 'Tenant context survived COMMIT on a shared backend -- ADR-002 §3 is void.');
    }

    #[Test]
    public function contextDoesNotSurviveIntoAnotherClientsTransaction(): void
    {
        $pidA = $this->tenantContext()->run(self::TENANT_A, fn (): int =>
            (int) $this->app()->fetchOne('SELECT pg_backend_pid()'));

        $other = $this->secondClient();
        $other->beginTransaction();
        $pidB = (int) $other->fetchOne('SELECT pg_backend_pid()');
        $leaked = $other->fetchOne("SELECT current_setting('app.tenant_id', true)");
        $other->commit();
        $other->close();

        self::assertSame($pidA, $pidB, 'Both clients must share one backend or the assertion below is vacuous.');
        self::assertContains($leaked, [null, ''], 'Tenant A context reached a second client -- cross-tenant leak.');
    }

    /**
     * The control, and the most important test in the slice.
     *
     * A test suite that cannot exhibit the failure mode it claims to prevent is
     * fake-green (R-3). This runs the *forbidden* form -- bare SET, no
     * transaction -- and asserts the leak actually happens. If this test ever
     * starts failing, the harness has stopped being able to detect the bug and
     * every other assertion here becomes untrustworthy.
     */
    #[Test]
    public function bareSetLeaksIntoTheNextClientOnTheSamePooledBackend(): void
    {
        $writer = $this->secondClient();
        $writer->executeStatement("SET app.tenant_id = '".self::TENANT_A."'");
        $pidWriter = (int) $writer->fetchOne('SELECT pg_backend_pid()');
        $writer->close();

        $reader = $this->secondClient();
        $pidReader = (int) $reader->fetchOne('SELECT pg_backend_pid()');
        $leaked = $reader->fetchOne("SELECT current_setting('app.tenant_id', true)");
        $reader->close();

        self::assertSame($pidWriter, $pidReader, 'Pool of one should reuse the backend.');
        self::assertSame(
            self::TENANT_A,
            $leaked,
            'Bare SET did NOT leak. Either PgBouncer is resetting between transactions or the pool is not shared -- '
            .'in which case this suite cannot demonstrate the failure it is supposed to prevent.'
        );
    }

    /**
     * The other half of ADR-002 §C-3: SET LOCAL is transaction-scoped, so under
     * autocommit it commits as its own transaction and the value is gone before
     * the real query runs.
     */
    #[Test]
    public function setLocalUnderAutocommitIsLostBeforeTheNextStatement(): void
    {
        $c = $this->secondClient();
        $c->executeStatement("SET LOCAL app.tenant_id = '".self::TENANT_A."'");
        $seen = $c->fetchOne("SELECT current_setting('app.tenant_id', true)");
        $c->close();

        self::assertContains($seen, [null, ''], 'SET LOCAL unexpectedly survived autocommit.');
    }

    #[Test]
    public function tenantIdMustBeAUuid(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->tenantContext()->run("not-a-uuid'; DROP TABLE supplier; --", fn (): null => null);
    }

    /**
     * ADR-002 "Open items": the tenant_id-is-UUID assumption and the ::uuid casts
     * in the policies. A parameter bound as text must survive the cast.
     */
    #[Test]
    public function contextRoundTripsThroughTheUuidCastUsedByThePolicies(): void
    {
        $cast = $this->tenantContext()->run(self::TENANT_A, fn (): string =>
            (string) $this->app()->fetchOne("SELECT current_setting('app.tenant_id', true)::uuid"));

        self::assertSame(self::TENANT_A, $cast);
    }

    #[Test]
    public function connectionIsErpAppAndNotTheOwner(): void
    {
        self::assertSame('erp_app', $this->app()->fetchOne('SELECT current_user'));
    }
}
