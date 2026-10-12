<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Modules\UserManagement\Domain\DefaultRoles;
use App\Platform\Http\Idempotency\IdempotencyKeyGuard;
use App\SharedKernel\ValueObject\AuditEvent;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Uid\Uuid;

/**
 * **Customers slice 1 C3 — `CreateCustomerAddress`, `UpdateCustomerAddress`** (`docs/session13/customers-s1-design.md` §5 and
 * its FINAL C3 block; copy `customers-s1-ux.md` §2 C-4, C-5, F-3, F-4, F-7, F-8).
 *
 * Each refusal's literal sentence is pinned once here. Every postal code is LETTER-bearing (CD-3: `WireScaleTest` reads an
 * all-digit text value as a decimal).
 *
 * The create's order is the purchase-order-line precedent: parent 404 → **key** (replay or conflict) → archived 409 →
 * insert, so a retry of a create that succeeded is handed its row even after the customer was archived (design §5.1 as
 * revised per RED L-1). The snapshot test is design §5.2: correcting an address moves no order, draft or confirmed.
 *
 * **The trail** (suppliers slice 1 C2b, 2026-10-12; owner ruling F-12 / S4 "Record address changes",
 * `docs/session13/suppliers-s1-ruling.md`): one `customer_address.created` per INSERT (never on a replay) and one
 * `customer_address.updated` per CHANGING update, each read back as the owner cell by cell — event, actor, label, subject
 * pair `('customer_address', id)`, no IP, no permission code, the exact `metadata` — and no row on any refusal or on a
 * no-op. Names, never values: an address is personal data. The race test is a real second connection holding the row.
 */
final class CustomerAddressWriteTest extends PurchaseOrderTestCase
{
    private const HOLDER_DEADLINE_SECONDS = 10.0;

    private const BODY = ['kind' => 'shipping', 'line' => '9 Threadneedle Street', 'city' => 'London', 'postal_code' => 'EC2R 8AH', 'country_code' => 'GB'];

