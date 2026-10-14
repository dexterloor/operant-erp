<?php

declare(strict_types=1);

namespace App\Tests\Tenancy;

use App\Modules\Purchasing\Application\SupplierOpenPurchaseOrders;
use App\Modules\Sales\Application\CustomerOpenSalesWork;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Uid\Uuid;

/**
 * **The two party ports refuse to answer outside a tenancy transaction** (`docs/session13/owed-arch-c5.md` follow-up B,
 * condition B, and RED L-5).
 *
 * `SupplierOpenWork` and `CustomerOpenWork` are what stop `DeleteSupplier` / `DeleteCustomer` archiving a party with open
 * work, and their answer FAILS OPEN: outside `TenantContext::run()`, or inside a transaction that set no tenant, RLS's
 * predicate is NULL, every row is hidden, the count is 0, and the archive goes through. Both shipped callers are correct
 * (they run inside `tenants->run`); these tests make the "call it inside your transaction" sentence a refusal. Each port is
 * driven three ways against the real database, as `erp_app`:
 *   1. at nesting level 0 → `\LogicException`;
 *   2. in an open transaction with no `app.tenant_id` → `\LogicException` (L-5: a level-only check misses this);
 *   3. **positive control**, inside `TenantContext::run()` → a number, so the guard does not refuse everything.
 * **Mutations:** delete either guard in either port → its test 1 or 2 is red (the call returns 0 instead of throwing).
 */
final class PartyPortTenancyGuardTest extends TenancyTestCase
{
    #[Test]
    public function theSupplierPortRefusesOutsideATransaction(): void
    {
        self::assertSame(0, $this->em()->getConnection()->getTransactionNestingLevel(), 'Precondition: no transaction is open.');

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('SupplierOpenWork needs the caller\'s open tenancy transaction');
        (new SupplierOpenPurchaseOrders())->openPurchaseOrderCountInside($this->em(), Uuid::v4()->toRfc4122());
    }

    #[Test]
    public function theSupplierPortRefusesATransactionWithNoTenant(): void
    {
        $this->inATransactionWithNoTenant(function (EntityManagerInterface $em): void {
            $this->expectException(\LogicException::class);
            $this->expectExceptionMessage('SupplierOpenWork was called in a transaction with no tenant set');
            (new SupplierOpenPurchaseOrders())->openPurchaseOrderCountInside($em, Uuid::v4()->toRfc4122());
        });
    }

    #[Test]
    public function theSupplierPortAnswersInsideATenancyTransaction(): void
    {
        self::assertSame(0, $this->tenantContext()->run(self::TENANT_A, static fn (EntityManagerInterface $em): int =>
            (new SupplierOpenPurchaseOrders())->openPurchaseOrderCountInside($em, Uuid::v4()->toRfc4122())));
    }

    #[Test]
    public function theCustomerPortRefusesOutsideATransaction(): void
    {
        self::assertSame(0, $this->em()->getConnection()->getTransactionNestingLevel(), 'Precondition: no transaction is open.');

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('CustomerOpenWork needs the caller\'s open tenancy transaction');
        (new CustomerOpenSalesWork())->openWorkInside($this->em(), Uuid::v4()->toRfc4122());
    }

    #[Test]
    public function theCustomerPortRefusesATransactionWithNoTenant(): void
    {
        $this->inATransactionWithNoTenant(function (EntityManagerInterface $em): void {
            $this->expectException(\LogicException::class);
            $this->expectExceptionMessage('CustomerOpenWork was called in a transaction with no tenant set');
            (new CustomerOpenSalesWork())->openWorkInside($em, Uuid::v4()->toRfc4122());
        });
    }

    #[Test]
    public function theCustomerPortAnswersInsideATenancyTransaction(): void
    {
        $count = $this->tenantContext()->run(self::TENANT_A, static fn (EntityManagerInterface $em) =>
            (new CustomerOpenSalesWork())->openWorkInside($em, Uuid::v4()->toRfc4122()));

        self::assertSame([0, 0, 0, 0], [$count->openSalesOrders, $count->unbilledShipments, $count->unpaidInvoices, $count->owedCredits]);
    }

    /**
     * A transaction opened directly on the application connection, never through `TenantContext`, with the tenant setting
     * checked empty first — the state a careless caller would put the port in. Always rolled back.
     *
     * @param callable(EntityManagerInterface): void $work
     */
    private function inATransactionWithNoTenant(callable $work): void
    {
        $em = $this->em();
        $connection = $em->getConnection();
        $connection->beginTransaction();
        try {
            self::assertNull($connection->fetchOne(SupplierOpenPurchaseOrders::TENANT_GUC), 'Precondition: no tenant is set.');
            self::assertSame(1, $connection->getTransactionNestingLevel());
            $work($em);
        } finally {
            $connection->rollBack();
        }
    }
}
