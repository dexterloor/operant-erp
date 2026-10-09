<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Modules\UserManagement\Domain\DefaultRoles;
use App\Platform\Http\Idempotency\IdempotencyKeyGuard;
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
 */
final class CustomerAddressWriteTest extends PurchaseOrderTestCase
{
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
}
