<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Modules\UserManagement\Domain\DefaultRoles;
use App\Modules\Warehouses\ApiResource\WarehouseResource;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Serializer\Attribute\SerializedName;
use Symfony\Component\Uid\Uuid;

/**
 * **Warehouses slice 1 C1 — `GetWarehouse`** (`docs/session13/warehouses-s1-design.md` §2 and FINAL item 2;
 * `SupplierReadTest`'s shape).
 *
 * Pins:
 *  - the `/warehouses` paths are exactly {@see self::PINNED_WAREHOUSE_OPERATIONS}, joined into
 *    `AuthOperationContractTest::theShippedSurfaceIsExactlyTheOperationsThisWaveHasPinned`'s union;
 *  - the body, whole: §4.4.3's derived `Warehouse` — never `address_line`, `city`, `postal_code` or `is_active` (Q-W4;
 *    mutation 5), pinned against the DTO by reflection, independent of `docs/`;
 *  - an archived warehouse is 200 with `archived_at` set (§5.2a's direct hit; mutation 6);
 *  - a non-uuid, absent and another workspace's id are byte-equal 404s; a seat without `warehouse.view` gets byte-equal
 *    403s for a real and a random id (E-1);
 *  - every default role holding `warehouse.view` reads it, and `seedWarehouse()`'s code is letter-bearing (CD-3).
 */
final class WarehouseReadTest extends PurchaseOrderTestCase
{
    /**
     * The `/warehouses` operations, held equal to the registry. Each later commit of the slice adds its own.
     *
     * @var array<string, array{method: string, path: string, idem: bool}>
     */
    public const PINNED_WAREHOUSE_OPERATIONS = [
        'GetWarehouse' => ['method' => 'GET', 'path' => '/warehouses/{id}', 'idem' => false],
        'ListWarehouses' => ['method' => 'GET', 'path' => '/warehouses', 'idem' => false],
        'ListStorageLocations' => ['method' => 'GET', 'path' => '/warehouses/{id}/locations', 'idem' => false],
        // C2 (2026-10-13): the create demands the header (`unique:warehouse_tenant_code_live`); the PATCH does not.
        'CreateWarehouse' => ['method' => 'POST', 'path' => '/warehouses', 'idem' => true],
        'UpdateWarehouse' => ['method' => 'PATCH', 'path' => '/warehouses/{id}', 'idem' => false],
        // C3 (2026-10-13): the location create demands the header (`unique:storage_location_tenant_warehouse_code_live`); the
        // PATCH does not.
        'CreateStorageLocation' => ['method' => 'POST', 'path' => '/warehouses/{id}/locations', 'idem' => true],
        'UpdateStorageLocation' => ['method' => 'PATCH', 'path' => '/warehouses/{id}/locations/{locationId}', 'idem' => false],
        // Warehouse/location delete-restore C1 (2026-10-20): `guard:warehouse.deleted_at` — no header.
        'RestoreWarehouse' => ['method' => 'POST', 'path' => '/warehouses/{id}/restoration', 'idem' => false],
    ];

    /** `WarehouseResource`'s serialized members — §4.4.3's derivation (design §1.4). */
    public const PINNED_WAREHOUSE_FIELDS = ['id', 'code', 'name', 'country_code', 'archived_at'];

    #[Test]
    public function theWarehousePathsDeclareExactlyThePinnedOperations(): void
    {
        $declared = [];
        foreach (TransactionBoundaryTest::contractOperations() as $name => $operation) {
            $path = (string) $operation->getUriTemplate();
            if (1 === preg_match('#^/warehouses(/|$)#', $path)) {
                $declared[$name] = ['method' => $operation->getMethod(), 'path' => $path];
            }
        }
        ksort($declared);
        $pinned = array_map(static fn (array $pin): array => ['method' => $pin['method'], 'path' => $pin['path']], self::PINNED_WAREHOUSE_OPERATIONS);
        ksort($pinned);

        self::assertSame($pinned, $declared);
    }

    /** Independent of `docs/`: the DTO's serialized members, in order. */
    #[Test]
    public function theWarehouseShapeIsTheDerivedOneAndNothingElse(): void
    {
        $members = [];
        foreach (new \ReflectionClass(WarehouseResource::class)->getConstructor()?->getParameters() ?? [] as $parameter) {
            $attribute = new \ReflectionProperty(WarehouseResource::class, $parameter->getName())->getAttributes(SerializedName::class)[0] ?? null;
            $members[] = null === $attribute ? $parameter->getName() : $attribute->newInstance()->serializedName;
        }

        self::assertSame(self::PINNED_WAREHOUSE_FIELDS, $members);
    }

