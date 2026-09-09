<?php

declare(strict_types=1);

namespace App\Tests\Tenancy;

use App\Tenancy\TenantContextViolation;
use PHPUnit\Framework\Attributes\Test;

/**
 * `TenantContext::establishOn()` — the second sanctioned entry point, added for
 * `API.md` §7.1 steps 4 and 8, which establish tenant context on connections
 * that have no entity manager.
 *
 * It exists so that `TenantContextContainmentTest`'s rule — *"exactly one
 * production file may write tenant context"*, with **no allow-list** — stays
 * true with a pre-authentication path in the tree. These tests are what stop it
 * being a hole in that rule rather than an entry point to it.
 */
final class PreAuthTenantContextTest extends TenancyTestCase
{
    /**
     * The guard that matters, and the failure it prevents is silent.
     *
     * `set_config(..., true)` is transaction-local. Called with no transaction
     * open it binds to the implicit single-statement one and is discarded before
     * the next statement — every RLS-filtered read that followed would return
     * zero rows, and under PgBouncer's `pool_mode=transaction` the connection
     * would go back to the pool in between.
     *
     * *Mutation, run:* delete the nesting-level check from
     * `TenantContext::establishOn()`; no exception is raised and this is red.
     */
    #[Test]
    public function establishingContextOutsideATransactionIsRefused(): void
    {
        $this->expectException(TenantContextViolation::class);

        $this->tenantContext()->establishOn($this->authConnection(), self::TENANT_A);
    }

    /**
     * The positive half, so the refusal above is not the only thing proven — and
     * on the pre-authentication principal, because that is the connection this
     * method exists for and its grants are the narrowest in the schema.
     *
     * *Mutation, run:* interpolate the tenant id into the SQL instead of binding
     * it; still green, which is why the assertion below is about the value and
     * the containment test is about the form. *Second mutation, run:* pass
     * `false` as `set_config`'s third argument; the setting survives the COMMIT
     * and the post-commit assertion is red.
     */
    #[Test]
    public function theContextIsEstablishedForTheTransactionAndDiesWithIt(): void
    {
        $connection = $this->authConnection();
        $connection->beginTransaction();

        try {
            $this->tenantContext()->establishOn($connection, self::TENANT_A);

            self::assertSame(self::TENANT_A, $connection->fetchOne("SELECT current_setting('app.tenant_id', true)"));

            $connection->commit();
        } catch (\Throwable $e) {
            $connection->rollBack();

            throw $e;
        }

        // After the COMMIT the setting is gone. `RESET ALL` inside PgBouncer's
        // `DISCARD ALL` leaves a custom GUC at the empty string rather than
        // unset, so both answers are accepted -- the assertion is that it is no
        // longer the tenant.
        self::assertNotSame(self::TENANT_A, (string) $connection->fetchOne("SELECT coalesce(current_setting('app.tenant_id', true), '')"));
    }

    /**
     * A non-UUID is refused before any statement is issued, exactly as `run()`
     * refuses one — the error lands at the call site rather than inside the
     * `::uuid` cast in the RLS policy.
     *
     * *Mutation, run:* delete the `Uuid::isValid()` check; the statement is
     * issued and the failure moves to the next query, as `22P02`. Red.
     */
    #[Test]
    public function aTenantIdThatIsNotAUuidIsRefused(): void
    {
        $connection = $this->authConnection();
        $connection->beginTransaction();

        try {
            $this->expectException(\InvalidArgumentException::class);
            $this->tenantContext()->establishOn($connection, 'not-a-uuid');
        } finally {
            $connection->rollBack();
        }
    }

    private function authConnection(): \Doctrine\DBAL\Connection
    {
        return self::getContainer()->get('doctrine.dbal.auth_connection');
    }
}
