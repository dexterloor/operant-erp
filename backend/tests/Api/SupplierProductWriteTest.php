<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Modules\Purchasing\Infrastructure\Entity\PurchaseOrder;
use App\Modules\Suppliers\Application\SupplierProductWriter;
use App\Modules\UserManagement\Domain\DefaultRoles;
use App\Platform\Http\Idempotency\IdempotencyKeyGuard;
use App\SharedKernel\ValueObject\AuditEvent;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Uid\Uuid;

/**
 * **Suppliers slice 1 C3 — `CreateSupplierProduct`, `UpdateSupplierProduct`, and `UpdateSupplier`'s currency rule**
 * (`docs/session13/suppliers-s1-design.md` FINAL F-4 and §5; owner ruling Q-S4 (a)+(ii), Q-S5; final RED M-B).
 * `CustomerAddressWriteTest`'s shape.
 *
 * Each refusal's literal sentence is pinned once here (placeholder copy, `UX` owes it). Every `supplier_sku` and item SKU is
 * LETTER-bearing (CD-3).
 *
 * - **Currency (Q-S4):** an entry's `currency_code` is the supplier's, copied at create and never accepted from the body;
 *   the supplier's currency cannot CHANGE while it has entries (409 `supplier.currency_is_fixed_while_terms_exist`). The two
 *   race tests are real second connections (`hold_order_lock.php`), one per order, and each fails if the request never
 *   waited — so dropping `CreateSupplierProduct`'s `FOR SHARE` ({@see SupplierProductWriter::PARENT_LOCK}) or
 *   `UpdateSupplier`'s `FOR NO KEY UPDATE` is red here (final RED M-B).
 * - **Snapshot:** correcting terms moves no placed purchase-order line; a line added afterwards reads the new lead time.
 * - **The trail** (G3-C0, 2026-10-22; G-41's closing work, `docs/session13/g3-g7b-reorder-settings-design.md` §1 and
 *   Revision 1 FINAL): `supplier_product.created` `{supplier_id, inventory_item_id}` after the INSERT, `supplier_product.updated`
 *   `{"fields": [...]}` — the changed wire names, **never values** (`unit_cost` is money, PC-12) — after an UPDATE that
 *   changed something, compared numerically at column scale under the entry's row lock; **a PATCH that changes nothing writes
 *   nothing** (no UPDATE, `updated_at` unmoved — owner notice N-1). *(Until G3-C0 this bullet read "No trail (owner Q-S5):
 *   catalogue changes write no `audit_log` row — G-41, pinned here so a trail added later is a decision, not a surprise"; the
 *   two no-row pins were flipped deliberately.)*
 */
final class SupplierProductWriteTest extends PurchaseOrderTestCase
{
    private const HOLDER_DEADLINE_SECONDS = 10.0;

