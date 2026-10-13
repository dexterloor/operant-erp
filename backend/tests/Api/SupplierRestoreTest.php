<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Modules\Suppliers\Application\SupplierWriter;
use App\Modules\UserManagement\Domain\DefaultRoles;
use App\SharedKernel\ValueObject\AuditEvent;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Uid\Uuid;

/**
 * **C5 C2a — `RestoreSupplier`, `POST /suppliers/{id}/restoration`, `supplier.delete`** (`docs/session13/c5-design.md`
 * §2.5; §4.2 `guard:supplier.deleted_at`; `RestoreItem`'s shape, API-21 branch 5).
 *
 * 200 with `GetSupplier`'s body in its new state; a live supplier → 409 `supplier.is_not_archived` (the guard that makes
 * a replay safe); a foreign, absent or malformed id → the same 404; a live supplier that took the archived one's code or
 * name meanwhile → 409 on the existing unique rules with restore sentences (the 23505 translated outside the
 * transaction); one `supplier.restored` row, `{}`, on success and none on any refusal. Archived suppliers are seeded by
 * owner SQL (a fixture; `DeleteSupplier`, C5 C2b, archives through the API — the round trip is
 * `SupplierDeleteTest::anArchivedSupplierIsRestoredByTheOperationThatPairsWithIt`). Copy is placeholder (`UX` owes it, design §9 U-1).
 */
