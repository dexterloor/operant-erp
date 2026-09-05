<?php

declare(strict_types=1);

namespace App\Tests\Tenancy;

use App\Entity\Supplier;
use App\Tenancy\SchemaManifest;
use App\Tenancy\SoftDeleteFilter;
use App\Tenancy\TenantFilter;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Uid\Uuid;

/**
 * D-12 and D-13, exercised rather than inspected.
 *
 * SchemaManifestTest asserts the constraints exist. A constraint that exists
 * and never fires is the failure mode that matters, so everything here works by
 * attempting the thing the constraint is supposed to refuse.
 */
final class DeletionPolicyTest extends TenancyTestCase
{
    private function insertSupplier(string $id, string $tenantId, string $name, string $code): void
    {
        $this->tenantContext()->run($tenantId, function () use ($id, $tenantId, $name, $code): void {
            $this->app()->executeStatement(
                "INSERT INTO supplier (id, tenant_id, name, code, currency_code) VALUES (?::uuid, ?::uuid, ?, ?, 'EUR')",
                [$id, $tenantId, $name, $code],
            );
        });
    }

    private function seedWarehouse(): void
    {
        $this->tenantContext()->run(self::TENANT_A, function (): void {
            $this->app()->executeStatement(<<<'SQL'
                INSERT INTO warehouse (id, tenant_id, code, name, country_code)
                VALUES (gen_random_uuid(), ?::uuid, 'WH-C', 'Cascade', 'DE')
                SQL, [self::TENANT_A]);
        });
    }

    /**
     * D-12 / A1.8. The whole point of the constraint is that a type check is not
     * enough: this id is a perfectly valid `uuid`, and it is a UUIDv7, so its
     * leading bits are a millisecond timestamp and the row after it is
     * guessable. `PRIMARY KEY (id)` is a bare unique index whose violations
     * bypass row security, so a guessable id is a confirmable one.
     */
    #[Test]
    public function aTimeOrderedUuidVersionSevenIdentifierIsRefused(): void
    {
        // Version nibble 7, RFC 4122 variant. Accepted by `uuid`, refused here.
        $v7 = '0192f3a1-2b3c-7def-8abc-0123456789ab';
        self::assertTrue(Uuid::isValid($v7), 'The fixture is not a well-formed UUID, so the test would prove nothing.');

        $thrown = null;
        try {
            $this->insertSupplier($v7, self::TENANT_A, 'Time Ordered', 'TO-1');
        } catch (\Throwable $e) {
            $thrown = $e;
        }

        self::assertNotNull($thrown, 'A UUIDv7 primary key was accepted on a tenant-scoped table.');
        self::assertStringContainsString('supplier_id_is_uuid_v4', $thrown->getMessage());
    }

    #[Test]
    public function aRandomUuidVersionFourIdentifierIsAccepted(): void
    {
        $id = Uuid::v4()->toRfc4122();
        $this->insertSupplier($id, self::TENANT_A, 'Random', 'R-1');

        self::assertSame(1, $this->countAsOwnerForTenant(
            self::TENANT_A, 'SELECT count(*) FROM supplier WHERE id = ?::uuid', [$id]));
    }

    /**
     * ADR-007 §1's sharp edge: "delete a supplier, re-create it with the same
     * code" is a routine ERP operation, and under a total unique constraint it
     * fails against a row the user cannot see -- with an error message that also
     * happens to bypass row security, because integrity checks always do.
     */
    #[Test]
    public function aSoftDeletedRowReleasesItsBusinessKeyForReuse(): void
    {
        $first = Uuid::v4()->toRfc4122();
        $this->insertSupplier($first, self::TENANT_A, 'Acme', 'ACME');

        $this->tenantContext()->run(self::TENANT_A, function () use ($first): void {
            $this->app()->executeStatement('UPDATE supplier SET deleted_at = now() WHERE id = ?::uuid', [$first]);
        });

        $second = Uuid::v4()->toRfc4122();
        $this->insertSupplier($second, self::TENANT_A, 'Acme', 'ACME');

        self::assertSame(2, $this->countAsOwnerForTenant(
            self::TENANT_A, "SELECT count(*) FROM supplier WHERE code = 'ACME'"),
            'Both rows must survive: the deleted one is history, the live one is the current supplier.');
    }

