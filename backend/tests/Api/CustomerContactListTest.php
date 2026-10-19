<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Modules\UserManagement\Domain\DefaultRoles;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Uid\Uuid;

/**
 * **Customers slice 2 C1 — `ListCustomerContacts`**, *(bounded)* (`docs/session13/customers-s2-design.md` §2.2; TB-1c).
 * {@see CustomerAddressListTest}'s shape.
 *
 * The whole set in one response, ordered `is_primary DESC, created_at, id`, both cursors null; no query parameter is
 * accepted; an archived customer's contacts are listed (§5.2a); an unknown or foreign customer is the same 404; another
 * customer's contacts never appear. `viewer` reads them (owner Q-3 "yes"; SEC S-1).
 */
final class CustomerContactListTest extends PurchaseOrderTestCase
{
    /** A `viewer` holds `customer.view`, so it reads the contacts — the primary first, then oldest first. */
    #[Test]
    public function aCustomersContactsAreListedWholePrimaryFirstThenByAge(): void
    {
        $customer = $this->seedCustomer(self::TENANT_A, 'Contact Listed');
        $other = $this->seedCustomer(self::TENANT_A, 'Contact Not Listed');
        $this->seedCustomerContact(self::TENANT_A, $other, 'Not Mine');
        $old = $this->seedCustomerContact(self::TENANT_A, $customer, 'Old Contact', null, null);
        $this->insertAsOwnerForTenant(self::TENANT_A, "UPDATE customer_contact SET created_at = now() - interval '1 day' WHERE id = ?::uuid", [$old]);
        $new = $this->seedCustomerContact(self::TENANT_A, $customer, 'New Contact', 'new@example.test', '+44 20 7946 0002');
        $primary = $this->seedCustomerContact(self::TENANT_A, $customer, 'Primary Contact', 'primary@example.test', 'ext. 12', true);
        $this->actAs(self::TENANT_A, DefaultRoles::codesFor(DefaultRoles::VIEWER));

        $this->send('GET', '/customers/'.$customer.'/contacts');

        self::assertSame(200, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        $body = $this->json();
        self::assertSame([$primary, $old, $new], array_column($body['items'], 'id'));
        self::assertSame(['id' => $primary, 'name' => 'Primary Contact', 'email' => 'primary@example.test', 'phone' => 'ext. 12', 'is_primary' => true],
            $body['items'][0]);
        self::assertSame(['id' => $old, 'name' => 'Old Contact', 'email' => null, 'phone' => null, 'is_primary' => false], $body['items'][1],
            'A name-only contact is served with null email and phone, never the retry key or digest.');
        self::assertSame([null, null, true], [$body['next_cursor'], $body['previous_cursor'], $body['tenant_has_rows']]);
    }

    /** §5.2a: reading an archived parent's data is the direct-hit case. */
    #[Test]
    public function anArchivedCustomersContactsAreStillListed(): void
    {
        $customer = $this->seedCustomer(self::TENANT_A, 'Archived Contact Listed');
        $contact = $this->seedCustomerContact(self::TENANT_A, $customer);
        $this->insertAsOwnerForTenant(self::TENANT_A, 'UPDATE customer SET deleted_at = now() WHERE id = ?::uuid', [$customer]);

        $this->send('GET', '/customers/'.$customer.'/contacts');

        self::assertSame(200, $this->httpStatus());
        self::assertSame([$contact], array_column($this->json()['items'], 'id'));
    }

    /** API-22: an empty set probes the workspace; a customer with no contact in a workspace that has some says so. */
    #[Test]
    public function anEmptySetSaysWhetherTheWorkspaceHasAnyContact(): void
    {
        $empty = $this->seedCustomer(self::TENANT_A, 'No Contact Yet');

        $this->send('GET', '/customers/'.$empty.'/contacts');
        self::assertSame([[], false], [$this->json()['items'], $this->json()['tenant_has_rows']]);

        $this->seedCustomerContact(self::TENANT_A, $this->seedCustomer(self::TENANT_A, 'Has A Contact'));
        $this->send('GET', '/customers/'.$empty.'/contacts');
        self::assertSame([[], true], [$this->json()['items'], $this->json()['tenant_has_rows']]);
    }

    /** ST-5: unknown, malformed and another workspace's customer — the same 404. */
    #[Test]
    public function anUnknownCustomerIsTheSame404(): void
    {
        $foreign = $this->seedCustomer(self::TENANT_B, 'Foreign Contact Listed');
        $this->seedCustomerContact(self::TENANT_B, $foreign);

        $bodies = [];
        foreach ([$foreign, Uuid::v4()->toRfc4122(), 'not-a-uuid'] as $customer) {
            $this->send('GET', '/customers/'.$customer.'/contacts');
            self::assertSame(404, $this->httpStatus(), $customer);
            $bodies[] = (string) $this->client()->getResponse()->getContent();
        }
        self::assertSame($bodies[0], $bodies[1]);
    }

    /** Bounded (TB-1c): paging, sorting and search parameters are refused, never ignored (API-19). */
    #[Test]
    public function aBoundedListRefusesPagingParameters(): void
    {
        $customer = $this->seedCustomer(self::TENANT_A, 'Contact Bounded');

        foreach (['limit=10', 'sort=name', 'q=Ada', 'archived=true'] as $query) {
            $this->send('GET', '/customers/'.$customer.'/contacts?'.$query);
            self::assertSame(422, $this->httpStatus(), $query);
        }
    }
}
