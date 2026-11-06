<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Modules\AuditLog\Infrastructure\ItemAuditWriter;
use App\Modules\Purchasing\Infrastructure\Entity\PurchaseOrder;
use App\Platform\Http\Idempotency\IdempotencyKeyGuard;
use App\SharedKernel\ValueObject\AuditEvent;
use PHPUnit\Framework\Attributes\Test;

/**
 * `CreateItem`, `UpdateItem`, `DeleteItem`, `RestoreItem` — B-3, the status
 * branches of API-21, and the refusals §5.2a and §5.3 require.
 *
 * Since items batch C1 (BK-25 + G-96, 2026-11-06) also `inventory_item.updated`: every effective edit is on the trail with
 * the old and new values, and the relabel G-96 accepts is pinned. Extends `PurchaseOrderTestCase` from then for its order
 * seeders (the relabel pin seeds an issued purchase order).
 */
final class ItemWriteTest extends PurchaseOrderTestCase
{
    private const KEY = [IdempotencyKeyGuard::HEADER => 'a-client-generated-key'];

    /** @return array<string, mixed> */
    private static function body(string $sku = 'SKU-1'): array
    {
        return ['sku' => $sku, 'name' => 'Widget', 'unit_of_measure_code' => 'EA'];
    }

    /** API-21 branch 4 — 201, `Location` naming the row-level URI `GetItem` answers. */
    #[Test]
    public function aCreateAnswers201WithALocationThatResolves(): void
    {
        $this->send('POST', '/items', self::body(), self::KEY);

        self::assertSame(201, $this->httpStatus());
        $location = (string) $this->client()->getResponse()->headers->get('Location');
        self::assertSame(self::BASE.'/items/'.$this->json()['id'], $location);

        $this->send('GET', $location);
        self::assertSame(200, $this->httpStatus(), 'Location names a URI that does not answer GET.');
    }

