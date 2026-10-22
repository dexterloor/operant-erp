<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Modules\Purchasing\Infrastructure\Entity\PurchaseOrder;
use App\Modules\UserManagement\Domain\DefaultRoles;
use App\Platform\Http\Idempotency\IdempotencyKeyGuard;
use App\SharedKernel\ValueObject\AuditEvent;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Uid\Uuid;

/**
 * **Suppliers slice 1 C4 — `DeleteSupplierProduct`** (`docs/session13/suppliers-s1-design.md` §6, FINAL F-5). A hard
 * delete: parent 404 → pair 404 → archived parent 409 → `DELETE` → 204, no body. `SupplierProductWriteTest`'s shape.
 *
 * Pinned here: another supplier's entry under this path is not found and survives (the pair predicate); an archived
 * supplier's catalogue cannot lose an entry; re-adding a removed item is a fresh create (no key to replay); removing every
 * entry lets the supplier's currency change again (owner Q-S4 (ii) — the path the refusal's own sentence names, end to end
 * through the API); a placed purchase-order line keeps its snapshot while a new line for the item is refused; and — since
 * G3-C0 (2026-10-22, G-41 closed) — exactly one `supplier_product.deleted` `{supplier_id, inventory_item_id}` row on the entry
 * (never on the supplier), and the removed entry's History stays readable by id to `supplier_product.view` holders.
 * *(Until G3-C0 this read "and no audit row is written (G-41 covers removal)"; the pin was flipped deliberately.)*
 */
