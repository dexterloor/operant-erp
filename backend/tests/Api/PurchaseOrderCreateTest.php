<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Modules\Purchasing\Infrastructure\Entity\PurchaseOrder;
use App\Platform\Http\Idempotency\IdempotencyKeyGuard;
use Doctrine\DBAL\Exception\NotNullConstraintViolationException;
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
     * The key reaches the column the index is built on.
     *
     * Asserted separately from the replay behaviour because the two can fail
     * apart: a create that discarded the key would still answer 201, and the
     * replay would then mint a second order rather than colliding.
     */
    #[Test]
    public function theClientsKeyIsStoredOnTheRowItCreated(): void
    {
        $key = Uuid::v4()->toRfc4122();
        $this->create($this->body(), $key);
        self::assertSame(201, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());

        $rows = $this->fetchAsOwnerForTenant(self::TENANT_A,
            'SELECT idempotency_key FROM purchase_order WHERE id = ?::uuid', [$this->json()['id']]);

        self::assertSame($key, $rows[0]['idempotency_key']);
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
