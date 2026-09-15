<?php

declare(strict_types=1);

namespace App\Tests\Tenancy;

use App\Modules\Inventory\Infrastructure\Entity\InventoryItem;
use App\Platform\Tenancy\SchemaManifest;
use App\Platform\Tenancy\TenantContext;
use App\SharedKernel\Reference\Currency;
use App\SharedKernel\Reference\UnitOfMeasure;
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
        $this->seedTenantRegistry();
    }

    /**
     * Every tenant-scoped table now carries FOREIGN KEY (tenant_id) REFERENCES
     * tenant (id), so a fixture that invents a tenant id no longer works. That
     * is the point of the key -- R-33 in the register is the same problem from
     * the other side -- but it means the registry has to exist before anything
     * else is written.
     *
     * Seeded as the owner: `tenant` is not RLS'd, and erp_app holds SELECT on it
     * and nothing more.
     */
    protected function seedTenantRegistry(): void
    {
        $this->owner()->executeStatement(<<<'SQL'
            INSERT INTO tenant (id, slug, name, status) VALUES
                (?::uuid, 'tenant-a', 'Tenant A', 'active'),
                (?::uuid, 'tenant-b', 'Tenant B', 'active')
            ON CONFLICT (id) DO NOTHING
            SQL, [self::TENANT_A, self::TENANT_B]);

        // `tenant_settings` is tenant-scoped, so the manifest-derived truncate
        // above empties it every setUp, and it has to be re-seeded here for the
        // same reason the registry does. It is not optional fixture furniture:
        // `default_currency_code` has no database default and nothing in the
        // application substitutes one (ledger E4-3), so a workspace without a
        // settings row cannot create an inventory item at all.
        //
        // Both tenants get `EUR`, which is the value the deployment was
        // actually running under the container parameter this table replaced.
        // A test that is *about* the setting says so by seeding a different
        // one -- and it has to, because two workspaces on `EUR` is exactly the
        // fixture a reverted `ItemWriter` would still satisfy.
        $this->seedTenantSettings(self::TENANT_A);
        $this->seedTenantSettings(self::TENANT_B);
    }

    /** Replaces the workspace's settings row, so a test may re-state the currency. */
    protected function seedTenantSettings(string $tenantId, string $currencyCode = 'EUR'): void
    {
        $this->insertAsOwnerForTenant($tenantId, <<<'SQL'
            INSERT INTO tenant_settings (id, tenant_id, default_currency_code)
            VALUES (?::uuid, ?::uuid, ?)
            ON CONFLICT (tenant_id) DO UPDATE SET default_currency_code = EXCLUDED.default_currency_code
            SQL, [Uuid::v4()->toRfc4122(), $tenantId, $currencyCode]);
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
     * The write-side twin of countAsOwnerForTenant, and it exists for the same
     * reason: FORCE ROW LEVEL SECURITY binds the owner, so a seeding path has to
     * declare a tenant like anyone else (ADR-006 §6.7).
     *
     * Here rather than in the test that needs it, so that
     * TenantContextContainmentTest's list of files touching the tenant GUC stays
     * short -- that list is a control, and every entry added to it is a place
     * the sanctioned API was bypassed.
     */
    protected function insertAsOwnerForTenant(string $tenantId, string $sql, array $params = []): void
    {
        $this->executeAsOwnerForTenant($tenantId, $sql, $params);
    }

    /**
     * Any one fixture write -- INSERT or UPDATE -- as the owner in a workspace's
     * context, committed. `insertAsOwnerForTenant` is this under its older name.
     *
     * ADR-002 Amendment 4 (V4 batch B7, 2026-09-16): `erp_app` holds only the
     * writes its `src/` callers issue, so a fixture writing a table no production
     * path writes (`supplier`, `warehouse`, `customer`, `app_user` …) cannot run
     * as `erp_app` and runs here. Locally the owner is NOBYPASSRLS and FORCE is on
     * every tenant table, so these rows still pass `WITH CHECK` and the composite
     * foreign keys. On Neon the owner is BYPASSRLS and would skip `WITH CHECK`; the
     * suite does not run there (G-3).
     */
    protected function executeAsOwnerForTenant(string $tenantId, string $sql, array $params = []): void
    {
        $c = $this->owner();
        $c->beginTransaction();
        try {
            $c->executeStatement("SELECT set_config('app.tenant_id', ?, true)", [$tenantId]);
            $c->executeStatement($sql, $params);
            $c->commit();
        } catch (\Throwable $e) {
            $c->rollBack();
            throw $e;
        }
    }

    /**
     * One statement as the owner in a workspace's context, **always rolled back**,
     * answering `accepted` or the SQLSTATE it raised.
     *
     * For constraint proofs (a unique, a CHECK) whose subject is the constraint
     * rather than the principal: constraints bind every role, so the owner proves
     * them as well as `erp_app` would, and it still can after ADR-002 Amendment 4
     * leaves `erp_app` without the write (V4 batch B7). Locally the owner is
     * bound by FORCE ROW LEVEL SECURITY, so the context is real.
     *
     * @param list<mixed> $params
     */
    protected function attemptAsOwnerForTenant(string $tenantId, string $sql, array $params = []): string
    {
        $c = $this->owner();
        $c->beginTransaction();
        try {
            $c->executeStatement("SELECT set_config('app.tenant_id', ?, true)", [$tenantId]);
            $c->executeStatement($sql, $params);

            return 'accepted';
        } catch (\Doctrine\DBAL\Exception\DriverException $e) {
            return $e->getSQLState() ?? $e->getMessage();
        } finally {
            $c->rollBack();
        }
    }

    /**
     * The read-many twin of `countAsOwnerForTenant`, and it is here for the
     * reason `insertAsOwnerForTenant` is: `TenantContextContainmentTest`'s list
     * of files touching the tenant GUC is a control, and every entry added to it
     * is a place the sanctioned API was bypassed. Output 4 §7 needs to read the
     * trail and the effective permission set back as ground truth -- `audit_log`
     * is written by a principal that cannot read it, and `role_permission` is
     * RLS'd -- and doing that in each test file would have added two entries.
     *
     * @param list<mixed> $params
     *
     * @return list<array<string, mixed>>
     */
    protected function fetchAsOwnerForTenant(string $tenantId, string $sql, array $params = []): array
    {
        $c = $this->owner();
        $c->beginTransaction();
        try {
            $c->executeStatement("SELECT set_config('app.tenant_id', ?, true)", [$tenantId]);
            $rows = $c->fetchAllAssociative($sql, $params);
            $c->commit();

            return $rows;
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
        // Derived from the manifest rather than listed: a new tenant-scoped
        // table that is not truncated leaves rows behind, and the failure shows
        // up two tests later as an unrelated uniqueness violation.
        $tables = implode(', ', SchemaManifest::tenantScopedTables());
        $this->owner()->executeStatement("TRUNCATE $tables RESTART IDENTITY CASCADE");
    }

    /**
     * Entity factory, so that a change to a constructor is one edit rather
     * than twenty. It must be called inside a tenant context: the reference rows
     * it looks up are non-tenant tables, but the entity it builds is not.
     *
     * There is no `newSupplier` any more (removed in V4 batch B7): under ADR-002
     * Amendment 4 `erp_app`, the entity manager's connection, writes only what a
     * `src/` caller writes, and nothing in `src/` writes `supplier`. Seed one
     * with `seedSupplier()` and load it with `em()->find()`.
     */
    protected function newItem(string $tenantId, string $sku, ?string $name = null): InventoryItem
    {
        return new InventoryItem(
            Uuid::v4(),
            Uuid::fromString($tenantId),
            $sku,
            $name ?? $sku,
            $this->em()->find(UnitOfMeasure::class, 'EA'),
            $this->em()->find(Currency::class, 'EUR'),
        );
    }

    /**
     * As the owner, not `erp_app`: no `src/` path writes `supplier`, so after
     * ADR-002 Amendment 4 `erp_app` holds SELECT on it and nothing else.
     *
     * @return string the new row's id
     */
    protected function seedSupplier(string $tenantId, string $name): string
    {
        $id = Uuid::v4()->toRfc4122();
        // `code` and `currency_code` are NOT NULL with no usable default:
        // a supplier without a currency cannot be priced, and Output 2
        // declines to invent one.
        $this->insertAsOwnerForTenant(
            $tenantId,
            "INSERT INTO supplier (id, tenant_id, name, code, currency_code) VALUES (?::uuid, ?::uuid, ?, ?, 'EUR')",
            [$id, $tenantId, $name, strtoupper(substr(md5($name), 0, 8))],
        );

        return $id;
    }

    /** @return string the new row's id */
    protected function seedItem(string $tenantId, string $sku, ?string $supplierId = null): string
    {
        $id = Uuid::v4()->toRfc4122();
        $this->tenantContext()->run($tenantId, function () use ($id, $tenantId, $sku, $supplierId): void {
            $this->app()->executeStatement(
                "INSERT INTO inventory_item (id, tenant_id, sku, name, unit_of_measure_code, currency_code, supplier_id)
                 VALUES (?::uuid, ?::uuid, ?, ?, 'EA', 'EUR', ?::uuid)",
                [$id, $tenantId, $sku, $sku, $supplierId],
            );
        });

        return $id;
    }
}
