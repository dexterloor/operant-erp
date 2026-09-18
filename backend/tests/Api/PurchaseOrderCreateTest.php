<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Modules\Purchasing\Application\PurchaseOrderWriter;
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
        ], [IdempotencyKeyGuard::HEADER => Uuid::v4()->toRfc4122()]);
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

    // -----------------------------------------------------------------------
    // The nested create (D-C, step 8a)
    // -----------------------------------------------------------------------

    /**
     * **D-C: an order and its lines are raised in one request.**
     *
     * §4.2 has named `lines[].inventory_item_id`, `lines[].quantity` and
     * `lines[].unit_cost` on this row since the contract was written, and until
     * this step the field was refused loudly with the shortfall pinned as
     * behaviour. This is that pin turned over.
     *
     * The line rules are `createLine()`'s and are **not** restated: `line_number`
     * is minted from the position in the list, and `lead_time_days` is
     * snapshotted from the supplier's catalogue.
     */
    #[Test]
    public function aCreateCarriesItsLinesInOneRequest(): void
    {
        $supplier = $this->seedSupplier(self::TENANT_A, 'Acme');
        $warehouse = $this->seedWarehouse(self::TENANT_A, 'Main');
        $first = $this->seedItem(self::TENANT_A, 'SKU-1');
        $second = $this->seedItem(self::TENANT_A, 'SKU-2');
        $this->seedSupplierProduct(self::TENANT_A, $supplier, $first, leadTimeDays: 3);
        $this->seedSupplierProduct(self::TENANT_A, $supplier, $second, leadTimeDays: 11);

        $this->create([
            'supplier_id' => $supplier,
            'warehouse_id' => $warehouse,
            'lines' => [
                ['inventory_item_id' => $first, 'quantity' => '2', 'unit_cost' => '3.0000'],
                ['inventory_item_id' => $second, 'quantity' => '1.500000', 'unit_cost' => '4.0000'],
            ],
        ]);

        self::assertSame(201, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        self::assertSame('12.0000', $this->json()['total_amount'],
            'The order was created without its lines, or total_amount was not recomputed from them.');

        self::assertSame([
            ['line_number' => 1, 'inventory_item_id' => $first, 'quantity_ordered' => '2.000000', 'unit_cost' => '3.0000', 'lead_time_days' => 3],
            ['line_number' => 2, 'inventory_item_id' => $second, 'quantity_ordered' => '1.500000', 'unit_cost' => '4.0000', 'lead_time_days' => 11],
        ], $this->fetchAsOwnerForTenant(self::TENANT_A, <<<'SQL'
            SELECT line_number, inventory_item_id::text AS inventory_item_id, quantity_ordered, unit_cost, lead_time_days
              FROM purchase_order_line WHERE purchase_order_id = ?::uuid ORDER BY line_number
            SQL, [$this->json()['id']]));
    }

    /**
     * **Atomic, and it is asserted as "nothing persisted" rather than as a
     * status.** D-C's whole content is that an order and its lines are one act:
     * before this step a client raised the order, then added lines one call at a
     * time, and a partial failure left a headless draft.
     *
     * The bad line is the **third**, so the first two have already been inserted
     * when the refusal fires — a create that committed the order first, or that
     * wrapped each line in its own transaction, leaves rows behind here and is
     * red. `TenantContext` joins a nested `run()` for the same tenant with no
     * savepoint, so there is nothing for a partial commit to hide behind.
     *
     * *Mutation, run:* the order inserted in its own `tenants->run()` before the
     * lines. Red here — the order survives.
     */
    #[Test]
    public function aRefusedLineRollsBackTheOrderAndEveryLineBeforeIt(): void
    {
        $supplier = $this->seedSupplier(self::TENANT_A, 'Acme');
        $warehouse = $this->seedWarehouse(self::TENANT_A, 'Main');
        $item = $this->seedItem(self::TENANT_A, 'SKU-1');
        $this->seedSupplierProduct(self::TENANT_A, $supplier, $item);

        $line = ['inventory_item_id' => $item, 'quantity' => '1', 'unit_cost' => '1.0000'];

        $this->create([
            'supplier_id' => $supplier,
            'warehouse_id' => $warehouse,
            'lines' => [$line, $line, ['inventory_item_id' => Uuid::v4()->toRfc4122(), 'quantity' => '1', 'unit_cost' => '1.0000']],
        ]);

        self::assertSame(422, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        self::assertSame('unknown_reference', array_column($this->json()['errors'], 'code', 'pointer')['/lines/2/inventory_item_id'] ?? null,
            'The refusal did not name the third line by its pointer.');

        self::assertSame([], $this->fetchAsOwnerForTenant(self::TENANT_A, 'SELECT id FROM purchase_order'),
            'The order survived a refused line, so the create is not atomic.');
        self::assertSame([], $this->fetchAsOwnerForTenant(self::TENANT_A, 'SELECT id FROM purchase_order_line'),
            'The two good lines survived a refused third, so the create is not atomic.');
    }

    /**
     * **Trap 2: every refusal inside `lines[]` names the line it is about.**
     *
     * `createLine()`'s errors point at `/quantity`, because there is one line and
     * the path already names it. In the nested create there are N, and a client
     * told only *"quantity must be a positive decimal"* has to guess which one.
     * The pointers are the single-line pointers with a `/lines/<n>` prefix and
     * there is no second list of them, so dropping the prefix is red here.
     *
     * Both bad lines are named: shape errors are collected across every line.
     *
     * *Mutation, run:* the prefix dropped (`self::nestedPointerPrefix()` returning
     * `''`). Red here.
     */
    #[Test]
    public function everyRefusalInsideTheLinesNamesItsOwnLine(): void
    {
        $supplier = $this->seedSupplier(self::TENANT_A, 'Acme');
        $warehouse = $this->seedWarehouse(self::TENANT_A, 'Main');
        $item = $this->seedItem(self::TENANT_A, 'SKU-1');
        $this->seedSupplierProduct(self::TENANT_A, $supplier, $item);

        $this->create([
            'supplier_id' => $supplier,
            'warehouse_id' => $warehouse,
            'lines' => [
                ['inventory_item_id' => $item, 'quantity' => '1', 'unit_cost' => '1.0000'],
                ['inventory_item_id' => $item, 'quantity' => '0', 'unit_cost' => '1.0000'],
                ['inventory_item_id' => 'not-a-uuid', 'quantity' => '1', 'unit_cost' => '1.0000'],
            ],
        ]);

        self::assertSame(422, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        self::assertSame([
            '/lines/1/quantity' => 'not_a_positive_decimal',
            '/lines/2/inventory_item_id' => 'not_a_uuid',
        ], array_column($this->json()['errors'], 'code', 'pointer'));
    }

    /**
     * **Trap 3: `lines[].expected_at` is not in §4.2's cell** (owner ruling Q2,
     * 2026-09-15, withdrew it: a purchase order line has no expected date), so the
     * nested create refuses it as undeclared, exactly as `CreatePurchaseOrderLine`
     * does. Accepting it and discarding it would be the failure — the field would
     * look supported and the date would vanish.
     */
    #[Test]
    public function anExpectedAtOnANestedLineIsRefusedAsUndeclared(): void
    {
        $supplier = $this->seedSupplier(self::TENANT_A, 'Acme');
        $warehouse = $this->seedWarehouse(self::TENANT_A, 'Main');
        $item = $this->seedItem(self::TENANT_A, 'SKU-1');
        $this->seedSupplierProduct(self::TENANT_A, $supplier, $item);

        $this->create([
            'supplier_id' => $supplier,
            'warehouse_id' => $warehouse,
            'lines' => [[
                'inventory_item_id' => $item, 'quantity' => '1', 'unit_cost' => '1.0000',
                'expected_at' => '2026-10-01T00:00:00Z',
            ]],
        ]);

        self::assertSame(422, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        self::assertSame('undeclared', array_column($this->json()['errors'], 'code', 'pointer')['/lines/0/expected_at'] ?? null);
        self::assertSame([], $this->fetchAsOwnerForTenant(self::TENANT_A, 'SELECT id FROM purchase_order'));
    }

    /**
     * **The supplier-catalogue rule is F-2 step 3's and it is about the order,
     * not about a field the caller sent** — so it stays a
     * `business-rule-violated`, which carries no pointer. The line is therefore
     * named in the message. Until V2-C-19 (2026-09-16) that was the pointer
     * (`/lines/1`), because a 0-based ordinal would have named the wrong line; it
     * is now the `line_number` the line would carry, which is minted from its
     * position and so is exactly "line 2".
     */
    #[Test]
    public function aNestedLineWhoseItemTheSupplierDoesNotListNamesTheLine(): void
    {
        $supplier = $this->seedSupplier(self::TENANT_A, 'Acme');
        $warehouse = $this->seedWarehouse(self::TENANT_A, 'Main');
        $listed = $this->seedItem(self::TENANT_A, 'SKU-1');
        $unlisted = $this->seedItem(self::TENANT_A, 'SKU-2');
        $this->seedSupplierProduct(self::TENANT_A, $supplier, $listed);

        $this->create([
            'supplier_id' => $supplier,
            'warehouse_id' => $warehouse,
            'lines' => [
                ['inventory_item_id' => $listed, 'quantity' => '1', 'unit_cost' => '1.0000'],
                ['inventory_item_id' => $unlisted, 'quantity' => '1', 'unit_cost' => '1.0000'],
            ],
        ]);

        self::assertSame(409, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        self::assertSame('urn:erp:problem:business-rule-violated', $this->json()['type']);
        self::assertSame('purchase_order_line.supplier_lists_the_item', $this->json()['rule']);
        // V2-C-19 (V4 B3e, 2026-09-16): named as the line NUMBER the order will
        // carry. A nested line's `line_number` is minted from its position on a
        // new order, so the second entry is line 2 — well defined, and the
        // user's terms rather than a 0-based pointer. Pinned exactly.
        self::assertSame(
            'This order\'s supplier has no catalogue entry for the item on line 2, so the line has no lead time to record.',
            (string) $this->json()['detail'],
            'The refusal did not name the line by the number it would carry (V2-C-19).',
        );
        self::assertSame([], $this->fetchAsOwnerForTenant(self::TENANT_A, 'SELECT id FROM purchase_order'));
    }

    /**
     * **An empty `lines` is refused, and omitting the field is not.**
     *
     * F-2 steps 2–6 raise a draft and fill it afterwards, so an absent `lines` is
     * ordinary and stays legal. `lines: []` is refused for a reason beyond
     * tidiness, and 6b named it: **in canonical JSON an empty list and an empty
     * object both encode as `[]`**, so an empty list admitted into the request
     * digest would be a value that cannot be told from a different one. Refusing
     * it means the digest never sees one.
     */
    #[Test]
    public function anEmptyLinesListIsRefusedAndAnAbsentOneIsNot(): void
    {
        $body = $this->body();
        $body['lines'] = [];
        $this->create($body);

        self::assertSame(422, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        self::assertSame('at_least_one_line', array_column($this->json()['errors'], 'code', 'pointer')['/lines'] ?? null);
        self::assertSame([], $this->fetchAsOwnerForTenant(self::TENANT_A, 'SELECT id FROM purchase_order'));

        $this->create($this->body());
        self::assertSame(201, $this->httpStatus(), 'An order raised with no lines at all is F-2\'s empty draft and is legal.');
    }

    /**
     * **The cap, driven at its own edge.** `MAXIMUM_LINES` is a decision no
     * document specifies; `PurchaseOrderOperationContractTest::theNestedLineCapIsThePageCeiling`
     * is where the number is justified. This is the behaviour: one over is
     * refused as a field defect naming `/lines`, and nothing is written.
     *
     * The accepted side is not driven at 200 — that would be 200 catalogue
     * lookups and 200 inserts for one assertion, and the boundary the cap decides
     * is the refusal.
     */
    #[Test]
    public function moreLinesThanTheCapAreRefused(): void
    {
        $body = $this->body();
        $body['lines'] = array_fill(0, PurchaseOrderWriter::MAXIMUM_LINES + 1, [
            'inventory_item_id' => Uuid::v4()->toRfc4122(), 'quantity' => '1', 'unit_cost' => '1.0000',
        ]);

        $this->create($body);

        self::assertSame(422, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        self::assertSame('too_many_lines', array_column($this->json()['errors'], 'code', 'pointer')['/lines'] ?? null);
        self::assertSame([], $this->fetchAsOwnerForTenant(self::TENANT_A, 'SELECT id FROM purchase_order'));
    }

    /**
     * §4.2's `lines[]` sub-fields, every one of which is digested. Written out
     * rather than derived, for `everyDigestedField()`'s reason: the point is to
     * fail when the writer's digest and this list disagree.
     *
     * @return iterable<string, array{string}>
     */
    public static function everyDigestedLineField(): iterable
    {
        foreach (['inventory_item_id', 'quantity', 'unit_cost'] as $field) {
            yield $field => [$field];
        }
    }

    /**
     * **D-B applied one level down: a key reused for an order with different
     * LINES is a conflict, not a replay.**
     *
     * Before 8a the digest could not see the lines, because there were none to
     * see. Now it can, and the failure mode if it did not would be the worst one
     * available: a client retries with a corrected line and is handed the order
     * carrying the wrong one, as a 201, as though it had got what it asked for.
     *
     * **Run once per accepted line field**, so a field silently dropped from
     * `digestedLines()` is red here by name.
     *
     * *Mutation, run:* `lines` dropped from the digested field set. Red in all
     * three cases.
     */
    #[Test]
    #[DataProvider('everyDigestedLineField')]
    public function theSameKeyWithADifferentLineIsAConflictAndWritesNothing(string $field): void
    {
        $supplier = $this->seedSupplier(self::TENANT_A, 'Acme');
        $warehouse = $this->seedWarehouse(self::TENANT_A, 'Main');
        $first = $this->seedItem(self::TENANT_A, 'SKU-1');
        $second = $this->seedItem(self::TENANT_A, 'SKU-2');
        $this->seedSupplierProduct(self::TENANT_A, $supplier, $first);
        $this->seedSupplierProduct(self::TENANT_A, $supplier, $second);

        $key = Uuid::v4()->toRfc4122();
        $body = [
            'supplier_id' => $supplier,
            'warehouse_id' => $warehouse,
            'lines' => [['inventory_item_id' => $first, 'quantity' => '2', 'unit_cost' => '3.0000']],
        ];

        $this->create($body, $key);
        self::assertSame(201, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        $order = $this->json()['id'];

        $body['lines'][0][$field] = match ($field) {
            'inventory_item_id' => $second,
            'quantity' => '3',
            'unit_cost' => '4.0000',
        };
        $this->create($body, $key);

        self::assertSame(409, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        self::assertSame('urn:erp:problem:idempotency-key-conflict', $this->json()['type']);
        self::assertSame([['id' => $order]], $this->fetchAsOwnerForTenant(self::TENANT_A, 'SELECT id FROM purchase_order'));
        self::assertSame(1, $this->countAsOwnerForTenant(self::TENANT_A, 'SELECT count(*) FROM purchase_order_line'),
            'A conflicting reuse of a key wrote a second order\'s lines.');
    }

    /**
     * **The same two lines the other way round is a DIFFERENT order, and this is
     * the one place in the project where that is true.**
     *
     * `RequestDigest` keeps list order deliberately and its docblock names this
     * operation as the reason: `purchase_order_line.line_number` is minted from
     * the position, so lines 1 and 2 swapped is a different document.
     * `GoodsReceiptWriter` sorts before digesting for exactly the opposite
     * reason — `goods_receipt_line` has no line number and the same two lines
     * either way round record the same delivery.
     *
     * So this test is the counterpart of
     * `GoodsReceiptCreateTest::aRetryThatReordersItsLinesIsAReplayAndNotAConflict`
     * and the two must never be made to agree.
     *
     * *Mutation, run:* `digestedLines()` sorted by item id, as the receipt's is.
     * Red here.
     */
    #[Test]
    public function aRetryThatReordersItsLinesIsAConflictBecauseTheOrderDecidesTheLineNumbers(): void
    {
        $supplier = $this->seedSupplier(self::TENANT_A, 'Acme');
        $warehouse = $this->seedWarehouse(self::TENANT_A, 'Main');
        $first = $this->seedItem(self::TENANT_A, 'SKU-1');
        $second = $this->seedItem(self::TENANT_A, 'SKU-2');
        $this->seedSupplierProduct(self::TENANT_A, $supplier, $first);
        $this->seedSupplierProduct(self::TENANT_A, $supplier, $second);

        $key = Uuid::v4()->toRfc4122();
        $a = ['inventory_item_id' => $first, 'quantity' => '1', 'unit_cost' => '1.0000'];
        $b = ['inventory_item_id' => $second, 'quantity' => '1', 'unit_cost' => '1.0000'];

        $this->create(['supplier_id' => $supplier, 'warehouse_id' => $warehouse, 'lines' => [$a, $b]], $key);
        self::assertSame(201, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());

        $this->create(['supplier_id' => $supplier, 'warehouse_id' => $warehouse, 'lines' => [$b, $a]], $key);

        self::assertSame(409, $this->httpStatus(),
            'The lines were reordered, so line 1 and line 2 name different items. That is a different order, not a retry.');
        self::assertSame('urn:erp:problem:idempotency-key-conflict', $this->json()['type']);

        // **The answer has to be diagnosable.** A reorder is the one difference
        // between two bodies that a developer diffing them will not register as
        // a difference, and the shared 409 sentence says only that the key was
        // used for a different request. This operation adds the missing half.
        // It is not in the shared sentence because the same reorder is a REPLAY
        // on `CreateGoodsReceipt`, so either wording would be false one
        // operation over.
        self::assertStringContainsString('in the order they were sent', (string) $this->json()['detail'],
            'The conflict does not tell the caller that list order is part of the request, which is the one '
            .'difference between the two bodies they will not spot.');
    }

    /**
     * **The other side: a genuine retry of a request with lines is never a
     * conflict because of how a decimal was spelled.**
     *
     * `2` and `2.000000` are one quantity — `purchase_order_line.quantity_ordered`
     * is `numeric(19,6)` and stores them identically — and `3.0000` and `3` are
     * one cost. A digest over the strings as sent would 409 a client whose retry
     * library re-serialised the numbers, which is the failure D-B's normalisation
     * rule exists to prevent.
     *
     * *Mutation, run:* the `bcadd(…, 6)` / `bcadd(…, 4)` normalisation dropped
     * from `digestedLines()`. Red here — 409.
     */
    #[Test]
    public function aCosmeticallyDifferentSpellingOfALineIsAReplayAndNotAConflict(): void
    {
        $supplier = $this->seedSupplier(self::TENANT_A, 'Acme');
        $warehouse = $this->seedWarehouse(self::TENANT_A, 'Main');
        $item = $this->seedItem(self::TENANT_A, 'SKU-1');
        $this->seedSupplierProduct(self::TENANT_A, $supplier, $item);

        $key = Uuid::v4()->toRfc4122();

        $this->create(['supplier_id' => $supplier, 'warehouse_id' => $warehouse, 'lines' => [
            ['inventory_item_id' => $item, 'quantity' => '2', 'unit_cost' => '3.0000'],
        ]], $key);
        self::assertSame(201, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        $order = $this->json()['id'];

        $this->create(['supplier_id' => $supplier, 'warehouse_id' => $warehouse, 'lines' => [
            ['unit_cost' => '3', 'quantity' => '2.000000', 'inventory_item_id' => strtoupper($item)],
        ]], $key);

        self::assertSame(201, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        self::assertSame($order, $this->json()['id']);
        self::assertCount(1, $this->fetchAsOwnerForTenant(self::TENANT_A, 'SELECT id FROM purchase_order'));
        self::assertSame(1, $this->countAsOwnerForTenant(self::TENANT_A, 'SELECT count(*) FROM purchase_order_line'));
    }

    /**
     * ⚠ **The compatibility clause of this whole step, and the reason `lines`
     * enters the digest only when it is SENT.**
     *
     * Every order raised before 8a stored a digest computed from four fields.
     * Adding a fifth key unconditionally — even as an explicit `null` — changes
     * the canonical JSON of a request that omits `lines`, so every one of those
     * rows would answer its next honest retry with a **409**: a client that did
     * exactly what P-5 asks, told it asked for something else, on a request that
     * had succeeded.
     *
     * `theStoredDigestOfAFixedRequestIsPinned` is the static half — that constant
     * is unchanged by this step. This is the behavioural half: a row whose digest
     * was computed the pre-8a way (which is what `seedOrder()` writes) is replayed
     * by a request that carries no `lines`.
     *
     * *Mutation, run:* `'lines' => null` added to the digest unconditionally. Red
     * here and in the pin, which is the pair that makes the compatibility claim
     * checkable rather than asserted.
     */
    #[Test]
    public function aRetryOfARequestRaisedBeforeTheNestedCreateIsStillAReplay(): void
    {
        $key = Uuid::v4()->toRfc4122();
        $supplier = $this->seedSupplier(self::TENANT_A, 'Acme');
        $warehouse = $this->seedWarehouse(self::TENANT_A, 'Main');
        $order = $this->seedOrder(self::TENANT_A, 'PO-000001', $supplier, $warehouse, idempotencyKey: $key);

        $this->create(['supplier_id' => $supplier, 'warehouse_id' => $warehouse], $key);

        self::assertSame(201, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        self::assertSame($order, $this->json()['id'],
            'A retry carrying no `lines` no longer digests as it did before the nested create shipped, '
            .'so every order raised before 8a answers its next honest retry with a 409.');
        self::assertCount(1, $this->fetchAsOwnerForTenant(self::TENANT_A, 'SELECT id FROM purchase_order'));
    }

    /**
     * **The field set of a request WITH lines, pinned to a literal**, as
     * `theStoredDigestOfAFixedRequestIsPinned` pins the request without them.
     *
     * The constant is worked out **by hand from the canonical string**, not
     * copied from a run — a constant copied from output pins the defect as
     * readily as the behaviour:
     *
     * ```
     * {"operation":"CreatePurchaseOrder","fields":{"currency_code":null,
     * "expected_at":"2026-10-01T00:00:00.000000Z",
     * "lines":[{"inventory_item_id":"…0004","quantity":"1.000000","unit_cost":"0.5000"},
     *          {"inventory_item_id":"…0003","quantity":"2.000000","unit_cost":"3.0000"}],
     * "supplier_id":"…0001","warehouse_id":"…0002"}}
     * ```
     *
     * ⚠ **Two lines rather than one, and the higher id sent FIRST — both on
     * purpose, and the second half was learned the hard way.** With one line the
     * list's order cannot be observed at all. With two in ascending id order it
     * still cannot: the pin was written that way, the mutation *"sort
     * `digestedLines()` by item id, as the receipt's is"* was run against it, and
     * **the pin stayed green** because the sort was a no-op on that request. Only
     * the reorder test caught it. Sending the lines in an order a sort would
     * change makes this constant see it too — which is the property a pin is for,
     * and a pin that cannot fail on the mutation it names is the fake green this
     * project keeps finding.
     *
     * The request is sent with `quantity: "2"` and `unit_cost: "3"`, so the
     * pinned string also proves the scale-6 and scale-4 normalisation is applied
     * before hashing rather than after.
     */
    #[Test]
    public function theStoredDigestOfARequestCarryingLinesIsPinned(): void
    {
        $supplier = '5a1e5a1e-0000-4000-8000-000000000001';
        $warehouse = '5a1e5a1e-0000-4000-8000-000000000002';
        $first = '5a1e5a1e-0000-4000-8000-000000000003';
        $second = '5a1e5a1e-0000-4000-8000-000000000004';

        $this->insertAsOwnerForTenant(self::TENANT_A,
            "INSERT INTO supplier (id, tenant_id, name, code, currency_code) VALUES (?::uuid, ?::uuid, 'Pinned', 'PINNED', 'EUR')",
            [$supplier, self::TENANT_A]);
        $this->insertAsOwnerForTenant(self::TENANT_A,
            "INSERT INTO warehouse (id, tenant_id, code, name, country_code) VALUES (?::uuid, ?::uuid, 'PINNED', 'Pinned', 'DE')",
            [$warehouse, self::TENANT_A]);
        foreach ([$first, $second] as $index => $item) {
            $this->insertAsOwnerForTenant(self::TENANT_A, <<<'SQL'
                INSERT INTO inventory_item (id, tenant_id, sku, name, unit_of_measure_code, currency_code)
                VALUES (?::uuid, ?::uuid, ?, 'Pinned', 'EA', 'EUR')
                SQL, [$item, self::TENANT_A, 'PINNED-'.$index]);
            $this->seedSupplierProduct(self::TENANT_A, $supplier, $item);
        }

        $this->create([
            'supplier_id' => $supplier,
            'warehouse_id' => $warehouse,
            'expected_at' => '2026-10-01T02:00:00+02:00',
            'lines' => [
                ['inventory_item_id' => $second, 'quantity' => '1', 'unit_cost' => '0.5'],
                ['inventory_item_id' => $first, 'quantity' => '2', 'unit_cost' => '3'],
            ],
        ], 'pinned-key-8a');
        self::assertSame(201, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());

        $row = $this->fetchAsOwnerForTenant(self::TENANT_A,
            'SELECT idempotency_key, request_digest FROM purchase_order WHERE id = ?::uuid', [$this->json()['id']])[0];

        self::assertSame('0fd17658a5e20753bcd6b9ce19266e16a2b6e595e527ec961f25d474d9824746', $row['request_digest'],
            'The writer now digests a different field set inside `lines`, spells a field differently, normalises the '
            .'decimals at a different scale, or has stopped keeping the list in order. Every digest stored before that '
            .'change will answer its next honest retry with a 409.');
        self::assertSame('59e9e7438147805fac6468a2abdcc0e246a12eba994e49f0475c76b748c30d2b', $row['idempotency_key']);
    }

    /**
     * ⚠ **A line the writer accepts must not be able to overflow the column the
     * order's total is stored in.**
     *
     * `purchase_order.total_amount` is `numeric(19,4)` — fifteen integer digits —
     * and `unit_cost` is `numeric(19,4)` too, so **one** accepted line at the
     * top of the column's range takes the order's total past it. Postgres raises
     * `22003` on `recomputeTotal()`'s UPDATE, which is neither a unique nor a
     * foreign-key violation, so `create()`'s catch rethrows it and the caller
     * gets a **500 with no pointer** for a request whose every field was inside
     * the range the API told it to use.
     *
     * The defect is older than the nested create — `CreatePurchaseOrderLine` can
     * reach it too — but 8a made one request able to trigger it and gave that
     * request up to 200 chances.
     *
     * ⚠ **This docblock read *"so it is fixed here for both paths"* until
     * 2026-09-19, and that was false.** Both cases below are nested creates. The
     * *shared* bound this file leant on is `malformedLineFields()`'s, which is
     * **per line** — it never reads the order's live lines — so on
     * `CreatePurchaseOrderLine` a second line of `1 × 1` still reached the same
     * `22003` and the same 500, measured over HTTP (RED probe P1). What covers
     * the sub-resource paths is `recomputeTotal()`'s server-derived backstop, and
     * what drives them is
     * {@see self::theLinesSubResourceRefusesASingleOverflowingProductAndASumThatOverflowsToo()}
     * and its neighbours, not this method.
     *
     * The second case here is the one the single-line check cannot see: every
     * line is individually fine and the **sum** is not. It names the line that
     * tipped it. Both of its lines have exact products, so it is the
     * *non-truncating* half of that case; the truncating half —
     * a product under `0.0001`, which the scale-4 accumulator counted as zero —
     * is {@see self::aNestedTotalThatOverflowsOnlyWhenTheDatabaseRoundsIsRefusedRatherThanAnsweringA500()}.
     *
     * *Mutation, run:* the running-total bound removed. Red — 500.
     *
     * @param list<array{string, string}> $lines quantity and unit cost, in order
     */
    #[Test]
    #[DataProvider('totalsThatOverflowTheColumn')]
    public function aLineThatWouldOverflowTheOrdersTotalIsAFieldDefectAndNotA500(array $lines, string $pointer): void
    {
        $supplier = $this->seedSupplier(self::TENANT_A, 'Acme');
        $warehouse = $this->seedWarehouse(self::TENANT_A, 'Main');
        $item = $this->seedItem(self::TENANT_A, 'SKU-1');
        $this->seedSupplierProduct(self::TENANT_A, $supplier, $item);

        $this->create([
            'supplier_id' => $supplier,
            'warehouse_id' => $warehouse,
            'lines' => array_map(static fn (array $line): array => [
                'inventory_item_id' => $item, 'quantity' => $line[0], 'unit_cost' => $line[1],
            ], $lines),
        ]);

        self::assertSame(422, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        self::assertSame('total_out_of_range', array_column($this->json()['errors'], 'code', 'pointer')[$pointer] ?? null,
            'The overflow was not named as a field defect on the line that caused it.');
        self::assertSame([], $this->fetchAsOwnerForTenant(self::TENANT_A, 'SELECT id FROM purchase_order'));
    }

    /** @return iterable<string, array{list<array{string, string}>, string}> */
    public static function totalsThatOverflowTheColumn(): iterable
    {
        // One line, both fields inside their own columns, product 16 digits.
        yield 'one line' => [[['2', '999999999999999']], '/lines/0/unit_cost'];

        // Three lines each comfortably inside the column; the third tips the sum.
        yield 'the sum of three' => [[
            ['1', '400000000000000'],
            ['1', '400000000000000'],
            ['1', '400000000000000'],
        ], '/lines/2/unit_cost'];
    }

    /**
     * **`CreatePurchaseOrderLine` reaches the same overflow, and *one* line of it
     * is refused by the shared per-line bound** in `malformedLineFields()`, which
     * both write paths enter.
     *
     * ⚠ **This docblock claimed the shared bound was the whole fix, and it is
     * not.** It read: *"the fix is in `malformedLineFields()`, which both write
     * paths enter, not in the nested path alone"*. That bound reads **one line's
     * own product** and never the order's live lines, so it closes exactly the
     * case driven below — a single line whose own product does not fit — and
     * nothing else. The accumulating case lived only in `nestedLines()`, which
     * the sub-resource paths never enter, so two individually legal lines still
     * answered **500** here (RED probe P1). The method drove one overflowing
     * product, so it could not have seen the gap the sentence claimed to close.
     *
     * **Strengthened rather than only re-worded**, because a docblock that names
     * a gap and a body that cannot reach it is how this defect survived a green
     * suite: the method now drives **both** halves on this path — the single
     * overflowing product the per-line bound refuses, and then, on an order left
     * empty by that refusal, a legal ceiling line followed by a second legal line
     * that only the running total can refuse. The second half answered **HTTP
     * 500** with `SQLSTATE[22003]` before this commit.
     *
     * **Mutation, run 2026-09-19:** the `total_out_of_range` clause deleted from
     * `malformedLineFields()` — the first half still answers 422, from the
     * backstop, so the per-line bound is **not** what this half proves and the
     * claim is now carried by the second half alone. Both mutations reverted; see
     * the progress note for the pair actually run.
     */
    #[Test]
    public function theLinesSubResourceRefusesASingleOverflowingProductAndASumThatOverflowsToo(): void
    {
        $draft = $this->seedDraft(self::TENANT_A);
        $this->seedSupplierProduct(self::TENANT_A, $draft['supplier'], $draft['item']);

        $this->send('POST', '/purchase-orders/'.$draft['order'].'/lines', [
            'inventory_item_id' => $draft['item'], 'quantity' => '2', 'unit_cost' => '999999999999999',
        ], [IdempotencyKeyGuard::HEADER => Uuid::v4()->toRfc4122()]);

        self::assertSame(422, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        self::assertSame('total_out_of_range', array_column($this->json()['errors'], 'code', 'pointer')['/unit_cost'] ?? null);

        // The gap the docblock used to claim was closed here: two lines that are
        // each inside every published bound, and no crafted rounding.
        $this->addLine($draft, '1', '999999999999999.9999');
        self::assertSame(201, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());

        $this->addLine($draft, '1', '1');

        $this->assertTheLineWasRefusedAt('/unit_cost');
        $this->assertTheOrderKeptItsCeilingAndItsOneLine($draft);
    }

    // -----------------------------------------------------------------------
    // The running total across lines the request did not send — RED probe
    // `docs/session11/purchasing-overflow-probe.md`. P1 is driven one method up,
    // folded into the test whose docblock claimed to cover it; P2-P4 are here,
    // with P5 — a single line, found while attacking the probe's prescription.
    // -----------------------------------------------------------------------

    /**
     * **P2 — the same path, and the shape that survives any fix which only adds
     * a truncating accumulator.** The second line's exact product is `0.00005`;
     * `bcmul(…, 4)` gives `0.0000`, so an accumulator at scale 4 would move by
     * nothing while Postgres carries the digit and rounds it into the cast.
     * Measured before the fix: **HTTP 500**, `SQLSTATE[22003]`.
     *
     * P1 subsumes it on this path today. It is driven anyway because the two
     * are refused by *different* halves of the fix the day a per-line running
     * total is reintroduced, and because it is the only case here that holds
     * the backstop's arithmetic rather than its existence.
     */
    #[Test]
    public function aSecondLineWhoseProductOnlyCountsOnceTheDatabaseRoundsIsRefusedRatherThanAnsweringA500(): void
    {
        $draft = $this->seedDraftWithACatalogue();

        $this->addLine($draft, '1', '999999999999999.9999');
        self::assertSame(201, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());

        $this->addLine($draft, '0.0001', '0.5');

        $this->assertTheLineWasRefusedAt('/unit_cost');
        $this->assertTheOrderKeptItsCeilingAndItsOneLine($draft);
    }

    /**
     * **P3 — the nested create, and the exact sales defect `98643f9` fixed.**
     * `nestedLines()`'s accumulator ran `bcadd($total, bcmul(q, c, 4), 4)`, and
     * bcmath's scale argument **truncates** while the product of
     * `numeric(19,6) × numeric(19,4)` is exact at scale **10**. Measured before
     * the fix: **HTTP 500**, `SQLSTATE[22003]` at `PurchaseOrderWriter.php:846`
     * through `create()`.
     *
     * **Mutation, run:** put either `4` back into `nestedLines()`'s `bcmul`/
     * `bcadd`, or drop `roundedToMoney()` from its bound — the refusal moves off
     * `/lines/1/unit_cost`, which is what this pointer assertion catches.
     */
    #[Test]
    public function aNestedTotalThatOverflowsOnlyWhenTheDatabaseRoundsIsRefusedRatherThanAnsweringA500(): void
    {
        $supplier = $this->seedSupplier(self::TENANT_A, 'Acme round');
        $warehouse = $this->seedWarehouse(self::TENANT_A, 'WH round');
        $item = $this->seedItem(self::TENANT_A, 'SKU-PO-ROUND');
        $this->seedSupplierProduct(self::TENANT_A, $supplier, $item);

        $this->create([
            'supplier_id' => $supplier,
            'warehouse_id' => $warehouse,
            'lines' => [
                ['inventory_item_id' => $item, 'quantity' => '1', 'unit_cost' => '999999999999999.9999'],
                ['inventory_item_id' => $item, 'quantity' => '0.0001', 'unit_cost' => '0.5'],
            ],
        ]);

        self::assertSame(422, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        self::assertSame(
            'total_out_of_range',
            array_column($this->json()['errors'], 'code', 'pointer')['/lines/1/unit_cost'] ?? null,
            'The tipping line is the one the caller can act on, and it is the second.',
        );
        self::assertSame([], $this->fetchAsOwnerForTenant(self::TENANT_A, 'SELECT id FROM purchase_order'));
        self::assertSame([], $this->fetchAsOwnerForTenant(self::TENANT_A, 'SELECT id FROM purchase_order_line'));
    }

    /**
     * **P5 — found here, not in the probe: ONE line, no accumulation, and the
     * scale-4 per-line bound accepted it into a 500.**
     *
     * `1.000001 × 999999000000999.9990` is exact at scale 9:
     * `999999999999999.999999999`. `bcmul(…, 4)` **truncates** it to
     * `999999999999999.9999`, which fits `numeric(19,4)` — so the per-line bound
     * said yes — while Postgres rounds it to `1000000000000000.0000`, sixteen
     * integer digits, and `recomputeTotal()` raised `22003`. Both values measured
     * on the cluster and in PHP on 2026-09-19.
     *
     * So the probe's *"no crafted rounding needed"* is true of the
     * sub-resource's accumulating hole and understates the per-line one: on an
     * **empty** order, with one line and nothing to accumulate, the truncating
     * bound was itself a live 500. Measured on this path at `e8a5b8a`: **HTTP
     * 500**, `SQLSTATE[22003]`.
     *
     * ⚠ **What this does NOT prove, stated because the first draft of this
     * docblock claimed it and the mutation refuted it.** It does not show that
     * rounding the *per-line* clause is load-bearing. Run 2026-09-19: with
     * `bcmul($quantity, $unitCost, 4)` and no `roundedToMoney()` put back into
     * `malformedLineFields()`, and everything else in place, this file stayed
     * **green** — `recomputeTotal()`'s backstop answers the identical 422 at the
     * identical pointer, and on the nested path `nestedLines()`'s accumulator
     * does. The per-line clause is now **redundant with both**, and it is kept
     * for the round trip it saves and for the two bounds doing one arithmetic,
     * not because anything observable depends on it. See
     * `docs/session11/purchasing-overflow-fix-progress.md`.
     *
     * **Mutation that IS red, run 2026-09-19:** the backstop removed *and* the
     * per-line clause reverted to scale 4 — **HTTP 500**, `SQLSTATE[22003]`, the
     * shipped behaviour this case describes.
     */
    #[Test]
    public function aSingleLineWhoseProductOnlyOverflowsOnceTheDatabaseRoundsIsRefusedRatherThanAnsweringA500(): void
    {
        $draft = $this->seedDraftWithACatalogue();

        $this->addLine($draft, '1.000001', '999999000000999.9990');

        $this->assertTheLineWasRefusedAt('/unit_cost');
        self::assertSame(
            [['total_amount' => '0.0000', 'count' => 0]],
            $this->fetchAsOwnerForTenant(self::TENANT_A, <<<'SQL'
                SELECT po.total_amount,
                       (SELECT count(*)::int FROM purchase_order_line l
                         WHERE l.purchase_order_id = po.id AND l.deleted_at IS NULL) AS count
                  FROM purchase_order po WHERE po.id = ?::uuid
                SQL, [$draft['order']]),
            'A single line the database could not have stored leaves the order exactly as it was.',
        );
    }

    /**
     * **P4 — `UpdatePurchaseOrderLine`, a `PATCH` naming one field.** Measured
     * before the fix: `{"quantity":"2"}` on a line whose cost was already
     * accepted answered **HTTP 500**, `SQLSTATE[22003]`.
     *
     * It is the case that settles where the refusal has to live. The `PATCH`
     * sends no `unit_cost`, so `malformedLineFields()`'s per-line bound is
     * **skipped entirely** — its clause requires both values — and there is no
     * arrangement of per-line checking that could have seen this. Only a check
     * over the order's own lines can.
     *
     * The pointer is `/quantity` because that is the field this request sent,
     * and §5.2's `errors[]` names a field of *this* request.
     */
    #[Test]
    public function aPatchThatOnlyRaisesTheQuantityIsRefusedRatherThanAnsweringA500(): void
    {
        $draft = $this->seedDraftWithACatalogue();

        $this->addLine($draft, '1', '999999999999999.9999');
        self::assertSame(201, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        $line = $this->json()['id'];

        $this->send('PATCH', '/purchase-orders/'.$draft['order'].'/lines/'.$line, ['quantity' => '2']);

        $this->assertTheLineWasRefusedAt('/quantity');
        $this->assertTheOrderKeptItsCeilingAndItsOneLine($draft);
        self::assertSame(
            [['quantity_ordered' => '1.000000']],
            $this->fetchAsOwnerForTenant(self::TENANT_A, 'SELECT quantity_ordered FROM purchase_order_line WHERE id = ?::uuid', [$line]),
            'The refused PATCH rolled back with its own UPDATE, so the line keeps the quantity it had.',
        );
    }

    /**
     * **And the ceiling itself is still accepted — the fix rounds, it does not
     * tighten.** `1 × 999999999999999.9999` is the largest total
     * `purchase_order.total_amount` can hold, and it is stored unchanged on both
     * write paths.
     *
     * Without this the whole change would be indistinguishable from a guard that
     * simply refuses more, which is the failure mode `98643f9` named for sales.
     */
    #[Test]
    public function aTotalSittingExactlyOnTheColumnsCeilingIsAcceptedOnBothWritePaths(): void
    {
        $draft = $this->seedDraftWithACatalogue();

        $this->addLine($draft, '1', '999999999999999.9999');

        self::assertSame(201, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        $this->assertTheOrderKeptItsCeilingAndItsOneLine($draft);

        $second = $this->seedSupplier(self::TENANT_A, 'Acme ceiling');
        $warehouse = $this->seedWarehouse(self::TENANT_A, 'WH ceiling');
        $item = $this->seedItem(self::TENANT_A, 'SKU-PO-CEIL');
        $this->seedSupplierProduct(self::TENANT_A, $second, $item);

        $this->create([
            'supplier_id' => $second,
            'warehouse_id' => $warehouse,
            'lines' => [['inventory_item_id' => $item, 'quantity' => '1', 'unit_cost' => '999999999999999.9999']],
        ]);

        self::assertSame(201, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        self::assertSame('999999999999999.9999', $this->json()['total_amount']);
    }

    /** A draft whose item the supplier really sells, so `CreatePurchaseOrderLine` can snapshot a lead time. */
    private function seedDraftWithACatalogue(): array
    {
        $draft = $this->seedDraft(self::TENANT_A);
        $this->seedSupplierProduct(self::TENANT_A, $draft['supplier'], $draft['item']);

        return $draft;
    }

    /** @param array{order: string, item: string} $draft */
    private function addLine(array $draft, string $quantity, string $unitCost): void
    {
        $this->send('POST', '/purchase-orders/'.$draft['order'].'/lines', [
            'inventory_item_id' => $draft['item'], 'quantity' => $quantity, 'unit_cost' => $unitCost,
        ], [IdempotencyKeyGuard::HEADER => Uuid::v4()->toRfc4122()]);
    }

    private function assertTheLineWasRefusedAt(string $pointer): void
    {
        self::assertSame(422, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        self::assertSame(
            [$pointer => 'total_out_of_range'],
            array_column($this->json()['errors'], 'code', 'pointer'),
            'The overflow must be a named field defect on the field this request sent, not a 500 with no pointer.',
        );
    }

    /**
     * The refusal rolled back with everything it touched: the order still holds
     * the ceiling it held before, and its one accepted line is still its only one.
     *
     * @param array{order: string} $draft
     */
    private function assertTheOrderKeptItsCeilingAndItsOneLine(array $draft): void
    {
        self::assertSame(
            [['total_amount' => '999999999999999.9999']],
            $this->fetchAsOwnerForTenant(self::TENANT_A, 'SELECT total_amount FROM purchase_order WHERE id = ?::uuid', [$draft['order']]),
        );
        self::assertSame(
            [['count' => 1]],
            $this->fetchAsOwnerForTenant(self::TENANT_A, 'SELECT count(*)::int AS count FROM purchase_order_line WHERE purchase_order_id = ?::uuid AND deleted_at IS NULL', [$draft['order']]),
        );
    }

    /**
     * **Every refusing branch of the line-shape check, driven.**
     *
     * The `not_a_string` case is the one that is load-bearing rather than
     * thorough: `lines` arrives as a decoded array, so nothing has typed its
     * entries, and under `strict_types` an int reaching `preg_match()`'s string
     * parameter is a `TypeError` — a **500** on a request that is merely wrong.
     * The guard is what makes it a named field defect.
     *
     * `{}` is the case worth reading twice: an empty JSON object decodes to the
     * same `[]` an empty JSON array does, so the "each line must be an object"
     * clause deliberately lets it through to be answered as the three missing
     * fields it is.
     *
     * @param mixed                   $lines    `lines` as the client sends it
     * @param array<string, string>   $expected pointer => code, exactly
     */
    #[Test]
    #[DataProvider('malformedLineShapes')]
    public function everyWayOfMalformingTheLinesIsANamedFieldDefect(mixed $lines, array $expected): void
    {
        $body = $this->body();
        $body['lines'] = $lines;
        $this->create($body);

        self::assertSame(422, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        self::assertSame($expected, array_column($this->json()['errors'], 'code', 'pointer'));
        self::assertSame([], $this->fetchAsOwnerForTenant(self::TENANT_A, 'SELECT id FROM purchase_order'));
    }

    /** @return iterable<string, array{mixed, array<string, string>}> */
    public static function malformedLineShapes(): iterable
    {
        yield 'an object instead of a list' => [['a' => 1], ['/lines' => 'not_a_list']];
        yield 'a string instead of a line' => [['x'], ['/lines/0' => 'not_an_object']];
        yield 'a number where a decimal string belongs' => [[['quantity' => 2]], [
            '/lines/0/quantity' => 'not_a_string',
            '/lines/0/inventory_item_id' => 'required',
            '/lines/0/unit_cost' => 'required',
        ]];
        yield 'an empty line object' => [[[]], [
            '/lines/0/inventory_item_id' => 'required',
            '/lines/0/quantity' => 'required',
            '/lines/0/unit_cost' => 'required',
        ]];
    }

    /**
     * **An unknown key on a line does not hide that line's other defects.**
     *
     * It did until this was fixed: the undeclared-key pass recorded its error and
     * the line then skipped its own field checks, so a caller was answered with
     * the stray key alone, corrected it, and got the same line back refused for a
     * second reason it could have been told the first time. `GoodsReceiptWriter`
     * — the other nested-lines create in this codebase — has always collected
     * both, and two collection rules for one shape is the drift this project
     * keeps finding.
     *
     * *Mutation, run:* the field checks skipped whenever the line produced any
     * error. Red here.
     */
    #[Test]
    public function anUnknownKeyOnALineDoesNotSuppressItsOtherRefusals(): void
    {
        $body = $this->body();
        $body['lines'] = [[
            'inventory_item_id' => 'not-a-uuid', 'quantity' => '1', 'unit_cost' => '1.0000', 'notes' => 'hurry',
        ]];
        $this->create($body);

        self::assertSame(422, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        self::assertSame([
            '/lines/0/notes' => 'undeclared',
            '/lines/0/inventory_item_id' => 'not_a_uuid',
        ], array_column($this->json()['errors'], 'code', 'pointer'),
            'The stray key suppressed the bad id, so the caller has to make two round trips to learn both.');
    }

    /**
     * **An explicit `"lines": null` is the same request as omitting the field,
     * and this pins it as a decision rather than leaving it to be inferred from
     * a property's type.**
     *
     * `CreatePurchaseOrderInput::$lines` is `?array` with a null default, so it
     * cannot tell an absent key from an explicit null — and `RequestDigest`'s own
     * rule is that the two digest alike *exactly when the input object cannot
     * tell them apart*. Both spellings ask for an order with no lines, so a retry
     * that switches between them is a retry, not a different request.
     *
     * The commit message that shipped the nested create said an explicit null
     * would change the digest. That was about adding a `lines` KEY to the
     * digested field set unconditionally, not about the client sending one, and
     * it reads the other way round. This is the test that settles which.
     */
    #[Test]
    public function anExplicitNullLinesIsTheSameRequestAsOmittingIt(): void
    {
        $key = Uuid::v4()->toRfc4122();
        $supplier = $this->seedSupplier(self::TENANT_A, 'Acme');
        $warehouse = $this->seedWarehouse(self::TENANT_A, 'Main');

        $this->create(['supplier_id' => $supplier, 'warehouse_id' => $warehouse], $key);
        self::assertSame(201, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        $order = $this->json()['id'];

        $this->create(['supplier_id' => $supplier, 'warehouse_id' => $warehouse, 'lines' => null], $key);

        self::assertSame(201, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        self::assertSame($order, $this->json()['id'],
            'An explicit null `lines` was treated as a different request from omitting the field. The input object '
            .'cannot tell them apart, so nothing downstream should.');
        self::assertCount(1, $this->fetchAsOwnerForTenant(self::TENANT_A, 'SELECT id FROM purchase_order'));
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
     * **An archived parent is refused as an unknown one.** The composite foreign
     * key admits an archived supplier or warehouse — the row still exists — so
     * the `deleted_at IS NULL` in the lookup is the whole of this rule.
     *
     * Written at the five-calls follow-up review (2026-09-13), when the lookup
     * became one helper shared with `UpdatePurchaseOrder`: removing
     * `deleted_at IS NULL` from it reddened the edit's two cases and **nothing on
     * the create side**, so the create's half of the rule had been unpinned since
     * it shipped. *Mutation, run:* that predicate removed (1 occurrence); red on
     * both cases here and both in `PurchaseOrderWriteTest::anEditMayNotPointADraftAtAnArchivedParent`.
     */
    #[Test]
    #[DataProvider('archivableParents')]
    public function anArchivedParentIsRefusedAsAnUnknownReference(string $table): void
    {
        $archived = 'warehouse' === $table
            ? $this->seedWarehouse(self::TENANT_A, 'Closed')
            : $this->seedSupplier(self::TENANT_A, 'Retired');
        $this->insertAsOwnerForTenant(self::TENANT_A, "UPDATE $table SET deleted_at = now() WHERE id = ?::uuid", [$archived]);

        $this->create('warehouse' === $table ? $this->body(warehouse: $archived) : $this->body(supplier: $archived));

        self::assertSame(422, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        self::assertSame(['/'.$table.'_id' => 'unknown_reference'], array_column($this->json()['errors'], 'code', 'pointer'));
        self::assertSame([], $this->fetchAsOwnerForTenant(self::TENANT_A, 'SELECT id FROM purchase_order'));
    }

    /** @return iterable<string, array{string}> */
    public static function archivableParents(): iterable
    {
        yield 'warehouse' => ['warehouse'];
        yield 'supplier' => ['supplier'];
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
