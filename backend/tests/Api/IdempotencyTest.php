<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Modules\Purchasing\Infrastructure\Entity\PurchaseOrder;
use App\Platform\Http\Idempotency\IdempotencyKeyGuard;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Uid\Uuid;

/**
 * **`docs/API.md` §6.1's own *fails if* clause, which names this class by name:
 * *"an operation marked `Idem` is implemented with no key store and no
 * precondition that refuses the repeat — `IdempotencyTest::everyIdempotentOperationRefusesAReplay`,
 * which derives its domain from the operation registry's `Idem` flag and replays
 * each request with the same key and body, asserting the second call writes no
 * second row."***
 *
 * ---------------------------------------------------------------------------
 * Why this is a second file and not a rename of `IdempotencyContractTest`
 * ---------------------------------------------------------------------------
 * The delivery ledger's step 9 line reads *"derived guards — `TransitionGuardTest`,
 * `IdempotencyTest`, each mutated"*, and no file had that name, while
 * `IdempotencyContractTest` did. The two were checked against §6.1 rather than
 * assumed to be the same thing, and **they are not**:
 *
 *  - `IdempotencyContractTest` reconciles §4.2's `Idem` **cells** against the
 *    live catalogue, the status machines, `SchemaManifest` and the ledger's
 *    gate. It answers *"does the mechanism this table claims exist, and is it
 *    the shape it claims?"*. **It never sends a request twice.**
 *  - This file answers the other question, which is the one a client asks:
 *    *"if I send it twice, does it happen twice?"* — over HTTP, counting **rows
 *    in the database** after the second call.
 *
 * A mechanism can be perfectly declared and perfectly indexed and still be
 * bypassed by a processor that never reaches it. Nothing here reads a document.
 *
 * ---------------------------------------------------------------------------
 * Individual replay cases already existed. A domain did not.
 * ---------------------------------------------------------------------------
 * `PurchaseOrderCreateTest::aRetriedCreateYieldsOneOrderAndNotTwo`,
 * `GoodsReceiptRetryKeyStorageTest` and `ItemWriteTest` each drive their own
 * operation's replay, and each is better at its own operation than anything
 * here. **What none of them can do is fail when a NEW operation ships without
 * one** — a set of individual cases is not a domain, and *"every operation"* was
 * exactly the claim §6.1 made. {@see self::REPLAY_CASES} is reconciled against
 * the derived set below, so the seventh operation to carry a live mechanism is
 * red until somebody replays it.
 *
 * ⚠ **Measured, because it decides whether this file should exist at all.** The
 * receipt's replay read was short-circuited and the **whole suite** run: four
 * tests went red, and **three of them were `GoodsReceiptCreateTest`'s own**. So
 * the six cases below are, for the operations that already have per-operation
 * coverage, **largely redundant — and that is said here rather than left for a
 * reviewer to find.** The load-bearing member of this file is
 * `theDomainIsEveryShippedOperationWhoseMechanismCanBeExercised`, and its value
 * is exactly one thing: **nothing else in this project fails when an operation
 * ships with a declared mechanism and no replay is ever sent.** §6.1's *fails
 * if* clause has claimed that check existed since it was written, and it did
 * not — the same species as `IdempotencyKeyGuard`'s docblock, and as the
 * deptrac test cited for months before `edee43d` wrote it.
 *
 * The cases are kept rather than reduced to the registry because a coverage gate
 * whose entries are names and not executions is the *next* version of the same
 * defect; an entry here has to actually send the request twice.
 *
 * ---------------------------------------------------------------------------
 * The domain, and the two exclusions that are not holes
 * ---------------------------------------------------------------------------
 * Derived as: every operation the **registry** ships, whose §4.2 mechanism is
 * not `no`, intersected with what that mechanism can be exercised against today.
 *
 *  - **`⛔ none` is excluded because nothing marked it may ship at all**, and
 *    `IdempotencyContractTest::noBlockedOperationShips` is what enforces that.
 *    An operation that appeared in both sets would be red there first.
 *  - **`store` is excluded because the store has no replay path** (API-O-31) and
 *    the three operations that use it are unbuilt. The same
 *    `noBlockedOperationShips`-shaped guard covers that:
 *    `everyStoreMechanismIsABulkOperationAndNoneShipsWithoutTheReplayPath`.
 *    **The intersection is asserted below rather than described**, so neither
 *    exclusion can quietly start hiding a shipped operation.
 */