    /** The whole body, exactly, and the columns nobody writes are not in it. */
    #[Test]
    public function aWarehouseIsReadWhole(): void
    {
        $id = $this->seedWarehouse(self::TENANT_A, 'Rotterdam Hub');
        $this->insertAsOwnerForTenant(self::TENANT_A,
            "UPDATE warehouse SET country_code = 'GB', address_line = '1 Quay', city = 'Rotterdam', postal_code = '3011', is_active = false WHERE id = ?::uuid", [$id]);
        $code = $this->fetchAsOwnerForTenant(self::TENANT_A, 'SELECT code FROM warehouse WHERE id = ?::uuid', [$id])[0]['code'];
        self::assertStringStartsWith('W-', $code, 'seedWarehouse() codes carry a letter prefix (CD-3).');

        $this->send('GET', '/warehouses/'.$id);

        self::assertSame(200, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        self::assertSame([
            'id' => $id,
            'code' => $code,
            'name' => 'Rotterdam Hub',
            'country_code' => 'GB',
            'archived_at' => null,
        ], $this->json());
    }

    /** Mutation 6: an archived warehouse is still readable by its id, with `archived_at` set (§5.2a, ST-9). */
    #[Test]
    public function anArchivedWarehouseIsReadWithItsArchiveDate(): void
    {
        $id = $this->seedWarehouse(self::TENANT_A, 'Former Depot');
        $this->insertAsOwnerForTenant(self::TENANT_A, 'UPDATE warehouse SET deleted_at = now() WHERE id = ?::uuid', [$id]);

        $this->send('GET', '/warehouses/'.$id);

        self::assertSame(200, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        self::assertNotNull($this->json()['archived_at']);
        self::assertSame('Former Depot', $this->json()['name']);
    }

    /** ST-5: a non-uuid, an absent id and another workspace's id cannot be told apart. */
    #[Test]
    public function anAbsentAForeignAndAMalformedIdAreTheSame404(): void
    {
        $foreign = $this->seedWarehouse(self::TENANT_B, 'Other Workspace');

        $bodies = [];
        foreach ([$foreign, Uuid::v4()->toRfc4122(), 'not-a-uuid'] as $id) {
            $this->send('GET', '/warehouses/'.$id);
            self::assertSame(404, $this->httpStatus(), $id);
            $bodies[] = (string) $this->client()->getResponse()->getContent();
        }

        self::assertSame($bodies[0], $bodies[1], 'Another workspace\'s warehouse is distinguishable from a random id.');
        self::assertSame($bodies[1], $bodies[2]);
    }

    /** E-1: without `warehouse.view` the refusal comes before anything is loaded — the same 403 for a real and a random id. */
    #[Test]
    public function aSeatWithoutTheViewIsRefusedBeforeAnythingIsLoaded(): void
    {
        $id = $this->seedWarehouse(self::TENANT_A, 'Hidden');
        $this->actAs(self::TENANT_A, ['inventory_item.view']);

        $bodies = [];
        foreach ([$id, Uuid::v4()->toRfc4122()] as $candidate) {
            $this->send('GET', '/warehouses/'.$candidate);
            self::assertSame(403, $this->httpStatus());
            $bodies[] = (string) $this->client()->getResponse()->getContent();
        }
        self::assertSame($bodies[0], $bodies[1]);

        $this->send('GET', '/warehouses');
        self::assertSame(403, $this->httpStatus());
    }

    /** `SECURITY_MODEL.md` §4: every default role holding `warehouse.view` reads the warehouse and the list. */
    #[Test]
    public function everyDefaultRoleHoldingTheViewReadsIt(): void
    {
        $id = $this->seedWarehouse(self::TENANT_A, 'Seen By Many');
        $holders = array_values(array_filter(DefaultRoles::names(), static fn (string $role): bool => \in_array('warehouse.view', DefaultRoles::codesFor($role), true)));
        self::assertNotSame([], $holders, 'No default role holds warehouse.view, so nothing below runs.');

        foreach ($holders as $role) {
            $this->actAs(self::TENANT_A, DefaultRoles::codesFor($role));
            $this->send('GET', '/warehouses/'.$id);
            self::assertSame(200, $this->httpStatus(), $role);
            $this->send('GET', '/warehouses');
            self::assertSame(200, $this->httpStatus(), $role);
        }
    }
}
