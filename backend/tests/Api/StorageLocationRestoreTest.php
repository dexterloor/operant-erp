<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Modules\UserManagement\Domain\DefaultRoles;
use App\Modules\Warehouses\Application\StorageLocationWriter;
use App\SharedKernel\ValueObject\AuditEvent;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Uid\Uuid;

/**
 * **Warehouse/location delete-restore C2 — `RestoreStorageLocation`, `POST /warehouses/{id}/locations/{locationId}/restoration`,
 * `storage_location.delete`** (`docs/session13/wh-loc-delete-design.md` §3.3, §4.1, §4.2, §7.3, Revision 2; §4.2
 * `guard:storage_location.deleted_at`; `WarehouseRestoreTest`'s shape).
 *
 * 200 with the location in its new state; a location of an archived warehouse → 409 `warehouse.is_archived` (restore the
 * warehouse first — owner Q-2 (a)); a live location → 409 `storage_location.is_not_archived`; another warehouse's
 * location under this path, a foreign, absent or malformed id → the same 404; a live location of the same warehouse that
 * took its code meanwhile → 409 `storage_location.code_is_unique_per_warehouse` with the restore sentence; one
 * `storage_location.restored` row, `{}`, on success and none on any refusal. Archived locations are seeded by owner SQL:
 * `DeleteStorageLocation` is C3. Copy is `UX`'s final (`wh-loc-delete-roles.md` U-1).
 */
