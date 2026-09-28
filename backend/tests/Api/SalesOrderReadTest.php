<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Modules\Sales\Infrastructure\Entity\SalesOrder;
use App\Platform\Http\Idempotency\IdempotencyKeyGuard;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Uid\Uuid;

/**
 * **Output 9, sales slice 1, commit 3 — `GetSalesOrder` over HTTP.**
 *
 * §4.4.3's declared field list for `SalesOrder`, §5.3's refusals, §5.2a's carve-out, and the two
 * disclosure rulings this read is the first to apply: **B1N-2** (`created_by` ships, PC-11 (4a) ground,
 * O-4 controlling) and **O-1 KEEP-AND-EXCEPT** (`ship_to_address_id` is a snapshot source and derives the
 * four `ship_to_*` fields, not a `ship_to_address` reference).
 *
 * ⚠ **It extends `PurchaseOrderTestCase` for `SalesOrderCreateTest`'s reason** — the customer, address,
 * warehouse and item fixtures live there because `OperationSuccessRecipes` is
 * `@phpstan-require-extends PurchaseOrderTestCase` and `CreateSalesOrder`'s recipe has to live inside that
 * trait (RED F-12). A sales rig of its own would give the two paths two sets of fixtures and nothing to
 * keep them equal.
 */
final class SalesOrderReadTest extends PurchaseOrderTestCase
{
    /**
     * **§4.4.3's derivation for `SalesOrder`, asserted as an exact key set.**
     *
     * Steps 1–3 give `customer`, `warehouse`, `currency_code`, `id` and `archived_at`; the additions row
     * gives `order_number`, `status`, `total_amount`, `ordered_at`, `created_by` and the four snapshot
     * fields. **`ship_to_address` is absent and `lines` is absent, and each by a different named
     * exception to step 1** — the snapshot-source exception (owner ruling O-1) and the sub-list exception
     * (owner ruling Q1(a)). *"And nothing else"* is why this is `assertSame` on `array_keys`: a field
     * that is neither derivable nor listed is the defect, and only an exact comparison finds it.
     */
    #[Test]
    public function theResourceCarriesExactlyItsDerivedFieldList(): void
    {
        $order = $this->raise();

        $this->send('GET', '/sales-orders/'.$order);

        self::assertSame(200, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        self::assertSame([
            'id', 'customer', 'warehouse', 'currency_code', 'order_number', 'status', 'total_amount',
            'ordered_at', 'created_by', 'ship_to_line', 'ship_to_city', 'ship_to_postal_code',
            'ship_to_country_code', 'archived_at',
        ], array_keys($this->json()));
    }

    /**
     * **The read and the create produce byte-identical bodies.** `SalesOrderRow` is the one place a row
     * becomes a `SalesOrderResource`, and this is what says so: a create that built its answer from the
     * values it was handed would disagree with a read the first time the database applied a default, and
     * `status` and `total_amount` both have one.
     */
    #[Test]
    public function theReadAnswersExactlyWhatTheCreateAnswered(): void
    {
        $c = $this->workspace();
        $this->send('POST', '/sales-orders', $this->body($c), [IdempotencyKeyGuard::HEADER => Uuid::v4()->toRfc4122()]);
        self::assertSame(201, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        $created = (string) $this->client()->getResponse()->getContent();

        $this->send('GET', (string) $this->client()->getResponse()->headers->get('Location'));

        self::assertSame(200, $this->httpStatus());
        self::assertSame($created, (string) $this->client()->getResponse()->getContent());
    }

    /**
     * **B1N-2, ruled 2026-09-19 — `created_by` is `{id, display_name}` and nothing else**, gated on
     * `sales_order.view` alone.
     *
     * §4.4.3 names this expansion's display field explicitly, and it is not `name`: `app_user` has no such
     * column. PC-15 condition 2 is what keeps `email` out of it — that is `app_user.view`'s to disclose,
     * and the caller here holds only the document's code.
     *
     * ⚠ **This field serves no separation-of-duty control on this resource**, and the purchasing mirror's
     * DSoD-1 justification must not be copied onto it: `SEC-O-9` verified the column records *"the
     * creator, not the confirmer"*, ADR-008 names no DSoD rule on any sales document, and IA-O-8 states
     * that F-5 *"has no mirror of this gap … there is no seam"*. It is disclosed because it is the
     * document's own business state (PC-11 (4a)) and because **no audit event records who RAISED a sales
     * order**, so with it withheld nothing on the API answers who raised the order.
     *
     * ⚠ **That clause read *"`AuditEvent::ALL` has no `sales_order.*` member"* until 2026-09-19**, when
     * `CancelSalesOrder` minted `SALES_ORDER_CANCELLED` — which names the **canceller**, not the creator
     * (SEC-O-9), so the reasoning survives narrowed. Re-derive `AuditEvent::ALL` before quoting it.
     */
    #[Test]
    public function theCreatorIsExpandedAsIdAndDisplayNameUnderTheDocumentsOwnPermission(): void
    {
        $order = $this->raise();

        $this->actAs(self::TENANT_A, ['sales_order.create', 'sales_order.view']);
        $this->send('GET', '/sales-orders/'.$order);

        self::assertSame(200, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        $body = $this->json();

        self::assertSame(['id', 'display_name'], array_keys($body['created_by']));
        self::assertSame('Fixture Actor', $body['created_by']['display_name']);
        self::assertSame(['id', 'name'], array_keys($body['customer']));
        self::assertSame(['id', 'name'], array_keys($body['warehouse']));

        // No member of any response, at any depth, is named `tenant_id` (API-6, P-6, T-1).
        self::assertSame([], ItemReadTest::tenantKeysIn($body));
    }

    /**
     * `created_by` is **null** on an order with no human author, and null is a real value rendered as
     * unknown rather than as a guess. `sales_order.created_by_user_id` is nullable, which is why the join
     * in `SalesOrderRow::FROM` is `LEFT` — an inner join here would make such an order unreadable rather
     * than anonymous, which is a 404 for a row that exists.
     *
     * *Mutation, run:* `LEFT JOIN app_user` → `JOIN app_user` in `SalesOrderRow::FROM`.
     */
    #[Test]
    public function anOrderWithNoHumanAuthorRendersANullCreator(): void
    {
        $order = $this->seedOrderRow(self::TENANT_A, 'SO-176000');

        $this->send('GET', '/sales-orders/'.$order);

        self::assertSame(200, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        self::assertNull($this->json()['created_by']);
    }

    /**
     * **O-1 KEEP-AND-EXCEPT, rendered.** The response carries the four snapshot fields and **no**
     * `ship_to_address` reference: the order stores no address id, so there is nothing to expand, and
     * `customer_address` has no `name`, `code`, `sku` or document number to expand it with (V2-C-14(a),
     * V2-R-10). The values are pinned and not only the keys, because the whole point of a snapshot is
     * that it is a record of what was copied.
     */
    #[Test]
    public function theShippingSnapshotIsReadBackFieldForFieldAndNoAddressReferenceIsReturned(): void
    {
        $order = $this->raise();

        $this->send('GET', '/sales-orders/'.$order);

        $body = $this->json();
        self::assertArrayNotHasKey('ship_to_address', $body);
        self::assertArrayNotHasKey('ship_to_address_id', $body);
        self::assertSame([
            'ship_to_line' => '9 Threadneedle Street',
            'ship_to_city' => 'London',
            'ship_to_postal_code' => 'EC2R 8AH',
            'ship_to_country_code' => 'GB',
        ], array_intersect_key($body, array_flip(['ship_to_line', 'ship_to_city', 'ship_to_postal_code', 'ship_to_country_code'])));
    }

    /**
     * §5.3 clause 2 — a row RLS does not return is `not-found`, never `permission-denied`, and all three
     * of "another tenant's", "never existed" and "not a uuid at all" answer **identically**. A 403 on a
     * cross-tenant id confirms the id exists somewhere (ST-5).
     */
    #[Test]
    public function everyInvisibleIdGetsTheSameNotFound(): void
    {
        $orderInB = $this->seedOrderRow(self::TENANT_B, 'SO-132000');

        $bodies = [];
        foreach ([$orderInB, Uuid::v4()->toRfc4122(), 'not-a-uuid'] as $id) {
            $this->send('GET', '/sales-orders/'.$id);
            self::assertSame(404, $this->httpStatus(), $id.' did not answer 404.');
            $bodies[] = (string) $this->client()->getResponse()->getContent();
        }

        self::assertSame([$bodies[0], $bodies[0], $bodies[0]], $bodies);
    }

    /**
     * **API-13a / §5.2a — a direct hit on a soft-deleted row inside the caller's own tenant returns 200
     * with a non-null `archived_at`, never 404.** ST-9: *"documents legitimately reference it, which is
     * the entire reason it is soft-deleted"* — here a fulfilment or an invoice points at the order.
     *
     * *Mutation, run:* `AND so.deleted_at IS NULL` added to `SalesOrderReader::get()`'s statement.
     */
    #[Test]
    public function anArchivedOrderIsReadableByDirectHit(): void
    {
        $order = $this->seedOrderRow(self::TENANT_A, 'SO-161000', archived: true);

        $this->send('GET', '/sales-orders/'.$order);

        self::assertSame(200, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        self::assertNotNull($this->json()['archived_at']);
        self::assertSame(SalesOrder::STATUS_DRAFT, $this->json()['status']);
    }

    // -----------------------------------------------------------------------

    /** @return array{customer: string, address: string, warehouse: string, item: string} */
    private function workspace(): array
    {
        $customer = $this->seedCustomer(self::TENANT_A, 'Acme Retail');

        return [
            'customer' => $customer,
            'address' => $this->seedCustomerAddress(self::TENANT_A, $customer),
            'warehouse' => $this->seedWarehouse(self::TENANT_A, 'WH SO-189000'),
            'item' => $this->seedItem(self::TENANT_A, 'SKU-SO-189000'),
        ];
    }

    /**
     * @param array{customer: string, address: string, warehouse: string, item: string} $c
     *
     * @return array<string, mixed>
     */
    private function body(array $c): array
    {
        return [
            'customer_id' => $c['customer'],
            'warehouse_id' => $c['warehouse'],
            'currency_code' => 'EUR',
            'ship_to_address_id' => $c['address'],
            'lines' => [['inventory_item_id' => $c['item'], 'quantity' => '2', 'unit_price' => '3.5']],
        ];
    }

    /**
     * An order raised **through the API**, so `created_by_user_id` is written by the operation rather than
     * by a fixture standing in for one.
     */
    private function raise(): string
    {
        $this->send('POST', '/sales-orders', $this->body($this->workspace()), [IdempotencyKeyGuard::HEADER => Uuid::v4()->toRfc4122()]);
        self::assertSame(201, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());

        return (string) $this->json()['id'];
    }

    /**
     * The rig's `seedSalesOrder` plus the one thing it deliberately does not do — archive the row. The
     * fixture lives on `PurchaseOrderTestCase` because three registry-wide checks reach for it; the
     * archive step is this file's, because only this file needs §5.2a's subject.
     */
    private function seedOrderRow(string $tenantId, string $number, bool $archived = false): string
    {
        $id = $this->seedSalesOrder($tenantId, $number);

        if ($archived) {
            $this->insertAsOwnerForTenant($tenantId, 'UPDATE sales_order SET deleted_at = now() WHERE id = ?::uuid', [$id]);
        }

        return $id;
    }
}