    /** 201, the entry as the body, `Location` naming the row-level URI §4.2 declares; the currency is the supplier's. */
    #[Test]
    public function aBuyerAddsAnItemToASuppliersCatalogue(): void
    {
        $supplier = $this->seedSupplier(self::TENANT_A, 'Catalogue Owner');
        $item = $this->seedItem(self::TENANT_A, 'SKU-BOLT');
        $this->actAs(self::TENANT_A, DefaultRoles::codesFor(DefaultRoles::BUYER));

        $this->create($supplier, ['inventory_item_id' => $item, 'supplier_sku' => 'ACME-BOLT', 'unit_cost' => '12.5', 'lead_time_days' => 9]);

        self::assertSame(201, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        $body = $this->json();
        self::assertTrue(Uuid::isValid($body['id']));
        self::assertSame(['id' => $body['id'], 'inventory_item' => ['id' => $item, 'sku' => 'SKU-BOLT', 'name' => 'SKU-BOLT'],
            'supplier_sku' => 'ACME-BOLT', 'unit_cost' => '12.5000', 'lead_time_days' => 9, 'minimum_order_quantity' => '1.000000',
            'currency_code' => 'EUR'], $body, 'The MOQ omitted takes the column default 1; the currency is copied from the supplier.');
        self::assertSame('/api/v1/suppliers/'.$supplier.'/products/'.$body['id'], $this->client()->getResponse()->headers->get('Location'));

        $this->send('GET', '/suppliers/'.$supplier.'/products');
        self::assertSame([$body], $this->json()['items'], 'The 201 body is not the list\'s item.');
        // G3-C0 (G-41 closed): flipped from "no row" to exactly one row, deliberately.
        self::assertSame([$this->catalogueRow(AuditEvent::SUPPLIER_PRODUCT_CREATED, $body['id'], ['supplier_id' => $supplier, 'inventory_item_id' => $item])],
            $this->auditRowsOf(AuditEvent::SUPPLIER_PRODUCT_CREATED), 'A catalogue add must write exactly one supplier_product.created row naming its creator.');
        self::assertSame([], $this->fetchAsOwnerForTenant(self::TENANT_A, 'SELECT id FROM audit_log WHERE subject_id = ?::uuid', [$supplier]),
            'A catalogue add wrote a row on the supplier: the subject is the entry (D-C0-1).');
    }

    /** Every field is checked by shape before anything is read; nothing is written. */
    #[Test]
    public function aMalformedEntryIsRefusedFieldByField(): void
    {
        $supplier = $this->seedSupplier(self::TENANT_A, 'Strict Catalogue');

        $this->create($supplier, []);
        self::assertSame(422, $this->httpStatus());
        self::assertSame(array_map(static fn (string $f): array => ['pointer' => '/'.$f, 'code' => 'required', 'message' => 'This field is required.'],
            ['inventory_item_id', 'supplier_sku', 'unit_cost', 'lead_time_days']), $this->fieldErrors());

        $this->create($supplier, ['inventory_item_id' => 'not-a-uuid', 'supplier_sku' => '  ', 'unit_cost' => '-1', 'lead_time_days' => -1,
            'minimum_order_quantity' => '0']);
        self::assertSame([
            ['pointer' => '/inventory_item_id', 'code' => 'not_a_uuid', 'message' => 'This field must be a UUID naming an item in this workspace.'],
            ['pointer' => '/supplier_sku', 'code' => 'blank', 'message' => 'This field may not be blank.'],
            ['pointer' => '/unit_cost', 'code' => 'not_a_decimal', 'message' => 'This field must be a non-negative decimal string with at most 15 integer digits and 4 fractional digits.'],
            ['pointer' => '/lead_time_days', 'code' => 'out_of_range', 'message' => 'Lead time must be a whole number of days, 0 or more.'],
            ['pointer' => '/minimum_order_quantity', 'code' => 'not_a_positive_decimal', 'message' => 'This field must be a positive decimal string with at most 13 integer digits and 6 fractional digits.'],
        ], $this->fieldErrors());

        // Past each column's width, and a lead time sent as text.
        $this->create($supplier, ['inventory_item_id' => Uuid::v4()->toRfc4122(), 'supplier_sku' => 'SP-WIDE', 'unit_cost' => '1.23456',
            'lead_time_days' => 2147483648, 'minimum_order_quantity' => '1.1234567']);
        self::assertSame(['/unit_cost', '/lead_time_days', '/minimum_order_quantity'], array_column($this->fieldErrors(), 'pointer'));
        $this->create($supplier, ['inventory_item_id' => Uuid::v4()->toRfc4122(), 'supplier_sku' => 'SP-TEXT', 'unit_cost' => '1', 'lead_time_days' => '7']);
        self::assertSame([['pointer' => '/lead_time_days', 'code' => 'out_of_range', 'message' => 'Lead time must be a whole number of days, 0 or more.']], $this->fieldErrors());

        self::assertSame(0, $this->countAsOwnerForTenant(self::TENANT_A, 'SELECT count(*) FROM supplier_product'));
    }

    /** Q-S4 (a): the currency is never accepted from the body (API-9), so the supplier's is the only one an entry can carry. */
    #[Test]
    public function aCurrencyCannotBeSent(): void
    {
        $supplier = $this->seedSupplier(self::TENANT_A, 'Currency Is Copied');

        $this->create($supplier, ['inventory_item_id' => $this->seedItem(self::TENANT_A, 'SKU-CUR'), 'supplier_sku' => 'SP-CUR', 'unit_cost' => '1',
            'lead_time_days' => 1, 'currency_code' => 'USD']);

        self::assertSame(422, $this->httpStatus());
        self::assertSame('undeclared_field', $this->fieldErrors()[0]['code'] ?? null, (string) $this->client()->getResponse()->getContent());
        self::assertSame(0, $this->countAsOwnerForTenant(self::TENANT_A, 'SELECT count(*) FROM supplier_product'));
    }

    /** ST-5's oracle on the field: a random id, another workspace's item and an archived item are one refusal. */
    #[Test]
    public function anUnknownOrArchivedItemIsRefused(): void
    {
        $supplier = $this->seedSupplier(self::TENANT_A, 'Item Check');
        $archived = $this->seedItem(self::TENANT_A, 'SKU-ARCH');
        $this->insertAsOwnerForTenant(self::TENANT_A, 'UPDATE inventory_item SET deleted_at = now() WHERE id = ?::uuid', [$archived]);

        foreach ([Uuid::v4()->toRfc4122(), $this->seedItem(self::TENANT_B, 'SKU-THEIRS'), $archived] as $item) {
            $this->create($supplier, ['inventory_item_id' => $item, 'supplier_sku' => 'SP-X', 'unit_cost' => '1', 'lead_time_days' => 1]);
            self::assertSame(422, $this->httpStatus(), $item);
            self::assertSame([['pointer' => '/inventory_item_id', 'code' => 'unknown_reference', 'message' => 'No live inventory item in this workspace has this id.']],
                $this->fieldErrors());
        }
        self::assertSame(0, $this->countAsOwnerForTenant(self::TENANT_A, 'SELECT count(*) FROM supplier_product'));
    }

    /**
     * API-14: the header is demanded (428), then unused: `supplier_product_unique` (supplier, item) refuses a second entry,
     * never replays it. Another supplier may list the same item.
     */
    #[Test]
    public function anItemIsListedOncePerSupplier(): void
    {
        $supplier = $this->seedSupplier(self::TENANT_A, 'Lists Once');
        $item = $this->seedItem(self::TENANT_A, 'SKU-ONCE');
        $body = ['inventory_item_id' => $item, 'supplier_sku' => 'SP-ONCE', 'unit_cost' => '1', 'lead_time_days' => 1];

        $this->send('POST', '/suppliers/'.$supplier.'/products', $body);
        self::assertSame(428, $this->httpStatus());

        $this->create($supplier, $body);
        self::assertSame(201, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());

        $this->create($supplier, array_replace($body, ['supplier_sku' => 'SP-AGAIN', 'unit_cost' => '2']));
        self::assertSame(409, $this->httpStatus());
        self::assertSame('supplier_product.item_is_listed_once_per_supplier', $this->json()['rule']);
        self::assertSame('This supplier already lists this item. Change its terms instead.', $this->json()['detail']);
        self::assertSame([['supplier_sku' => 'SP-ONCE', 'unit_cost' => '1.0000']], $this->fetchAsOwnerForTenant(self::TENANT_A,
            'SELECT supplier_sku, unit_cost FROM supplier_product WHERE supplier_id = ?::uuid', [$supplier]));

        $this->create($this->seedSupplier(self::TENANT_A, 'Lists It Too'), $body);
        self::assertSame(201, $this->httpStatus(), 'Another supplier could not list the same item.');
    }

    /** ST-9: an archived supplier's catalogue is read-only, on create and on update; nothing is written. */
    #[Test]
    public function anArchivedSuppliersCatalogueCannotBeChanged(): void
    {
        $supplier = $this->seedSupplier(self::TENANT_A, 'Archived Supplier');
        $entry = $this->seedSupplierProduct(self::TENANT_A, $supplier, $this->seedItem(self::TENANT_A, 'SKU-AR-1'));
        $this->insertAsOwnerForTenant(self::TENANT_A, 'UPDATE supplier SET deleted_at = now() WHERE id = ?::uuid', [$supplier]);

        $this->create($supplier, ['inventory_item_id' => $this->seedItem(self::TENANT_A, 'SKU-AR-2'), 'supplier_sku' => 'SP-AR', 'unit_cost' => '1', 'lead_time_days' => 1]);
        self::assertSame([409, 'supplier.is_archived', 'This supplier is archived, so nothing can be added to its catalogue until it\'s restored.'],
            [$this->httpStatus(), $this->json()['rule'] ?? null, $this->json()['detail'] ?? null]);

        $this->update($supplier, $entry, ['unit_cost' => '9']);
        self::assertSame([409, 'supplier.is_archived', 'This supplier is archived, so its catalogue can\'t be changed until it\'s restored.'],
            [$this->httpStatus(), $this->json()['rule'] ?? null, $this->json()['detail'] ?? null]);

        self::assertSame([['id' => $entry, 'unit_cost' => '1.0000']], $this->fetchAsOwnerForTenant(self::TENANT_A, 'SELECT id::text AS id, unit_cost FROM supplier_product'));
    }

    /** ST-5: an unknown, malformed or foreign supplier is the same 404 on both writes. */
    #[Test]
    public function anUnknownSupplierIsTheSame404(): void
    {
        $foreign = $this->seedSupplier(self::TENANT_B, 'Foreign Writer');
        $foreignEntry = $this->seedSupplierProduct(self::TENANT_B, $foreign, $this->seedItem(self::TENANT_B, 'SKU-FW'));
        $item = $this->seedItem(self::TENANT_A, 'SKU-404');

        $bodies = [];
        foreach ([$foreign, Uuid::v4()->toRfc4122(), 'not-a-uuid'] as $supplier) {
            $this->create($supplier, ['inventory_item_id' => $item, 'supplier_sku' => 'SP-404', 'unit_cost' => '1', 'lead_time_days' => 1]);
            self::assertSame(404, $this->httpStatus(), $supplier);
            $bodies[] = (string) $this->client()->getResponse()->getContent();
            $this->update($supplier, $foreignEntry, ['unit_cost' => '9']);
            self::assertSame(404, $this->httpStatus(), $supplier);
        }
        self::assertSame($bodies[0], $bodies[1]);
        self::assertSame(0, $this->countAsOwnerForTenant(self::TENANT_A, 'SELECT count(*) FROM supplier_product'));
        self::assertSame('1.0000', $this->fetchAsOwnerForTenant(self::TENANT_B, 'SELECT unit_cost FROM supplier_product')[0]['unit_cost']);
    }

    /** ST-5: another supplier's entry under this supplier's path is not found, never changed (the pair names the row). */
    #[Test]
    public function anotherSuppliersEntryIsNotFoundUnderThisPath(): void
    {
        $mine = $this->seedSupplier(self::TENANT_A, 'Mine');
        $theirs = $this->seedSupplier(self::TENANT_A, 'Theirs');
        $theirEntry = $this->seedSupplierProduct(self::TENANT_A, $theirs, $this->seedItem(self::TENANT_A, 'SKU-THEIRS'));

        $this->update($mine, $theirEntry, ['unit_cost' => '99']);

        self::assertSame(404, $this->httpStatus());
        self::assertSame('1.0000', $this->fetchAsOwnerForTenant(self::TENANT_A, 'SELECT unit_cost FROM supplier_product WHERE id = ?::uuid', [$theirEntry])[0]['unit_cost']);
    }

    /** A PATCH sets exactly the submitted terms; `null` on any field and the item or currency are refused; an empty PATCH writes nothing. */
    #[Test]
    public function termsAreCorrectedFieldByField(): void
    {
        $supplier = $this->seedSupplier(self::TENANT_A, 'Repriced');
        $item = $this->seedItem(self::TENANT_A, 'SKU-REP');
        $entry = $this->seedSupplierProduct(self::TENANT_A, $supplier, $item);
        // Seeded before any request: a refused request closes the shared entity manager `seedItem()` writes through.
        $anotherItem = $this->seedItem(self::TENANT_A, 'SKU-REP-2');
        $this->actAs(self::TENANT_A, DefaultRoles::codesFor(DefaultRoles::PURCHASING_MANAGER));

        $this->update($supplier, $entry, ['unit_cost' => '7.25', 'lead_time_days' => 12]);
        self::assertSame(200, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        self::assertSame(['id' => $entry, 'inventory_item' => ['id' => $item, 'sku' => 'SKU-REP', 'name' => 'SKU-REP'],
            'supplier_sku' => 'SP-'.substr(md5($item), 0, 8), 'unit_cost' => '7.2500', 'lead_time_days' => 12, 'minimum_order_quantity' => '1.000000',
            'currency_code' => 'EUR'], $this->json());

        $this->update($supplier, $entry, ['supplier_sku' => 'NEW-SKU', 'minimum_order_quantity' => '24']);
        self::assertSame(['NEW-SKU', '24.000000', '7.2500', 12], [$this->json()['supplier_sku'], $this->json()['minimum_order_quantity'],
            $this->json()['unit_cost'], $this->json()['lead_time_days']]);

        $this->update($supplier, $entry, ['supplier_sku' => null, 'unit_cost' => null, 'lead_time_days' => null, 'minimum_order_quantity' => null]);
        self::assertSame(422, $this->httpStatus());
        self::assertSame(['not_nullable', 'not_nullable', 'not_nullable', 'not_nullable'], array_column($this->fieldErrors(), 'code'));

        foreach (['inventory_item_id' => $anotherItem, 'currency_code' => 'USD'] as $field => $value) {
            $this->update($supplier, $entry, [$field => $value]);
            self::assertSame(422, $this->httpStatus(), $field);
            self::assertSame('undeclared_field', $this->fieldErrors()[0]['code'] ?? null, $field);
        }

        $before = $this->fetchAsOwnerForTenant(self::TENANT_A, 'SELECT updated_at::text AS u FROM supplier_product WHERE id = ?::uuid', [$entry]);
        $this->update($supplier, $entry, []);
        self::assertSame(200, $this->httpStatus());
        self::assertSame($before, $this->fetchAsOwnerForTenant(self::TENANT_A, 'SELECT updated_at::text AS u FROM supplier_product WHERE id = ?::uuid', [$entry]),
            'An empty PATCH issued an UPDATE.');
        // G3-C0 (G-41 closed): flipped from "no row" to one row per changing PATCH, deliberately. The refused and empty
        // PATCHes above wrote none.
        self::assertSame([
            $this->catalogueRow(AuditEvent::SUPPLIER_PRODUCT_UPDATED, $entry, ['fields' => ['lead_time_days', 'unit_cost']]),
            $this->catalogueRow(AuditEvent::SUPPLIER_PRODUCT_UPDATED, $entry, ['fields' => ['minimum_order_quantity', 'supplier_sku']]),
        ], $this->auditRowsOf(AuditEvent::SUPPLIER_PRODUCT_UPDATED), 'Each changing PATCH writes one row naming only its changed fields, sorted.');
        self::assertSame([], $this->auditRowsOf(AuditEvent::SUPPLIER_PRODUCT_CREATED), 'An update wrote a create row.');
    }

    // ---- the trail (G3-C0, G-41's closing work) -------------------------------------------------------------------------

    /**
     * **N-1 (shipped-behaviour change):** a PATCH whose every value equals the stored one — compared numerically at column
     * scale, so `"1.00"` is the stored `1.0000` and `"1"` the stored `1.000000` — issues no UPDATE, leaves `updated_at`
     * unmoved, writes no row and answers 200 with the unchanged entry. Mutation M-C0-1 (compare `unit_cost` as strings) → red.
     */
    #[Test]
    public function aNoOpTermsEditWritesNothingAndKeepsUpdatedAt(): void
    {
        $supplier = $this->seedSupplier(self::TENANT_A, 'Unchanged Terms');
        $item = $this->seedItem(self::TENANT_A, 'SKU-NOOP');
        $entry = $this->seedSupplierProduct(self::TENANT_A, $supplier, $item, leadTimeDays: 7);
        $updatedAt = fn (): array => $this->fetchAsOwnerForTenant(self::TENANT_A, 'SELECT updated_at::text AS u FROM supplier_product WHERE id = ?::uuid', [$entry]);
        $before = $updatedAt();
        $this->actAs(self::TENANT_A, DefaultRoles::codesFor(DefaultRoles::BUYER));

        $this->recorder()->start();
        $this->update($supplier, $entry, ['unit_cost' => '1.00', 'lead_time_days' => 7, 'minimum_order_quantity' => '1',
            'supplier_sku' => 'SP-'.substr(md5($item), 0, 8)]);
        $this->recorder()->stop();

        self::assertSame(200, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        self::assertSame(['1.0000', 7, '1.000000'], [$this->json()['unit_cost'], $this->json()['lead_time_days'], $this->json()['minimum_order_quantity']]);
        self::assertSame([], $this->recorder()->matching('/\bUPDATE\s+supplier_product\b/i'), 'A no-op catalogue edit issued an UPDATE.');
        self::assertNotSame([], $this->recorder()->matching('/\bFROM\s+supplier_product\b/i'), 'Control: the recorder saw none of the writer\'s statements.');
        self::assertSame($before, $updatedAt(), 'A no-op catalogue edit moved updated_at (N-1).');
        self::assertSame([], $this->auditRowsOf(AuditEvent::SUPPLIER_PRODUCT_UPDATED), 'A no-op catalogue edit wrote a supplier_product.updated row.');
    }

    /**
     * All four fields submitted, one changed: the row names that one; only that column is set (the others are not
     * rewritten). A scale-equal cost (`"1.0"` for `1.0000`) is not a change.
     */
    #[Test]
    public function onlyChangedFieldsAreNamed(): void
    {
        $supplier = $this->seedSupplier(self::TENANT_A, 'One Change');
        $item = $this->seedItem(self::TENANT_A, 'SKU-ONE');
        $entry = $this->seedSupplierProduct(self::TENANT_A, $supplier, $item, leadTimeDays: 7);

        $this->recorder()->start();
        $this->update($supplier, $entry, ['unit_cost' => '1.0', 'lead_time_days' => 21, 'minimum_order_quantity' => '1.000000',
            'supplier_sku' => 'SP-'.substr(md5($item), 0, 8)]);
        $this->recorder()->stop();

        self::assertSame(200, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        self::assertSame(21, $this->json()['lead_time_days']);
        self::assertSame([$this->catalogueRow(AuditEvent::SUPPLIER_PRODUCT_UPDATED, $entry, ['fields' => ['lead_time_days']])],
            $this->auditRowsOf(AuditEvent::SUPPLIER_PRODUCT_UPDATED));
        $updates = $this->recorder()->matching('/\bUPDATE\s+supplier_product\b/i');
        self::assertCount(1, $updates, 'Exactly one UPDATE for one changed field.');
        self::assertStringNotContainsString('unit_cost', (string) array_values($updates)[0], 'An unchanged cost was rewritten.');
    }

    /** A refused create — the duplicate (409), an archived supplier (409), a malformed body (422), a 404 — writes no row. */
    #[Test]
    public function aRefusedDuplicateWritesNoRow(): void
    {
        $supplier = $this->seedSupplier(self::TENANT_A, 'Refused Adds');
        $item = $this->seedItem(self::TENANT_A, 'SKU-DUP');
        $archived = $this->seedSupplier(self::TENANT_A, 'Archived Adds');
        $this->insertAsOwnerForTenant(self::TENANT_A, 'UPDATE supplier SET deleted_at = now() WHERE id = ?::uuid', [$archived]);
        $body = ['inventory_item_id' => $item, 'supplier_sku' => 'SP-DUP', 'unit_cost' => '1', 'lead_time_days' => 1];

        $this->create($supplier, $body);
        self::assertSame(201, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        $this->create($supplier, $body);
        self::assertSame([409, 'supplier_product.item_is_listed_once_per_supplier'], [$this->httpStatus(), $this->json()['rule'] ?? null]);
        $this->create($archived, $body);
        self::assertSame(409, $this->httpStatus());
        $this->create($supplier, ['unit_cost' => '-1'] + $body);
        self::assertSame(422, $this->httpStatus());
        $this->create(Uuid::v4()->toRfc4122(), $body);
        self::assertSame(404, $this->httpStatus());

        self::assertCount(1, $this->auditRowsOf(AuditEvent::SUPPLIER_PRODUCT_CREATED), 'A refused catalogue add wrote a supplier_product.created row.');
    }

    /**
     * **Field names, never values** (PC-12: `unit_cost` is money; `API.md` §4.4.3 `AuditLogEntry.metadata`). A walk over
     * every `supplier_product.*` row this test writes: only the allowed keys, and no string anywhere in the metadata that
     * carries either cost at any scale. Mutation M-C0-4 (write values into metadata) → red.
     */
    #[Test]
    public function metadataNeverCarriesTheCost(): void
    {
        $supplier = $this->seedSupplier(self::TENANT_A, 'Secret Costs');
        $item = $this->seedItem(self::TENANT_A, 'SKU-SECRET');

        $this->create($supplier, ['inventory_item_id' => $item, 'supplier_sku' => 'SP-SECRET', 'unit_cost' => '4321.9876', 'lead_time_days' => 13,
            'minimum_order_quantity' => '17']);
        self::assertSame(201, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        $entry = (string) $this->json()['id'];
        $this->update($supplier, $entry, ['unit_cost' => '8765.4321', 'lead_time_days' => 29, 'minimum_order_quantity' => '19', 'supplier_sku' => 'SP-SECRET-2']);
        self::assertSame(200, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());

        $rows = [...$this->auditRowsOf(AuditEvent::SUPPLIER_PRODUCT_CREATED), ...$this->auditRowsOf(AuditEvent::SUPPLIER_PRODUCT_UPDATED)];
        self::assertCount(2, $rows, 'Control: the two writes did not each leave a row, so the walk below proves nothing.');
        $allowed = [AuditEvent::SUPPLIER_PRODUCT_CREATED => ['inventory_item_id', 'supplier_id'], AuditEvent::SUPPLIER_PRODUCT_UPDATED => ['fields']];
        foreach ($rows as $row) {
            $metadata = json_decode($row['metadata'], true, 512, \JSON_THROW_ON_ERROR);
            $keys = array_keys($metadata);
            sort($keys);
            self::assertSame($allowed[$row['event_type']], $keys, $row['event_type'].' carries a key outside its allowlist.');
            foreach (['4321.9876', '4321.987', '4321', '8765.4321', '8765.432', '8765', 'SP-SECRET', '"13"', '"29"', '"17', '"19'] as $value) {
                self::assertStringNotContainsString($value, $row['metadata'], 'A value reached '.$row['event_type'].'\'s metadata.');
            }
        }
        self::assertSame(['lead_time_days', 'minimum_order_quantity', 'supplier_sku', 'unit_cost'], json_decode($rows[1]['metadata'], true)['fields']);
    }

    /**
     * **The diff is taken under the entry's row lock** (M-C0-6, a race test rather than a review check): a holder locks the
     * entry `FOR NO KEY UPDATE` and moves the cost to `5`; a PATCH sending `unit_cost: "5"` and a new lead time WAITS, reads
     * the committed cost and names only `lead_time_days`. Without the lock the PATCH diffs against the cost it read before
     * the holder committed and names `unit_cost` too.
     */
    #[Test]
    public function aConcurrentTermsEditNamesOnlyItsOwnChange(): void
    {
        $supplier = $this->seedSupplier(self::TENANT_A, 'Racing Terms');
        $entry = $this->seedSupplierProduct(self::TENANT_A, $supplier, $this->seedItem(self::TENANT_A, 'SKU-RACE-T'), leadTimeDays: 7);

        $holder = $this->raceAgainstAHolder([
            ['SELECT 1 FROM supplier_product WHERE id = ?::uuid FOR NO KEY UPDATE', [$entry]],
            ['UPDATE supplier_product SET unit_cost = 5, updated_at = now() WHERE id = ?::uuid', [$entry]],
        ], function () use ($supplier, $entry): void {
            $this->update($supplier, $entry, ['unit_cost' => '5', 'lead_time_days' => 20]);
        });

        self::assertTrue($holder['waiter_seen'], 'The PATCH never waited on the holder\'s entry lock. '.json_encode($holder));
        self::assertTrue($holder['committed'], 'The holder did not commit: '.json_encode($holder));
        self::assertSame(200, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        self::assertSame(['5.0000', 20], [$this->json()['unit_cost'], $this->json()['lead_time_days']]);
        self::assertSame([$this->catalogueRow(AuditEvent::SUPPLIER_PRODUCT_UPDATED, $entry, ['fields' => ['lead_time_days']])],
            $this->auditRowsOf(AuditEvent::SUPPLIER_PRODUCT_UPDATED),
            'The PATCH named a field the holder had already changed: its diff was not taken under the entry\'s row lock.');
    }

    /**
     * Design §5.1: a purchase-order line snapshots the lead time (and carries its own cost), so correcting the catalogue
     * moves neither a draft line nor an ordered one; a line ADDED afterwards reads the new lead time.
     */
    #[Test]
    public function correctingTermsMovesNoPlacedOrderLine(): void
    {
        $supplier = $this->seedSupplier(self::TENANT_A, 'Snapshot Supplier');
        $warehouse = $this->seedWarehouse(self::TENANT_A, 'WH Snapshot');
        $item = $this->seedItem(self::TENANT_A, 'SKU-SNAP');
        $entry = $this->seedSupplierProduct(self::TENANT_A, $supplier, $item, leadTimeDays: 7);
        $draft = $this->seedOrder(self::TENANT_A, 'PO-000101', $supplier, $warehouse);
        $ordered = $this->seedOrder(self::TENANT_A, 'PO-000102', $supplier, $warehouse, status: PurchaseOrder::STATUS_ORDERED);
        $this->seedLine(self::TENANT_A, $draft, $item, leadTimeDays: 7);
        $this->seedLine(self::TENANT_A, $ordered, $item, leadTimeDays: 7);
        $lines = fn (): array => $this->fetchAsOwnerForTenant(self::TENANT_A,
            'SELECT id::text AS id, lead_time_days, unit_cost, quantity_ordered, updated_at::text AS u FROM purchase_order_line ORDER BY id');
        $before = $lines();

        $this->update($supplier, $entry, ['lead_time_days' => 30, 'unit_cost' => '99']);
        self::assertSame(200, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        self::assertSame($before, $lines(), 'Correcting the catalogue moved a placed purchase-order line.');

        $this->send('POST', '/purchase-orders/'.$draft.'/lines', ['inventory_item_id' => $item, 'quantity' => '1', 'unit_cost' => '1'],
            [IdempotencyKeyGuard::HEADER => Uuid::v4()->toRfc4122()]);
        self::assertSame(201, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        self::assertSame(30, (int) $this->fetchAsOwnerForTenant(self::TENANT_A, 'SELECT lead_time_days FROM purchase_order_line WHERE id = ?::uuid',
            [$this->json()['id']])[0]['lead_time_days'], 'A line added after the correction did not read the new lead time.');
    }

    /**
     * **Q-S4 (ii):** a supplier's currency cannot CHANGE while it has catalogue entries; resending the same currency is not
     * a change; with no entries it changes. Nothing is written by the refusal (no row, no `supplier.updated`).
     */
    #[Test]
    public function aSuppliersCurrencyIsFixedWhileItHasCatalogueEntries(): void
    {
        $supplier = $this->seedSupplier(self::TENANT_A, 'Priced In Euros');
        $entry = $this->seedSupplierProduct(self::TENANT_A, $supplier, $this->seedItem(self::TENANT_A, 'SKU-EUR'));

        $this->send('PATCH', '/suppliers/'.$supplier, ['currency_code' => 'USD', 'name' => 'Priced In Dollars']);
        self::assertSame([409, 'supplier.currency_is_fixed_while_terms_exist',
            'This supplier\'s catalogue is priced in its current currency. Remove its catalogue entries before changing the currency.'],
            [$this->httpStatus(), $this->json()['rule'] ?? null, $this->json()['detail'] ?? null]);
        self::assertSame([['currency_code' => 'EUR', 'name' => 'Priced In Euros']], $this->fetchAsOwnerForTenant(self::TENANT_A,
            'SELECT currency_code, name FROM supplier WHERE id = ?::uuid', [$supplier]));
        self::assertSame(0, $this->countAsOwnerForTenant(self::TENANT_A, "SELECT count(*) FROM audit_log WHERE event_type = 'supplier.updated'"));

        $this->send('PATCH', '/suppliers/'.$supplier, ['currency_code' => 'EUR', 'name' => 'Still In Euros']);
        self::assertSame(200, $this->httpStatus(), 'Resending the current currency was refused as a change.');

        $this->insertAsOwnerForTenant(self::TENANT_A, 'DELETE FROM supplier_product WHERE id = ?::uuid', [$entry]);
        $this->send('PATCH', '/suppliers/'.$supplier, ['currency_code' => 'USD']);
        self::assertSame([200, 'USD'], [$this->httpStatus(), $this->json()['currency_code'] ?? null], 'With no entries the currency did not change.');
    }

    /**
     * **Final RED M-B, order 1:** a currency change holds the supplier (`UpdateSupplier`'s `FOR NO KEY UPDATE`, then its
     * UPDATE) while a catalogue add arrives. The add's parent read is `FOR SHARE`, so it WAITS, then reads the committed new
     * currency and copies it — never an old-currency price under a new-currency supplier. Without `FOR SHARE` the add does
     * not wait at all (the FK's `KEY SHARE` does not conflict), the holder sees no waiter and rolls back, and this is red.
     */
    #[Test]
    public function aCatalogueAddRacingACurrencyChangeCopiesTheNewCurrency(): void
    {
        $supplier = $this->seedSupplier(self::TENANT_A, 'Racing Currency');
        $item = $this->seedItem(self::TENANT_A, 'SKU-RACE-1');

        $holder = $this->raceAgainstAHolder([
            ['SELECT 1 FROM supplier WHERE id = ?::uuid FOR NO KEY UPDATE', [$supplier]],
            ["UPDATE supplier SET currency_code = 'USD', updated_at = now() WHERE id = ?::uuid", [$supplier]],
        ], function () use ($supplier, $item): void {
            $this->create($supplier, ['inventory_item_id' => $item, 'supplier_sku' => 'SP-RACE', 'unit_cost' => '1', 'lead_time_days' => 1]);
        });

        self::assertTrue($holder['waiter_seen'], 'The catalogue add never waited on the currency change: its parent read is not FOR SHARE. '.json_encode($holder));
        self::assertTrue($holder['committed'], 'The holder did not commit: '.json_encode($holder));
        self::assertSame(201, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        self::assertSame('USD', $this->json()['currency_code'], 'The add copied the currency the supplier no longer has.');
        self::assertSame([['currency_code' => 'USD']], $this->fetchAsOwnerForTenant(self::TENANT_A, 'SELECT currency_code FROM supplier_product WHERE supplier_id = ?::uuid', [$supplier]));
    }

    /**
     * **Final RED M-B, order 2:** a catalogue add holds the supplier with THE WRITER'S OWN parent read
     * ({@see SupplierProductWriter::PARENT_LOCK}, never a transcription) and inserts an entry, while a currency change
     * arrives. `UpdateSupplier`'s `FOR NO KEY UPDATE` conflicts with `FOR SHARE`, so it WAITS, then counts the committed
     * entry and refuses. Without either lock the change would count zero and succeed.
     */
    #[Test]
    public function aCurrencyChangeRacingACatalogueAddIsRefused(): void
    {
        $supplier = $this->seedSupplier(self::TENANT_A, 'Racing Catalogue');
        $item = $this->seedItem(self::TENANT_A, 'SKU-RACE-2');

        $holder = $this->raceAgainstAHolder([
            [SupplierProductWriter::PARENT_LOCK, [$supplier]],
            ["INSERT INTO supplier_product (id, tenant_id, supplier_id, inventory_item_id, supplier_sku, unit_cost, lead_time_days, currency_code)
              VALUES (gen_random_uuid(), ?::uuid, ?::uuid, ?::uuid, 'SP-HELD', 1, 1, 'EUR')", [self::TENANT_A, $supplier, $item]],
        ], function () use ($supplier): void {
            $this->send('PATCH', '/suppliers/'.$supplier, ['currency_code' => 'USD']);
        });

        self::assertTrue($holder['waiter_seen'], 'The currency change never waited on the catalogue add. '.json_encode($holder));
        self::assertTrue($holder['committed'], 'The holder did not commit: '.json_encode($holder));
        self::assertSame(409, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        self::assertSame('supplier.currency_is_fixed_while_terms_exist', $this->json()['rule'] ?? null);
        self::assertSame([['currency_code' => 'EUR']], $this->fetchAsOwnerForTenant(self::TENANT_A, 'SELECT currency_code FROM supplier WHERE id = ?::uuid', [$supplier]));
    }

    // -----------------------------------------------------------------------

    /** @param array<string, mixed> $body */
    private function create(string $supplier, array $body): void
    {
        $this->send('POST', '/suppliers/'.$supplier.'/products', $body, [IdempotencyKeyGuard::HEADER => Uuid::v4()->toRfc4122()]);
    }

    /** @param array<string, mixed> $body */
    private function update(string $supplier, string $entry, array $body): void
    {
        $this->send('PATCH', '/suppliers/'.$supplier.'/products/'.$entry, $body);
    }

    /**
     * The one `supplier_product.*` row a catalogue write must be (`businessRow()` with this metadata, normalised through
     * `jsonb` as the column stores it).
     *
     * @param array<string, mixed> $metadata
     *
     * @return array<string, ?string>
     */
    private function catalogueRow(string $event, string $entry, array $metadata): array
    {
        return array_replace($this->businessRow($event, 'supplier_product', $entry), [
            'metadata' => (string) $this->fetchAsOwnerForTenant(self::TENANT_A, 'SELECT ?::jsonb::text AS m',
                [json_encode($metadata, \JSON_THROW_ON_ERROR)])[0]['m'],
        ]);
    }

    /** @return list<array{pointer: string, code: string, message: string}> */
    private function fieldErrors(): array
    {
        return $this->json()['errors'] ?? [];
    }

    /**
     * `SupplierWriteTest::raceAgainstAHolder`'s shape, a fourth copy (customer, supplier, address, catalogue — not extracted,
     * C2b deviation 5): the holder's own lock is on a random `purchase_order` id, so it matches no row; the real locks are
     * `HOLD_BEFORE`'s statements.
     *
     * @param list<array{0: string, 1: list<string>}> $before
     *
     * @return array{waiter_seen: bool, committed: bool, sqlstate: ?string, error: ?string}
     */
    private function raceAgainstAHolder(array $before, callable $request): array
    {
        $readyFile = tempnam(sys_get_temp_dir(), 'erp_catalogue_');
        file_put_contents($readyFile, '');

        $proc = proc_open(
            [PHP_BINARY, __DIR__.'/support/hold_order_lock.php'],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            null,
            [
                'APP_DIRECT_DATABASE_URL' => $_ENV['APP_DIRECT_DATABASE_URL'],
                'HOLD_TENANT' => self::TENANT_A,
                'HOLD_ORDER' => Uuid::v4()->toRfc4122(),
                'HOLD_TABLE' => 'purchase_order',
                'HOLD_BEFORE' => json_encode($before, \JSON_THROW_ON_ERROR),
                'HOLD_AFTER' => json_encode([], \JSON_THROW_ON_ERROR),
                'HOLD_READY_FILE' => $readyFile,
                'HOLD_DEADLINE_SECONDS' => (string) self::HOLDER_DEADLINE_SECONDS,
                'PATH' => (string) getenv('PATH'),
            ],
        );
        self::assertIsResource($proc);

        try {
            $deadline = microtime(true) + self::HOLDER_DEADLINE_SECONDS;
            while ('' === trim((string) file_get_contents($readyFile))) {
                if (microtime(true) > $deadline) {
                    self::fail('The holder never took the lock: '.stream_get_contents($pipes[2]));
                }
                usleep(1000);
            }
            if ('failed' === trim((string) file_get_contents($readyFile))) {
                self::fail('The holder failed before taking the lock: '.stream_get_contents($pipes[1]).stream_get_contents($pipes[2]));
            }

            $request();

            $out = (string) stream_get_contents($pipes[1]);
            $err = (string) stream_get_contents($pipes[2]);
        } finally {
            if (proc_get_status($proc)['running']) {
                proc_terminate($proc);
            }
            foreach ($pipes as $pipe) {
                if (\is_resource($pipe)) {
                    fclose($pipe);
                }
            }
            proc_close($proc);
            @unlink($readyFile);
        }

        $report = json_decode(trim($out), true);
        self::assertIsArray($report, 'The holder printed no report. stdout: '.$out.' stderr: '.$err);

        return $report;
    }
}