final class SupplierProductDeleteTest extends PurchaseOrderTestCase
{
    /** 204, no body; the row is gone; a repeat is the same 404 as an unknown id; one `supplier_product.deleted` row (G3-C0). */
    #[Test]
    public function aBuyerRemovesAnItemFromASuppliersCatalogue(): void
    {
        $supplier = $this->seedSupplier(self::TENANT_A, 'Delisting Supplier');
        $item = $this->seedItem(self::TENANT_A, 'SKU-DEL-1');
        $entry = $this->seedSupplierProduct(self::TENANT_A, $supplier, $item);
        $kept = $this->seedSupplierProduct(self::TENANT_A, $supplier, $this->seedItem(self::TENANT_A, 'SKU-DEL-2'));
        $this->actAs(self::TENANT_A, DefaultRoles::codesFor(DefaultRoles::BUYER));

        $this->delete($supplier, $entry);

        self::assertSame(204, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        self::assertSame('', (string) $this->client()->getResponse()->getContent(), 'A 204 carried a body.');
        self::assertSame([['id' => $kept]], $this->fetchAsOwnerForTenant(self::TENANT_A, 'SELECT id::text AS id FROM supplier_product'),
            'The entry was not removed, or another entry went with it.');
        // G3-C0 (G-41 closed): the entry half flipped from "no row" to exactly one row, deliberately; the supplier half stays
        // "no row" (the subject is the entry, D-C0-1).
        self::assertSame([array_replace($this->businessRow(AuditEvent::SUPPLIER_PRODUCT_DELETED, 'supplier_product', $entry), [
            'metadata' => (string) $this->fetchAsOwnerForTenant(self::TENANT_A, 'SELECT ?::jsonb::text AS m',
                [json_encode(['supplier_id' => $supplier, 'inventory_item_id' => $item], \JSON_THROW_ON_ERROR)])[0]['m'],
        ])], $this->auditRowsOf(AuditEvent::SUPPLIER_PRODUCT_DELETED), 'A catalogue removal must write exactly one supplier_product.deleted row on the entry.');
        self::assertSame(0, $this->countAsOwnerForTenant(self::TENANT_A, 'SELECT count(*) FROM audit_log WHERE subject_id = ?::uuid', [$supplier]),
            'A catalogue removal wrote a row on the supplier: the subject is the entry (D-C0-1).');

        $this->delete($supplier, $entry);
        $repeat = [$this->httpStatus(), (string) $this->client()->getResponse()->getContent()];
        $this->delete($supplier, Uuid::v4()->toRfc4122());
        self::assertSame([404, $repeat[1]], [$repeat[0], (string) $this->client()->getResponse()->getContent()],
            'A repeated delete is not the same 404 as an unknown entry.');
        self::assertCount(1, $this->auditRowsOf(AuditEvent::SUPPLIER_PRODUCT_DELETED), 'A delete that removed nothing (404) wrote a row.');
    }

    /**
     * **A removed entry's History is still readable by id** (G-52's precedent, code-level H-3 (ii); design §1.2 D-C0-1): added,
     * changed and removed through the API, its three rows are served newest first to a `supplier_product.view` holder. The
     * 403 seat (RED L-3) holds `audit_log.view_record` and `supplier.view` and lacks only `supplier_product.view`, so the
     * refusal can come from no other code. Mutations M-C0-3 (subject the supplier) and M-C0-5 (drop the map entry) → red.
     */
    #[Test]
    public function aRemovedEntrysHistoryIsStillReadableById(): void
    {
        $supplier = $this->seedSupplier(self::TENANT_A, 'History Supplier');
        $item = $this->seedItem(self::TENANT_A, 'SKU-HIST');
        $this->actAs(self::TENANT_A, DefaultRoles::codesFor(DefaultRoles::BUYER));
        $this->send('POST', '/suppliers/'.$supplier.'/products', ['inventory_item_id' => $item, 'supplier_sku' => 'SP-HIST', 'unit_cost' => '2',
            'lead_time_days' => 3], [IdempotencyKeyGuard::HEADER => Uuid::v4()->toRfc4122()]);
        self::assertSame(201, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        $entry = (string) $this->json()['id'];
        $this->send('PATCH', '/suppliers/'.$supplier.'/products/'.$entry, ['unit_cost' => '3']);
        self::assertSame(200, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        $this->delete($supplier, $entry);
        self::assertSame(204, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        self::assertSame(0, $this->countAsOwnerForTenant(self::TENANT_A, 'SELECT count(*) FROM supplier_product WHERE id = ?::uuid', [$entry]), 'Control: the entry is not gone.');

        $this->actAs(self::TENANT_A, ['audit_log.view_record', 'supplier_product.view']);
        $this->send('GET', '/audit-log/records?subject_type=supplier_product&subject_id='.$entry);
        self::assertSame(200, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        self::assertSame([AuditEvent::SUPPLIER_PRODUCT_DELETED, AuditEvent::SUPPLIER_PRODUCT_UPDATED, AuditEvent::SUPPLIER_PRODUCT_CREATED],
            array_column($this->json()['items'], 'event_type'), 'A removed catalogue entry\'s History must stay readable by id to supplier_product.view holders.');

        $this->actAs(self::TENANT_A, ['audit_log.view_record', 'supplier.view']);
        $this->send('GET', '/audit-log/records?subject_type=supplier_product&subject_id='.$entry);
        self::assertSame(403, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        self::assertSame('supplier_product.view', $this->json()['required_permission'] ?? null, 'The 403 must name supplier_product.view, the subject type\'s own code.');

        $this->send('GET', '/audit-log/records?subject_type=supplier&subject_id='.$supplier);
        self::assertSame(200, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        self::assertSame([], $this->json()['items'], 'The supplier\'s History shows its catalogue edits: the subject is the entry (new Low gap, G-53\'s twin).');
    }

    /** ST-5: another supplier's entry under this supplier's path is not found and survives (the pair names the row). */
    #[Test]
    public function anotherSuppliersEntryCannotBeRemovedUnderThisPath(): void
    {
        $mine = $this->seedSupplier(self::TENANT_A, 'Mine To Delist');
        $theirs = $this->seedSupplier(self::TENANT_A, 'Theirs To Keep');
        $theirEntry = $this->seedSupplierProduct(self::TENANT_A, $theirs, $this->seedItem(self::TENANT_A, 'SKU-THEIRS-DEL'));

        $this->delete($mine, $theirEntry);

        self::assertSame(404, $this->httpStatus());
        self::assertSame(1, $this->countAsOwnerForTenant(self::TENANT_A, 'SELECT count(*) FROM supplier_product WHERE id = ?::uuid', [$theirEntry]),
            'Another supplier\'s entry was removed through this supplier\'s path.');
    }

    /** ST-5: an unknown, malformed or foreign supplier, or a malformed entry id, is the same 404; nothing is removed. */
    #[Test]
    public function anUnknownSupplierOrEntryIsTheSame404(): void
    {
        $foreign = $this->seedSupplier(self::TENANT_B, 'Foreign Delister');
        $foreignEntry = $this->seedSupplierProduct(self::TENANT_B, $foreign, $this->seedItem(self::TENANT_B, 'SKU-FD'));

        $bodies = [];
        foreach ([[$foreign, $foreignEntry], [Uuid::v4()->toRfc4122(), $foreignEntry], ['not-a-uuid', $foreignEntry], [$foreign, 'not-a-uuid']] as [$supplier, $entry]) {
            $this->delete($supplier, $entry);
            self::assertSame(404, $this->httpStatus(), $supplier.' '.$entry);
            $bodies[] = (string) $this->client()->getResponse()->getContent();
        }
        self::assertSame($bodies[0], $bodies[1]);
        self::assertSame(1, $this->countAsOwnerForTenant(self::TENANT_B, 'SELECT count(*) FROM supplier_product WHERE id = ?::uuid', [$foreignEntry]),
            'Another workspace\'s entry was removed.');
    }

    /** ST-9: an archived supplier's catalogue is read-only — removing an entry is refused and nothing is removed. */
    #[Test]
    public function anArchivedSuppliersCatalogueCannotLoseAnEntry(): void
    {
        $supplier = $this->seedSupplier(self::TENANT_A, 'Archived Delister');
        $entry = $this->seedSupplierProduct(self::TENANT_A, $supplier, $this->seedItem(self::TENANT_A, 'SKU-AR-DEL'));
        $this->insertAsOwnerForTenant(self::TENANT_A, 'UPDATE supplier SET deleted_at = now() WHERE id = ?::uuid', [$supplier]);

        $this->delete($supplier, $entry);

        self::assertSame([409, 'supplier.is_archived', 'This supplier is archived, so nothing can be removed from its catalogue until it\'s restored.'],
            [$this->httpStatus(), $this->json()['rule'] ?? null, $this->json()['detail'] ?? null]);
        self::assertSame(1, $this->countAsOwnerForTenant(self::TENANT_A, 'SELECT count(*) FROM supplier_product WHERE id = ?::uuid', [$entry]));
    }

    /**
     * `supplier_product_unique` is total but the row is gone, so re-adding the item is a FRESH create: 201, a new id, and the
     * terms sent now — nothing of the removed entry is replayed (there is no key to replay).
     */
    #[Test]
    public function reAddingARemovedItemIsAFreshCreate(): void
    {
        $supplier = $this->seedSupplier(self::TENANT_A, 'Relisting Supplier');
        $item = $this->seedItem(self::TENANT_A, 'SKU-RELIST');
        $entry = $this->seedSupplierProduct(self::TENANT_A, $supplier, $item);

        $this->delete($supplier, $entry);
        self::assertSame(204, $this->httpStatus());

        $this->send('POST', '/suppliers/'.$supplier.'/products', ['inventory_item_id' => $item, 'supplier_sku' => 'ACME-RELIST',
            'unit_cost' => '3.5', 'lead_time_days' => 4], [IdempotencyKeyGuard::HEADER => Uuid::v4()->toRfc4122()]);
        self::assertSame(201, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        self::assertNotSame($entry, $this->json()['id'], 'The re-added entry reused the removed entry\'s id.');
        self::assertSame(['ACME-RELIST', '3.5000', 4], [$this->json()['supplier_sku'], $this->json()['unit_cost'], $this->json()['lead_time_days']]);
    }

    /**
     * **Owner Q-S4 (ii), end to end through the API:** the currency is fixed while entries exist; removing every entry with
     * `DeleteSupplierProduct` lets it change, and an entry added afterwards copies the new currency.
     */
    #[Test]
    public function removingEveryEntryLetsTheSuppliersCurrencyChange(): void
    {
        $supplier = $this->seedSupplier(self::TENANT_A, 'Switching Currency');
        $entries = [
            $this->seedSupplierProduct(self::TENANT_A, $supplier, $this->seedItem(self::TENANT_A, 'SKU-CUR-1')),
            $this->seedSupplierProduct(self::TENANT_A, $supplier, $this->seedItem(self::TENANT_A, 'SKU-CUR-2')),
        ];
        $later = $this->seedItem(self::TENANT_A, 'SKU-CUR-3');
        $this->actAs(self::TENANT_A, DefaultRoles::codesFor(DefaultRoles::PURCHASING_MANAGER));

        $this->send('PATCH', '/suppliers/'.$supplier, ['currency_code' => 'USD']);
        self::assertSame([409, 'supplier.currency_is_fixed_while_terms_exist'], [$this->httpStatus(), $this->json()['rule'] ?? null]);

        $this->delete($supplier, $entries[0]);
        self::assertSame(204, $this->httpStatus());
        $this->send('PATCH', '/suppliers/'.$supplier, ['currency_code' => 'USD']);
        self::assertSame(409, $this->httpStatus(), 'One entry is left, so the currency must still be fixed.');

        $this->delete($supplier, $entries[1]);
        self::assertSame(204, $this->httpStatus());
        $this->send('PATCH', '/suppliers/'.$supplier, ['currency_code' => 'USD']);
        self::assertSame([200, 'USD'], [$this->httpStatus(), $this->json()['currency_code'] ?? null], (string) $this->client()->getResponse()->getContent());

        $this->send('POST', '/suppliers/'.$supplier.'/products', ['inventory_item_id' => $later, 'supplier_sku' => 'ACME-USD',
            'unit_cost' => '2', 'lead_time_days' => 1], [IdempotencyKeyGuard::HEADER => Uuid::v4()->toRfc4122()]);
        self::assertSame([201, 'USD'], [$this->httpStatus(), $this->json()['currency_code'] ?? null], 'An entry added after the switch did not copy the new currency.');
    }

    /**
     * Design §6's consequences: placed lines keep their snapshot (nothing references the entry), and a NEW line for the
     * item is refused `purchase_order_line.supplier_lists_the_item` — existing behaviour, now reachable by the API.
     */
    #[Test]
    public function aPlacedOrderLineKeepsItsSnapshotAndANewLineIsRefused(): void
    {
        $supplier = $this->seedSupplier(self::TENANT_A, 'Delisted Under Order');
        $warehouse = $this->seedWarehouse(self::TENANT_A, 'WH Delist');
        $item = $this->seedItem(self::TENANT_A, 'SKU-DELIST-PO');
        $entry = $this->seedSupplierProduct(self::TENANT_A, $supplier, $item, leadTimeDays: 7);
        $draft = $this->seedOrder(self::TENANT_A, 'PO-000201', $supplier, $warehouse);
        $ordered = $this->seedOrder(self::TENANT_A, 'PO-000202', $supplier, $warehouse, status: PurchaseOrder::STATUS_ORDERED);
        $this->seedLine(self::TENANT_A, $draft, $item, leadTimeDays: 7);
        $this->seedLine(self::TENANT_A, $ordered, $item, leadTimeDays: 7);
        $lines = fn (): array => $this->fetchAsOwnerForTenant(self::TENANT_A,
            'SELECT id::text AS id, lead_time_days, unit_cost, quantity_ordered, updated_at::text AS u FROM purchase_order_line ORDER BY id');
        $before = $lines();

        $this->delete($supplier, $entry);
        self::assertSame(204, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        self::assertSame($before, $lines(), 'Removing a catalogue entry moved a placed purchase-order line.');

        $this->send('POST', '/purchase-orders/'.$draft.'/lines', ['inventory_item_id' => $item, 'quantity' => '1', 'unit_cost' => '1'],
            [IdempotencyKeyGuard::HEADER => Uuid::v4()->toRfc4122()]);
        self::assertSame([409, 'purchase_order_line.supplier_lists_the_item'], [$this->httpStatus(), $this->json()['rule'] ?? null]);
    }

    /** `viewer` holds neither `supplier_product` code (PC-12): the delete is refused before anything is read. */
    #[Test]
    public function aViewerCannotRemoveAnEntry(): void
    {
        $supplier = $this->seedSupplier(self::TENANT_A, 'Viewed Not Delisted');
        $entry = $this->seedSupplierProduct(self::TENANT_A, $supplier, $this->seedItem(self::TENANT_A, 'SKU-VIEWER-DEL'));
        $this->actAs(self::TENANT_A, DefaultRoles::codesFor(DefaultRoles::VIEWER));

        $this->delete($supplier, $entry);

        self::assertSame(403, $this->httpStatus());
        self::assertSame(1, $this->countAsOwnerForTenant(self::TENANT_A, 'SELECT count(*) FROM supplier_product WHERE id = ?::uuid', [$entry]));
    }

    // -----------------------------------------------------------------------

    private function delete(string $supplier, string $entry): void
    {
        $this->send('DELETE', '/suppliers/'.$supplier.'/products/'.$entry);
    }
}