    /** The same key twice while both are live is still refused. */
    #[Test]
    public function twoLiveRowsCannotShareABusinessKey(): void
    {
        $this->insertSupplier(Uuid::v4()->toRfc4122(), self::TENANT_A, 'Acme', 'ACME');

        $thrown = null;
        try {
            $this->insertSupplier(Uuid::v4()->toRfc4122(), self::TENANT_A, 'Acme Duplicate', 'ACME');
        } catch (\Throwable $e) {
            $thrown = $e;
        }

        self::assertNotNull($thrown, 'The live-row unique index did not fire.');
        self::assertStringContainsString('supplier_tenant_code_live', $thrown->getMessage());
    }

    /**
     * Why `UNIQUE (tenant_id, id)` has to stay total, demonstrated rather than
     * argued: a child legitimately references a soft-deleted parent, and the
     * composite foreign key resolves against the total index. Had ADR-007's
     * partial predicate been applied to it, this insert would fail and the
     * entire deletion policy would collapse.
     */
    #[Test]
    public function aChildCanBeCreatedAgainstASoftDeletedParent(): void
    {
        $supplierId = Uuid::v4()->toRfc4122();
        $this->insertSupplier($supplierId, self::TENANT_A, 'Retired', 'RET');

        $this->tenantContext()->run(self::TENANT_A, function () use ($supplierId): void {
            $this->app()->executeStatement('UPDATE supplier SET deleted_at = now() WHERE id = ?::uuid', [$supplierId]);
        });

        $itemId = $this->seedItem(self::TENANT_A, 'SKU-ORPHAN', $supplierId);

        self::assertSame(1, $this->countAsOwnerForTenant(
            self::TENANT_A, 'SELECT count(*) FROM inventory_item WHERE id = ?::uuid AND supplier_id = ?::uuid',
            [$itemId, $supplierId]));
    }

