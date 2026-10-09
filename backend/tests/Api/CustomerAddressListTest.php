<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Modules\UserManagement\Domain\DefaultRoles;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Uid\Uuid;

/**
 * **Customers slice 1 C3 — `ListCustomerAddresses`**, *(bounded)* (`docs/session13/customers-s1-design.md` §5.1; TB-1c).
 *
 * The whole set in one response, ordered `kind, created_at, id`, both cursors null; no query parameter is accepted; an
 * archived customer's addresses are listed (§5.2a); an unknown or foreign customer is the same 404; another customer's
 * addresses never appear.
 */
final class CustomerAddressListTest extends PurchaseOrderTestCase
{
    /** A `viewer` holds `customer.view` (design N-3, `SEC` to confirm), so it reads the addresses. */
    #[Test]
    public function aCustomersAddressesAreListedWholeInKindThenAgeOrder(): void
    {
        $customer = $this->seedCustomer(self::TENANT_A, 'Listed');
        $other = $this->seedCustomer(self::TENANT_A, 'Not Listed');
        $this->seedCustomerAddress(self::TENANT_A, $other);
        $shippingOld = $this->seedCustomerAddress(self::TENANT_A, $customer, 'shipping', '1 First Street', 'York', 'YO1 1AA');
        $billing = $this->seedCustomerAddress(self::TENANT_A, $customer, 'billing', '2 Bill Street', 'Leeds', 'LS1 1AA');
        $this->insertAsOwnerForTenant(self::TENANT_A, "UPDATE customer_address SET created_at = now() - interval '1 day' WHERE id = ?::uuid", [$shippingOld]);
        $shippingNew = $this->seedCustomerAddress(self::TENANT_A, $customer, 'shipping', '3 Third Street', 'Bath', 'BA1 1AA');
        $this->actAs(self::TENANT_A, DefaultRoles::codesFor(DefaultRoles::VIEWER));

        $this->send('GET', '/customers/'.$customer.'/addresses');

        self::assertSame(200, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        $body = $this->json();
        self::assertSame([$billing, $shippingOld, $shippingNew], array_column($body['items'], 'id'));
        self::assertSame(['id' => $billing, 'kind' => 'billing', 'line' => '2 Bill Street', 'city' => 'Leeds', 'postal_code' => 'LS1 1AA',
            'country_code' => 'GB'], $body['items'][0]);
        self::assertSame([null, null, true], [$body['next_cursor'], $body['previous_cursor'], $body['tenant_has_rows']]);
    }

    /** §5.2a: reading an archived parent's data is the direct-hit case. */
    #[Test]
    public function anArchivedCustomersAddressesAreStillListed(): void
    {
        $customer = $this->seedCustomer(self::TENANT_A, 'Archived Listed');
        $address = $this->seedCustomerAddress(self::TENANT_A, $customer);
        $this->insertAsOwnerForTenant(self::TENANT_A, 'UPDATE customer SET deleted_at = now() WHERE id = ?::uuid', [$customer]);

        $this->send('GET', '/customers/'.$customer.'/addresses');

        self::assertSame(200, $this->httpStatus());
        self::assertSame([$address], array_column($this->json()['items'], 'id'));
    }

    /** API-22: an empty set probes the workspace; a customer with no address in a workspace that has some says so. */
    #[Test]
    public function anEmptySetSaysWhetherTheWorkspaceHasAnyAddress(): void
    {
        $empty = $this->seedCustomer(self::TENANT_A, 'No Address Yet');

        $this->send('GET', '/customers/'.$empty.'/addresses');
        self::assertSame([[], false], [$this->json()['items'], $this->json()['tenant_has_rows']]);

        $this->seedCustomerAddress(self::TENANT_A, $this->seedCustomer(self::TENANT_A, 'Has One'));
        $this->send('GET', '/customers/'.$empty.'/addresses');
        self::assertSame([[], true], [$this->json()['items'], $this->json()['tenant_has_rows']]);
    }

    /** ST-5: unknown, malformed and another workspace's customer — the same 404. */
    #[Test]
    public function anUnknownCustomerIsTheSame404(): void
    {
        $foreign = $this->seedCustomer(self::TENANT_B, 'Foreign Listed');
        $this->seedCustomerAddress(self::TENANT_B, $foreign);

        $bodies = [];
        foreach ([$foreign, Uuid::v4()->toRfc4122(), 'not-a-uuid'] as $customer) {
            $this->send('GET', '/customers/'.$customer.'/addresses');
            self::assertSame(404, $this->httpStatus(), $customer);
            $bodies[] = (string) $this->client()->getResponse()->getContent();
        }
        self::assertSame($bodies[0], $bodies[1]);
    }

    /** Bounded (TB-1c): paging and sorting parameters are refused, never ignored (API-19). */
    #[Test]
    public function aBoundedListRefusesPagingParameters(): void
    {
        $customer = $this->seedCustomer(self::TENANT_A, 'Bounded');

        foreach (['limit=10', 'sort=kind', 'archived=true'] as $query) {
            $this->send('GET', '/customers/'.$customer.'/addresses?'.$query);
            self::assertSame(422, $this->httpStatus(), $query);
        }
    }
}