final class StorageLocationRestoreTest extends PurchaseOrderTestCase
{
    /** An admin restores an archived location of a live warehouse: 200, the location as `ListStorageLocations` serves it. */
    #[Test]
    public function anAdminRestoresAnArchivedLocation(): void
    {
        [$warehouse, $id] = $this->archived('R-BACK');
        $before = $this->stored($id)['updated_at'];
        $this->actAs(self::TENANT_A, DefaultRoles::codesFor(DefaultRoles::ADMIN));

        $this->restore($warehouse, $id);

        self::assertSame(200, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        $body = $this->json();
        self::assertSame([$id, 'R-BACK', 'bin', null], [$body['id'], $body['code'], $body['kind'], $body['archived_at']]);
        self::assertNull($this->stored($id)['deleted_at']);
        self::assertNotSame($before, $this->stored($id)['updated_at'], 'A restore did not move updated_at.');

        $this->send('GET', '/warehouses/'.$warehouse.'/locations');
        self::assertSame(200, $this->httpStatus());
        $listed = array_values(array_filter($this->json()['items'], static fn (array $row): bool => $row['id'] === $id));
        self::assertSame([$body], $listed, 'The restore body is not the location as the live list serves it.');
        $this->send('GET', '/warehouses/'.$warehouse.'/locations?archived=true');
        self::assertSame([], $this->json()['items'], 'A restored location is still on the Archived view.');
    }

    /** Owner Q-2 (a) / design §4.2: a location of an archived warehouse is restored only after the warehouse. */
    #[Test]
    public function aLocationOfAnArchivedWarehouseIsRestoredOnlyAfterTheWarehouse(): void
    {
        [$warehouse, $id] = $this->archived('R-INSIDE');
        $this->insertAsOwnerForTenant(self::TENANT_A, 'UPDATE warehouse SET deleted_at = now() WHERE id = ?::uuid', [$warehouse]);

        $this->restore($warehouse, $id);

        self::assertSame(409, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        self::assertSame('warehouse.is_archived', $this->json()['rule']);
        self::assertSame('This location\'s warehouse is archived, so the location can\'t be restored until the warehouse is restored.', $this->json()['detail']);
        self::assertSame(StorageLocationWriter::WAREHOUSE_ARCHIVED_ON_RESTORE, $this->json()['detail']);
        self::assertNotNull($this->stored($id)['deleted_at']);
        self::assertSame([], $this->auditRowsOf(AuditEvent::STORAGE_LOCATION_RESTORED));

        $this->send('POST', '/warehouses/'.$warehouse.'/restoration');
        self::assertSame(200, $this->httpStatus());
        self::assertNotNull($this->stored($id)['deleted_at'], 'Restoring the warehouse restored a location archived on its own.');
        $this->restore($warehouse, $id);
        self::assertSame(200, $this->httpStatus(), 'Once the warehouse is restored its location can be.');
    }

    /** The §4.2 guard: restoring a live location is refused, so a replayed restore is told the first one committed. */
    #[Test]
    public function restoringALiveLocationIsRefusedByTheGuard(): void
    {
        [$warehouse, $id] = $this->archived('R-ONCE');

        $this->restore($warehouse, $id);
        self::assertSame(200, $this->httpStatus());
        $updatedAt = $this->stored($id)['updated_at'];

        $this->restore($warehouse, $id);
        self::assertSame(409, $this->httpStatus());
        self::assertSame('storage_location.is_not_archived', $this->json()['rule']);
        self::assertSame('This location is not archived, so there is nothing to restore.', $this->json()['detail']);
        self::assertSame($updatedAt, $this->stored($id)['updated_at'], 'A refused restore wrote the row.');
        self::assertCount(1, $this->auditRowsOf(AuditEvent::STORAGE_LOCATION_RESTORED));
    }

    /**
     * ST-5 and mutation M6: another warehouse's location under this path, another workspace's, an absent or a malformed id
     * — all the same 404, nothing written. The lookup is scoped to the path's warehouse.
     */
    #[Test]
    public function aRestoreOfAnUnknownLocationIsTheSame404(): void
    {
        [$warehouse, $id] = $this->archived('R-MINE');
        [, $elsewhere] = $this->archived('R-ELSEWHERE');
        [$theirWarehouse, $theirs] = $this->archived('R-THEIRS', self::TENANT_B);

        $bodies = [];
        foreach ([[$warehouse, $elsewhere], [$theirWarehouse, $theirs], [$warehouse, Uuid::v4()->toRfc4122()], [Uuid::v4()->toRfc4122(), $id], [$warehouse, 'not-a-uuid']] as [$w, $l]) {
            $this->restore($w, $l);
            self::assertSame(404, $this->httpStatus(), $w.' / '.$l);
            $bodies[] = (string) $this->client()->getResponse()->getContent();
        }
        self::assertCount(1, array_unique(\array_slice($bodies, 0, 4)), 'The 404s are not one body.');
        self::assertNotNull($this->stored($elsewhere)['deleted_at'], 'A location of another warehouse was restored under this path.');
        self::assertSame([], $this->auditRowsOf(AuditEvent::STORAGE_LOCATION_RESTORED));
    }

    /**
     * Mutation M1-loc: the restore collision (design §4.1). A live location of the same warehouse took the code; the
     * restore is refused with the restore sentence, never a 500. The same code in another warehouse does not refuse.
     */
    #[Test]
    public function aRestoreOntoATakenCodeIsRefusedByItsOwnRule(): void
    {
        [$warehouse, $id] = $this->archived('R-TAKEN');
        $this->seedStorageLocation(self::TENANT_A, $warehouse, 'R-TAKEN');

        $this->restore($warehouse, $id);

        self::assertSame(409, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        self::assertSame('storage_location.code_is_unique_per_warehouse', $this->json()['rule']);
        self::assertSame(StorageLocationWriter::CODE_TAKEN_ON_RESTORE, $this->json()['detail']);
        self::assertSame('Another location in this warehouse now uses this location\'s code, so it can\'t be restored. It can be restored once that location\'s code is changed.', $this->json()['detail']);
        self::assertNotNull($this->stored($id)['deleted_at']);
        self::assertSame([], $this->auditRowsOf(AuditEvent::STORAGE_LOCATION_RESTORED));

        [$other, $twin] = $this->archived('R-SHARED');
        $this->seedStorageLocation(self::TENANT_A, $this->seedWarehouse(self::TENANT_A, 'Another Site'), 'R-SHARED');
        $this->restore($other, $twin);
        self::assertSame(200, $this->httpStatus(), 'The same code in another warehouse refused the restore.');
    }

    /** One `storage_location.restored`, `{}`, naming the restorer; the subject is the location (business, no IP). */
    #[Test]
    public function aRestoreWritesExactlyOneStorageLocationRestoredRow(): void
    {
        [$warehouse, $id] = $this->archived('R-AUDIT');
        $this->actAs(self::TENANT_A, DefaultRoles::codesFor(DefaultRoles::ADMIN));

        $this->restore($warehouse, $id);

        self::assertSame(200, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        self::assertSame([$this->businessRow(AuditEvent::STORAGE_LOCATION_RESTORED, 'storage_location', $id)], $this->auditRowsOf(AuditEvent::STORAGE_LOCATION_RESTORED));
        self::assertSame([], $this->auditRowsOf(AuditEvent::STORAGE_LOCATION_UPDATED), 'A restore wrote an update row.');
    }

    /** `storage_location.delete` gates the restore: `viewer` and `warehouse_operator` (holds `.edit`) are refused. */
    #[Test]
    public function onlyAHolderOfStorageLocationDeleteMayRestore(): void
    {
        [$warehouse, $id] = $this->archived('R-GUARD');

        foreach ([DefaultRoles::VIEWER, DefaultRoles::WAREHOUSE_OPERATOR] as $role) {
            $this->actAs(self::TENANT_A, DefaultRoles::codesFor($role));
            $this->restore($warehouse, $id);
            self::assertSame(403, $this->httpStatus(), $role);
        }
        self::assertNotNull($this->stored($id)['deleted_at']);
        self::assertSame([], $this->auditRowsOf(AuditEvent::STORAGE_LOCATION_RESTORED));
    }

    // -----------------------------------------------------------------------

    /** @return array{0: string, 1: string} a live warehouse and an archived location of it */
    private function archived(string $code, string $tenantId = self::TENANT_A): array
    {
        $warehouse = $this->seedWarehouse($tenantId, 'Site '.$code);
        $id = $this->seedStorageLocation($tenantId, $warehouse, $code);
        $this->insertAsOwnerForTenant($tenantId, 'UPDATE storage_location SET deleted_at = now() WHERE id = ?::uuid', [$id]);

        return [$warehouse, $id];
    }

    private function restore(string $warehouse, string $id): void
    {
        $this->send('POST', '/warehouses/'.$warehouse.'/locations/'.$id.'/restoration');
    }

    /** @return array{deleted_at: ?string, updated_at: string} */
    private function stored(string $id): array
    {
        return $this->fetchAsOwnerForTenant(self::TENANT_A, 'SELECT deleted_at, updated_at FROM storage_location WHERE id = ?::uuid', [$id])[0];
    }
}
