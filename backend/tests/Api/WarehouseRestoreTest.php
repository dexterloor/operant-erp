<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Modules\UserManagement\Domain\DefaultRoles;
use App\Modules\Warehouses\Application\WarehouseWriter;
use App\SharedKernel\ValueObject\AuditEvent;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Uid\Uuid;

/**
 * **Warehouse/location delete-restore C1 — `RestoreWarehouse`, `POST /warehouses/{id}/restoration`, `warehouse.delete`**
 * (`docs/session13/wh-loc-delete-design.md` §3.3, §4.1, §7.3, Revision 2; §4.2 `guard:warehouse.deleted_at`;
 * `SupplierRestoreTest`'s shape, API-21 branch 5).
 *
 * 200 with `GetWarehouse`'s body in its new state; a live warehouse → 409 `warehouse.is_not_archived`; a foreign, absent or
 * malformed id → the same 404; a live warehouse that took the archived one's code meanwhile → 409
 * `warehouse.code_is_unique_per_workspace` with the restore sentence (the 23505 translated outside the transaction); one
 * `warehouse.restored` row, `{}`, on success and none on any refusal; its locations untouched (owner Q-2 (a)). Archived
 * warehouses are seeded by owner SQL (written before `DeleteWarehouse`, C4). Copy is `UX`'s final (`wh-loc-delete-roles.md` U-1).
 */
