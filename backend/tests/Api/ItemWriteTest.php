<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Platform\Http\Idempotency\IdempotencyKeyGuard;
use PHPUnit\Framework\Attributes\Test;

/**
 * `CreateItem`, `UpdateItem`, `DeleteItem`, `RestoreItem` — B-3, the status
 * branches of API-21, and the refusals §5.2a and §5.3 require.
 */
final class ItemWriteTest extends ApiTestCase
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
     * B-3's runtime half. The spec-level clause stops `id` being *declared*;
     * only this stops it being *accepted*.
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

        foreach ([['PATCH', ['name' => 'x'], []], ['DELETE', null, []]] as [$method, $body, $headers]) {
            $this->send($method, '/items/'.$id, $body, $headers);
            self::assertSame(409, $this->httpStatus(), $method.' on an archived row was not refused.');
            self::assertSame('urn:erp:problem:business-rule-violated', $this->json()['type']);
            self::assertSame('inventory_item.is_archived', $this->json()['rule']);
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
}
