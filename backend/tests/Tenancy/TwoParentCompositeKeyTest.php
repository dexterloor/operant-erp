<?php

declare(strict_types=1);

namespace App\Tests\Tenancy;

use App\Modules\Purchasing\Infrastructure\Entity\PurchaseOrder;
use App\Modules\Suppliers\Infrastructure\Entity\Supplier;
use App\Modules\Warehouses\Infrastructure\Entity\Warehouse;
use App\SharedKernel\Reference\Currency;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Uid\Uuid;

/**
 * The question W0-a left open, answered.
 *
 * A1.6 rejected the composite ORM identifier because the **association**, not
 * the field, writes a shared `tenant_id` column: a null parent nulls the
 * tenant, and a parent from another tenant silently overrides an explicitly-set
 * one. The replacement -- scalar id, `tenantId` as an ordinary field,
 * single-column associations, composite foreign key by hand -- was proved on
 * `inventory_item`, which has **one** parent. The slice's second composite key
 * was on `stock_movement`, which had no ORM mapping at all.
 *
 * So the shape that actually disqualified the rejected mapping -- one mapped
 * entity that is the child of several tenant-scoped parents **through the same
 * tenant_id column** -- had never been executed. It is also the ordinary shape
 * of an ERP: a purchase order belongs to a supplier, is received into a
 * warehouse, and was raised by a user, all within one tenant.
 *
 * `purchase_order` has three such parents. Under the rejected mapping they
 * could not even be expressed: three associations cannot each own the same
 * column. Under this one none of them owns it, the field does, and Postgres
 * enforces the tenant agreement through three hand-written composite keys.
 */
final class TwoParentCompositeKeyTest extends TenancyTestCase
{
    private function currency(): Currency
    {
        return $this->em()->find(Currency::class, 'EUR');
    }

    /**
     * The parents are fixtures, written as owner SQL. No `src/` path writes
     * `supplier` or `warehouse`, so `erp_app` -- the connection the entity manager
     * is pinned to -- holds SELECT on them and nothing else (ADR-002 Amendment 4),
     * and an ORM `persist()` of either would be refused. The subject of this file
     * is the `PurchaseOrder` ORM insert, which still runs through the entity
     * manager as `erp_app`; each test loads the parents with `em()->find()`.
     *
     * @return array{0: string, 1: string} supplier id, warehouse id
     */
    private function seedParents(string $tenantId, string $suffix = ''): array
    {
        $supplierId = Uuid::v4()->toRfc4122();
        $warehouseId = Uuid::v4()->toRfc4122();

        $this->insertAsOwnerForTenant($tenantId,
            "INSERT INTO supplier (id, tenant_id, name, code, country_code, currency_code) VALUES (?::uuid, ?::uuid, ?, ?, ?, 'EUR')",
            [$supplierId, $tenantId, 'Parent Supplier'.$suffix, 'SUP'.$suffix, 'GB']);
        $this->insertAsOwnerForTenant($tenantId,
            "INSERT INTO warehouse (id, tenant_id, code, name, country_code) VALUES (?::uuid, ?::uuid, ?, ?, 'DE')",
            [$warehouseId, $tenantId, 'WH'.$suffix, 'Parent Warehouse'.$suffix]);

        return [$supplierId, $warehouseId];
    }

    #[Test]
    public function anEntityWithThreeTenantScopedParentsWritesItsOwnTenantId(): void
    {
        [$supplierId, $warehouseId] = $this->seedParents(self::TENANT_A);

        $orderId = $this->tenantContext()->run(self::TENANT_A, function () use ($supplierId, $warehouseId): string {
            $order = new PurchaseOrder(
                Uuid::v4(),
                Uuid::fromString(self::TENANT_A),
                'PO-000001',
                $this->em()->find(Supplier::class, Uuid::fromString($supplierId)),
                $this->em()->find(Warehouse::class, Uuid::fromString($warehouseId)),
                $this->currency(),
                // `Version20260911000200` refuses an insert with no key, so every
                // writer of this table now supplies one -- including the ORM
                // route, whose only way to carry it is this constructor argument.
                // Fixture furniture here: this test is about tenant_id, not about
                // idempotency. Since `Version20260911000300` both values are hex
                // SHA-256 and travel together; their content is immaterial here.
                hash('sha256', Uuid::v4()->toRfc4122()),
                hash('sha256', 'a request nobody sent'),
            );
            $this->em()->persist($order);
            $this->em()->flush();

            return $order->getId()->toRfc4122();
        });

        // Read back from outside the ORM. Under the rejected mapping the
        // association would have written this column, and the assertion below is
        // the one that failed.
        self::assertSame(1, $this->countAsOwnerForTenant(
            self::TENANT_A,
            'SELECT count(*) FROM purchase_order WHERE id = ?::uuid AND tenant_id = ?::uuid',
            [$orderId, self::TENANT_A]));
    }