final class IdempotencyTest extends PurchaseOrderTestCase
{
    /**
     * The replay case for each shipped operation with a live mechanism. The keys
     * are checked against the derived set; the values name a method here.
     *
     * `why` is what the second call must NOT have done, in the words the
     * mechanism makes true — `unique:` operations must not create a second row,
     * `guard:` operations must not apply a second effect to the one they have.
     *
     * @var array<string, array{method: string, why: string}>
     */
    private const REPLAY_CASES = [
        'CreateItem' => [
            'method' => 'replayCreateItem',
            'why' => 'A second inventory_item with the same SKU. Its mechanism is the live-row unique on (tenant, sku).',
        ],
        'CreatePurchaseOrder' => [
            'method' => 'replayCreatePurchaseOrder',
            'why' => 'A second purchase_order. Its mechanism is the header-sourced retry key on a live-row unique.',
        ],
        'CreateGoodsReceipt' => [
            'method' => 'replayCreateGoodsReceipt',
            'why' => 'A second goods_receipt, and a second helping of quantity_received on the order line.',
        ],
        'ApprovePurchaseOrder' => [
            'method' => 'replayApprovePurchaseOrder',
            'why' => 'A second placement of an order already placed. Its mechanism is the status guard, which refuses '
                .'the repeat with no key at all — so the check is that the row is byte-identical afterwards.',
        ],
        'CancelPurchaseOrder' => [
            'method' => 'replayCancelPurchaseOrder',
            'why' => 'A second cancellation of a cancelled order. Status guard, as above.',
        ],
        'RestoreItem' => [
            'method' => 'replayRestoreItem',
            'why' => 'A second restoration of a live row. Its mechanism is the soft-delete predicate rather than a '
                .'status, which is why §4.2 spells it guard:<table>.deleted_at.',
        ],
    ];

    /**
     * **The domain, derived from the registry and the mechanism table rather
     * than from the list above.**
     *
     * **Mutation:** delete any entry from `REPLAY_CASES`; red, naming the
     * operation that ships with a mechanism nothing replays. **Reverse
     * mutation:** add one for an operation that does not ship; also red.
     */
    #[Test]
    public function theDomainIsEveryShippedOperationWhoseMechanismCanBeExercised(): void
    {
        $shipped = array_keys(TransactionBoundaryTest::contractOperations());
        self::assertNotSame([], $shipped, 'The registry produced no operations, so this file has no subject.');

        $exercisable = [];
        foreach (self::mechanisms() as $operationId => $mechanism) {
            if (!\in_array($operationId, $shipped, true)) {
                continue;
            }

            if ('no' === $mechanism || '⛔ none' === $mechanism || 'store' === $mechanism) {
                // The last two are asserted unshippable by IdempotencyContractTest;
                // seeing one here would mean that guard had stopped working, so it
                // is named rather than skipped.
                self::assertSame('no', $mechanism, \sprintf(
                    '%s ships with the mechanism "%s", which IdempotencyContractTest asserts cannot ship. '
                    .'This file excluded it on that guarantee; the guarantee has failed.', $operationId, $mechanism));

                continue;
            }

            $exercisable[] = $operationId;
        }

        sort($exercisable);
        $covered = array_keys(self::REPLAY_CASES);
        sort($covered);

        self::assertNotSame([], $exercisable, 'No shipped operation carries a live mechanism, so every case below is vacuous.');
        self::assertSame($covered, $exercisable,
            'The operations that ship with a mechanism §4.2 names are not the ones replayed here. A mechanism that is '
            .'declared, indexed and never sent twice is the gap §6.1\'s *fails if* is about: the contract test says the '
            .'index exists, and nothing says the processor reaches it.');
    }