final class WarehouseRestoreTest extends PurchaseOrderTestCase
{
    /** An admin (holds `warehouse.delete`) restores an archived warehouse: 200, the body is `GetWarehouse`'s, `archived_at` null. */
    #[Test]
    public function anAdminRestoresAnArchivedWarehouse(): void
    {
        $id = $this->archived('Returning Site');
        $before = $this->stored($id)['updated_at'];
        $this->actAs(self::TENANT_A, DefaultRoles::codesFor(DefaultRoles::ADMIN));

        $this->restore($id);

        self::assertSame(200, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        $body = $this->json();
        self::assertSame($id, $body['id']);
        self::assertNull($body['archived_at']);
        self::assertNull($this->stored($id)['deleted_at']);
        self::assertNotSame($before, $this->stored($id)['updated_at'], 'A restore did not move updated_at (mutation M5).');
        $this->send('GET', '/warehouses/'.$id);
        self::assertSame($this->json(), $body, 'The restore body is not GetWarehouse\'s.');

        self::assertContains($id, array_column($this->listed(''), 'id'), 'A restored warehouse is not back on the live list.');
        self::assertSame([], $this->listed('?archived=true'), 'A restored warehouse is still on the Archived view.');
    }

    /** The §4.2 guard: restoring a live warehouse is refused, so a replayed restore is told the first one committed. */
    #[Test]
    public function restoringALiveWarehouseIsRefusedByTheGuard(): void
    {
        $id = $this->archived('Once Archived');

        $this->restore($id);
        self::assertSame(200, $this->httpStatus());
        $updatedAt = $this->stored($id)['updated_at'];

        $this->restore($id);
        self::assertSame(409, $this->httpStatus());
        self::assertSame('warehouse.is_not_archived', $this->json()['rule']);
        self::assertSame('This warehouse is not archived, so there is nothing to restore.', $this->json()['detail']);
        self::assertSame($updatedAt, $this->stored($id)['updated_at'], 'A refused restore wrote the row.');
        self::assertCount(1, $this->auditRowsOf(AuditEvent::WAREHOUSE_RESTORED), 'A refused restore wrote a trail row.');
    }

    /** ST-5: a foreign, absent or malformed id is the same 404 as `GetWarehouse`'s, and nothing is written. */
    #[Test]
    public function aRestoreOfAnUnknownWarehouseIsTheSame404(): void
    {
        $theirs = $this->archived('Theirs', self::TENANT_B);
        $bodies = [];
        foreach ([$theirs, Uuid::v4()->toRfc4122(), 'not-a-uuid'] as $id) {
            $this->restore($id);
            self::assertSame(404, $this->httpStatus(), $id);
            $bodies[] = (string) $this->client()->getResponse()->getContent();
        }
        self::assertSame($bodies[0], $bodies[1]);
        self::assertSame($bodies[1], $bodies[2]);
        self::assertSame(1, $this->countAsOwnerForTenant(self::TENANT_B, 'SELECT count(*) FROM warehouse WHERE id = ?::uuid AND deleted_at IS NOT NULL', [$theirs]));
        self::assertSame([], $this->auditRowsOf(AuditEvent::WAREHOUSE_RESTORED));
    }

    /**
     * Mutation M1: the restore collision (design §4.1). While a warehouse was archived a live one took its code (the live
     * unique is partial on `deleted_at IS NULL`); the restore is refused on the existing rule with the restore sentence,
     * never a 500, and writes nothing. The name is not unique, so a namesake does not refuse (G-48, `PO` P-6).
     */
    #[Test]
    public function aRestoreOntoATakenCodeIsRefusedByItsOwnRule(): void
    {
        $id = $this->archived('Original Code Holder');
        $code = $this->stored($id)['code'];
        $taker = $this->seedWarehouse(self::TENANT_A, 'Code Taker');
        $this->insertAsOwnerForTenant(self::TENANT_A, 'UPDATE warehouse SET code = ? WHERE id = ?::uuid', [$code, $taker]);

        $this->restore($id);

        self::assertSame(409, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        self::assertSame('warehouse.code_is_unique_per_workspace', $this->json()['rule']);
        self::assertSame(WarehouseWriter::CODE_TAKEN_ON_RESTORE, $this->json()['detail']);
        self::assertSame('Another warehouse now uses this warehouse\'s code, so it can\'t be restored. It can be restored once that warehouse\'s code is changed.', $this->json()['detail']);
        self::assertNotNull($this->stored($id)['deleted_at']);
        self::assertSame([], $this->auditRowsOf(AuditEvent::WAREHOUSE_RESTORED), 'A refused restore wrote a trail row.');

        $namesake = $this->archived('Shared Site Name');
        $this->seedWarehouse(self::TENANT_A, 'Shared Site Name');
        $this->restore($namesake);
        self::assertSame(200, $this->httpStatus(), 'A live namesake refused the restore; warehouse names are not unique.');
    }

    /** One `warehouse.restored`, `{}`, naming the restorer; the subject is the warehouse (business, PC-11 (4a), no IP). */
    #[Test]
    public function aRestoreWritesExactlyOneWarehouseRestoredRow(): void
    {
        $id = $this->archived('Audited Return');
        $this->actAs(self::TENANT_A, DefaultRoles::codesFor(DefaultRoles::ADMIN));

        $this->restore($id);

        self::assertSame(200, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        self::assertSame([$this->businessRow(AuditEvent::WAREHOUSE_RESTORED, 'warehouse', $id)], $this->auditRowsOf(AuditEvent::WAREHOUSE_RESTORED));
        self::assertSame([], $this->auditRowsOf(AuditEvent::WAREHOUSE_UPDATED), 'A restore wrote an update row.');
    }

    /**
     * `warehouse.delete` gates the restore (§4.2): `viewer`, `sales_rep` and `warehouse_operator` (which holds `warehouse.edit`)
     * are refused, and nothing moves.
     */
    #[Test]
    public function onlyAHolderOfWarehouseDeleteMayRestore(): void
    {
        $id = $this->archived('Guarded');

        foreach ([DefaultRoles::VIEWER, DefaultRoles::SALES_REP, DefaultRoles::WAREHOUSE_OPERATOR] as $role) {
            $this->actAs(self::TENANT_A, DefaultRoles::codesFor($role));
            $this->restore($id);
            self::assertSame(403, $this->httpStatus(), $role);
        }
        self::assertNotNull($this->stored($id)['deleted_at']);
        self::assertSame([], $this->auditRowsOf(AuditEvent::WAREHOUSE_RESTORED));
    }

    /**
     * Owner Q-2 (a): a warehouse's locations are left as they are. A location left live while the warehouse was archived is
     * usable again at once (a location can be added beside it, it can be changed); one archived on its own stays archived.
     */
    #[Test]
    public function aRestoredWarehouseKeepsItsLocationsAsTheyWere(): void
    {
        $id = $this->seedWarehouse(self::TENANT_A, 'With Locations');
        $live = $this->seedStorageLocation(self::TENANT_A, $id, 'A-LIVE');
        $own = $this->seedStorageLocation(self::TENANT_A, $id, 'A-OWN');
        $this->insertAsOwnerForTenant(self::TENANT_A, 'UPDATE storage_location SET deleted_at = now() WHERE id = ?::uuid', [$own]);
        $before = $this->fetchAsOwnerForTenant(self::TENANT_A, 'SELECT id, code, kind, deleted_at FROM storage_location WHERE warehouse_id = ?::uuid ORDER BY code', [$id]);
        $this->insertAsOwnerForTenant(self::TENANT_A, 'UPDATE warehouse SET deleted_at = now() WHERE id = ?::uuid', [$id]);

        $this->restore($id);

        self::assertSame(200, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        self::assertSame($before, $this->fetchAsOwnerForTenant(self::TENANT_A,
            'SELECT id, code, kind, deleted_at FROM storage_location WHERE warehouse_id = ?::uuid ORDER BY code', [$id]), 'A restore touched a location.');
        $this->send('PATCH', '/warehouses/'.$id.'/locations/'.$live, ['code' => 'A-LIVE-2']);
        self::assertSame(200, $this->httpStatus(), 'A location left live is not usable after its warehouse is restored.');
    }

    // -----------------------------------------------------------------------

    private function archived(string $name, string $tenantId = self::TENANT_A): string
    {
        $id = $this->seedWarehouse($tenantId, $name);
        $this->insertAsOwnerForTenant($tenantId, 'UPDATE warehouse SET deleted_at = now() WHERE id = ?::uuid', [$id]);

        return $id;
    }

    private function restore(string $id): void
    {
        $this->send('POST', '/warehouses/'.$id.'/restoration');
    }

    /** @return list<array<string, mixed>> */
    private function listed(string $query): array
    {
        $this->send('GET', '/warehouses'.$query);
        self::assertSame(200, $this->httpStatus());

        return $this->json()['items'];
    }

    /** @return array{code: string, deleted_at: ?string, updated_at: string} */
    private function stored(string $id): array
    {
        return $this->fetchAsOwnerForTenant(self::TENANT_A, 'SELECT code, deleted_at, updated_at FROM warehouse WHERE id = ?::uuid', [$id])[0];
    }
}
