<?php

declare(strict_types=1);

namespace App\Tests\Tenancy;

use App\Tenancy\TenantContext;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Tools\DsnParser;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

/**
 * Shared rig for the W0-a tenancy slice.
 *
 * Two connections, and which one a test uses is the point of the test:
 *
 *   app()   erp_app through PgBouncer. RLS-bound, no BYPASSRLS. Every isolation
 *           assertion runs here. ADR-002 "Verification" #1: a suite that connects
 *           as the owner is green and meaningless.
 *   owner() the schema owner, direct and unpooled. Used only to introspect the
 *           catalogue and to TRUNCATE between tests -- never to assert isolation.
 */
abstract class TenancyTestCase extends KernelTestCase
{
    protected const TENANT_A = '11111111-1111-4111-8111-111111111111';
    protected const TENANT_B = '22222222-2222-4222-8222-222222222222';

    protected function setUp(): void
    {
        self::bootKernel();
        $this->clearLeakedTenantContext();
        $this->truncateTenantTables();
    }

    /**
     * Not hygiene -- evidence.
     *
     * TenantContextTest deliberately performs the forbidden bare SET, and with
     * default_pool_size=1 that value survives on the shared backend into every
     * later test in the run. Without this reset the suite fails in ways that
     * look like unrelated bugs. That is the failure mode ADR-002 §C-3 describes,
     * reproduced here as a side effect: production has no equivalent hook, which
     * is the whole reason bare SET is forbidden.
     */
    protected function clearLeakedTenantContext(): void
    {
        $this->app()->executeStatement('RESET app.tenant_id');
    }

    protected function app(): Connection
    {
        return self::getContainer()->get('doctrine.dbal.app_connection');
    }

    protected function owner(): Connection
    {
        return self::getContainer()->get('doctrine.dbal.owner_connection');
    }

    protected function em(): EntityManagerInterface
    {
        return self::getContainer()->get('doctrine.orm.default_entity_manager');
    }

    protected function tenantContext(): TenantContext
    {
        return self::getContainer()->get(TenantContext::class);
    }

    /**
     * A second, independent client connection to the same pooled endpoint.
     * With default_pool_size=1 this is guaranteed to land on the same Postgres
     * backend as app(), which is what makes the leak test meaningful.
     */
    protected function secondClient(): Connection
    {
        $params = (new DsnParser(['postgresql' => 'pdo_pgsql', 'postgres' => 'pdo_pgsql']))
            ->parse($_ENV['APP_DATABASE_URL']);

        return DriverManager::getConnection($params);
    }

    /**
     * A connection to a Postgres backend that has never had app.tenant_id set.
     *
     * This is not a convenience. `RESET app.tenant_id` leaves a custom setting at
     * the empty string, not unset, so on the pooled endpoint -- where every test
     * shares one backend -- current_setting() never returns NULL again once any
     * test has set it. NULL and '' take different paths through the policy, and
     * only the unpooled route can exercise the first one.
     */
    protected function freshUnpooledClient(): Connection
    {
        $params = (new DsnParser(['postgresql' => 'pdo_pgsql', 'postgres' => 'pdo_pgsql']))
            ->parse($_ENV['APP_DIRECT_DATABASE_URL']);

        return DriverManager::getConnection($params);
    }

    /**
     * Ground truth, read from outside the assertion under test.
     *
     * FORCE ROW LEVEL SECURITY binds the owner too, so the owner cannot be used
     * as an unfiltered oracle -- it has to declare a tenant like anyone else.
     * That is the point of §C-2, and it means "did the row really land?" has to
     * be asked once per tenant.
     */
    protected function countAsOwnerForTenant(string $tenantId, string $sql, array $params = []): int
    {
        $c = $this->owner();
        $c->beginTransaction();
        try {
            $c->executeStatement("SELECT set_config('app.tenant_id', ?, true)", [$tenantId]);
            $n = (int) $c->fetchOne($sql, $params);
            $c->commit();

            return $n;
        } catch (\Throwable $e) {
            $c->rollBack();
            throw $e;
        }
    }

    /**
     * TRUNCATE, not DELETE. As the owner under FORCE ROW LEVEL SECURITY a DELETE
     * would itself be filtered by the policy and quietly remove nothing.
     */
    protected function truncateTenantTables(): void
    {
        $this->owner()->executeStatement('TRUNCATE inventory_item, supplier, stock_movement RESTART IDENTITY CASCADE');
    }

    /** @return string the new row's id */
    protected function seedSupplier(string $tenantId, string $name): string
    {
        $id = Uuid::v4()->toRfc4122();
        $this->tenantContext()->run($tenantId, function () use ($id, $tenantId, $name): void {
            $this->app()->executeStatement(
                'INSERT INTO supplier (id, tenant_id, name) VALUES (?::uuid, ?::uuid, ?)',
                [$id, $tenantId, $name],
            );
        });

        return $id;
    }

    /** @return string the new row's id */
    protected function seedItem(string $tenantId, string $sku, ?string $supplierId = null): string
    {
        $id = Uuid::v4()->toRfc4122();
        $this->tenantContext()->run($tenantId, function () use ($id, $tenantId, $sku, $supplierId): void {
            $this->app()->executeStatement(
                'INSERT INTO inventory_item (id, tenant_id, sku, supplier_id) VALUES (?::uuid, ?::uuid, ?, ?::uuid)',
                [$id, $tenantId, $sku, $supplierId],
            );
        });

        return $id;
    }
}
