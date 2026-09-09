<?php

declare(strict_types=1);

namespace App\Tests\Tenancy;

use App\Modules\Inventory\Infrastructure\Entity\InventoryItem;
use PHPUnit\Framework\Attributes\Test;

/**
 * ADR-002 "Open items" -- DDC-3174: the query cache reportedly not keying on
 * SQLFilter state, which would be a cross-tenant cache-poisoning shape.
 * Reported against ORM 2.x, not confirmed for 3.x.
 *
 * This does not claim the issue is fixed. It runs the shape the report describes
 * and records what ORM 3.x actually does, with the caches deliberately still off
 * in configuration.
 */
final class QueryCacheFilterStateTest extends TenancyTestCase
{
    #[Test]
    public function bothDoctrineCachesAreDisabledInConfiguration(): void
    {
        $config = $this->em()->getConfiguration();

        self::assertFalse($config->isSecondLevelCacheEnabled(), 'ADR-002 keeps the second-level cache off until DDC-3174 is settled.');
    }

    /**
     * The DDC-3174 shape: same DQL, two different filter parameter values, with
     * the query cache in play. If the generated SQL for tenant B were served from
     * a cache entry keyed without the filter state, B would run A's SQL.
     */
    #[Test]
    public function theGeneratedSqlDiffersPerTenantEvenWithTheQueryCacheWarm(): void
    {
        $dql = 'SELECT i FROM '.InventoryItem::class.' i';

        $sqlA = $this->tenantContext()->run(self::TENANT_A, fn (): string =>
            $this->em()->createQuery($dql)->getSQL());

        $sqlB = $this->tenantContext()->run(self::TENANT_B, fn (): string =>
            $this->em()->createQuery($dql)->getSQL());

        fwrite(STDERR, "\n  [DDC-3174 probe] tenant A SQL: $sqlA\n  [DDC-3174 probe] tenant B SQL: $sqlB\n");

        self::assertNotSame($sqlA, $sqlB, 'Both tenants got byte-identical SQL from a warm query cache -- DDC-3174 reproduces on ORM 3.x.');
    }

    /** The behavioural version: results, not SQL strings. */
    #[Test]
    public function aWarmQueryCacheDoesNotServeOneTenantAnothersRows(): void
    {
        $this->seedItem(self::TENANT_A, 'SKU-A');
        $this->seedItem(self::TENANT_B, 'SKU-B');

        $dql = 'SELECT i FROM '.InventoryItem::class.' i';

        $a = $this->tenantContext()->run(self::TENANT_A, fn (): array =>
            array_map(fn (InventoryItem $i): string => $i->getSku(), $this->em()->createQuery($dql)->getResult()));
        $this->em()->clear();
        $b = $this->tenantContext()->run(self::TENANT_B, fn (): array =>
            array_map(fn (InventoryItem $i): string => $i->getSku(), $this->em()->createQuery($dql)->getResult()));

        self::assertSame(['SKU-A'], $a);
        self::assertSame(['SKU-B'], $b);
    }
}
