<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Modules\UserManagement\Domain\DefaultRoles;
use App\Modules\Warehouses\ApiResource\StorageLocationResource;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Serializer\Attribute\SerializedName;
use Symfony\Component\Uid\Uuid;

/**
 * **Warehouses slice 1 C1 — `ListStorageLocations`**, `GET /api/v1/warehouses/{id}/locations`, `storage_location.view`,
 * *(bounded)*, `archived` (`docs/session13/warehouses-s1-design.md` §1.6, §2.1 and FINAL item 2; `SupplierProductListTest`'s
 * shape).
 *
 * The whole set, ordered `code, id`, both cursors null; `archived=true` holds the archived locations instead of the live
 * ones; an archived warehouse's locations are listed (§5.2a); an unknown, foreign or malformed warehouse is the same 404;
 * another warehouse's locations never appear (mutation 8). Gated `storage_location.view` alone: `viewer` and
 * `warehouse_operator` read it; `sales_rep`, `buyer` and `purchasing_manager` are refused — it is not a bin picker for them.
 */
final class StorageLocationListTest extends PurchaseOrderTestCase
{
    /** `StorageLocationResource`'s serialized members — §4.4.3's derivation (design §1.4). */
    public const PINNED_STORAGE_LOCATION_FIELDS = ['id', 'code', 'kind', 'archived_at'];