    /**
     * §6.1's clause, one operation at a time.
     *
     * **Mutations, run 2026-09-12, each landed by occurrence count and reverted
     * `cmp`-identical:** the client's key replaced by a server-minted one in
     * `PurchaseOrderWriter::create()` → red on `CreatePurchaseOrder`;
     * `PurchaseOrder::place()`'s status guard deleted → red on
     * `ApprovePurchaseOrder` (200 where 409 is owed); the receipt's replay read
     * short-circuited in `GoodsReceiptWriter::createInside()` → red on
     * `CreateGoodsReceipt`.
     *
     * ⚠ **The third one was GREEN on the first attempt and the test was wrong,
     * not the mutation.** With the replay read bypassed, the second delivery
     * loses at `goods_receipt_idem_unique` instead — so one row was still the
     * answer and a case that only counted rows stayed green over a broken replay
     * path. The index stops the second delivery; the replay read is what turns
     * the retry into an *answer*. The case now asserts the returned id, which is
     * what §6.2 requires of a `unique:` operation carrying a stored digest, and
     * the same mutation reddens it. See `PurchaseOrderRowLockTest`'s twin for the
     * same distinction at the race boundary.
     */
    #[Test]
    #[DataProvider('replayCases')]
    public function everyIdempotentOperationRefusesAReplay(string $operationId): void
    {
        $case = self::REPLAY_CASES[$operationId];

        $this->{$case['method']}($case['why']);
    }

    /** @return iterable<string, array{string}> */
    public static function replayCases(): iterable
    {
        foreach (array_keys(self::REPLAY_CASES) as $operationId) {
            yield $operationId => [$operationId];
        }
    }

    // -----------------------------------------------------------------------
    // The cases. Each sends the SAME request twice, and reads the database as
    // the owner afterwards — a response is not evidence about a row.
    // -----------------------------------------------------------------------

    private function replayCreateItem(string $why): void
    {
        $body = ['sku' => 'SKU-REPLAY', 'name' => 'Widget', 'unit_of_measure_code' => 'EA'];
        $key = [IdempotencyKeyGuard::HEADER => Uuid::v4()->toRfc4122()];

        $this->send('POST', '/items', $body, $key);
        self::assertSame(201, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());

        $this->send('POST', '/items', $body, $key);

        self::assertCount(1, $this->fetchAsOwnerForTenant(self::TENANT_A, 'SELECT id FROM inventory_item'), $why);
    }

