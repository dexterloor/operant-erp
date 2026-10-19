<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Modules\Customers\ApiResource\CustomerResource;
use App\Modules\UserManagement\Domain\DefaultRoles;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Serializer\Attribute\SerializedName;
use Symfony\Component\Uid\Uuid;

/**
 * **Customers slice 1 C1 — `GetCustomer`** (`docs/session13/customers-s1-design.md` §3; final RED M-4's View shape).
 *
 * Pins:
 *  - the `/customers` paths are exactly {@see self::PINNED_CUSTOMER_OPERATIONS}, joined into
 *    `AuthOperationContractTest::theShippedSurfaceIsExactlyTheOperationsThisWaveHasPinned`'s union;
 *  - the body, whole: §4.4.3's derived `Customer` — never `email`, `phone`, `credit_limit` or `is_active` (owner Q-C2;
 *    mutation C1-4), pinned against the DTO by reflection, independent of `docs/`;
 *  - an archived customer is 200 with `archived_at` set (§5.2a's direct hit; mutation C1-5);
 *  - a non-uuid, absent and another workspace's id are byte-equal 404s; a seat without `customer.view` gets byte-equal 403s
 *    for a real and a random id (E-1);
 *  - every default role holding `customer.view` reads it (`sales_rep`, `viewer`, `admin`, owner).
 */
final class CustomerReadTest extends PurchaseOrderTestCase
{
    /**
     * The `/customers` operations, held equal to the registry. Each later commit of the slice adds its own.
     *
     * @var array<string, array{method: string, path: string, idem: bool}>
     */
    public const PINNED_CUSTOMER_OPERATIONS = [
        'GetCustomer' => ['method' => 'GET', 'path' => '/customers/{id}', 'idem' => false],
        'ListCustomers' => ['method' => 'GET', 'path' => '/customers', 'idem' => false],
        // C2 (2026-10-09): the create demands the header (`unique:customer_tenant_code_live`); the PATCH does not.
        'CreateCustomer' => ['method' => 'POST', 'path' => '/customers', 'idem' => true],
        'UpdateCustomer' => ['method' => 'PATCH', 'path' => '/customers/{id}', 'idem' => false],
        // C5 C4a: `guard:customer.deleted_at` — no header (a repeat is refused by the guard, `RestoreItem`'s reason).
        'RestoreCustomer' => ['method' => 'POST', 'path' => '/customers/{id}/restoration', 'idem' => false],
        // C5 C4b: `Idem` `no` — a repeated delete is refused `customer.is_archived`, never replayed.
        'DeleteCustomer' => ['method' => 'DELETE', 'path' => '/customers/{id}', 'idem' => false],
        // C3 (2026-10-09): the address create demands the header (`unique:customer_address_idem_unique`); the PATCH and the
        // bounded list do not.
        'CreateCustomerAddress' => ['method' => 'POST', 'path' => '/customers/{id}/addresses', 'idem' => true],
        'UpdateCustomerAddress' => ['method' => 'PATCH', 'path' => '/customers/{id}/addresses/{addressId}', 'idem' => false],
        'ListCustomerAddresses' => ['method' => 'GET', 'path' => '/customers/{id}/addresses', 'idem' => false],
        // Customers slice 2 C1 (2026-10-19): the contact create demands the header (`unique:customer_contact_idem_unique`);
        // the PATCH and the bounded list do not.
        'CreateCustomerContact' => ['method' => 'POST', 'path' => '/customers/{id}/contacts', 'idem' => true],
        'UpdateCustomerContact' => ['method' => 'PATCH', 'path' => '/customers/{id}/contacts/{contactId}', 'idem' => false],
        'ListCustomerContacts' => ['method' => 'GET', 'path' => '/customers/{id}/contacts', 'idem' => false],
        // C2 (2026-10-19): `Idem` `no` — a repeated delete is a 404, never replayed.
        'DeleteCustomerContact' => ['method' => 'DELETE', 'path' => '/customers/{id}/contacts/{contactId}', 'idem' => false],
    ];

    /** `CustomerResource`'s serialized members — §4.4.3's derivation (design §2.3 item 8). */
    public const PINNED_CUSTOMER_FIELDS = ['id', 'code', 'name', 'country_code', 'currency_code', 'payment_terms_days', 'archived_at'];

    #[Test]
    public function theCustomerPathsDeclareExactlyThePinnedOperations(): void
    {
        $declared = [];
        foreach (TransactionBoundaryTest::contractOperations() as $name => $operation) {
            $path = (string) $operation->getUriTemplate();
            if (1 === preg_match('#^/customers(/|$)#', $path)) {
                $declared[$name] = ['method' => $operation->getMethod(), 'path' => $path];
            }
        }
        ksort($declared);
        $pinned = array_map(static fn (array $pin): array => ['method' => $pin['method'], 'path' => $pin['path']], self::PINNED_CUSTOMER_OPERATIONS);
        ksort($pinned);

        self::assertSame($pinned, $declared);
    }

