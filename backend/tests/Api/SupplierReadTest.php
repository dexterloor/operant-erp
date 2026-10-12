<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Modules\Suppliers\ApiResource\SupplierResource;
use App\Modules\UserManagement\Domain\DefaultRoles;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Serializer\Attribute\SerializedName;
use Symfony\Component\Uid\Uuid;

/**
 * **Suppliers slice 1 C1 — `GetSupplier`** (`docs/session13/suppliers-s1-design.md` FINAL F-2 and §3; `CustomerReadTest`'s
 * shape).
 *
 * Pins:
 *  - the `/suppliers` paths are exactly {@see self::PINNED_SUPPLIER_OPERATIONS}, joined into
 *    `AuthOperationContractTest::theShippedSurfaceIsExactlyTheOperationsThisWaveHasPinned`'s union;
 *  - the body, whole: §4.4.3's derived `Supplier` — never `email`, `phone` or `is_active` (owner Q-S6; mutation C1-4),
 *    pinned against the DTO by reflection, independent of `docs/`;
 *  - an archived supplier is 200 with `archived_at` set (§5.2a's direct hit; mutation C1-5);
 *  - a non-uuid, absent and another workspace's id are byte-equal 404s; a seat without `supplier.view` gets byte-equal 403s
 *    for a real and a random id (E-1);
 *  - every default role holding `supplier.view` reads it.
 */
final class SupplierReadTest extends PurchaseOrderTestCase
{
    /**
     * The `/suppliers` operations, held equal to the registry. Each later commit of the slice adds its own.
     *
     * @var array<string, array{method: string, path: string, idem: bool}>
     */
    public const PINNED_SUPPLIER_OPERATIONS = [
        'GetSupplier' => ['method' => 'GET', 'path' => '/suppliers/{id}', 'idem' => false],
        'ListSuppliers' => ['method' => 'GET', 'path' => '/suppliers', 'idem' => false],
        // C2 (2026-10-12): the create demands the header (`unique:supplier_tenant_code_live`); the PATCH does not.
        'CreateSupplier' => ['method' => 'POST', 'path' => '/suppliers', 'idem' => true],
        'UpdateSupplier' => ['method' => 'PATCH', 'path' => '/suppliers/{id}', 'idem' => false],
    ];

    /** `SupplierResource`'s serialized members — §4.4.3's derivation (design §2.3 item 4). */
    public const PINNED_SUPPLIER_FIELDS = ['id', 'code', 'name', 'country_code', 'currency_code', 'payment_terms_days', 'archived_at'];

    #[Test]
    public function theSupplierPathsDeclareExactlyThePinnedOperations(): void
    {
        $declared = [];
        foreach (TransactionBoundaryTest::contractOperations() as $name => $operation) {
            $path = (string) $operation->getUriTemplate();
            if (1 === preg_match('#^/suppliers(/|$)#', $path)) {
                $declared[$name] = ['method' => $operation->getMethod(), 'path' => $path];
            }
        }
        ksort($declared);
        $pinned = array_map(static fn (array $pin): array => ['method' => $pin['method'], 'path' => $pin['path']], self::PINNED_SUPPLIER_OPERATIONS);
        ksort($pinned);

        self::assertSame($pinned, $declared);
    }

    /** Independent of `docs/`: the DTO's serialized members, in order. */
    #[Test]
    public function theSupplierShapeIsTheDerivedOneAndNothingElse(): void
    {
        $members = [];
        foreach (new \ReflectionClass(SupplierResource::class)->getConstructor()?->getParameters() ?? [] as $parameter) {
            $attribute = new \ReflectionProperty(SupplierResource::class, $parameter->getName())->getAttributes(SerializedName::class)[0] ?? null;
            $members[] = null === $attribute ? $parameter->getName() : $attribute->newInstance()->getSerializedName();
        }

        self::assertSame(self::PINNED_SUPPLIER_FIELDS, $members);
    }