    private function replayCreatePurchaseOrder(string $why): void
    {
        $body = [
            'supplier_id' => $this->seedSupplier(self::TENANT_A, 'Acme'),
            'warehouse_id' => $this->seedWarehouse(self::TENANT_A, 'Main'),
        ];
        $key = [IdempotencyKeyGuard::HEADER => Uuid::v4()->toRfc4122()];

        $this->send('POST', '/purchase-orders', $body, $key);
        self::assertSame(201, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        $first = $this->json()['id'];

        $this->send('POST', '/purchase-orders', $body, $key);

        self::assertSame($first, $this->json()['id'] ?? null, 'The replay did not return the first order.');
        self::assertCount(1, $this->fetchAsOwnerForTenant(self::TENANT_A, 'SELECT id FROM purchase_order'), $why);
    }

    private function replayCreateGoodsReceipt(string $why): void
    {
        [$order, $line, $bin] = $this->orderedOrderToReceiveHere();
        $body = ['lines' => [['purchase_order_line_id' => $line, 'quantity' => '1', 'storage_location_id' => $bin]]];
        $key = [IdempotencyKeyGuard::HEADER => Uuid::v4()->toRfc4122()];

        $this->send('POST', '/purchase-orders/'.$order.'/goods-receipts', $body, $key);
        self::assertSame(201, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        $first = $this->json()['id'];

        $this->send('POST', '/purchase-orders/'.$order.'/goods-receipts', $body, $key);

        // ⚠ The row count alone does NOT hold this operation's replay read up:
        // measured 2026-09-12 by short-circuiting `if (false !== $replay)` in
        // `GoodsReceiptWriter::createInside()` — the second INSERT then loses at
        // `goods_receipt_idem_unique` instead, so one row is still the answer and
        // the count stays green. The index prevents the second delivery; the
        // replay read is what turns the retry into an ANSWER rather than a
        // refusal, which is what §6.2 requires of a `unique:` operation carrying
        // a stored digest. Asserting the returned id is what makes that mutation
        // red, and it is the reason this assertion is here rather than only the
        // count below.
        self::assertSame($first, $this->json()['id'] ?? null,
            'The replay did not return the receipt the first call recorded. A retry that is refused rather than '
            .'answered is not idempotent; it is merely not duplicated.');

        self::assertCount(1, $this->fetchAsOwnerForTenant(self::TENANT_A, 'SELECT id FROM goods_receipt'), $why);
        self::assertSame([['quantity_received' => '1.000000']], $this->fetchAsOwnerForTenant(self::TENANT_A,
            'SELECT quantity_received FROM purchase_order_line WHERE id = ?::uuid', [$line]),
            'The replay applied the delivery a second time to the order line, which is the effect the row count alone '
            .'cannot see.');
    }

    private function replayApprovePurchaseOrder(string $why): void
    {
        $draft = $this->seedDraft(self::TENANT_A, 'PO-1');
        $this->seedLine(self::TENANT_A, $draft['order'], $draft['item']);

        $this->send('POST', '/purchase-orders/'.$draft['order'].'/approval', []);
        self::assertSame(200, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        $after = $this->rowOf($draft['order']);

        $this->send('POST', '/purchase-orders/'.$draft['order'].'/approval', []);

        self::assertSame(409, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        self::assertSame($after, $this->rowOf($draft['order']), $why);
        self::assertSame(PurchaseOrder::STATUS_ORDERED, $after[0]['status']);
    }

    private function replayCancelPurchaseOrder(string $why): void
    {
        $draft = $this->seedDraft(self::TENANT_A, 'PO-1');

        $this->send('POST', '/purchase-orders/'.$draft['order'].'/cancellation', []);
        self::assertSame(200, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        $after = $this->rowOf($draft['order']);

        $this->send('POST', '/purchase-orders/'.$draft['order'].'/cancellation', []);

        self::assertSame(409, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        self::assertSame($after, $this->rowOf($draft['order']), $why);
        self::assertSame(PurchaseOrder::STATUS_CANCELLED, $after[0]['status']);
    }

    private function replayRestoreItem(string $why): void
    {
        $this->send('POST', '/items', ['sku' => 'SKU-R', 'name' => 'Widget', 'unit_of_measure_code' => 'EA'],
            [IdempotencyKeyGuard::HEADER => Uuid::v4()->toRfc4122()]);
        self::assertSame(201, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        $id = $this->json()['id'];

        $this->send('DELETE', '/items/'.$id);
        self::assertSame(204, $this->httpStatus());

        $this->send('POST', '/items/'.$id.'/restoration');
        self::assertSame(200, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        $after = $this->fetchAsOwnerForTenant(self::TENANT_A, 'SELECT deleted_at, updated_at FROM inventory_item WHERE id = ?::uuid', [$id]);

        $this->send('POST', '/items/'.$id.'/restoration');

        self::assertSame(409, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        self::assertSame($after, $this->fetchAsOwnerForTenant(self::TENANT_A,
            'SELECT deleted_at, updated_at FROM inventory_item WHERE id = ?::uuid', [$id]), $why);
        self::assertNull($after[0]['deleted_at'], 'The first restoration did not restore the row, so the second proves nothing.');
    }

    // -----------------------------------------------------------------------

    /**
     * Everything a repeat could move on the order. `updated_at` is in it on
     * purpose: a guard that refused the transition and still touched the clock
     * has applied an effect.
     *
     * @return list<array<string, mixed>>
     */
    private function rowOf(string $orderId): array
    {
        return $this->fetchAsOwnerForTenant(self::TENANT_A,
            'SELECT status, ordered_at, expected_at, updated_at, total_amount FROM purchase_order WHERE id = ?::uuid',
            [$orderId]);
    }

    /** @return array{0: string, 1: string, 2: string} order, line, bin */
    private function orderedOrderToReceiveHere(): array
    {
        $draft = $this->seedDraft(self::TENANT_A, 'PO-1');
        $line = $this->seedLine(self::TENANT_A, $draft['order'], $draft['item']);
        $bin = Uuid::v4()->toRfc4122();
        $this->insertAsOwnerForTenant(self::TENANT_A,
            "INSERT INTO storage_location (id, tenant_id, warehouse_id, code) VALUES (?::uuid, ?::uuid, ?::uuid, 'A-01')",
            [$bin, self::TENANT_A, $draft['warehouse']]);
        $this->insertAsOwnerForTenant(self::TENANT_A,
            "UPDATE purchase_order SET status = 'ordered', ordered_at = now() WHERE id = ?::uuid", [$draft['order']]);

        return [$draft['order'], $line, $bin];
    }

    /**
     * §4.2's mechanism per operation, read from the file that pins them so this
     * one does not become a second copy of the same table.
     *
     * @return array<string, string>
     */
    private static function mechanisms(): array
    {
        /** @var array<string, string> $pinned */
        $pinned = (new \ReflectionClass(IdempotencyContractTest::class))->getConstant('PINNED_MECHANISMS');

        return $pinned;
    }
}
