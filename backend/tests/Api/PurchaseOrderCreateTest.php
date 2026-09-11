<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Modules\Purchasing\Infrastructure\Entity\PurchaseOrder;
use App\Platform\Http\Idempotency\IdempotencyKeyGuard;
use Doctrine\DBAL\Exception\NotNullConstraintViolationException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Uid\Uuid;

/**
 * **`CreatePurchaseOrder` — the operation E9-2 named by name for a month, and
 * the first in this project whose retry key is a domain column.**
 *
 * ---------------------------------------------------------------------------
 * What this file exists to prove, stated as the defect it closes
 * ---------------------------------------------------------------------------
 * §6.1 put the exposure in one sentence: *"a client that retries a timed-out
 * `CreatePurchaseOrder` gets a second purchase order, through a green suite"* —
 * the flagship operation of the flagship flow, protected by a `Idem` marking and
 * nothing else. `Version20260910000200` shipped a table and did not close it;
 * `Version20260911000100` shipped a column and did not close it either, because
 * **nothing wrote the column**.
 *
 * `aRetriedCreateYieldsOneOrderAndNotTwo` is the assertion that finally does, and
 * it counts **rows in the database**, not responses: a replay that answered 201
 * twice while writing twice would satisfy every response-level check in this
 * file.
 *
 * ---------------------------------------------------------------------------
 * The header is the mechanism, which is why two tests look redundant and are not
 * ---------------------------------------------------------------------------
 * `aCreateWithoutTheKeyIsRefused` asserts the 428 — API-14's contract. It does
 * **not** prove the key does anything, and for every other `unique:` operation in
 * this contract the two are genuinely separate concerns (`CreateItem` collides on
 * `sku`, which arrives in the body whether or not a header does). Here they are
 * the same value, so the pair is: the header is demanded, and the demanded header
 * is what refuses the replay.
 */
final class PurchaseOrderCreateTest extends PurchaseOrderTestCase
{
    /** @return array<string, mixed> */
    private function body(?string $supplier = null, ?string $warehouse = null): array
    {
        return [
            'supplier_id' => $supplier ?? $this->seedSupplier(self::TENANT_A, 'Acme '.Uuid::v4()),
            'warehouse_id' => $warehouse ?? $this->seedWarehouse(self::TENANT_A, 'WH '.Uuid::v4()),
        ];
    }

    /** @param array<string, mixed> $body */
    private function create(array $body, ?string $key = null): void
    {
        $this->send('POST', '/purchase-orders', $body, [
            IdempotencyKeyGuard::HEADER => $key ?? Uuid::v4()->toRfc4122(),
        ]);
    }

    // -----------------------------------------------------------------------
    // API-21 branch 4
    // -----------------------------------------------------------------------

    /**
     * 201, `Location` naming the row-level URI `GetPurchaseOrder` answers, and
     * the created resource as the body.
     *
     * The `Location` is **followed**, which is what makes branch 4 checkable
     * rather than asserted: §4.4.1's own *fails if* is that the header names a
     * URI the branch did not test for, and `CreatedRowLocation`'s docblock
     * records measuring exactly that failure on the lines sub-resource.
     */
    #[Test]
    public function aCreateAnswers201WithALocationThatResolves(): void
    {
        $this->create($this->body());

        self::assertSame(201, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());

        $location = (string) $this->client()->getResponse()->headers->get('Location');
        self::assertSame(self::BASE.'/purchase-orders/'.$this->json()['id'], $location);

        $this->send('GET', $location);
        self::assertSame(200, $this->httpStatus(), 'Location names a URI that does not answer GET.');
        self::assertSame(PurchaseOrder::STATUS_DRAFT, $this->json()['status']);
    }