    /** Independent of `docs/`: the DTO's serialized members, in order. */
    #[Test]
    public function theCustomerShapeIsTheDerivedOneAndNothingElse(): void
    {
        $members = [];
        foreach (new \ReflectionClass(CustomerResource::class)->getConstructor()?->getParameters() ?? [] as $parameter) {
            $attribute = new \ReflectionProperty(CustomerResource::class, $parameter->getName())->getAttributes(SerializedName::class)[0] ?? null;
            $members[] = null === $attribute ? $parameter->getName() : $attribute->newInstance()->getSerializedName();
        }

        self::assertSame(self::PINNED_CUSTOMER_FIELDS, $members);
    }

    /** The whole body, exactly, and the columns nobody writes are not in it. */
    #[Test]
    public function aCustomerIsReadWhole(): void
    {
        $id = $this->seedCustomer(self::TENANT_A, 'Acme Retail', 'DE');
        $this->insertAsOwnerForTenant(self::TENANT_A,
            "UPDATE customer SET payment_terms_days = 45, email = 'a@example.test', phone = '1', credit_limit = 10 WHERE id = ?::uuid", [$id]);
        $code = $this->fetchAsOwnerForTenant(self::TENANT_A, 'SELECT code FROM customer WHERE id = ?::uuid', [$id])[0]['code'];

        $this->send('GET', '/customers/'.$id);

        self::assertSame(200, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        self::assertSame([
            'id' => $id,
            'code' => $code,
            'name' => 'Acme Retail',
            'country_code' => 'DE',
            'currency_code' => 'EUR',
            'payment_terms_days' => 45,
            'archived_at' => null,
        ], $this->json());
    }

    /** Mutation C1-5: an archived customer is still readable by its id, with `archived_at` set (§5.2a, ST-9). */
    #[Test]
    public function anArchivedCustomerIsReadWithItsArchiveDate(): void
    {
        $id = $this->seedCustomer(self::TENANT_A, 'Closed Account');
        $this->insertAsOwnerForTenant(self::TENANT_A, 'UPDATE customer SET deleted_at = now() WHERE id = ?::uuid', [$id]);

        $this->send('GET', '/customers/'.$id);

        self::assertSame(200, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        self::assertNotNull($this->json()['archived_at']);
        self::assertSame('Closed Account', $this->json()['name']);
    }

    /** ST-5: a non-uuid, an absent id and another workspace's id cannot be told apart. */
    #[Test]
    public function anAbsentAForeignAndAMalformedIdAreTheSame404(): void
    {
        $foreign = $this->seedCustomer(self::TENANT_B, 'Other Workspace');

        $bodies = [];
        foreach ([$foreign, Uuid::v4()->toRfc4122(), 'not-a-uuid'] as $id) {
            $this->send('GET', '/customers/'.$id);
            self::assertSame(404, $this->httpStatus(), $id);
            $bodies[] = (string) $this->client()->getResponse()->getContent();
        }

        self::assertSame($bodies[0], $bodies[1], 'Another workspace\'s customer is distinguishable from a random id.');
        self::assertSame($bodies[1], $bodies[2]);
    }

    /** E-1: without `customer.view` the refusal comes before anything is loaded — the same 403 for a real and a random id. */
    #[Test]
    public function aSeatWithoutTheViewIsRefusedBeforeAnythingIsLoaded(): void
    {
        $id = $this->seedCustomer(self::TENANT_A, 'Hidden');
        $this->actAs(self::TENANT_A, DefaultRoles::codesFor(DefaultRoles::WAREHOUSE_OPERATOR));

        $bodies = [];
        foreach ([$id, Uuid::v4()->toRfc4122()] as $candidate) {
            $this->send('GET', '/customers/'.$candidate);
            self::assertSame(403, $this->httpStatus());
            $bodies[] = (string) $this->client()->getResponse()->getContent();
        }
        self::assertSame($bodies[0], $bodies[1]);

        $this->send('GET', '/customers');
        self::assertSame(403, $this->httpStatus());
    }

    /** `SECURITY_MODEL.md` §4: every default role holding `customer.view` reads the customer and the list. */
    #[Test]
    public function everyDefaultRoleHoldingTheViewReadsIt(): void
    {
        $id = $this->seedCustomer(self::TENANT_A, 'Seen By Many');
        $holders = array_values(array_filter(DefaultRoles::names(), static fn (string $role): bool => \in_array('customer.view', DefaultRoles::codesFor($role), true)));
        self::assertNotSame([], $holders, 'No default role holds customer.view, so nothing below runs.');

        foreach ($holders as $role) {
            $this->actAs(self::TENANT_A, DefaultRoles::codesFor($role));
            $this->send('GET', '/customers/'.$id);
            self::assertSame(200, $this->httpStatus(), $role);
            $this->send('GET', '/customers');
            self::assertSame(200, $this->httpStatus(), $role);
        }
    }
}