    /** 201, the address as the body, `Location` naming the row-level URI §4.2 declares (no `GetCustomerAddress`). */
    #[Test]
    public function aSalesRepAddsAnAddressToACustomer(): void
    {
        $customer = $this->seedCustomer(self::TENANT_A, 'Address Owner');
        $this->actAs(self::TENANT_A, DefaultRoles::codesFor(DefaultRoles::SALES_REP));

        $this->create($customer, self::BODY);

        self::assertSame(201, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        $body = $this->json();
        self::assertTrue(Uuid::isValid($body['id']));
        self::assertSame(['id' => $body['id']] + self::BODY, $body);
        self::assertSame('/api/v1/customers/'.$customer.'/addresses/'.$body['id'], $this->client()->getResponse()->headers->get('Location'));

        $stored = $this->fetchAsOwnerForTenant(self::TENANT_A,
            'SELECT customer_id::text AS c, address_line, region, idempotency_key ~ \'^[0-9a-f]{64}$\' AS hashed FROM customer_address WHERE id = ?::uuid', [$body['id']])[0];
        self::assertSame(['c' => $customer, 'address_line' => '9 Threadneedle Street', 'region' => '', 'hashed' => true], $stored,
            '`line` is stored in address_line, region takes its default, and the key is stored hashed.');
    }

    /** All five fields are required; blanks, an unknown kind and a bad or unsupported country are refused field by field. */
    #[Test]
    public function aMalformedAddressIsRefusedFieldByField(): void
    {
        $customer = $this->seedCustomer(self::TENANT_A, 'Strict');

        $this->create($customer, []);
        self::assertSame(422, $this->httpStatus());
        self::assertSame(array_map(static fn (string $f): array => ['pointer' => '/'.$f, 'code' => 'required', 'message' => 'This field is required.'],
            ['kind', 'line', 'city', 'postal_code', 'country_code']), $this->fieldErrors());

        $this->create($customer, ['kind' => 'warehouse', 'line' => ' ', 'city' => '', 'postal_code' => '  ', 'country_code' => 'gb']);
        self::assertSame([
            ['pointer' => '/kind', 'code' => 'not_an_address_kind', 'message' => 'An address is either a shipping address or a billing address.'],
            ['pointer' => '/line', 'code' => 'blank', 'message' => 'This field may not be blank.'],
            ['pointer' => '/city', 'code' => 'blank', 'message' => 'This field may not be blank.'],
            ['pointer' => '/postal_code', 'code' => 'blank', 'message' => 'This field may not be blank.'],
            ['pointer' => '/country_code', 'code' => 'not_a_country_code', 'message' => 'Use the two-letter country code, for example GB.'],
        ], $this->fieldErrors());

        $this->create($customer, ['country_code' => 'FR'] + self::BODY);
        self::assertSame([['pointer' => '/country_code', 'code' => 'unknown_reference', 'message' => 'This country isn\'t supported yet.']], $this->fieldErrors());

        self::assertSame(0, $this->countAsOwnerForTenant(self::TENANT_A, 'SELECT count(*) FROM customer_address'));
    }

    /** Both kinds are accepted; a billing address is a real row too. Values are stored as sent (no trim, final RED L-2). */
    #[Test]
    public function aBillingAddressIsAcceptedAndValuesAreStoredAsSent(): void
    {
        $customer = $this->seedCustomer(self::TENANT_A, 'Billed');

        $this->create($customer, ['kind' => 'billing', 'city' => ' Leeds '] + self::BODY);

        self::assertSame(201, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        self::assertSame(['billing', ' Leeds '], [$this->json()['kind'], $this->json()['city']]);
    }

    /** ST-5: an unknown, malformed or another workspace's customer is the same 404, and nothing is written. */
    #[Test]
    public function anAddressForAnUnknownCustomerIsTheSame404(): void
    {
        $bodies = [];
        foreach ([$this->seedCustomer(self::TENANT_B, 'Theirs'), Uuid::v4()->toRfc4122(), 'not-a-uuid'] as $customer) {
            $this->create($customer, self::BODY);
            self::assertSame(404, $this->httpStatus(), $customer);
            $bodies[] = (string) $this->client()->getResponse()->getContent();
        }
        self::assertSame($bodies[0], $bodies[1]);
        self::assertSame(0, $this->countAsOwnerForTenant(self::TENANT_B, 'SELECT count(*) FROM customer_address'));
    }

    /** ST-9: an archived customer gets no new address (C-4: no "Restore it" while `RestoreCustomer` is blocked, R-3). */
    #[Test]
    public function anArchivedCustomerGetsNoNewAddress(): void
    {
        $customer = $this->archivedCustomer('Closed For Addresses');

        $this->create($customer, self::BODY);

        self::assertSame(409, $this->httpStatus());
        self::assertSame('customer.is_archived', $this->json()['rule']);
        self::assertSame('This customer is archived, so no address can be added to it.', $this->json()['detail']);
        self::assertSame(0, $this->countAsOwnerForTenant(self::TENANT_A, 'SELECT count(*) FROM customer_address WHERE customer_id = ?::uuid', [$customer]));
    }

    /** The same key and body replay the stored address (same id, one row); the same key with another body is 409. */
    #[Test]
    public function aRetryReplaysAndAnotherBodyUnderTheKeyConflicts(): void
    {
        $customer = $this->seedCustomer(self::TENANT_A, 'Retried');
        $key = Uuid::v4()->toRfc4122();

        $this->create($customer, self::BODY, $key);
        self::assertSame(201, $this->httpStatus());
        $first = $this->json();

        $this->create($customer, self::BODY, $key);
        self::assertSame(201, $this->httpStatus());
        self::assertSame($first, $this->json(), 'A retry was not handed the stored address.');

        $this->create($customer, ['city' => 'Bath'] + self::BODY, $key);
        self::assertSame(409, $this->httpStatus(), 'A different body under a used key was not refused.');
        self::assertSame('urn:erp:problem:idempotency-key-conflict', $this->json()['type']);

        $this->send('POST', '/customers/'.$customer.'/addresses', self::BODY);
        self::assertSame(428, $this->httpStatus(), 'The header is demanded.');

        self::assertSame(1, $this->countAsOwnerForTenant(self::TENANT_A, 'SELECT count(*) FROM customer_address WHERE customer_id = ?::uuid', [$customer]));
    }

    /**
     * The digest carries the PATH's customer id (design mutation C3-5): the same key and body sent to another customer is
     * a conflict, never a replay of the first customer's address.
     */
    #[Test]
    public function theSameKeyOnAnotherCustomerConflicts(): void
    {
        $first = $this->seedCustomer(self::TENANT_A, 'First Keyed');
        $second = $this->seedCustomer(self::TENANT_A, 'Second Keyed');
        $key = Uuid::v4()->toRfc4122();

        $this->create($first, self::BODY, $key);
        self::assertSame(201, $this->httpStatus());

        $this->create($second, self::BODY, $key);
        self::assertSame(409, $this->httpStatus(), 'The same key on another customer was replayed or created, not refused.');
        self::assertSame('urn:erp:problem:idempotency-key-conflict', $this->json()['type']);
        self::assertSame(0, $this->countAsOwnerForTenant(self::TENANT_A, 'SELECT count(*) FROM customer_address WHERE customer_id = ?::uuid', [$second]));
    }

    /**
     * The key is read BEFORE the archived guard (RED L-1; design mutation C3-9): a stored key on a since-archived customer
     * replays 201, and a NEW key on the same customer is the archived 409.
     */
    #[Test]
    public function aRetryAfterTheCustomerWasArchivedStillReplays(): void
    {
        $customer = $this->seedCustomer(self::TENANT_A, 'Archived After');
        $key = Uuid::v4()->toRfc4122();
        $this->create($customer, self::BODY, $key);
        self::assertSame(201, $this->httpStatus());
        $first = $this->json();

        $this->insertAsOwnerForTenant(self::TENANT_A, 'UPDATE customer SET deleted_at = now() WHERE id = ?::uuid', [$customer]);

        $this->create($customer, self::BODY, $key);
        self::assertSame(201, $this->httpStatus(), 'A retry of a create that succeeded was refused because the customer was archived since.');
        self::assertSame($first, $this->json());

        $this->create($customer, self::BODY);
        self::assertSame(409, $this->httpStatus());
        self::assertSame('customer.is_archived', $this->json()['rule']);
    }

    /** Each field is editable; the rest stay as they were; an empty PATCH is legal. */
    #[Test]
    public function anAddressIsCorrectedFieldByField(): void
    {
        $customer = $this->seedCustomer(self::TENANT_A, 'Moving');
        $address = $this->seedCustomerAddress(self::TENANT_A, $customer);
        $this->actAs(self::TENANT_A, DefaultRoles::codesFor(DefaultRoles::SALES_REP));

        $this->update($customer, $address, ['line' => '1 New Street', 'city' => 'Manchester', 'postal_code' => 'M3 2BW']);
        self::assertSame(200, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        self::assertSame(['id' => $address, 'kind' => 'shipping', 'line' => '1 New Street', 'city' => 'Manchester', 'postal_code' => 'M3 2BW',
            'country_code' => 'GB'], $this->json());

        $this->update($customer, $address, ['kind' => 'billing', 'country_code' => 'DE']);
        self::assertSame(['billing', 'DE', 'Manchester'], [$this->json()['kind'], $this->json()['country_code'], $this->json()['city']]);

        $this->update($customer, $address, []);
        self::assertSame(200, $this->httpStatus(), 'An empty PATCH is legal: nothing is written, the row is read back.');
    }

    /** Explicit `null` clears nothing; blanks, kinds and countries are refused as on create. */
    #[Test]
    public function noAddressFieldCanBeCleared(): void
    {
        $customer = $this->seedCustomer(self::TENANT_A, 'Kept Address');
        $address = $this->seedCustomerAddress(self::TENANT_A, $customer);

        $this->update($customer, $address, ['kind' => null, 'line' => null, 'city' => null, 'postal_code' => null, 'country_code' => null]);
        self::assertSame(422, $this->httpStatus());
        self::assertSame(array_map(static fn (string $f): array => ['pointer' => '/'.$f, 'code' => 'not_nullable', 'message' => 'This field may not be cleared.'],
            ['kind', 'line', 'city', 'postal_code', 'country_code']), $this->fieldErrors());

        $this->update($customer, $address, ['kind' => 'warehouse', 'city' => ' ']);
        self::assertSame([
            ['pointer' => '/kind', 'code' => 'not_an_address_kind', 'message' => 'An address is either a shipping address or a billing address.'],
            ['pointer' => '/city', 'code' => 'blank', 'message' => 'This field may not be blank.'],
        ], $this->fieldErrors());

        $this->update($customer, $address, ['country_code' => 'FR']);
        self::assertSame([['pointer' => '/country_code', 'code' => 'unknown_reference', 'message' => 'This country isn\'t supported yet.']], $this->fieldErrors());

        self::assertSame('London', $this->fetchAsOwnerForTenant(self::TENANT_A, 'SELECT city FROM customer_address WHERE id = ?::uuid', [$address])[0]['city']);
    }

    /**
     * ST-5: another customer's address under this customer's path is the same 404 as a random id (design mutation C3-1),
     * and so is an address under a foreign or unknown customer. Nothing is written.
     */
    #[Test]
    public function anotherCustomersAddressIsNotFoundUnderThisPath(): void
    {
        $mine = $this->seedCustomer(self::TENANT_A, 'Mine');
        $other = $this->seedCustomer(self::TENANT_A, 'Other');
        $theirs = $this->seedCustomerAddress(self::TENANT_A, $other);
        $foreignCustomer = $this->seedCustomer(self::TENANT_B, 'Foreign');
        $foreignAddress = $this->seedCustomerAddress(self::TENANT_B, $foreignCustomer);

        $bodies = [];
        foreach ([[$mine, $theirs], [$mine, Uuid::v4()->toRfc4122()], [$foreignCustomer, $foreignAddress], [$mine, 'not-a-uuid']] as [$customer, $address]) {
            $this->update($customer, $address, ['city' => 'Hijacked']);
            self::assertSame(404, $this->httpStatus(), $customer.' / '.$address);
            $bodies[] = (string) $this->client()->getResponse()->getContent();
        }
        self::assertSame($bodies[0], $bodies[1], 'Another customer\'s address is distinguishable from a random id.');
        self::assertSame('London', $this->fetchAsOwnerForTenant(self::TENANT_A, 'SELECT city FROM customer_address WHERE id = ?::uuid', [$theirs])[0]['city']);
        self::assertSame('London', $this->fetchAsOwnerForTenant(self::TENANT_B, 'SELECT city FROM customer_address WHERE id = ?::uuid', [$foreignAddress])[0]['city']);
    }

    /** ST-9: an archived customer's addresses are read-only (C-5; design mutation C3-8). */
    #[Test]
    public function anArchivedCustomersAddressCannotBeChanged(): void
    {
        $customer = $this->seedCustomer(self::TENANT_A, 'Archived With Address');
        $address = $this->seedCustomerAddress(self::TENANT_A, $customer);
        $this->insertAsOwnerForTenant(self::TENANT_A, 'UPDATE customer SET deleted_at = now() WHERE id = ?::uuid', [$customer]);

        $this->update($customer, $address, ['city' => 'Reopened']);

        self::assertSame(409, $this->httpStatus());
        self::assertSame('customer.is_archived', $this->json()['rule']);
        self::assertSame('This customer is archived, so its addresses can\'t be changed.', $this->json()['detail']);
        self::assertSame('London', $this->fetchAsOwnerForTenant(self::TENANT_A, 'SELECT city FROM customer_address WHERE id = ?::uuid', [$address])[0]['city']);
    }

    /** Permissions: both writes need `customer.edit` (NOT `.create`); `viewer` holds neither. */
    #[Test]
    public function aViewerCanNeitherAddNorCorrectAnAddress(): void
    {
        $customer = $this->seedCustomer(self::TENANT_A, 'Watched');
        $address = $this->seedCustomerAddress(self::TENANT_A, $customer);
        $this->actAs(self::TENANT_A, DefaultRoles::codesFor(DefaultRoles::VIEWER));

        $this->create($customer, self::BODY);
        self::assertSame(403, $this->httpStatus());
        self::assertSame('customer.edit', $this->json()['required_permission'] ?? null);
        $this->update($customer, $address, ['city' => 'Touched']);
        self::assertSame(403, $this->httpStatus());

        $this->actAs(self::TENANT_A, ['customer.create', 'customer.view']);
        $this->create($customer, self::BODY);
        self::assertSame(403, $this->httpStatus(), '`customer.create` alone must not add an address: §4.2 gates it on `customer.edit`.');
    }

    /**
     * **Design §5.2: correcting an address moves no order.** A draft and a confirmed order ship to address A (the draft's
     * snapshot taken by `CreateSalesOrder`); every field of A is changed and its kind flipped to billing → both orders'
     * `ship_to_*` columns, `GetSalesOrder` bodies and the confirmed order's `GetPickList` are byte-identical. A later
     * `UpdateSalesOrder` naming A (now billing) is the shipped `unknown_reference` refusal.
     */
    #[Test]
    public function correctingAnAddressMovesNoOrder(): void
    {
        $customer = $this->seedCustomer(self::TENANT_A, 'Snapshot Customer');
        $address = $this->seedCustomerAddress(self::TENANT_A, $customer, 'shipping', '1 Old Road', 'York', 'YO1 1AA', 'GB');
        $warehouse = $this->seedWarehouse(self::TENANT_A, 'Snapshot WH');
        $item = $this->seedItem(self::TENANT_A, 'SKU-SNAP-1');

        $orders = [];
        foreach (['draft', 'confirmed'] as $label) {
            $this->send('POST', '/sales-orders', [
                'customer_id' => $customer, 'warehouse_id' => $warehouse, 'ship_to_address_id' => $address,
                'lines' => [['inventory_item_id' => $item, 'quantity' => '1', 'unit_price' => '5']],
            ], [IdempotencyKeyGuard::HEADER => Uuid::v4()->toRfc4122()]);
            self::assertSame(201, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
            $orders[$label] = $this->json()['id'];
        }
        $this->insertAsOwnerForTenant(self::TENANT_A, "UPDATE sales_order SET status = 'confirmed', ordered_at = now() WHERE id = ?::uuid", [$orders['confirmed']]);

        $before = $this->ordersAsSeen($orders);
        self::assertSame(['1 Old Road', 'York', 'YO1 1AA', 'GB'], array_values($before['columns']['draft']), 'The draft did not snapshot address A.');

        $this->update($customer, $address, ['kind' => 'billing', 'line' => '2 New Road', 'city' => 'Leeds', 'postal_code' => 'LS1 1AA', 'country_code' => 'DE']);
        self::assertSame(200, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());

        self::assertSame($before, $this->ordersAsSeen($orders), 'Correcting an address changed an order that ships to it.');

        $this->send('PATCH', '/sales-orders/'.$orders['draft'], ['ship_to_address_id' => $address]);
        self::assertSame(422, $this->httpStatus(), 'A billing address was accepted as a ship-to.');
        self::assertSame('unknown_reference', $this->json()['errors'][0]['code']);
    }

    /** C2b.1 — one `customer_address.created`, `{}`, naming the creator; the subject is the new address. */
    #[Test]
    public function aCreatedAddressWritesExactlyOneCustomerAddressCreatedRow(): void
    {
        $customer = $this->seedCustomer(self::TENANT_A, 'Audited Address');
        $this->actAs(self::TENANT_A, DefaultRoles::codesFor(DefaultRoles::SALES_REP));

        $this->create($customer, self::BODY);

        self::assertSame(201, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        self::assertSame([$this->businessRow(AuditEvent::CUSTOMER_ADDRESS_CREATED, 'customer_address', $this->json()['id'])],
            $this->auditRowsOf(AuditEvent::CUSTOMER_ADDRESS_CREATED));
        self::assertSame([], $this->auditRowsOf(AuditEvent::CUSTOMER_ADDRESS_UPDATED), 'A create wrote an update row.');
        self::assertSame([], $this->auditRowsOf(AuditEvent::CUSTOMER_UPDATED), 'An address create wrote a customer.updated row.');
    }

    /**
     * C2b.2 — a replay inserts nothing, so it writes nothing; a key conflict (409), an archived customer (409), a malformed
     * body (422), an unsupported country (422) and an unknown customer (404) write no row either.
     */
    #[Test]
    public function aReplayedOrRefusedCreateWritesNoRow(): void
    {
        $customer = $this->seedCustomer(self::TENANT_A, 'Replayed Address');
        $key = Uuid::v4()->toRfc4122();

        $this->create($customer, self::BODY, $key);
        self::assertSame(201, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        self::assertCount(1, $this->auditRowsOf(AuditEvent::CUSTOMER_ADDRESS_CREATED));

        $this->create($customer, self::BODY, $key);
        self::assertSame(201, $this->httpStatus(), 'Fixture: the same key and body must replay.');
        $this->create($customer, ['city' => 'Leeds'] + self::BODY, $key);
        self::assertSame(409, $this->httpStatus());
        $this->create($this->archivedCustomer('Archived For Trail'), self::BODY);
        self::assertSame(409, $this->httpStatus());
        $this->create($customer, ['city' => ' '] + self::BODY);
        self::assertSame(422, $this->httpStatus());
        $this->create($customer, ['country_code' => 'FR'] + self::BODY);
        self::assertSame(422, $this->httpStatus());
        $this->create(Uuid::v4()->toRfc4122(), self::BODY);
        self::assertSame(404, $this->httpStatus());

        self::assertCount(1, $this->auditRowsOf(AuditEvent::CUSTOMER_ADDRESS_CREATED), 'A replayed or refused create wrote a customer_address.created row.');
    }

    /** C2b.3 — all five submitted, one changed: the row names that one field; a second case names two, sorted. */
    #[Test]
    public function anAddressUpdateWritesOneRowNamingOnlyTheChangedFields(): void
    {
        $customer = $this->seedCustomer(self::TENANT_A, 'Stable Address');
        $address = $this->seedCustomerAddress(self::TENANT_A, $customer);
        $stored = $this->storedAddress($address);
        $this->actAs(self::TENANT_A, DefaultRoles::codesFor(DefaultRoles::SALES_REP));

        $this->update($customer, $address, ['city' => 'Manchester'] + $stored);
        self::assertSame(200, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        self::assertSame([$this->addressUpdatedRow($address, ['city'])], $this->auditRowsOf(AuditEvent::CUSTOMER_ADDRESS_UPDATED));

        $this->update($customer, $address, ['postal_code' => 'M3 2BW', 'line' => '1 New Street', 'kind' => 'shipping']);
        self::assertSame(200, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        self::assertSame([$this->addressUpdatedRow($address, ['city']), $this->addressUpdatedRow($address, ['line', 'postal_code'])],
            $this->auditRowsOf(AuditEvent::CUSTOMER_ADDRESS_UPDATED));
        self::assertSame([], $this->auditRowsOf(AuditEvent::CUSTOMER_ADDRESS_CREATED), 'An update wrote a create row.');
    }

    /**
     * C2b.4 — all five submitted equal to the stored values, and an empty PATCH: no row, no `UPDATE customer_address`
     * statement, `updated_at` unmoved, the unchanged row answered.
     */
    #[Test]
    public function aNoOpAddressUpdateWritesNoRowAndNoStatement(): void
    {
        $customer = $this->seedCustomer(self::TENANT_A, 'Unchanged Address');
        $address = $this->seedCustomerAddress(self::TENANT_A, $customer);
        $stored = $this->storedAddress($address);
        $before = $this->fetchAsOwnerForTenant(self::TENANT_A, 'SELECT updated_at::text AS updated_at FROM customer_address WHERE id = ?::uuid', [$address]);

        foreach ([$stored, []] as $body) {
            $this->recorder()->start();
            $this->update($customer, $address, $body);
            $this->recorder()->stop();

            self::assertSame(200, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
            self::assertSame(['id' => $address] + $stored, $this->json(), 'A no-op update must answer the unchanged row.');
            self::assertSame([], $this->recorder()->matching('/\bUPDATE\s+customer_address\b/i'), 'A no-op update issued an UPDATE.');
        }
        self::assertSame([], $this->auditRowsOf(AuditEvent::CUSTOMER_ADDRESS_UPDATED), 'A no-op update wrote a customer_address.updated row.');
        self::assertSame($before, $this->fetchAsOwnerForTenant(self::TENANT_A, 'SELECT updated_at::text AS updated_at FROM customer_address WHERE id = ?::uuid', [$address]));
    }

    /** C2b.5 — another customer's address (404), an archived customer (409), a malformed body and a bad country (422) write no row. */
    #[Test]
    public function aRefusedAddressUpdateWritesNoRow(): void
    {
        $mine = $this->seedCustomer(self::TENANT_A, 'Refused Mine');
        $address = $this->seedCustomerAddress(self::TENANT_A, $mine);
        $other = $this->seedCustomer(self::TENANT_A, 'Refused Other');
        $archived = $this->seedCustomer(self::TENANT_A, 'Refused Archived');
        $archivedAddress = $this->seedCustomerAddress(self::TENANT_A, $archived);
        $this->insertAsOwnerForTenant(self::TENANT_A, 'UPDATE customer SET deleted_at = now() WHERE id = ?::uuid', [$archived]);

        $this->update($other, $address, ['city' => 'Hijacked']);
        self::assertSame(404, $this->httpStatus());
        $this->update($archived, $archivedAddress, ['city' => 'Reopened']);
        self::assertSame(409, $this->httpStatus());
        $this->update($mine, $address, ['city' => 'Leeds', 'kind' => 'warehouse']);
        self::assertSame(422, $this->httpStatus());
        $this->update($mine, $address, ['city' => 'Leeds', 'country_code' => 'FR']);
        self::assertSame(422, $this->httpStatus());

        self::assertSame([], $this->auditRowsOf(AuditEvent::CUSTOMER_ADDRESS_UPDATED), 'A refused update wrote a customer_address.updated row.');
    }

    /** C2b.6 — `metadata` is `{fields}` and nothing else, and no submitted or stored value appears in it. */
    #[Test]
    public function theAddressUpdatedRowCarriesNamesNeverValues(): void
    {
        $customer = $this->seedCustomer(self::TENANT_A, 'Valued Address');
        $address = $this->seedCustomerAddress(self::TENANT_A, $customer);

        $this->update($customer, $address, ['line' => '77 Secret Lane', 'city' => 'Zebraville', 'postal_code' => 'ZZ9 9ZZ', 'country_code' => 'DE', 'kind' => 'billing']);
        self::assertSame(200, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());

        $rows = $this->auditRowsOf(AuditEvent::CUSTOMER_ADDRESS_UPDATED);
        self::assertCount(1, $rows);
        $metadata = json_decode($rows[0]['metadata'], true, flags: \JSON_THROW_ON_ERROR);
        self::assertSame(['fields'], array_keys($metadata), 'customer_address.updated carries a key other than fields.');
        self::assertSame(['city', 'country_code', 'kind', 'line', 'postal_code'], $metadata['fields']);
        foreach (['Secret', 'Zebra', 'ZZ9', 'DE', 'billing', 'Threadneedle', 'London', 'EC2R', 'GB', 'shipping'] as $value) {
            self::assertStringNotContainsString($value, $rows[0]['metadata'], 'A value reached customer_address.updated\'s metadata.');
        }
    }

    /**
     * C2b.7 — the race the row lock exists for (the `UpdateCustomer` precedent, judge C-3). A second connection re-points the
     * city to `Elsewhere` and holds the row; this request sends `{city: London, line}` against a row stored at `London`.
     * Locked, the request waits, then diffs against `Elsewhere`: both fields change, the row ends at `London`, and the
     * request's row names both — the diversion is undone AND attributed. Unlocked, it diffs against the uncommitted
     * `London`, leaves the city out of its `SET`, and the row keeps `Elsewhere` with nobody's name on it.
     */
    #[Test]
    public function aDiversionRacingACorrectionIsAttributedToTheCorrector(): void
    {
        $customer = $this->seedCustomer(self::TENANT_A, 'Raced Address');
        $address = $this->seedCustomerAddress(self::TENANT_A, $customer);
        self::assertSame('London', $this->storedAddress($address)['city'], 'Fixture: the seeded city must be London, or an unlocked diff cannot redden this test.');

        $holder = $this->raceAgainstAHolder(
            [['UPDATE customer_address SET city = \'Elsewhere\' WHERE id = ?::uuid', [$address]]],
            function () use ($customer, $address): void {
                $this->update($customer, $address, ['city' => 'London', 'line' => '10 Raced Road']);
            },
        );

        self::assertTrue($holder['waiter_seen'], 'The request never waited on the holder, so nothing here was a race: '.json_encode($holder));
        self::assertTrue($holder['committed'], 'The holder did not commit: '.json_encode($holder));
        self::assertSame(200, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        self::assertSame('London', $this->storedAddress($address)['city'], 'The request did not re-apply its city over the holder\'s diversion.');
        self::assertSame([$this->addressUpdatedRow($address, ['city', 'line'])], $this->auditRowsOf(AuditEvent::CUSTOMER_ADDRESS_UPDATED),
            'The request\'s customer_address.updated row does not name the city it changed back.');
    }

    // -----------------------------------------------------------------------

    /**
     * @param array<string, string> $orders label => id
     *
     * @return array{columns: array<string, array<string, ?string>>, bodies: array<string, string>}
     */
    private function ordersAsSeen(array $orders): array
    {
        $seen = ['columns' => [], 'bodies' => []];
        foreach ($orders as $label => $id) {
            $seen['columns'][$label] = $this->fetchAsOwnerForTenant(self::TENANT_A,
                'SELECT ship_to_line, ship_to_city, ship_to_postal_code, ship_to_country_code FROM sales_order WHERE id = ?::uuid', [$id])[0];
            $this->send('GET', '/sales-orders/'.$id);
            self::assertSame(200, $this->httpStatus());
            $seen['bodies'][$label] = (string) $this->client()->getResponse()->getContent();
        }
        $this->send('GET', '/sales-orders/'.$orders['confirmed'].'/pick-list');
        self::assertSame(200, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        $seen['bodies']['pick-list'] = (string) $this->client()->getResponse()->getContent();

        return $seen;
    }

    private function archivedCustomer(string $name): string
    {
        $customer = $this->seedCustomer(self::TENANT_A, $name);
        $this->insertAsOwnerForTenant(self::TENANT_A, 'UPDATE customer SET deleted_at = now() WHERE id = ?::uuid', [$customer]);

        return $customer;
    }

    /** @param array<string, mixed> $body */
    private function create(string $customer, array $body, ?string $key = null): void
    {
        $this->send('POST', '/customers/'.$customer.'/addresses', $body, [IdempotencyKeyGuard::HEADER => $key ?? Uuid::v4()->toRfc4122()]);
    }

    /** @param array<string, mixed> $body */
    private function update(string $customer, string $address, array $body): void
    {
        $this->send('PATCH', '/customers/'.$customer.'/addresses/'.$address, $body);
    }

    /** @return list<array{pointer: string, code: string, message: string}> */
    private function fieldErrors(): array
    {
        return $this->json()['errors'];
    }

    /** @return array{kind: string, line: string, city: string, postal_code: string, country_code: string} */
    private function storedAddress(string $address): array
    {
        return $this->fetchAsOwnerForTenant(self::TENANT_A,
            'SELECT kind, address_line AS line, city, postal_code, country_code FROM customer_address WHERE id = ?::uuid', [$address])[0];
    }

    /**
     * The one `customer_address.updated` row a change must write: this actor, the fixture label, the subject pair, no IP,
     * no permission code, `metadata` exactly `{"fields": [...]}` as Postgres renders the jsonb.
     *
     * @param list<string> $fields
     *
     * @return array<string, ?string>
     */
    private function addressUpdatedRow(string $address, array $fields): array
    {
        return array_replace($this->businessRow(AuditEvent::CUSTOMER_ADDRESS_UPDATED, 'customer_address', $address), [
            'metadata' => (string) $this->fetchAsOwnerForTenant(self::TENANT_A, 'SELECT ?::jsonb::text AS m',
                [json_encode(['fields' => $fields], \JSON_THROW_ON_ERROR)])[0]['m'],
        ]);
    }

    /**
     * `CustomerWriteTest::raceAgainstAHolder`'s shape (the holder's `HOLD_TABLE` whitelist names no `customer_address`, which
     * must NOT be added). The holder's own lock is on a random `purchase_order` id, so it matches no row; the real lock is
     * `HOLD_BEFORE`'s `UPDATE customer_address`, and the holder's `pg_blocking_pids` wait sees a waiter on any lock it holds.
     *
     * @param list<array{0: string, 1: list<string>}> $before
     *
     * @return array{waiter_seen: bool, committed: bool, sqlstate: ?string, error: ?string}
     */
    private function raceAgainstAHolder(array $before, callable $request): array
    {
        $readyFile = tempnam(sys_get_temp_dir(), 'erp_customer_address_');
        file_put_contents($readyFile, '');

        $proc = proc_open(
            [PHP_BINARY, __DIR__.'/support/hold_order_lock.php'],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            null,
            [
                'APP_DIRECT_DATABASE_URL' => $_ENV['APP_DIRECT_DATABASE_URL'],
                'HOLD_TENANT' => self::TENANT_A,
                'HOLD_ORDER' => Uuid::v4()->toRfc4122(),
                'HOLD_TABLE' => 'purchase_order',
                'HOLD_BEFORE' => json_encode($before, \JSON_THROW_ON_ERROR),
                'HOLD_AFTER' => json_encode([], \JSON_THROW_ON_ERROR),
                'HOLD_READY_FILE' => $readyFile,
                'HOLD_DEADLINE_SECONDS' => (string) self::HOLDER_DEADLINE_SECONDS,
                'PATH' => (string) getenv('PATH'),
            ],
        );
        self::assertIsResource($proc);

        try {
            $deadline = microtime(true) + self::HOLDER_DEADLINE_SECONDS;
            while ('' === trim((string) file_get_contents($readyFile))) {
                if (microtime(true) > $deadline) {
                    self::fail('The holder never took the lock: '.stream_get_contents($pipes[2]));
                }
                usleep(1000);
            }
            if ('failed' === trim((string) file_get_contents($readyFile))) {
                self::fail('The holder failed before taking the lock: '.stream_get_contents($pipes[1]).stream_get_contents($pipes[2]));
            }

            $request();

            $out = (string) stream_get_contents($pipes[1]);
            $err = (string) stream_get_contents($pipes[2]);
        } finally {
            if (proc_get_status($proc)['running']) {
                proc_terminate($proc);
            }
            foreach ($pipes as $pipe) {
                if (\is_resource($pipe)) {
                    fclose($pipe);
                }
            }
            proc_close($proc);
            @unlink($readyFile);
        }

        $report = json_decode(trim($out), true);
        self::assertIsArray($report, 'The holder printed no report. stdout: '.$out.' stderr: '.$err);

        return $report;
    }
}
