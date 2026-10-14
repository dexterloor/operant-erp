<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Modules\Sales\Infrastructure\Entity\SalesOrder;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Uid\Uuid;

/**
 * **`UpdateSalesOrder` — the behaviour, over HTTP.**
 *
 * The contract registries (`EditWindowGuardTest`, `ArchivedRowTest`, `PermissionEnforcementTest`,
 * `TenantClaimProbeTest`, `WireScaleTest`, `ResponseShapeTest`) each hold one property of this
 * operation over a derived domain. **This class holds the things that are true of this operation
 * alone**, and in particular the two guards owner ruling O-2 attached and the co-submission rule that
 * keeps `RESIDUAL_GAPS.md` G-6's harm out of a shipped path.
 */
final class SalesOrderWriteTest extends PurchaseOrderTestCase
{
    /**
     * **O-2's re-snapshot, read back off the database rather than out of the response.**
     *
     * The response is built by `SalesOrderRow` from the same row, so asserting on it alone would prove
     * the read agrees with itself. These four columns are what a shipment is actually sent to.
     */
    #[Test]
    public function movingTheShippingAddressRewritesAllFourSnapshotColumns(): void
    {
        $c = $this->workspace('MOVE');
        $moved = $this->seedCustomerAddress(self::TENANT_A, $c['customer'], 'shipping', '4 Ropergate', 'Pontefract', 'WF8 1LE', 'GB');

        $this->send('PATCH', '/sales-orders/'.$c['order'], ['ship_to_address_id' => $moved]);

        self::assertSame(200, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        self::assertSame([
            'ship_to_line' => '4 Ropergate',
            'ship_to_city' => 'Pontefract',
            'ship_to_postal_code' => 'WF8 1LE',
            'ship_to_country_code' => 'GB',
        ], $this->shipTo($c['order']));
    }

    /**
     * **An edit that does not name the address leaves the snapshot byte-identical.**
     *
     * The complement of the case above, and the one that would fail if the writer ever re-resolved the
     * snapshot on every request: a `customer_id` unchanged and no address named must touch nothing but
     * the column it was given.
     */
    #[Test]
    public function anEditThatNamesNoAddressLeavesTheSnapshotAlone(): void
    {
        $c = $this->workspace('KEEP');
        $before = $this->shipTo($c['order']);
        $elsewhere = $this->seedWarehouse(self::TENANT_A, 'WH Elsewhere');

        $this->send('PATCH', '/sales-orders/'.$c['order'], ['warehouse_id' => $elsewhere]);

        self::assertSame(200, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        self::assertSame($elsewhere, $this->json()['warehouse']['id']);
        self::assertSame($before, $this->shipTo($c['order']));
    }

    /**
     * **The co-submission rule: a `customer_id` that CHANGES must carry `ship_to_address_id`.**
     *
     * Without it the four `ship_to_*` columns would keep an address copied from the **previous**
     * customer's address book, and no constraint, index or trail row in this system could ever detect
     * it — `RESIDUAL_GAPS.md` G-6's harm class, reached through a shipped operation. The snapshot
     * cannot be cleared instead (`ship_to_country_code` is `NOT NULL` with no default and a foreign key
     * onto `country`) and cannot be re-validated (there is no residue to validate).
     */
    #[Test]
    public function changingTheCustomerWithoutNamingAnAddressIsRefused(): void
    {
        $c = $this->workspace('SWAP');
        $other = $this->seedCustomer(self::TENANT_A, 'Other Customer');
        $this->seedCustomerAddress(self::TENANT_A, $other);
        $before = $this->shipTo($c['order']);

        $this->send('PATCH', '/sales-orders/'.$c['order'], ['customer_id' => $other]);

        self::assertSame(422, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        self::assertSame('urn:erp:problem:validation-failed', $this->json()['type']);
        self::assertSame([['pointer' => '/ship_to_address_id', 'code' => 'required']], array_map(
            static fn (array $error): array => ['pointer' => $error['pointer'], 'code' => $error['code']],
            $this->json()['errors'],
        ));
        self::assertSame($before, $this->shipTo($c['order']));
        self::assertSame($c['customer'], $this->customerOf($c['order']), 'The refused request moved the customer anyway.');
    }

    /**
     * ⚠ **The other half of that rule, and the half that makes it a CHANGE rule rather than a PRESENCE
     * rule: resubmitting the customer the order already has must SUCCEED.**
     *
     * A form-driven client PATCHes every field it rendered, so it sends the unchanged `customer_id` on
     * every save. Refusing that would 422 an ordinary, correct request for nothing — and the design this
     * was built from placed the check in the shape-only validator, where **only presence is decidable**
     * and this case would have been refused. `RED` measured that and the owner's decision was
     * *"on change, not on presence"*. **This test is that decision.**
     */
    #[Test]
    public function resubmittingTheUnchangedCustomerWithoutAnAddressSucceeds(): void
    {
        $c = $this->workspace('SAME');
        $before = $this->shipTo($c['order']);

        $this->send('PATCH', '/sales-orders/'.$c['order'], ['customer_id' => $c['customer']]);

        self::assertSame(200, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        self::assertSame($c['customer'], $this->json()['customer']['id']);
        self::assertSame($before, $this->shipTo($c['order']));
    }

    /** A customer change that names one of the NEW customer's shipping addresses is what the rule asks for. */
    #[Test]
    public function changingTheCustomerAndTheAddressTogetherSucceeds(): void
    {
        $c = $this->workspace('BOTH');
        $other = $this->seedCustomer(self::TENANT_A, 'Other Customer');
        $theirs = $this->seedCustomerAddress(self::TENANT_A, $other, 'shipping', '12 Deansgate', 'Manchester', 'M3 2BW', 'GB');

        $this->send('PATCH', '/sales-orders/'.$c['order'], ['customer_id' => $other, 'ship_to_address_id' => $theirs]);

        self::assertSame(200, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        self::assertSame($other, $this->json()['customer']['id']);
        self::assertSame([
            'ship_to_line' => '12 Deansgate',
            'ship_to_city' => 'Manchester',
            'ship_to_postal_code' => 'M3 2BW',
            'ship_to_country_code' => 'GB',
        ], $this->shipTo($c['order']));
    }

    /**
     * **The ownership rule, reached by the SECOND writer — G-6 option (a) in one test.**
     *
     * Three different failures answer with **one** refusal, which is ST-5 rather than laziness: an
     * address of another customer, a billing address of the right customer and an id that names nothing
     * must be indistinguishable, or the answer is an oracle over the workspace's address book.
     *
     * **⚠ The first case is the one G-6 exists for.** `moveToAnotherCustomersAddress` is precisely the
     * write that, if it succeeded, nothing could ever detect.
     */
    #[Test]
    public function anAddressThatIsNotAShippingAddressOfThisCustomerIsRefused(): void
    {
        $c = $this->workspace('OWN');
        $other = $this->seedCustomer(self::TENANT_A, 'Other Customer');
        $before = $this->shipTo($c['order']);

        $refused = [
            'another customer\'s shipping address' => $this->seedCustomerAddress(self::TENANT_A, $other),
            'this customer\'s BILLING address' => $this->seedCustomerAddress(self::TENANT_A, $c['customer'], 'billing'),
            'an id that names nothing' => Uuid::v4()->toRfc4122(),
        ];

        foreach ($refused as $why => $address) {
            $this->send('PATCH', '/sales-orders/'.$c['order'], ['ship_to_address_id' => $address]);

            self::assertSame(422, $this->httpStatus(), $why.': '.(string) $this->client()->getResponse()->getContent());
            self::assertSame([['pointer' => '/ship_to_address_id', 'code' => 'unknown_reference']], array_map(
                static fn (array $error): array => ['pointer' => $error['pointer'], 'code' => $error['code']],
                $this->json()['errors'],
            ), $why.' must be answered exactly as the other two are (ST-5).');
        }

        self::assertSame($before, $this->shipTo($c['order']));
    }

    /**
     * **The archived guard runs BEFORE any reference is resolved, and this is the test that says so.**
     *
     * `ArchivedRowSubjects` drives this operation with an **empty** body, because its bodies are static
     * and every field here is a seeded id — so that case cannot prove the ordering. This one sends a
     * `warehouse_id` that resolves to nothing at an archived order: the answer must be
     * `sales_order.is_archived`, not `unknown_reference`. Swap the two guards in
     * `SalesOrderWriter::update()` and this goes red.
     */
    #[Test]
    public function anArchivedOrderIsRefusedBeforeAnyReferenceIsResolved(): void
    {
        $c = $this->workspace('ARCH');
        $this->executeAsOwnerForTenant(self::TENANT_A, 'UPDATE sales_order SET deleted_at = now() WHERE id = ?::uuid', [$c['order']]);

        $this->send('PATCH', '/sales-orders/'.$c['order'], ['warehouse_id' => Uuid::v4()->toRfc4122()]);

        self::assertSame(409, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        self::assertSame('sales_order.is_archived', $this->json()['rule']);
        self::assertSame('This sales order is archived, so it can\'t be changed.', $this->json()['detail']);
    }

    /**
     * All three columns behind these fields are `NOT NULL`, so a `PATCH` may not clear any of them —
     * and `ship_to_address_id`, which is not a column at all, has nothing to clear the snapshot to:
     * `ship_to_country_code` is `NOT NULL` with no default and a foreign key onto `country`.
     */
    #[Test]
    public function noAcceptedFieldMayBeCleared(): void
    {
        $c = $this->workspace('NULL');

        $this->send('PATCH', '/sales-orders/'.$c['order'], ['customer_id' => null, 'warehouse_id' => null, 'ship_to_address_id' => null]);

        self::assertSame(422, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        self::assertSame(
            ['/customer_id', '/warehouse_id', '/ship_to_address_id'],
            array_column($this->json()['errors'], 'pointer'),
        );
        self::assertSame(['not_nullable', 'not_nullable', 'not_nullable'], array_column($this->json()['errors'], 'code'));
    }

    /**
     * An unresolvable parent is a named field defect, never a bare foreign-key failure — ST-5 applied to
     * the two tenant-scoped parents, as `CreateSalesOrder` applies it.
     */
    #[Test]
    public function anUnresolvableParentIsRefusedAtTheFieldThatNamesIt(): void
    {
        $c = $this->workspace('REF');

        $this->send('PATCH', '/sales-orders/'.$c['order'], ['warehouse_id' => Uuid::v4()->toRfc4122()]);

        self::assertSame(422, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        self::assertSame([['pointer' => '/warehouse_id', 'code' => 'unknown_reference']], array_map(
            static fn (array $error): array => ['pointer' => $error['pointer'], 'code' => $error['code']],
            $this->json()['errors'],
        ));
    }

    /**
     * §5.3 clause 2: a `PATCH` at an id no row answers is the same `not-found` `GetSalesOrder` returns,
     * which is what the shared `SalesOrderProvider` on the operation buys.
     */
    #[Test]
    public function anIdNoRowAnswersIsNotFound(): void
    {
        $this->workspace('MISS');

        $this->send('PATCH', '/sales-orders/'.Uuid::v4()->toRfc4122(), ['warehouse_id' => Uuid::v4()->toRfc4122()]);

        self::assertSame(404, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
    }

    // -----------------------------------------------------------------------

    /**
     * A draft sales order with a customer that has one shipping address, a warehouse and one line.
     *
     * @return array{order: string, customer: string, warehouse: string}
     */
    private function workspace(string $tag): array
    {
        $customer = $this->seedCustomer(self::TENANT_A, 'Customer '.$tag);
        $this->seedCustomerAddress(self::TENANT_A, $customer);
        $warehouse = $this->seedWarehouse(self::TENANT_A, 'WH '.$tag);
        $order = $this->seedSalesOrder(self::TENANT_A, self::freshNumber('SO'), $customer, $warehouse, SalesOrder::STATUS_DRAFT);
        $this->seedSalesOrderLine(self::TENANT_A, $order, $this->seedItem(self::TENANT_A, 'SKU-'.$tag));

        return ['order' => $order, 'customer' => $customer, 'warehouse' => $warehouse];
    }

    /** @return array<string, mixed> */
    private function shipTo(string $order): array
    {
        return $this->fetchAsOwnerForTenant(self::TENANT_A, <<<'SQL'
            SELECT ship_to_line, ship_to_city, ship_to_postal_code, ship_to_country_code
              FROM sales_order WHERE id = ?::uuid
            SQL, [$order])[0];
    }

    private function customerOf(string $order): string
    {
        return (string) $this->fetchAsOwnerForTenant(self::TENANT_A,
            'SELECT customer_id FROM sales_order WHERE id = ?::uuid', [$order])[0]['customer_id'];
    }
}