    /** F-2 step 2: a new order is a `draft`, with no ordered date and no lines. */
    #[Test]
    public function aNewOrderIsADraftWithNoOrderedDate(): void
    {
        $this->create($this->body());

        self::assertSame(201, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        self::assertSame(PurchaseOrder::STATUS_DRAFT, $this->json()['status']);
        self::assertNull($this->json()['ordered_at']);
        self::assertSame('0.0000', $this->json()['total_amount']);
        self::assertNull($this->json()['archived_at']);
    }

    /** B-3 / D-12 — the id is minted by the server and is not a client field. */
    #[Test]
    public function theIdIsServerGeneratedAndACreateCarryingOneIsRefused(): void
    {
        $ids = [];
        foreach (range(1, 3) as $ignored) {
            $this->create($this->body());
            $ids[] = $this->json()['id'];
        }

        self::assertCount(3, array_unique($ids));
        foreach ($ids as $id) {
            self::assertTrue(Uuid::isValid((string) $id));
        }

        $body = $this->body();
        $body['id'] = Uuid::v4()->toRfc4122();
        $this->create($body);

        self::assertSame(422, $this->httpStatus());
        self::assertSame('urn:erp:problem:validation-failed', $this->json()['type']);
    }

    // -----------------------------------------------------------------------
    // The retry key — this file's subject
    // -----------------------------------------------------------------------

    /** API-14 as amended: a `unique:` operation demands the header. */
    #[Test]
    public function aCreateWithoutTheKeyIsRefused(): void
    {
        $this->send('POST', '/purchase-orders', $this->body());

        self::assertSame(428, $this->httpStatus());
        self::assertSame('urn:erp:problem:idempotency-key-required', $this->json()['type']);

        self::assertSame([], $this->fetchAsOwnerForTenant(self::TENANT_A, 'SELECT id FROM purchase_order'),
            'The refused request wrote a row. The guard is on kernel.controller, ahead of every state class.');
    }

    /**
     * **THE assertion this whole step exists for.**
     *
     * A retried timeout is the same request with the same key. It must yield
     * **one** order — the same one — and not two.
     *
     * *Mutation, run:* the `$idempotencyKey` bound into
     * `PurchaseOrderWriter::create()`'s `INSERT` replaced by
     * `Uuid::v4()->toRfc4122()`, i.e. a key the server mints per request instead
     * of the client's. Red here on the row count (2, not 1), and **green
     * everywhere else in this file** — which is the shape of the defect §6.1
     * describes: every response is correct and the database holds two orders.
     */
    #[Test]
    public function aRetriedCreateYieldsOneOrderAndNotTwo(): void
    {
        $key = Uuid::v4()->toRfc4122();
        $body = $this->body();

        $this->create($body, $key);
        self::assertSame(201, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        $first = $this->json()['id'];

        $this->create($body, $key);

        self::assertSame(201, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        self::assertSame($first, $this->json()['id'],
            'The replay returned a different order. A retried timeout must be answered with the order the '
            .'first attempt created, not with a second one.');

        self::assertCount(1, $this->fetchAsOwnerForTenant(self::TENANT_A, 'SELECT id FROM purchase_order'),
            'A retried create wrote a second purchase order. This is the exact defect §6.1 names: '
            .'"a client that retries a timed-out CreatePurchaseOrder gets a second purchase order, '
            .'through a green suite".');
    }

    /**
     * The other direction, without which the test above is satisfied by an
     * operation that only ever creates one order per workspace.
     */
    #[Test]
    public function aDifferentKeyCreatesASecondOrder(): void
    {
        $this->create($this->body());
        self::assertSame(201, $this->httpStatus());

        $this->create($this->body());
        self::assertSame(201, $this->httpStatus());

        self::assertCount(2, $this->fetchAsOwnerForTenant(self::TENANT_A, 'SELECT id FROM purchase_order'));
    }

    /**
     * §4.2's accepted fields for `CreatePurchaseOrder`, every one of which is
     * digested. Written out rather than derived: the point is to fail when the
     * writer's digest and this list disagree.
     *
     * @return iterable<string, array{string}>
     */
    public static function everyDigestedField(): iterable
    {
        foreach (['supplier_id', 'warehouse_id', 'currency_code', 'expected_at'] as $field) {
            yield $field => [$field];
        }
    }

    /**
     * **D-B's shipped defect, and the reason `IdempotencyKeyConflict` existed
     * for a whole step without ever being thrown.**
     *
     * §6.2: *"a replay with the same key and a **different** body digest is
     * `idempotency-key-conflict` (409)."* Until 2026-09-11 the replay read matched
     * on the key alone, so a client that reused a key for a genuinely different
     * order — another warehouse — was answered **201 with the first order**, as
     * though it had got what it asked for.
     *
     * The row count is asserted as well as the status: a 409 that had written a
     * second order first would satisfy the response check and be the opposite
     * failure.
     *
     * **Run once per accepted field**, so a field silently dropped from the
     * digest is red here by name rather than passing because the one field this
     * test used to vary happened to still be digested.
     */
    #[Test]
    #[DataProvider('everyDigestedField')]
    public function theSameKeyWithADifferentBodyIsAConflictAndWritesNothing(string $field): void
    {
        $key = Uuid::v4()->toRfc4122();
        $body = [
            'supplier_id' => $this->seedSupplier(self::TENANT_A, 'Acme'),
            'warehouse_id' => $this->seedWarehouse(self::TENANT_A, 'Main'),
            'currency_code' => 'EUR',
            'expected_at' => '2026-10-01T00:00:00Z',
        ];

        $this->create($body, $key);
        self::assertSame(201, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        $first = $this->json()['id'];

        $body[$field] = match ($field) {
            'supplier_id' => $this->seedSupplier(self::TENANT_A, 'Other'),
            'warehouse_id' => $this->seedWarehouse(self::TENANT_A, 'Other'),
            'currency_code' => 'GBP',
            'expected_at' => '2026-10-02T00:00:00Z',
        };
        $this->create($body, $key);

        self::assertSame(409, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        self::assertSame('urn:erp:problem:idempotency-key-conflict', $this->json()['type']);
        self::assertArrayNotHasKey('id', $this->json(), 'The conflict carried an order in its body.');

        self::assertSame([['id' => $first]], $this->fetchAsOwnerForTenant(self::TENANT_A, 'SELECT id FROM purchase_order'),
            'A conflicting reuse of a key wrote a second purchase order, or changed the first one\'s identity.');
    }

    /**
     * **The field set the writer digests, pinned to a literal.**
     *
     * `RetryKeyTest::theCanonicalFormIsFixed` pins the *encoding* of an array
     * that test builds itself, so it cannot see the array
     * `PurchaseOrderWriter::create()` builds: rename, add or drop a field there
     * and both sides still compute with the same code, the suite stays green, and
     * every stored digest silently becomes a conflict on its next honest retry.
     * This test posts a fixed request and compares the stored `request_digest`
     * with a hex constant, worked out by hand from the canonical string
     * `{"operation":"CreatePurchaseOrder","fields":{"currency_code":null,
     * "expected_at":"2026-10-01T00:00:00.000000Z","supplier_id":"…0001",
     * "warehouse_id":"…0002"}}`.
     *
     * ⚠ **Step 8a (`lines[]`) must keep a request that omits `lines` digesting
     * exactly as it does today** — this constant — or every order created before
     * 8a turns into a conflict on retry. Adding `lines` to the digested fields
     * only when it is sent is the way to do that.
     *
     * *Mutation, run:* `currency_code` dropped from the writer's digest array.
     * Red here.
     */
    #[Test]
    public function theStoredDigestOfAFixedRequestIsPinned(): void
    {
        $supplier = '5a1e5a1e-0000-4000-8000-000000000001';
        $warehouse = '5a1e5a1e-0000-4000-8000-000000000002';

        $this->insertAsOwnerForTenant(self::TENANT_A,
            "INSERT INTO supplier (id, tenant_id, name, code, currency_code) VALUES (?::uuid, ?::uuid, 'Pinned', 'PINNED', 'EUR')",
            [$supplier, self::TENANT_A]);
        $this->insertAsOwnerForTenant(self::TENANT_A,
            "INSERT INTO warehouse (id, tenant_id, code, name, country_code) VALUES (?::uuid, ?::uuid, 'PINNED', 'Pinned', 'DE')",
            [$warehouse, self::TENANT_A]);

        $this->create(['supplier_id' => $supplier, 'warehouse_id' => $warehouse, 'expected_at' => '2026-10-01T02:00:00+02:00'], 'pinned-key-1');
        self::assertSame(201, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());

        $row = $this->fetchAsOwnerForTenant(self::TENANT_A,
            'SELECT idempotency_key, request_digest FROM purchase_order WHERE id = ?::uuid', [$this->json()['id']])[0];

        self::assertSame('4d40067625d6a6e4eb93bf48916957bd3772b74caa6aed5e7702b402798cac10', $row['request_digest'],
            'The writer now digests a different field set, or spells a field differently. Every digest stored before '
            .'this change will answer its next honest retry with a 409. If that is intended, it needs a migration '
            .'story, not a new constant.');
        self::assertSame('05e4fdf1bb71e1b4981e606d60e8891c765402392421870195401f81a2da87d0', $row['idempotency_key']);
    }

    /**
     * **The other side of the 409: a genuine retry is never a conflict because of
     * how it was spelled.** D-B requires the digest over the deserialised,
     * normalised input, and this is the test that says why.
     *
     * The retry differs from the first attempt in every way a retry library or a
     * proxy may legitimately change a request and in no way that changes what is
     * asked for: the timestamp as `.000+00:00` instead of `Z`, both UUIDs in
     * upper case, the JSON keys in another order, and `currency_code` sent as an
     * explicit `null` instead of omitted. A digest over raw bytes would 409 it.
     *
     * *Mutation, run:* the normalisers dropped from the writer's digest (raw
     * strings digested). Red here — 409 — and nowhere else in this file.
     */
    #[Test]
    public function aCosmeticallyDifferentSpellingOfTheSameRequestIsAReplayAndNotAConflict(): void
    {
        $key = Uuid::v4()->toRfc4122();
        $supplier = $this->seedSupplier(self::TENANT_A, 'Acme');
        $warehouse = $this->seedWarehouse(self::TENANT_A, 'Main');

        $this->create(['supplier_id' => $supplier, 'warehouse_id' => $warehouse, 'expected_at' => '2026-10-01T00:00:00Z'], $key);
        self::assertSame(201, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        $first = $this->json()['id'];

        $this->create([
            'expected_at' => '2026-10-01T00:00:00.000+00:00',
            'currency_code' => null,
            'warehouse_id' => strtoupper($warehouse),
            'supplier_id' => strtoupper($supplier),
        ], $key);

        self::assertSame(201, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        self::assertSame($first, $this->json()['id']);
        self::assertCount(1, $this->fetchAsOwnerForTenant(self::TENANT_A, 'SELECT id FROM purchase_order'));
    }

    /**
     * **Why the comparison is against a stored digest and never against the
     * row.** D-B ruled it after `CRIT` found the false 409 this test pins.
     *
     * A timed-out create can have succeeded, and the client can have gone on to
     * edit the draft it got — `UpdatePurchaseOrder` moves supplier, warehouse and
     * `expected_at` while the order is a draft — before a late retry of the
     * original request arrives. That retry is still the request the key was
     * minted for, so it is a replay: the order comes back **as it now stands**
     * (§6.2's recomputed-from-the-row clause), not a 409.
     *
     * *Mutation, run:* the digest comparison replaced by a comparison of the
     * request's `supplier_id` with the row's. Red here — 409 — which is exactly
     * the design D-B refused.
     */
    #[Test]
    public function aLateRetryAfterTheDraftWasEditedIsStillAReplay(): void
    {
        $key = Uuid::v4()->toRfc4122();
        $body = $this->body();

        $this->create($body, $key);
        self::assertSame(201, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        $order = $this->json()['id'];

        $moved = $this->seedSupplier(self::TENANT_A, 'Moved');
        $this->send('PATCH', '/purchase-orders/'.$order, ['supplier_id' => $moved, 'expected_at' => '2026-12-01T00:00:00+00:00']);
        self::assertSame(200, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());

        $this->create($body, $key);

        self::assertSame(201, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        self::assertSame($order, $this->json()['id']);
        self::assertSame($moved, $this->json()['supplier']['id'], 'The replay did not return the order as it now stands.');
        self::assertCount(1, $this->fetchAsOwnerForTenant(self::TENANT_A, 'SELECT id FROM purchase_order'));
    }

    /**
     * **The key reaches the column the index is built on — HASHED, and the raw
     * value is nowhere on the row.** D-B, ruled 2026-09-11.
     *
     * Asserted separately from the replay behaviour because the two can fail
     * apart: a create that discarded the key would still answer 201, and the
     * replay would then mint a second order rather than colliding.
     *
     * The hash is computed here with `hash('sha256', …)` rather than through the
     * production helper, so the test pins the ruled format — lower-case hex
     * SHA-256 of the header value — instead of agreeing with whatever the helper
     * happens to do. The raw key is then searched for in the **whole row's**
     * text, not only in the one column: a key copied into any other column is
     * the same exposure API-O-32 named.
     *
     * This test rewrites `theClientsKeyIsStoredOnTheRowItCreated`, which
     * asserted the raw key was stored. That was the shipped behaviour and D-B
     * reversed it.
     */
    #[Test]
    public function theClientsKeyIsStoredHashedAndTheRawKeyIsNowhereOnTheRow(): void
    {
        $key = Uuid::v4()->toRfc4122();
        $this->create($this->body(), $key);
        self::assertSame(201, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());

        $rows = $this->fetchAsOwnerForTenant(self::TENANT_A,
            'SELECT idempotency_key, request_digest, row_to_json(po)::text AS whole_row FROM purchase_order po WHERE id = ?::uuid',
            [$this->json()['id']]);

        self::assertSame(hash('sha256', $key), $rows[0]['idempotency_key'],
            'The stored key is not the lower-case hex SHA-256 of the Idempotency-Key header.');
        self::assertStringNotContainsString($key, (string) $rows[0]['whole_row'],
            'The raw Idempotency-Key is stored on the row it created.');
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', (string) $rows[0]['request_digest'],
            'The row carries no request digest, so a same-key replay has nothing to be compared against.');
    }

    /**
     * ⚠ **§6.2's *"byte for byte"* is FALSE for the `unique:` route, and this
     * test is where that is said out loud rather than smuggled.**
     *
     * The row carries no stored response, so a replay is answered by re-reading
     * the order the key created — *the operation's response recomputed from the
     * row*, which is what §6.2 now says in its own sentence. The difference is
     * observable: a line added between the original create and the replay moves
     * `total_amount`, so the second response is **not** the first one's bytes.
     *
     * That is the cost of the domain-column ruling, priced by the adversarial
     * round and accepted. This test pins the actual behaviour so that a later
     * reader finds the deviation asserted rather than discovering it.
     */
    #[Test]
    public function aReplayReturnsTheRowRecomputedAndNotTheOriginalBytes(): void
    {
        $key = Uuid::v4()->toRfc4122();
        $supplier = $this->seedSupplier(self::TENANT_A, 'Acme');
        $warehouse = $this->seedWarehouse(self::TENANT_A, 'Main');
        $body = ['supplier_id' => $supplier, 'warehouse_id' => $warehouse];

        $this->create($body, $key);
        self::assertSame(201, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        $order = $this->json()['id'];
        $original = (string) $this->client()->getResponse()->getContent();

        // The world moves between the timeout and the retry.
        $item = $this->seedItem(self::TENANT_A, 'SKU-1');
        $this->seedSupplierProduct(self::TENANT_A, $supplier, $item);
        $this->send('POST', '/purchase-orders/'.$order.'/lines', [
            'inventory_item_id' => $item, 'quantity' => '2', 'unit_cost' => '3.0000',
        ]);
        self::assertSame(201, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());

        $this->create($body, $key);

        self::assertSame(201, $this->httpStatus());
        self::assertSame($order, $this->json()['id'], 'The replay did not return the row the key created.');
        self::assertSame('6.0000', $this->json()['total_amount'],
            'The replay returned the original total rather than the row as it now stands.');
        self::assertNotSame($original, (string) $this->client()->getResponse()->getContent(),
            '§6.2\'s byte-for-byte clause is false for the `unique:` route and this test asserts the deviation '
            .'rather than the promise. If the bytes ever match again, the contract and the code have moved '
            .'and §6.2\'s amendment needs re-reading.');
    }

    /**
     * **`Version20260911000200`'s trigger, exercised at the database.**
     *
     * Every PHP path supplies a key, so the trigger's subject is what a PHP path
     * would do if it stopped: this drives a raw insert as the owner, which is the
     * principal a migration or a future writer runs as, and the refusal is the
     * database's rather than the application's.
     *
     * *Mutation, run:* the `CREATE TRIGGER` statement removed from the migration
     * and the volume rebuilt. The insert succeeds, this test is red, and nothing
     * else in the suite changes colour — which is what makes the trigger the only
     * thing standing between a future writer and a NULL key.
     */
    #[Test]
    public function theDatabaseRefusesAnInsertCarryingNoKey(): void
    {
        $supplier = $this->seedSupplier(self::TENANT_A, 'Acme');
        $warehouse = $this->seedWarehouse(self::TENANT_A, 'Main');

        $this->expectException(NotNullConstraintViolationException::class);

        $this->insertAsOwnerForTenant(self::TENANT_A, <<<'SQL'
            INSERT INTO purchase_order
                (id, tenant_id, order_number, supplier_id, warehouse_id, currency_code)
            VALUES (?::uuid, ?::uuid, 'PO-NOKEY', ?::uuid, ?::uuid, 'EUR')
            SQL, [Uuid::v4()->toRfc4122(), self::TENANT_A, $supplier, $warehouse]);
    }

    // -----------------------------------------------------------------------
    // The fields
    // -----------------------------------------------------------------------

    /** API-9 — an undeclared field is refused, never ignored. */
    #[Test]
    public function anUndeclaredFieldIsRefused(): void
    {
        $body = $this->body();
        $body['notes'] = 'hurry';
        $this->create($body);

        self::assertSame(422, $this->httpStatus());
        self::assertSame('urn:erp:problem:validation-failed', $this->json()['type']);
    }

    /**
     * ⚠ **`lines` is in §4.2's accepted cell and this operation refuses it.**
     *
     * The nested create is not implemented — F-2 raises a draft and adds lines
     * through `CreatePurchaseOrderLine` — and the field is refused loudly rather
     * than accepted and discarded, which is the same treatment `expected_at` gets
     * on a purchase order line. **This test pins the shortfall as behaviour**, so
     * that shipping the nested create later means deleting an assertion that
     * names it rather than discovering that a documented field was silently
     * dropped all along.
     */
    #[Test]
    public function theNestedLinesCreateIsNotImplementedAndIsRefusedRatherThanDiscarded(): void
    {
        $body = $this->body();
        $body['lines'] = [['inventory_item_id' => Uuid::v4()->toRfc4122(), 'quantity' => '1', 'unit_cost' => '1.0000']];
        $this->create($body);

        self::assertSame(422, $this->httpStatus(),
            '§4.2 names `lines[]` on this row and this package does not implement the nested create. '
            .'A 201 here means the field was accepted and thrown away.');
        self::assertSame('urn:erp:problem:validation-failed', $this->json()['type']);
    }

    /** @return iterable<string, array{string}> */
    public static function impossibleTimestamps(): iterable
    {
        yield '30 February' => ['2026-02-30T00:00:00Z'];
        yield 'hour 24' => ['2026-10-01T24:00:00Z'];
        yield 'second 60' => ['2026-10-01T23:59:60Z'];
    }

    /**
     * **An impossible `expected_at` is a field defect, not the day it rolls over
     * to.** Until the fix after `1a1194b` the validator accepted 30 February and
     * the order was raised for 2 March — `createFromFormat()` warns and rolls
     * over, and nothing read the warning. `24:00` and `:60` roll over in Postgres
     * too, and are refused on the same rule: `Rfc3339Timestamp` refuses any
     * input PHP had to correct.
     */
    #[Test]
    #[DataProvider('impossibleTimestamps')]
    public function anImpossibleExpectedAtIsRefusedAndNotRolledOver(string $expectedAt): void
    {
        $body = $this->body();
        $body['expected_at'] = $expectedAt;
        $this->create($body);

        self::assertSame(422, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        self::assertSame('not_a_timestamp', array_column($this->json()['errors'], 'code', 'pointer')['/expected_at'] ?? null);
        self::assertSame([], $this->fetchAsOwnerForTenant(self::TENANT_A, 'SELECT id FROM purchase_order'));
    }

    /** Both parents are `NOT NULL` with no default, so both are required. */
    #[Test]
    public function theTwoParentIdsAreRequired(): void
    {
        $this->create([]);

        self::assertSame(422, $this->httpStatus());
        $codes = array_column($this->json()['errors'], 'code', 'pointer');
        self::assertSame('required', $codes['/supplier_id'] ?? null);
        self::assertSame('required', $codes['/warehouse_id'] ?? null);
    }

    /**
     * **ST-5's oracle.** A supplier id from another tenant is a *field* defect,
     * not a `not-found` on a row the caller never addressed: under RLS the
     * application cannot tell it from an id that never existed.
     */
    #[Test]
    public function aParentIdFromAnotherTenantIsAFieldDefectAndNotANotFound(): void
    {
        $body = $this->body();
        $body['supplier_id'] = $this->seedSupplier(self::TENANT_B, 'Other');
        $this->create($body);

        self::assertSame(422, $this->httpStatus());
        self::assertSame('urn:erp:problem:validation-failed', $this->json()['type']);
        $codes = array_column($this->json()['errors'], 'code', 'pointer');
        self::assertSame('unknown_reference', $codes['/supplier_id'] ?? null);
        self::assertArrayNotHasKey('/warehouse_id', $codes, 'The warehouse resolved and was reported as a defect anyway.');
    }

    /**
     * E4-3 — the currency comes from `tenant_settings`, per workspace, read
     * inside the tenant transaction. There is no instance-wide default and no
     * `?? 'EUR'` on the path.
     *
     * Tenant B is re-seeded at `USD` so the assertion cannot pass against a
     * constant: `EUR` is what both workspaces carry by default, which is exactly
     * the fixture a reverted writer would still satisfy.
     */
    #[Test]
    public function theCurrencyDefaultsToTheWorkspacesOwnSetting(): void
    {
        $this->seedTenantSettings(self::TENANT_B, 'USD');
        $this->actAs(self::TENANT_B);

        $this->send('POST', '/purchase-orders', [
            'supplier_id' => $this->seedSupplier(self::TENANT_B, 'Acme'),
            'warehouse_id' => $this->seedWarehouse(self::TENANT_B, 'Main'),
        ], [IdempotencyKeyGuard::HEADER => Uuid::v4()->toRfc4122()]);

        self::assertSame(201, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        self::assertSame('USD', $this->json()['currency_code']);
    }

    /** A stated currency wins over the workspace's, and an unknown one is a named field defect. */
    #[Test]
    public function astatedCurrencyIsUsedAndAnUnknownOneIsRefused(): void
    {
        $body = $this->body();
        $body['currency_code'] = 'GBP';
        $this->create($body);

        self::assertSame(201, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        self::assertSame('GBP', $this->json()['currency_code']);

        $body = $this->body();
        $body['currency_code'] = 'ZZZ';
        $this->create($body);

        self::assertSame(422, $this->httpStatus());
        self::assertSame('/currency_code', $this->json()['errors'][0]['pointer']);
    }

    /**
     * `order_number` is server-generated, which is **why** it cannot be the retry
     * key — §6.1's whole argument for the column this operation actually uses.
     *
     * The format is a decision taken in `PurchaseOrderWriter` and reported as
     * one: no document specifies it. What is asserted here is the property the
     * contract does rely on — the caller sends nothing that decides it.
     */
    #[Test]
    public function theOrderNumberIsServerGeneratedAndSequential(): void
    {
        $this->create($this->body());
        self::assertSame('PO-000001', $this->json()['order_number']);

        $this->create($this->body());
        self::assertSame('PO-000002', $this->json()['order_number']);
    }

    /** T-1 — the tenant is the token's claim, so a create lands in the caller's workspace and nowhere else. */
    #[Test]
    public function aCreateLandsInTheCallersOwnWorkspace(): void
    {
        $this->create($this->body());
        self::assertSame(201, $this->httpStatus());

        self::assertCount(1, $this->fetchAsOwnerForTenant(self::TENANT_A, 'SELECT id FROM purchase_order'));
        self::assertSame([], $this->fetchAsOwnerForTenant(self::TENANT_B, 'SELECT id FROM purchase_order'));
    }

    /**
     * The same key in two workspaces is two orders, because the index is
     * `(tenant_id, idempotency_key)`.
     *
     * Without the `tenant_id` lead this would be a cross-tenant collision: one
     * workspace's retry would be answered with **another workspace's order**,
     * which is the disclosure `SchemaGuardTest::noTenantScopedTableHasAUniqueIndexThatDoesNotLeadWithTenantId`
     * exists to prevent and which this operation would be the first to be able to
     * trigger.
     */
    #[Test]
    public function theSameKeyInTwoWorkspacesIsTwoOrders(): void
    {
        $key = Uuid::v4()->toRfc4122();

        $this->create($this->body(), $key);
        self::assertSame(201, $this->httpStatus());
        $inA = $this->json()['id'];

        $this->actAs(self::TENANT_B);
        $this->send('POST', '/purchase-orders', [
            'supplier_id' => $this->seedSupplier(self::TENANT_B, 'Acme'),
            'warehouse_id' => $this->seedWarehouse(self::TENANT_B, 'Main'),
        ], [IdempotencyKeyGuard::HEADER => $key]);

        self::assertSame(201, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        self::assertNotSame($inA, $this->json()['id'],
            'A key replayed in another workspace returned the first workspace\'s order.');
    }
}