    /** The whole body, exactly, and the columns nobody writes are not in it. */
    #[Test]
    public function aSupplierIsReadWhole(): void
    {
        $id = $this->seedSupplier(self::TENANT_A, 'Acme Components', 'DE');
        $this->insertAsOwnerForTenant(self::TENANT_A,
            "UPDATE supplier SET payment_terms_days = 45, currency_code = 'USD', email = 'a@example.test', phone = '1', is_active = false WHERE id = ?::uuid", [$id]);
        $code = $this->fetchAsOwnerForTenant(self::TENANT_A, 'SELECT code FROM supplier WHERE id = ?::uuid', [$id])[0]['code'];
        self::assertStringStartsWith('S-', $code, 'seedSupplier() codes carry a letter prefix (CD-3).');

        $this->send('GET', '/suppliers/'.$id);

        self::assertSame(200, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        self::assertSame([
            'id' => $id,
            'code' => $code,
            'name' => 'Acme Components',
            'country_code' => 'DE',
            'currency_code' => 'USD',
            'payment_terms_days' => 45,
            'archived_at' => null,
        ], $this->json());
    }

    /** Mutation C1-5: an archived supplier is still readable by its id, with `archived_at` set (§5.2a, ST-9). */
    #[Test]
    public function anArchivedSupplierIsReadWithItsArchiveDate(): void
    {
        $id = $this->seedSupplier(self::TENANT_A, 'Former Supplier');
        $this->insertAsOwnerForTenant(self::TENANT_A, 'UPDATE supplier SET deleted_at = now() WHERE id = ?::uuid', [$id]);

        $this->send('GET', '/suppliers/'.$id);

        self::assertSame(200, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        self::assertNotNull($this->json()['archived_at']);
        self::assertSame('Former Supplier', $this->json()['name']);
    }

    /** ST-5: a non-uuid, an absent id and another workspace's id cannot be told apart. */
    #[Test]
    public function anAbsentAForeignAndAMalformedIdAreTheSame404(): void
    {
        $foreign = $this->seedSupplier(self::TENANT_B, 'Other Workspace');

        $bodies = [];
        foreach ([$foreign, Uuid::v4()->toRfc4122(), 'not-a-uuid'] as $id) {
            $this->send('GET', '/suppliers/'.$id);
            self::assertSame(404, $this->httpStatus(), $id);
            $bodies[] = (string) $this->client()->getResponse()->getContent();
        }

        self::assertSame($bodies[0], $bodies[1], 'Another workspace\'s supplier is distinguishable from a random id.');
        self::assertSame($bodies[1], $bodies[2]);
    }

    /** E-1: without `supplier.view` the refusal comes before anything is loaded — the same 403 for a real and a random id. */
    #[Test]
    public function aSeatWithoutTheViewIsRefusedBeforeAnythingIsLoaded(): void
    {
        $id = $this->seedSupplier(self::TENANT_A, 'Hidden');
        $codes = DefaultRoles::codesFor(DefaultRoles::SALES_REP);
        self::assertNotContains('supplier.view', $codes, 'The control: this seat must lack supplier.view.');
        $this->actAs(self::TENANT_A, $codes);

        $bodies = [];
        foreach ([$id, Uuid::v4()->toRfc4122()] as $candidate) {
            $this->send('GET', '/suppliers/'.$candidate);
            self::assertSame(403, $this->httpStatus());
            $bodies[] = (string) $this->client()->getResponse()->getContent();
        }
        self::assertSame($bodies[0], $bodies[1]);

        $this->send('GET', '/suppliers');
        self::assertSame(403, $this->httpStatus());
    }

    /** `SECURITY_MODEL.md` §4: every default role holding `supplier.view` reads the supplier and the list. */
    #[Test]
    public function everyDefaultRoleHoldingTheViewReadsIt(): void
    {
        $id = $this->seedSupplier(self::TENANT_A, 'Seen By Many');
        $holders = array_values(array_filter(DefaultRoles::names(), static fn (string $role): bool => \in_array('supplier.view', DefaultRoles::codesFor($role), true)));
        self::assertNotSame([], $holders, 'No default role holds supplier.view, so nothing below runs.');

        foreach ($holders as $role) {
            $this->actAs(self::TENANT_A, DefaultRoles::codesFor($role));
            $this->send('GET', '/suppliers/'.$id);
            self::assertSame(200, $this->httpStatus(), $role);
            $this->send('GET', '/suppliers');
            self::assertSame(200, $this->httpStatus(), $role);
        }
    }
}