    #[Test]
    public function aWarehousesLocationsAreListedWholeInCodeOrder(): void
    {
        $warehouse = $this->seedWarehouse(self::TENANT_A, 'Listed');
        $c = $this->location($warehouse, 'C-01', 'quarantine');
        $a = $this->location($warehouse, 'A-01');
        $b = $this->location($warehouse, 'B-01', 'shelf');
        $this->actAs(self::TENANT_A, DefaultRoles::codesFor(DefaultRoles::WAREHOUSE_OPERATOR));

        $this->send('GET', '/warehouses/'.$warehouse.'/locations');

        self::assertSame(200, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        $body = $this->json();
        self::assertSame([$a, $b, $c], array_column($body['items'], 'id'), 'Not ordered by code.');
        self::assertSame(['id' => $c, 'code' => 'C-01', 'kind' => 'quarantine', 'archived_at' => null], $body['items'][2]);
        self::assertSame([null, null, true], [$body['next_cursor'], $body['previous_cursor'], $body['tenant_has_rows']]);
    }

    /** Mutation 8: another warehouse's locations never appear under this path, even in the same workspace. */
    #[Test]
    public function anotherWarehousesLocationsAreNotListed(): void
    {
        $warehouse = $this->seedWarehouse(self::TENANT_A, 'Mine');
        $other = $this->seedWarehouse(self::TENANT_A, 'Next Door');
        $mine = $this->location($warehouse, 'A-01');
        $this->location($other, 'A-02');

        $this->send('GET', '/warehouses/'.$warehouse.'/locations');

        self::assertSame([$mine], array_column($this->json()['items'], 'id'));
    }

    /** `archived=true` returns the archived locations instead of the live ones (API-19c limb 2). */
    #[Test]
    public function theLiveViewExcludesArchivedLocationsAndTheArchivedViewHoldsOnlyThem(): void
    {
        $warehouse = $this->seedWarehouse(self::TENANT_A, 'Views');
        $live = $this->location($warehouse, 'A-01');
        $gone = $this->location($warehouse, 'A-02');
        $this->insertAsOwnerForTenant(self::TENANT_A, 'UPDATE storage_location SET deleted_at = now() WHERE id = ?::uuid', [$gone]);

        $this->send('GET', '/warehouses/'.$warehouse.'/locations');
        self::assertSame([$live], array_column($this->json()['items'], 'id'));

        $this->send('GET', '/warehouses/'.$warehouse.'/locations?archived=true');
        self::assertSame(200, $this->httpStatus());
        self::assertSame([$gone], array_column($this->json()['items'], 'id'));
        self::assertNotNull($this->json()['items'][0]['archived_at']);

        $this->send('GET', '/warehouses/'.$warehouse.'/locations?archived=maybe');
        self::assertSame(422, $this->httpStatus());
    }

    /** §5.2a: an archived warehouse's locations are read. */
    #[Test]
    public function anArchivedWarehousesLocationsAreStillListed(): void
    {
        $warehouse = $this->seedWarehouse(self::TENANT_A, 'Closed');
        $bin = $this->location($warehouse, 'A-01');
        $this->insertAsOwnerForTenant(self::TENANT_A, 'UPDATE warehouse SET deleted_at = now() WHERE id = ?::uuid', [$warehouse]);

        $this->send('GET', '/warehouses/'.$warehouse.'/locations');

        self::assertSame(200, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        self::assertSame([$bin], array_column($this->json()['items'], 'id'));
    }

    /** API-22: an empty set says whether the workspace holds any location at all. */
    #[Test]
    public function anEmptySetSaysWhetherTheWorkspaceHasAnyLocation(): void
    {
        $empty = $this->seedWarehouse(self::TENANT_A, 'Empty');
        $this->send('GET', '/warehouses/'.$empty.'/locations');
        self::assertSame([[], false], [$this->json()['items'], $this->json()['tenant_has_rows']]);

        $this->location($this->seedWarehouse(self::TENANT_A, 'Stocked'), 'A-01');
        $this->send('GET', '/warehouses/'.$empty.'/locations');
        self::assertSame([[], true], [$this->json()['items'], $this->json()['tenant_has_rows']]);
    }

    /** ST-5: a non-uuid, an absent and another workspace's warehouse cannot be told apart. */
    #[Test]
    public function anUnknownAForeignAndAMalformedWarehouseAreTheSame404(): void
    {
        $foreign = $this->seedWarehouse(self::TENANT_B, 'Theirs');

        $bodies = [];
        foreach ([$foreign, Uuid::v4()->toRfc4122(), 'not-a-uuid'] as $id) {
            $this->send('GET', '/warehouses/'.$id.'/locations');
            self::assertSame(404, $this->httpStatus(), $id);
            $bodies[] = (string) $this->client()->getResponse()->getContent();
        }
        self::assertSame($bodies[0], $bodies[1]);
        self::assertSame($bodies[1], $bodies[2]);
    }

    #[Test]
    public function aBoundedListRefusesPagingParameters(): void
    {
        $warehouse = $this->seedWarehouse(self::TENANT_A, 'Bounded');
        foreach (['limit=1', 'after=x', 'before=x', 'sort=code', 'q=A'] as $query) {
            $this->send('GET', '/warehouses/'.$warehouse.'/locations?'.$query);
            self::assertSame(422, $this->httpStatus(), $query.': '.$this->client()->getResponse()->getContent());
        }
    }

    /**
     * `SECURITY_MODEL.md` §4: the holders of `storage_location.view` read it; the default roles without it are refused, the
     * same 403 for a real and a random warehouse (E-1) — the list is not a bin picker for them.
     */
    #[Test]
    public function onlyHoldersOfTheLocationViewReadIt(): void
    {
        $warehouse = $this->seedWarehouse(self::TENANT_A, 'Gated');
        $readers = 0;
        $refused = 0;
        foreach (DefaultRoles::names() as $role) {
            $codes = DefaultRoles::codesFor($role);
            $this->actAs(self::TENANT_A, $codes);
            if (\in_array('storage_location.view', $codes, true)) {
                $this->send('GET', '/warehouses/'.$warehouse.'/locations');
                self::assertSame(200, $this->httpStatus(), $role);
                ++$readers;
                continue;
            }
            $bodies = [];
            foreach ([$warehouse, Uuid::v4()->toRfc4122()] as $candidate) {
                $this->send('GET', '/warehouses/'.$candidate.'/locations');
                self::assertSame(403, $this->httpStatus(), $role);
                $bodies[] = (string) $this->client()->getResponse()->getContent();
            }
            self::assertSame($bodies[0], $bodies[1], $role);
            ++$refused;
        }
        self::assertGreaterThan(0, $readers, 'No default role holds storage_location.view, so the 200 half ran nothing.');
        self::assertGreaterThan(0, $refused, 'Every default role holds storage_location.view, so the 403 half ran nothing.');
        self::assertNotContains('storage_location.view', DefaultRoles::codesFor(DefaultRoles::SALES_REP), 'The control: sales_rep must lack it.');
    }

    /** Independent of `docs/`: the DTO's serialized members, in order. */
    #[Test]
    public function theStorageLocationShapeIsTheDerivedOneAndNothingElse(): void
    {
        self::assertSame(self::PINNED_STORAGE_LOCATION_FIELDS, self::serializedMembers(StorageLocationResource::class));
    }

    // `theWireSchemaIsTheResourcesOwnMembers` was DELETED at C3 (2026-10-13), as its docblock asked: C3 builds the first
    // `StorageLocation` write, so the component exists and `StorageLocationCollection.items` is a `$ref`
    // (`OpenApiContractTest::everyReferenceInTheSpecResolves` holds it resolving).

    /** @return list<string> a DTO's serialized member names, in declaration order */
    private static function serializedMembers(string $class): array
    {
        $names = [];
        foreach (new \ReflectionClass($class)->getProperties(\ReflectionProperty::IS_PUBLIC) as $property) {
            $serialized = $property->getAttributes(SerializedName::class);
            $names[] = [] === $serialized ? $property->getName() : (string) $serialized[0]->newInstance()->serializedName;
        }

        return $names;
    }

    private function location(string $warehouse, string $code, string $kind = 'bin'): string
    {
        $id = Uuid::v4()->toRfc4122();
        $this->insertAsOwnerForTenant(self::TENANT_A,
            'INSERT INTO storage_location (id, tenant_id, warehouse_id, code, kind) VALUES (?::uuid, ?::uuid, ?::uuid, ?, ?)',
            [$id, self::TENANT_A, $warehouse, $code, $kind]);

        return $id;
    }
}