    /**
     * `RED`'s MAJOR-2, closed in the database rather than in a convention.
     *
     * Before the cascade trigger, soft-deleting a purchase order left its lines
     * live, and `purchase_order_line_tenant_item_idx` exists precisely to answer
     * "how much of this item is already on order" -- so the obvious query
     * counted supply from a discarded order, suppressed a legitimate reorder,
     * and would have surfaced weeks later as a stockout with no explanation.
     *
     * The reply at the time was "the deletion filter applies at the aggregate
     * root". That is a convention, and ADR-007's own argument is that a control
     * enforced by a convention is a hope. Written here as raw SQL, so the
     * cascade is proved to survive a caller that never touches the ORM.
     */
    #[Test]
    public function softDeletingAPurchaseOrderSoftDeletesItsLinesAndRemovesItFromTheOnOrderQuantity(): void
    {
        $supplierId = Uuid::v4()->toRfc4122();
        $this->insertSupplier($supplierId, self::TENANT_A, 'Cascade', 'CAS');
        $itemId = $this->seedItem(self::TENANT_A, 'SKU-CASCADE', $supplierId);
        $this->seedWarehouse();

        $orderId = Uuid::v4()->toRfc4122();
        $this->tenantContext()->run(self::TENANT_A, function () use ($orderId, $supplierId, $itemId): void {
            $this->app()->executeStatement(<<<'SQL'
                INSERT INTO purchase_order (id, tenant_id, order_number, supplier_id, warehouse_id, currency_code, status, ordered_at)
                VALUES (?::uuid, ?::uuid, 'PO-CASCADE', ?::uuid,
                        (SELECT id FROM warehouse WHERE code = 'WH-C'), 'EUR', 'ordered', now())
                SQL, [$orderId, self::TENANT_A, $supplierId]);
            $this->app()->executeStatement(<<<'SQL'
                INSERT INTO purchase_order_line
                    (id, tenant_id, purchase_order_id, inventory_item_id, line_number, quantity_ordered, unit_cost, lead_time_days)
                VALUES (gen_random_uuid(), ?::uuid, ?::uuid, ?::uuid, 1, 100, 5.00, 7)
                SQL, [self::TENANT_A, $orderId, $itemId]);
        });

        $onOrder = fn (): string => $this->tenantContext()->run(self::TENANT_A, fn (): string =>
            (string) $this->app()->fetchOne(
                'SELECT coalesce(sum(quantity_ordered), 0) FROM purchase_order_line
                 WHERE inventory_item_id = ?::uuid AND deleted_at IS NULL', [$itemId]));

        self::assertSame('100.000000', $onOrder());

        $this->tenantContext()->run(self::TENANT_A, function () use ($orderId): void {
            $this->app()->executeStatement('UPDATE purchase_order SET deleted_at = now() WHERE id = ?::uuid', [$orderId]);
        });

        self::assertSame('0', $onOrder(),
            'A discarded purchase order still contributes to on-order quantity. That is supply that will never '
            .'arrive, and it suppresses the reorder that should have been raised.');

        self::assertSame(1, $this->countAsOwnerForTenant(self::TENANT_A,
            'SELECT count(*) FROM purchase_order_line WHERE purchase_order_id = ?::uuid AND deleted_at IS NOT NULL',
            [$orderId]),
            'The line was hidden by a filter rather than actually marked deleted.');

        // Symmetry: ADR-007 §4 leaves whether restore is offered to Output 17,
        // but if it is, the aggregate has to come back whole.
        $this->tenantContext()->run(self::TENANT_A, function () use ($orderId): void {
            $this->app()->executeStatement('UPDATE purchase_order SET deleted_at = NULL WHERE id = ?::uuid', [$orderId]);
        });

        self::assertSame('100.000000', $onOrder(), 'Restoring the order did not restore its lines.');
    }

    /**
     * The mirror of the test above, and the reason it exists separately.
     *
     * The `RED` gate dropped each trigger in turn and found that
     * `sales_order_cascade_deletion` could be removed with the **whole suite
     * still green**. The fix had gone to both tables; the assertion had gone to
     * one. That is the same defect as the one the purchasing test was written to
     * catch, wearing the other module's name — and it is a reminder that "I
     * fixed both sides" and "I proved both sides" are different claims.
     *
     * Sales is not a copy of purchasing here: the query is ADR-004's *demand*
     * history rather than its supply, served by
     * `sales_order_line_tenant_item_idx`. Demand from a discarded order is not
     * demand, and a forecast that counts it over-orders instead of
     * under-ordering — the opposite failure, from the same cause.
     */
    #[Test]
    public function softDeletingASalesOrderSoftDeletesItsLinesAndRemovesItFromTheDemandHistory(): void
    {
        $itemId = $this->seedItem(self::TENANT_A, 'SKU-DEMAND');
        $this->seedWarehouse();

        $orderId = Uuid::v4()->toRfc4122();
        $this->tenantContext()->run(self::TENANT_A, function () use ($orderId, $itemId): void {
            $this->app()->executeStatement(<<<'SQL'
                INSERT INTO customer (id, tenant_id, code, name, currency_code)
                VALUES (gen_random_uuid(), ?::uuid, 'CUS-C', 'Cascade Customer', 'EUR')
                SQL, [self::TENANT_A]);
            $this->app()->executeStatement(<<<'SQL'
                INSERT INTO sales_order
                    (id, tenant_id, order_number, customer_id, warehouse_id, currency_code, status, ordered_at, ship_to_country_code)
                VALUES (?::uuid, ?::uuid, 'SO-CASCADE',
                        (SELECT id FROM customer  WHERE code = 'CUS-C'),
                        (SELECT id FROM warehouse WHERE code = 'WH-C'),
                        'EUR', 'confirmed', now(), 'DE')
                SQL, [$orderId, self::TENANT_A]);
            $this->app()->executeStatement(<<<'SQL'
                INSERT INTO sales_order_line
                    (id, tenant_id, sales_order_id, inventory_item_id, line_number, quantity, unit_price)
                VALUES (gen_random_uuid(), ?::uuid, ?::uuid, ?::uuid, 1, 40, 12.50)
                SQL, [self::TENANT_A, $orderId, $itemId]);
        });

        $demand = fn (): string => $this->tenantContext()->run(self::TENANT_A, fn (): string =>
            (string) $this->app()->fetchOne(
                'SELECT coalesce(sum(quantity), 0) FROM sales_order_line
                 WHERE inventory_item_id = ?::uuid AND deleted_at IS NULL', [$itemId]));

        self::assertSame('40.000000', $demand());

        $this->tenantContext()->run(self::TENANT_A, function () use ($orderId): void {
            $this->app()->executeStatement('UPDATE sales_order SET deleted_at = now() WHERE id = ?::uuid', [$orderId]);
        });

        self::assertSame('0', $demand(),
            'A discarded sales order still contributes to demand history. ADR-004 forecasts from this figure, '
            .'so the order that was never really placed becomes stock that was never really needed.');

        self::assertSame(1, $this->countAsOwnerForTenant(self::TENANT_A,
            'SELECT count(*) FROM sales_order_line WHERE sales_order_id = ?::uuid AND deleted_at IS NOT NULL',
            [$orderId]),
            'The line was hidden by a filter rather than actually marked deleted.');

        $this->tenantContext()->run(self::TENANT_A, function () use ($orderId): void {
            $this->app()->executeStatement('UPDATE sales_order SET deleted_at = NULL WHERE id = ?::uuid', [$orderId]);
        });

        self::assertSame('40.000000', $demand(), 'Restoring the order did not restore its lines.');
    }

    /**
     * `RED`'s MAJOR-3. ADR-006 §2.1 step 2's sentinel exists so an unresolved
     * slug still runs the `app_user` SELECT and finds nothing -- which is what
     * makes the statement sequence identical on every miss path (V-8). "Owns no
     * app_user rows" is the property the whole equalisation rests on, and it was
     * a property of nothing until this constraint.
     */
    #[Test]
    public function theSentinelTenantCannotOwnAUser(): void
    {
        $thrown = null;
        try {
            // As the owner, through the shared helper: FORCE ROW LEVEL SECURITY
            // binds the owner too, so a seeding path needs a context like anyone
            // else -- and this is the route a fixture builder would take.
            $this->insertAsOwnerForTenant(SchemaManifest::SENTINEL_TENANT_ID, <<<'SQL'
                INSERT INTO app_user (id, tenant_id, email, password_hash, display_name)
                VALUES (gen_random_uuid(), ?::uuid, 'nobody@example.com', 'x', 'Nobody')
                SQL, [SchemaManifest::SENTINEL_TENANT_ID]);
        } catch (\Throwable $e) {
            $thrown = $e;
        }

        self::assertNotNull($thrown, 'A user was created against the sentinel tenant.');
        self::assertStringContainsString('app_user_not_sentinel_tenant', $thrown->getMessage());
    }

    /**
     * ADR-007 §2, stated as a test so the two filters cannot quietly become the
     * same kind of thing in someone's mental model.
     *
     * Turning the deletion filter off shows a deleted row -- a bug. Turning the
     * tenant filter off shows nothing extra at all, because RLS is underneath
     * it. Same gesture, two entirely different consequences, in one test.
     */
    #[Test]
    public function theDeletionFilterIsErgonomicsAndTheTenantFilterHasABackstop(): void
    {
        $deletedId = Uuid::v4()->toRfc4122();
        $this->insertSupplier($deletedId, self::TENANT_A, 'Gone', 'GONE');
        $this->insertSupplier(Uuid::v4()->toRfc4122(), self::TENANT_A, 'Here', 'HERE');
        $this->insertSupplier(Uuid::v4()->toRfc4122(), self::TENANT_B, 'Other Tenant', 'OTHER');

        $this->tenantContext()->run(self::TENANT_A, function () use ($deletedId): void {
            $this->app()->executeStatement('UPDATE supplier SET deleted_at = now() WHERE id = ?::uuid', [$deletedId]);
        });

        $this->em()->clear();

        $withBothFilters = $this->tenantContext()->run(self::TENANT_A, fn (): int => \count(
            $this->em()->createQuery('SELECT s FROM '.Supplier::class.' s')->getResult()));

        self::assertSame(1, $withBothFilters, 'The deleted row was returned with both filters on.');

        // Deletion filter off: the deleted row comes back. A bug, not a breach.
        $this->em()->getFilters()->disable(SoftDeleteFilter::NAME);
        $this->em()->clear();

        $withoutDeletionFilter = $this->tenantContext()->run(self::TENANT_A, fn (): int => \count(
            $this->em()->createQuery('SELECT s FROM '.Supplier::class.' s')->getResult()));

        // Tenant filter off as well: still nothing from tenant B, because the
        // boundary was never the filter.
        $this->em()->getFilters()->disable(TenantFilter::NAME);
        $this->em()->clear();

        $withNeitherFilter = $this->tenantContext()->run(self::TENANT_A, fn (): array => array_map(
            static fn (Supplier $s): string => $s->getName(),
            $this->em()->createQuery('SELECT s FROM '.Supplier::class.' s')->getResult()));

        $this->em()->getFilters()->enable(SoftDeleteFilter::NAME);
        $this->em()->getFilters()->enable(TenantFilter::NAME);

        self::assertSame(2, $withoutDeletionFilter,
            'Disabling the deletion filter must expose the deleted row -- it is ergonomics, and this is the '
            .'bug it protects against, not a control.');

        sort($withNeitherFilter);
        self::assertSame(['Gone', 'Here'], $withNeitherFilter,
            'With both filters off, RLS must still return tenant A only. If tenant B appeared here, the '
            .'tenant filter was the boundary all along, which is the mistake ADR-002 §4 exists to prevent.');
    }
}