final class SupplierRestoreTest extends PurchaseOrderTestCase
{
    /** A buyer (holds `supplier.delete`) restores an archived supplier: 200, the body is `GetSupplier`'s, `archived_at` null. */
    #[Test]
    public function aBuyerRestoresAnArchivedSupplier(): void
    {
        $id = $this->archived('Returning Supplier');
        $this->actAs(self::TENANT_A, DefaultRoles::codesFor(DefaultRoles::BUYER));

        $this->restore($id);

        self::assertSame(200, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        $body = $this->json();
        self::assertSame($id, $body['id']);
        self::assertNull($body['archived_at']);
        self::assertNull($this->stored($id)['deleted_at']);
        $this->send('GET', '/suppliers/'.$id);
        self::assertSame($this->json(), $body, 'The restore body is not GetSupplier\'s.');

        self::assertContains('Returning Supplier', array_column($this->listed(''), 'name'), 'A restored supplier is not back on the live list.');
        self::assertSame([], $this->listed('?archived=true'), 'A restored supplier is still on the Archived view.');
    }

    /** The §4.2 guard: restoring a live supplier is refused, so a replayed restore is told the first one committed. */
    #[Test]
    public function restoringALiveSupplierIsRefusedByTheGuard(): void
    {
        $id = $this->archived('Once Archived');

        $this->restore($id);
        self::assertSame(200, $this->httpStatus());
        $updatedAt = $this->stored($id)['updated_at'];

        $this->restore($id);
        self::assertSame(409, $this->httpStatus());
        self::assertSame('supplier.is_not_archived', $this->json()['rule']);
        self::assertSame('This supplier is not archived, so there is nothing to restore.', $this->json()['detail']);
        self::assertSame($updatedAt, $this->stored($id)['updated_at'], 'A refused restore wrote the row.');
        self::assertCount(1, $this->auditRowsOf(AuditEvent::SUPPLIER_RESTORED), 'A refused restore wrote a trail row.');
    }

    /** ST-5: a foreign, absent or malformed id is the same 404 as `GetSupplier`'s, and nothing is written. */
    #[Test]
    public function aRestoreOfAnUnknownSupplierIsTheSame404(): void
    {
        $theirs = $this->archived('Theirs', self::TENANT_B);
        $bodies = [];
        foreach ([$theirs, Uuid::v4()->toRfc4122(), 'not-a-uuid'] as $id) {
            $this->restore($id);
            self::assertSame(404, $this->httpStatus(), $id);
            $bodies[] = (string) $this->client()->getResponse()->getContent();
        }
        self::assertSame($bodies[0], $bodies[1]);
        self::assertSame(1, $this->countAsOwnerForTenant(self::TENANT_B, "SELECT count(*) FROM supplier WHERE name = 'Theirs' AND deleted_at IS NOT NULL"));
    }

    /**
     * Mutation M10: the restore collision (design §2.5). While a supplier was archived a live one took its code (or its
     * name — both live uniques are partial on `deleted_at IS NULL`); the restore is refused on the existing rule with a
     * restore sentence, never a 500, and writes nothing.
     */
    #[Test]
    public function aRestoreOntoATakenCodeOrNameIsRefusedByItsOwnRule(): void
    {
        $byCode = $this->archived('Original Code Holder');
        $code = $this->stored($byCode)['code'];
        $taker = $this->seedSupplier(self::TENANT_A, 'Code Taker');
        $this->insertAsOwnerForTenant(self::TENANT_A, 'UPDATE supplier SET code = ? WHERE id = ?::uuid', [$code, $taker]);

        $this->restore($byCode);
        self::assertSame(409, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        self::assertSame('supplier.code_is_unique_per_workspace', $this->json()['rule']);
        self::assertSame(SupplierWriter::CODE_TAKEN_ON_RESTORE, $this->json()['detail']);
        self::assertSame('A live supplier now uses this supplier\'s code. Change that supplier\'s code, then restore this one.', $this->json()['detail']);
        self::assertNotNull($this->stored($byCode)['deleted_at']);

        $byName = $this->archived('Shared Name');
        // The live twin gets a different code, so only the name unique can fire (seedSupplier's code is name-derived).
        $twin = $this->seedSupplier(self::TENANT_A, 'Shared Name');
        $this->insertAsOwnerForTenant(self::TENANT_A, "UPDATE supplier SET code = 'S-TWINCODE' WHERE id = ?::uuid", [$twin]);

        $this->restore($byName);
        self::assertSame(409, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        self::assertSame('supplier.name_is_unique_per_workspace', $this->json()['rule']);
        self::assertSame('A live supplier now uses this supplier\'s name. Rename that supplier, then restore this one.', $this->json()['detail']);
        self::assertNotNull($this->stored($byName)['deleted_at']);

        self::assertSame([], $this->auditRowsOf(AuditEvent::SUPPLIER_RESTORED), 'A refused restore wrote a trail row.');
    }

    /** One `supplier.restored`, `{}`, naming the restorer; the subject is the supplier (business, PC-11 (4a), no IP). */
    #[Test]
    public function aRestoreWritesExactlyOneSupplierRestoredRow(): void
    {
        $id = $this->archived('Audited Return');
        $this->actAs(self::TENANT_A, DefaultRoles::codesFor(DefaultRoles::PURCHASING_MANAGER));

        $this->restore($id);

        self::assertSame(200, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        self::assertSame([$this->businessRow(AuditEvent::SUPPLIER_RESTORED, 'supplier', $id)], $this->auditRowsOf(AuditEvent::SUPPLIER_RESTORED));
        self::assertSame([], $this->auditRowsOf(AuditEvent::SUPPLIER_UPDATED), 'A restore wrote an update row.');
    }

    /** `supplier.delete` gates the restore (§4.2): `viewer` and a holder of `supplier.edit` alone are refused, and nothing moves. */
    #[Test]
    public function onlyAHolderOfSupplierDeleteMayRestore(): void
    {
        $id = $this->archived('Guarded');

        foreach ([DefaultRoles::codesFor(DefaultRoles::VIEWER), ['supplier.view', 'supplier.edit']] as $codes) {
            $this->actAs(self::TENANT_A, $codes);
            $this->restore($id);
            self::assertSame(403, $this->httpStatus());
        }
        self::assertNotNull($this->stored($id)['deleted_at']);
        self::assertSame([], $this->auditRowsOf(AuditEvent::SUPPLIER_RESTORED));
    }

    /** Archiving and restoring touch no child: the catalogue comes back unchanged (all children `hard`; design §4.1). */
    #[Test]
    public function aRestoredSupplierKeepsItsCatalogue(): void
    {
        $id = $this->seedSupplier(self::TENANT_A, 'With Catalogue');
        $entry = $this->seedSupplierProduct(self::TENANT_A, $id, $this->seedItem(self::TENANT_A, 'SKU-REST-1'));
        $before = $this->fetchAsOwnerForTenant(self::TENANT_A, 'SELECT * FROM supplier_product WHERE id = ?::uuid', [$entry]);
        $this->insertAsOwnerForTenant(self::TENANT_A, 'UPDATE supplier SET deleted_at = now() WHERE id = ?::uuid', [$id]);

        $this->restore($id);

        self::assertSame(200, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        self::assertSame($before, $this->fetchAsOwnerForTenant(self::TENANT_A, 'SELECT * FROM supplier_product WHERE id = ?::uuid', [$entry]));
    }

    // -----------------------------------------------------------------------

    private function archived(string $name, string $tenantId = self::TENANT_A): string
    {
        $id = $this->seedSupplier($tenantId, $name);
        $this->insertAsOwnerForTenant($tenantId, 'UPDATE supplier SET deleted_at = now() WHERE id = ?::uuid', [$id]);

        return $id;
    }

    private function restore(string $id): void
    {
        $this->send('POST', '/suppliers/'.$id.'/restoration');
    }

    /** @return list<array<string, mixed>> */
    private function listed(string $query): array
    {
        $this->send('GET', '/suppliers'.$query);
        self::assertSame(200, $this->httpStatus());

        return $this->json()['items'];
    }

    /** @return array{code: string, deleted_at: ?string, updated_at: string} */
    private function stored(string $id): array
    {
        return $this->fetchAsOwnerForTenant(self::TENANT_A, 'SELECT code, deleted_at, updated_at FROM supplier WHERE id = ?::uuid', [$id])[0];
    }
}