    /**
     * A1.6's first failure mode, on the three-parent shape: with `$createdBy`
     * null the composite-identifier mapping emitted NULL for tenant_id and died
     * on the NOT NULL constraint. Here the nullable parent is simply absent and
     * the tenant is still the entity's own.
     */
    #[Test]
    public function aNullOptionalParentDoesNotNullTheTenantId(): void
    {
        [$supplierId, $warehouseId] = $this->seedParents(self::TENANT_A);

        $orderId = $this->tenantContext()->run(self::TENANT_A, function () use ($supplierId, $warehouseId): string {
            $order = new PurchaseOrder(
                Uuid::v4(),
                Uuid::fromString(self::TENANT_A),
                'PO-000002',
                $this->em()->find(Supplier::class, Uuid::fromString($supplierId)),
                $this->em()->find(Warehouse::class, Uuid::fromString($warehouseId)),
                $this->currency(),
                hash('sha256', Uuid::v4()->toRfc4122()),
                hash('sha256', 'a request nobody sent'),
            );
            $order->setCreatedBy(null);
            $this->em()->persist($order);
            $this->em()->flush();

            return $order->getId()->toRfc4122();
        });

        self::assertSame(1, $this->countAsOwnerForTenant(
            self::TENANT_A,
            'SELECT count(*) FROM purchase_order WHERE id = ?::uuid AND created_by_user_id IS NULL AND tenant_id = ?::uuid',
            [$orderId, self::TENANT_A]));
    }

    /**
     * A1.6's second failure mode, and the property the composite keys buy:
     * a parent from another tenant is refused **by Postgres**, whatever the ORM
     * believes.
     *
     * Written as raw SQL on purpose. Through the ORM this is hard to even
     * attempt, because RLS returns zero rows for the other tenant's supplier --
     * which is the ergonomic guard. This bypasses that guard and goes straight
     * at the constraint, because "the ORM makes it awkward" is not the property
     * ADR-002 §5 claims.
     */
    #[Test]
    public function aParentFromAnotherTenantIsRefusedByTheCompositeForeignKey(): void
    {
        [$supplierA, $warehouseA] = $this->seedParents(self::TENANT_A, '-A');
        [$supplierB] = $this->seedParents(self::TENANT_B, '-B');

        // The control runs first, deliberately. A constraint violation inside
        // wrapInTransaction closes the EntityManager, so anything after the
        // refusal would fail for that reason instead -- worth knowing before
        // Output 4 designs error responses: a refused cross-tenant write does
        // not leave a usable unit of work behind.
        $this->tenantContext()->run(self::TENANT_A, function () use ($supplierA, $warehouseA): void {
            $this->app()->executeStatement(
                "INSERT INTO purchase_order (id, tenant_id, order_number, supplier_id, warehouse_id, currency_code, idempotency_key, request_digest)
                 VALUES (gen_random_uuid(), ?::uuid, 'PO-122000', ?::uuid, ?::uuid, 'EUR', encode(sha256(gen_random_uuid()::text::bytea), 'hex'), encode(sha256(gen_random_uuid()::text::bytea), 'hex'))",
                [self::TENANT_A, $supplierA, $warehouseA],
            );
        });

        self::assertSame(1, $this->countAsOwnerForTenant(
            self::TENANT_A, "SELECT count(*) FROM purchase_order WHERE order_number = 'PO-122000'"));

        $thrown = null;
        try {
            $this->tenantContext()->run(self::TENANT_A, function () use ($supplierB, $warehouseA): void {
                $this->app()->executeStatement(
                    "INSERT INTO purchase_order (id, tenant_id, order_number, supplier_id, warehouse_id, currency_code, idempotency_key, request_digest)
                     VALUES (gen_random_uuid(), ?::uuid, 'PO-109000', ?::uuid, ?::uuid, 'EUR', encode(sha256(gen_random_uuid()::text::bytea), 'hex'), encode(sha256(gen_random_uuid()::text::bytea), 'hex'))",
                    [self::TENANT_A, $supplierB, $warehouseA],
                );
            });
        } catch (\Throwable $e) {
            $thrown = $e;
        }

        self::assertNotNull($thrown, 'A purchase order was created in tenant A against tenant B\'s supplier.');
        self::assertStringContainsString('purchase_order_supplier_fk', $thrown->getMessage(),
            'The insert failed for some reason other than the composite tenant foreign key: '.$thrown->getMessage());

        self::assertSame(0, $this->countAsOwnerForTenant(
            self::TENANT_A, "SELECT count(*) FROM purchase_order WHERE order_number = 'PO-109000'"));
    }

    /**
     * The cost A1.6 accepted, still bounded at three associations: the ORM
     * identifier stays scalar, so `find()`, every DQL parameter bind and every
     * future API Platform IRI keep working on one value.
     */
    #[Test]
    public function theOrmIdentifierStaysScalarOnAThreeParentEntity(): void
    {
        $meta = $this->em()->getClassMetadata(PurchaseOrder::class);

        self::assertSame(['id'], $meta->getIdentifierFieldNames());

        // And tenant_id is a field, not a join column, on all three associations.
        self::assertTrue($meta->hasField('tenantId'));
        foreach ($meta->getAssociationMappings() as $association) {
            foreach ($association->joinColumns ?? [] as $joinColumn) {
                self::assertNotSame('tenant_id', $joinColumn->name,
                    'An association owns the tenant_id column. That is A1.6 exactly: the association wins over '
                    .'the field, and the row is written into the parent\'s tenant.');
            }
        }
    }
}
