<?php

declare(strict_types=1);

namespace App\Tests\Tenancy;

use App\Entity\InventoryItem;
use App\Tenancy\TenantContextViolation;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Uid\Uuid;

/**
 * Weak spot 5 from the W0-a report, chased down.
 *
 * The ORM identifier is scalar `id`, so Doctrine's identity map is keyed on the
 * id alone with no notion of tenant. That is only dangerous when two tenants are
 * live against one EntityManager, and there were exactly two ways to get there:
 *
 *   inside one transaction, through a nested run() for another tenant
 *       -- now refused outright (TenantContextNestingTest)
 *   across two sequential top-level run() calls in one request
 *       -- covered here
 *
 * The second is the nastier of the two, because a find() that hits the identity
 * map never reaches the database, so RLS is never asked the question. No UUID
 * collision is required: the same id is simply still in the map.
 */
final class TenantIdentityMapTest extends TenancyTestCase
{
    #[Test]
    public function anEntityLoadedForOneTenantIsNotServedFromTheIdentityMapToTheNext(): void
    {
        $id = $this->tenantContext()->run(self::TENANT_A, function (): string {
            $item = $this->newItem(self::TENANT_A, 'SKU-A');
            $this->em()->persist($item);
            $this->em()->flush();

            return $item->getId()->toRfc4122();
        });

        // Same EntityManager, no explicit clear() by the caller, different tenant.
        $seenByB = $this->tenantContext()->run(self::TENANT_B, fn (): ?InventoryItem =>
            $this->em()->find(InventoryItem::class, Uuid::fromString($id)));

        self::assertNull($seenByB,
            'Tenant B was handed tenant A\'s entity out of the identity map. The query never reached '
            .'Postgres, so RLS never saw it.');
    }

    #[Test]
    public function entitiesFromThePreviousTenantAreDetachedBeforeTheNextUnitOfWork(): void
    {
        $item = $this->tenantContext()->run(self::TENANT_A, function (): InventoryItem {
            $item = $this->newItem(self::TENANT_A, 'SKU-A');
            $this->em()->persist($item);
            $this->em()->flush();

            return $item;
        });

        self::assertTrue($this->em()->contains($item), 'Still tenant A\'s unit of work; the entity should be managed.');

        $stillManaged = $this->tenantContext()->run(self::TENANT_B, fn (): bool => $this->em()->contains($item));

        self::assertFalse($stillManaged,
            'A managed entity belonging to tenant A survived into tenant B\'s unit of work. A flush() there '
            .'would attempt to write A\'s rows under B\'s context.');
    }

    /** Consecutive units of work for the SAME tenant must not pay the clear(). */
    #[Test]
    public function consecutiveUnitsOfWorkForTheSameTenantKeepTheirManagedEntities(): void
    {
        $item = $this->tenantContext()->run(self::TENANT_A, function (): InventoryItem {
            $item = $this->newItem(self::TENANT_A, 'SKU-A');
            $this->em()->persist($item);
            $this->em()->flush();

            return $item;
        });

        $stillManaged = $this->tenantContext()->run(self::TENANT_A, fn (): bool => $this->em()->contains($item));

        self::assertTrue($stillManaged, 'The identity map was cleared for a unit of work in the same tenant.');
    }

    /**
     * The other route, restated where it belongs: two tenants can no longer be
     * live against one EntityManager inside a single transaction at all.
     */
    #[Test]
    public function twoTenantsCannotBothBeLiveAgainstOneEntityManagerInOneTransaction(): void
    {
        $this->seedItem(self::TENANT_B, 'SKU-B');

        $this->expectException(TenantContextViolation::class);

        $this->tenantContext()->run(self::TENANT_A, function (): void {
            $this->tenantContext()->run(self::TENANT_B, fn (): array =>
                $this->em()->getRepository(InventoryItem::class)->findAll());
        });
    }
}