    /** B-3 / D-12 — the id is minted by the server and is a v4 UUID (`IdentifierGenerationTest`'s source). */
    #[Test]
    public function theIdIsServerGeneratedAndRandom(): void
    {
        $ids = [];
        foreach (['A', 'B', 'C'] as $sku) {
            $this->send('POST', '/items', self::body($sku), self::KEY);
            $ids[] = $this->json()['id'];
        }

        self::assertCount(3, array_unique($ids));
        foreach ($ids as $id) {
            self::assertMatchesRegularExpression('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $id);
        }
    }

    /**
     * B-3's runtime half: a create carrying `id` is answered 422 at `/id`, and
     * no row appears at the id the client chose.
     *
     * **Three layers stand between a client and a chosen id, and this test sees
     * only the outcome, not which layer produced it:**
     *  1. *Declaration* — `CreateItemInput` has no `id`. Proved by
     *     `OpenApiContractTest::noWriteSchemaDeclaresAnIdProperty`, not here:
     *     adding the property leaves this test GREEN.
     *  2. *API Platform's own refusal* — `ItemNormalizer::denormalize` throws
     *     "Update is not allowed for this operation." for any body carrying
     *     `id` when the context's `api_allow_update` is not `true` (a POST
     *     defaults it to `false`); `FrameworkProblemTranslator` relabels it
     *     `/id`. **This is the layer that answers today.**
     *  3. `allow_extra_attributes: false` — **does not answer today only
     *     because layer 2 fires first.** With layer 2's `isset($data['id'])`
     *     block cut from the vendor normaliser, this flag **does refuse `id`,
     *     with 422** (R4, layer L3b, 2026-09-14). With layer 2 switched off by
     *     `'api_allow_update' => true` instead, the `id` is resolved as an IRI
     *     (a 404 path) and never reaches this flag.
     *
     * None of the three, alone or combined, lets a chosen id persist: the writer
     * mints `Uuid::v4()` regardless (R4's verdict; owner decision (c) makes that
     * minting B-3's guard). A row at a chosen id needs the writer changed to read
     * the input id **and** all three layers removed — and under that mutation
     * this test is red at the status (`201 is identical to 422`) before its
     * trailing `GET … 404` runs. **The check on the row is
     * `ClientChosenIdTest`**, which asserts no row at the chosen id before it
     * reads any status.
     *
     * Mutations (C5, 2026-09-14, `backend/var/cache/test` deleted before each
     * mutated run and before the revert run — Rule 17):
     *  - `public ?string $id` added to `CreateItemInput` **and**
     *    `allow_extra_attributes` removed from `CreateItem`: this test GREEN
     *    (the spec test goes red). *Not* "only disabling both goes red" as
     *    `a8e2b78`'s record had it.
     *  - `'api_allow_update' => true` on `CreateItem`, everything else intact:
     *    RED, `Failed asserting that 404 is identical to 422.` — the `id` is
     *    resolved as an IRI and not found. That red is the 404 path, not an
     *    acceptance.
     *  - Revert: GREEN.
     */
    #[Test]
    public function aCreateCarryingAnIdIsRefused(): void
    {
        $chosen = '11111111-1111-4111-8111-111111111111';

        $this->send('POST', '/items', [...self::body(), 'id' => $chosen], self::KEY);

        self::assertSame(422, $this->httpStatus());
        self::assertSame('urn:erp:problem:validation-failed', $this->json()['type']);
        self::assertSame('/id', $this->json()['errors'][0]['pointer']);

        $this->send('GET', '/items/'.$chosen);
        self::assertSame(404, $this->httpStatus(), 'The row was written at the id the client chose.');
    }

    /** The same, on the other write verb. API-5 removes the third. */
    #[Test]
    public function aPatchCarryingAnIdIsRefused(): void
    {
        $this->send('POST', '/items', self::body(), self::KEY);
        $id = $this->json()['id'];

        $this->send('PATCH', '/items/'.$id, ['id' => '11111111-1111-4111-8111-111111111111']);

        self::assertSame(422, $this->httpStatus());
        self::assertSame('/id', $this->json()['errors'][0]['pointer']);
    }

    /** API-9 — unknown fields are refused, never ignored, and the field is named back. */
    #[Test]
    public function anUndeclaredFieldIsRefusedAndNamed(): void
    {
        $this->send('POST', '/items', [...self::body(), 'unit_cost' => '4.00'], self::KEY);

        self::assertSame(422, $this->httpStatus());
        self::assertSame('/unit_cost', $this->json()['errors'][0]['pointer']);
        self::assertSame('undeclared_field', $this->json()['errors'][0]['code']);
    }

    /** API-14 — an operation §4.2 marks `Idem` refuses the request without the header. */
    #[Test]
    public function anIdempotentOperationRefusesARequestWithoutTheKey(): void
    {
        $this->send('POST', '/items', self::body());

        self::assertSame(428, $this->httpStatus());
        self::assertSame('urn:erp:problem:idempotency-key-required', $this->json()['type']);

        $this->send('POST', '/items', self::body(), self::KEY);
        self::assertSame(201, $this->httpStatus());
    }

    /**
     * ⚠ **`RestoreItem` does NOT require the key, and this test asserted the
     * opposite until 2026-09-10.**
     *
     * §4.2's cell is `guard:inventory_item.deleted_at`, and API-14 as amended
     * demands the header for `store` and `unique:` only: a guard refuses its own
     * repeat whether or not a header arrives, so the 428 this method used to
     * assert was a refusal that protected nothing — it made a caller who had
     * done nothing wrong resend a request the system would have answered
     * correctly either way.
     *
     * The assertion is **inverted rather than deleted**, because "no 428" is the
     * behaviour that would otherwise be untested: the day somebody re-adds the
     * flag to the operation, this goes red instead of silently reintroducing a
     * pointless refusal. The replay refusal itself is
     * `aReplayedRestoreIsRefusedByTheLiveRowRatherThanByTheHeader` below.
     */
    #[Test]
    public function restoreDoesNotRequireTheKeyBecauseItsGuardRefusesTheRepeat(): void
    {
        $this->send('POST', '/items', self::body(), self::KEY);
        $id = $this->json()['id'];
        $this->send('DELETE', '/items/'.$id);

        $this->send('POST', '/items/'.$id.'/restoration');

        self::assertSame(200, $this->httpStatus(),
            'A `guard:` operation must not 428 for a missing header: the guard refuses the repeat either way.');
    }

    /**
     * The mechanism §4.2's cell actually names, as behaviour rather than as a
     * cell. A replayed restore hits a live row and is refused — which is what
     * tells the caller the first attempt committed.
     */
    #[Test]
    public function aReplayedRestoreIsRefusedByTheLiveRowRatherThanByTheHeader(): void
    {
        $this->send('POST', '/items', self::body(), self::KEY);
        $id = $this->json()['id'];
        $this->send('DELETE', '/items/'.$id);

        $this->send('POST', '/items/'.$id.'/restoration');
        self::assertSame(200, $this->httpStatus());

        $this->send('POST', '/items/'.$id.'/restoration');

        self::assertSame(409, $this->httpStatus());
        self::assertSame('urn:erp:problem:business-rule-violated', $this->json()['type']);
        self::assertSame('inventory_item.is_not_archived', $this->json()['rule']);
    }

    /**
     * §6.1's interim rule, which is what lets these two operations ship before
     * the key store of API-O-1 exists: the repeat is refused by a precondition,
     * and the refusal tells the caller the first attempt committed.
     */
    #[Test]
    public function aReplayedCreateIsRefusedByThePreconditionRatherThanDuplicated(): void
    {
        $this->send('POST', '/items', self::body(), self::KEY);
        self::assertSame(201, $this->httpStatus());

        $this->send('POST', '/items', self::body(), self::KEY);

        self::assertSame(409, $this->httpStatus());
        self::assertSame('urn:erp:problem:business-rule-violated', $this->json()['type']);
        self::assertSame('inventory_item.sku_is_unique_per_workspace', $this->json()['rule']);

        $this->send('GET', '/items');
        self::assertCount(1, $this->json()['items'], 'The replay wrote a second row.');
    }

    /** §5.2 — a field-level defect is 422 with `errors[]` of `{pointer, code, message}`. */
    #[Test]
    public function aMalformedBodyIsRefusedFieldByField(): void
    {
        $this->send('POST', '/items', [
            'sku' => '  ',
            'unit_of_measure_code' => 'NOPE',
            'supplier_id' => 'not-a-uuid',
            'reorder_point' => 'lots',
        ], self::KEY);

        self::assertSame(422, $this->httpStatus());

        foreach ($this->json()['errors'] as $error) {
            self::assertSame(['pointer', 'code', 'message'], array_keys($error));
        }

        $pointers = array_column($this->json()['errors'], 'pointer');
        self::assertContains('/name', $pointers, 'A required field that was not sent.');
        self::assertContains('/sku', $pointers, 'A field that was sent blank.');
        self::assertContains('/supplier_id', $pointers);
        self::assertContains('/reorder_point', $pointers);
    }

    /** §3.1 case 1 — a foreign key is validated under the caller's tenant context. */
    #[Test]
    public function aSupplierFromAnotherTenantIsNotResolvable(): void
    {
        $theirs = $this->seedSupplier(self::TENANT_B, 'Acme');

        $this->send('POST', '/items', [...self::body(), 'supplier_id' => $theirs], self::KEY);

        self::assertSame(422, $this->httpStatus());
        self::assertSame('/supplier_id', $this->json()['errors'][0]['pointer']);
        self::assertSame('unknown_reference', $this->json()['errors'][0]['code']);
    }

    /** API-21 branch 2 — 200 with the updated resource, and only the fields sent change. */
    #[Test]
    public function aPatchChangesOnlyTheFieldsItCarries(): void
    {
        $supplier = $this->seedSupplier(self::TENANT_A, 'Acme');
        $this->send('POST', '/items', [...self::body(), 'supplier_id' => $supplier, 'reorder_point' => '5.5'], self::KEY);
        $id = $this->json()['id'];

        $this->send('PATCH', '/items/'.$id, ['name' => 'Renamed']);

        self::assertSame(200, $this->httpStatus());
        self::assertSame('Renamed', $this->json()['name']);
        self::assertSame('SKU-1', $this->json()['sku']);
        self::assertSame('5.500000', $this->json()['reorder_point']);
        self::assertSame($supplier, $this->json()['supplier']['id']);
    }

    /**
     * The reason `UpdateItemInput`'s properties are uninitialised: an absent
     * `supplier_id` and a `supplier_id` sent as null are different instructions.
     */
    #[Test]
    public function anExplicitNullClearsAReferenceAndAnAbsentFieldDoesNot(): void
    {
        $supplier = $this->seedSupplier(self::TENANT_A, 'Acme');
        $this->send('POST', '/items', [...self::body(), 'supplier_id' => $supplier], self::KEY);
        $id = $this->json()['id'];

        $this->send('PATCH', '/items/'.$id, ['name' => 'Renamed']);
        self::assertNotNull($this->json()['supplier'], 'An absent field cleared the reference.');

        $this->send('PATCH', '/items/'.$id, ['supplier_id' => null]);
        self::assertNull($this->json()['supplier'], 'An explicit null did not clear the reference.');
    }

    /** A NOT NULL column may not be cleared by a PATCH. */
    #[Test]
    public function aPatchCannotClearANotNullField(): void
    {
        $this->send('POST', '/items', self::body(), self::KEY);
        $id = $this->json()['id'];

        $this->send('PATCH', '/items/'.$id, ['name' => null]);

        self::assertSame(422, $this->httpStatus());
        self::assertSame('/name', $this->json()['errors'][0]['pointer']);
        self::assertSame('not_nullable', $this->json()['errors'][0]['code']);
    }

    /** API-21 branch 3 — 204 and no body. */
    #[Test]
    public function aDeleteAnswers204WithNoBody(): void
    {
        $this->send('POST', '/items', self::body(), self::KEY);
        $id = $this->json()['id'];

        $this->send('DELETE', '/items/'.$id);

        self::assertSame(204, $this->httpStatus());
        self::assertSame('', (string) $this->client()->getResponse()->getContent());
    }

    /** §5.2a — ST-9 says an archived row is read-only, and the refusal is on the business-rule branch. */
    #[Test]
    public function everyWriteOnAnArchivedRowIsRefusedAsABusinessRule(): void
    {
        $this->send('POST', '/items', self::body(), self::KEY);
        $id = $this->json()['id'];
        $this->send('DELETE', '/items/'.$id);

        foreach ([
            ['PATCH', ['name' => 'x'], 'This item is archived, so it can\'t be changed until it\'s restored.'],
            // A repeat delete is told the plain fact (s34 D-1 item 1), and since remaining-ops G-I where to find it: the
            // clause `IA.md` §5.7's D-a items note (s34 D-1 item 6) held back until the `/items` Archived view existed.
            ['DELETE', null, 'This item is already archived. It\'s in the archived items.'],
        ] as [$method, $body, $detail]) {
            $this->send($method, '/items/'.$id, $body);
            self::assertSame(409, $this->httpStatus(), $method.' on an archived row was not refused.');
            self::assertSame('urn:erp:problem:business-rule-violated', $this->json()['type']);
            self::assertSame('inventory_item.is_archived', $this->json()['rule']);
            self::assertSame($detail, $this->json()['detail'], $method.' on an archived row.');
        }
    }

    /** API-12a / ST-9 — restore, at the permission that archived it, returning the new state. */
    #[Test]
    public function aRestoreReturnsTheRowInItsNewState(): void
    {
        $this->send('POST', '/items', self::body(), self::KEY);
        $id = $this->json()['id'];
        $this->send('DELETE', '/items/'.$id);

        $this->send('POST', '/items/'.$id.'/restoration', null, self::KEY);

        self::assertSame(200, $this->httpStatus());
        self::assertNull($this->json()['archived_at']);
        self::assertNull($this->client()->getResponse()->headers->get('Location'), 'Branch 5 mints no row, so there is nothing to point at.');

        $this->send('GET', '/items');
        self::assertSame(['SKU-1'], array_column($this->json()['items'], 'sku'));
    }

    /** The precondition that makes a replayed restore safe (§6.1). */
    #[Test]
    public function restoringALiveRowIsRefused(): void
    {
        $this->send('POST', '/items', self::body(), self::KEY);
        $id = $this->json()['id'];

        $this->send('POST', '/items/'.$id.'/restoration', null, self::KEY);

        self::assertSame(409, $this->httpStatus());
        self::assertSame('inventory_item.is_not_archived', $this->json()['rule']);
    }

    /** Restoring onto a SKU somebody reused while the row was archived. */
    #[Test]
    public function aRestoreThatWouldCollideOnSkuIsRefused(): void
    {
        $this->send('POST', '/items', self::body(), self::KEY);
        $id = $this->json()['id'];
        $this->send('DELETE', '/items/'.$id);

        $this->send('POST', '/items', self::body(), self::KEY);
        self::assertSame(201, $this->httpStatus(), 'The partial unique index should free the SKU once the row is archived.');

        $this->send('POST', '/items/'.$id.'/restoration', null, self::KEY);

        self::assertSame(409, $this->httpStatus());
        self::assertSame('inventory_item.sku_is_unique_per_workspace', $this->json()['rule']);
    }

    /** RLS on the write path: a row in another tenant is not writable, and the refusal is `not-found`. */
    #[Test]
    public function anotherTenantsRowIsNotWritable(): void
    {
        $theirs = $this->seedItem(self::TENANT_B, 'THEIRS');

        foreach ([['PATCH', ['name' => 'x']], ['DELETE', null]] as [$method, $body]) {
            $this->send($method, '/items/'.$theirs, $body);
            self::assertSame(404, $this->httpStatus(), $method.' reached another tenant.');
            self::assertSame('urn:erp:problem:not-found', $this->json()['type']);
        }

        $this->send('POST', '/items/'.$theirs.'/restoration', null, self::KEY);
        self::assertSame(404, $this->httpStatus());
    }

    /** The row a create writes belongs to the acting tenant and to no other. */
    #[Test]
    public function aCreateLandsInTheActingTenant(): void
    {
        $this->send('POST', '/items', self::body(), self::KEY);
        $id = $this->json()['id'];

        self::assertSame(1, $this->countAsOwnerForTenant(self::TENANT_A, 'SELECT count(*) FROM inventory_item WHERE id = ?::uuid', [$id]));
        self::assertSame(0, $this->countAsOwnerForTenant(self::TENANT_B, 'SELECT count(*) FROM inventory_item WHERE id = ?::uuid', [$id]));
    }

    /**
     * **G-96's pin** (`RESIDUAL_GAPS.md` G-96; items batch C1, BK-25): a rename or SKU change **relabels** an issued purchase
     * order, whose lines read the item's sku and name through a live join — and the old label survives on the trail
     * (`inventory_item.updated`'s `from`). Pinned as accepted, not prevented: a snapshot added later reddens the first half,
     * as a decision rather than a surprise. `WarehouseWriteTest::aRenameRelabelsAnIssuedOrderAndTheOldNameIsOnTheTrail` is
     * the G-43 twin.
     */
    #[Test]
    public function aRenameRelabelsAnIssuedOrderAndTheOldNameIsOnTheTrail(): void
    {
        $item = $this->seedItem(self::TENANT_A, 'OLD-SKU');
        $order = $this->seedOrder(self::TENANT_A, 'PO-000779', $this->seedSupplier(self::TENANT_A, 'Relabel Supplier'),
            $this->seedWarehouse(self::TENANT_A), status: PurchaseOrder::STATUS_ORDERED);
        $this->seedLine(self::TENANT_A, $order, $item);
        $old = $this->stored($item);

        $this->send('PATCH', '/items/'.$item, ['sku' => 'NEW-SKU', 'name' => 'New Item Name']);
        self::assertSame(200, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());

        $this->send('GET', '/purchase-orders/'.$order.'/lines');
        self::assertSame(200, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        self::assertSame(['id' => $item, 'sku' => 'NEW-SKU', 'name' => 'New Item Name'], $this->json()['items'][0]['inventory_item'],
            'G-96: the issued order no longer reads the item\'s live sku and name. If a snapshot was added on purpose, this is the decision G-96 names: close the gap and move this pin.');

        self::assertSame([$this->updatedRow($item, ['name' => $old['name'], 'sku' => 'OLD-SKU'], ['name' => 'New Item Name', 'sku' => 'NEW-SKU'])],
            $this->auditRowsOf(AuditEvent::INVENTORY_ITEM_UPDATED), 'G-96: the old label is not recoverable from the trail.');
    }

    /**
     * BK-25: the diff is the served row before and after, never the submitted strings. `"10"` sent for a stored
     * `10.000000`, and every other current value resent, is no change: 200, and no `inventory_item.updated` row.
     */
    #[Test]
    public function anEditThatChangesNothingWritesNoRow(): void
    {
        $supplier = $this->seedSupplier(self::TENANT_A, 'Acme');
        $this->send('POST', '/items', [...self::body(), 'supplier_id' => $supplier, 'reorder_point' => '10.000000'], self::KEY);
        $id = $this->json()['id'];

        $this->send('PATCH', '/items/'.$id, [
            'sku' => 'SKU-1', 'name' => 'Widget', 'unit_of_measure_code' => 'EA', 'supplier_id' => $supplier,
            'reorder_point' => '10', 'reorder_quantity' => '0', 'safety_stock' => '0.0',
        ]);
        self::assertSame(200, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        self::assertSame('10.000000', $this->json()['reorder_point']);

        $this->send('PATCH', '/items/'.$id, []);
        self::assertSame(200, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());

        self::assertSame([], $this->auditRowsOf(AuditEvent::INVENTORY_ITEM_UPDATED), 'An edit that changed nothing wrote a row.');
    }

    /**
     * BK-25: every allowlisted field, changed at once, is recorded with its served value (a decimal at scale 6), and the
     * allowlist is pinned by literal. A cleared supplier is recorded as `null` — the one field whose value may be null.
     */
    #[Test]
    public function aClearedSupplierIsRecordedAsNull(): void
    {
        self::assertSame(['name', 'reorder_point', 'reorder_quantity', 'safety_stock', 'sku', 'supplier_id', 'unit_of_measure_code'],
            ItemAuditWriter::VALUE_FIELDS, 'The item value allowlist moved: adding a field is a SEC decision.');

        $supplier = $this->seedSupplier(self::TENANT_A, 'Acme');
        $this->send('POST', '/items', [...self::body(), 'supplier_id' => $supplier], self::KEY);
        $id = $this->json()['id'];

        $this->send('PATCH', '/items/'.$id, [
            'sku' => 'SKU-2', 'name' => 'Gadget', 'unit_of_measure_code' => 'BOX', 'supplier_id' => null,
            'reorder_point' => '5', 'reorder_quantity' => '12.5', 'safety_stock' => '1',
        ]);
        self::assertSame(200, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());

        self::assertSame([$this->updatedRow($id,
            ['name' => 'Widget', 'reorder_point' => '0.000000', 'reorder_quantity' => '0.000000', 'safety_stock' => '0.000000',
             'sku' => 'SKU-1', 'supplier_id' => $supplier, 'unit_of_measure_code' => 'EA'],
            ['name' => 'Gadget', 'reorder_point' => '5.000000', 'reorder_quantity' => '12.500000', 'safety_stock' => '1.000000',
             'sku' => 'SKU-2', 'supplier_id' => null, 'unit_of_measure_code' => 'BOX'],
        )], $this->auditRowsOf(AuditEvent::INVENTORY_ITEM_UPDATED));
    }

    /** BK-25: a refused edit writes no row — a SKU collision (its 23505 rolls the row back) and an archived item. */
    #[Test]
    public function aRefusedEditWritesNoRow(): void
    {
        $this->send('POST', '/items', self::body('SKU-TAKEN'), self::KEY);
        $this->send('POST', '/items', self::body('SKU-MINE'), self::KEY);
        $mine = $this->json()['id'];

        $this->send('PATCH', '/items/'.$mine, ['sku' => 'SKU-TAKEN', 'name' => 'Renamed']);
        self::assertSame(409, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        self::assertSame('inventory_item.sku_is_unique_per_workspace', $this->json()['rule']);

        $this->send('DELETE', '/items/'.$mine);
        self::assertSame(204, $this->httpStatus());
        $this->send('PATCH', '/items/'.$mine, ['name' => 'Renamed']);
        self::assertSame(409, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        self::assertSame('inventory_item.is_archived', $this->json()['rule']);

        self::assertSame([], $this->auditRowsOf(AuditEvent::INVENTORY_ITEM_UPDATED), 'A refused edit wrote a row.');
        self::assertSame('Widget', $this->stored($mine)['name']);
    }

    /** @return array{sku: string, name: string} */
    private function stored(string $id): array
    {
        return $this->fetchAsOwnerForTenant(self::TENANT_A, 'SELECT sku, name FROM inventory_item WHERE id = ?::uuid', [$id])[0];
    }

    /** The server's own `jsonb` text for a value, so key order is Postgres's, never PHP's. */
    private function jsonb(array $value): string
    {
        return (string) $this->fetchAsOwnerForTenant(self::TENANT_A, 'SELECT ?::jsonb::text AS m', [json_encode($value, \JSON_THROW_ON_ERROR)])[0]['m'];
    }

    /**
     * @param array<string, string|null> $from
     * @param array<string, string|null> $to
     *
     * @return array<string, mixed>
     */
    private function updatedRow(string $id, array $from, array $to): array
    {
        $fields = array_keys($from);
        sort($fields);

        return array_replace($this->businessRow(AuditEvent::INVENTORY_ITEM_UPDATED, 'inventory_item', $id), [
            'metadata' => $this->jsonb(['fields' => $fields, 'from' => $from, 'to' => $to]),
        ]);
    }
}
