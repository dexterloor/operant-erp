<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Modules\Inventory\Application\StockAdjuster;
use App\Modules\Inventory\Application\StockTransferrer;
use App\Modules\UserManagement\Domain\DefaultRoles;
use App\Modules\Warehouses\Application\StorageLocationWriter;
use App\Platform\Http\Idempotency\IdempotencyKeyGuard;
use App\Platform\Http\Idempotency\IdempotencyKeyHash;
use App\Platform\Http\Idempotency\RequestDigest;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Uid\Uuid;

/**
 * **The stock transfer's races, two connections each** (stock transfer C1, 2026-10-20; design Revision 2 §C1.4 T-R1…T-R7,
 * final RED L-3). Connection A is `support/hold_order_lock.php`, a child running its own SQL (`HOLD_BEFORE`), uncommitted,
 * which runs `HOLD_AFTER` and commits only once Postgres reports a backend blocked on it; connection B is the real HTTP
 * request. Only B's code is under test. The holder's own aggregate lock lands on a random `purchase_order` id and matches
 * no row (`StockAdjustmentRaceTest`'s shape; the whitelist is not widened). Where a statement of the application exists
 * as a public constant, the holder executes THAT constant, never a transcription (final RED M-3).
 *
 * - **T-R1** (mutation M-L1, source-first level order): B moves stock from the HIGHER bin id to the LOWER one (asserted
 *   before the race). The holder is the opposite transfer's levels in the house order: `LEVEL_BOUNDED_UPSERT` on the lower
 *   id, then — once B waits — `LEVEL_DECREMENT` on the higher. Ascending, B's first level statement is on the lower id, so
 *   it waits there holding nothing the holder needs: the holder commits (`sqlstate` null) and B answers 201. Source-first,
 *   B holds the higher row and waits on the lower while the holder waits on the higher: a deadlock, `40P01` on one side.
 *   **T-R1b**, its twin (source id lower), is green under M-L1 by construction — source-first IS ascending there.
 * - **T-R2 / T-R3**: a pick (`StockDepleter`'s own decrement) or a count holds the source level; B waits at its decrement
 *   and then reads the committed figure — a shortage refusal stating THAT figure, never a negative level or a 500.
 * - **T-R4** (M-I2): the same request's legs are in flight (same key, same digest), uncommitted; B waits at the unique key,
 *   and its 23505 is translated INSIDE the transaction, under the savepoint, into the replay; another digest is 409.
 * - **T-R5** (M-K1): A holds B's pickable SOURCE bin as quarantine (`FOR UPDATE`, a hold); B, a non-owner, waits at its
 *   source-bin `FOR KEY SHARE`, then reads `quarantine` and is refused the release.
 * - **T-R6** (M-K2): A releases B's empty quarantine DESTINATION with `StorageLocationWriter`'s own statements; B, a
 *   non-owner moving quarantine → quarantine, waits at its destination-bin lock, then reads the committed `bin` and is
 *   refused the release.
 * - **T-R7** — the (C′) invariant: a non-owner's quarantine → quarantine move keeps the item held, so the count's held-item
 *   gate still refuses a non-owner's count-up afterwards. **Disclosed, not a race:** `StockAdjuster::HELD_ANYWHERE` takes
 *   no lock, so a count reading WHILE a move is in flight waits on nothing and this harness has no waiter to see; that the
 *   two legs commit together (one statement, one transaction) is what makes the unlocked read safe, and that half is a
 *   REVIEW CHECK, not evidence a mutation can redden here.
 */
final class StockTransferRaceTest extends PurchaseOrderTestCase
{
    private const HOLDER_DEADLINE_SECONDS = 10.0;

    #[Test]
    public function oppositeTransfersBetweenTwoBinsTakeTheLevelsInOneOrder(): void
    {
        [$high, $low, $item] = $this->twoBins(sourceHigher: true);
        $this->level($item, $high, '10');
        $this->level($item, $low, '10');
        $this->actAs(self::TENANT_A, DefaultRoles::codesFor(DefaultRoles::WAREHOUSE_OPERATOR));
        self::assertGreaterThan(0, strcmp($high, $low), 'T-R1 needs the source id to sort after the destination id.');

        $holder = $this->raceAgainstAHolder(
            [[StockTransferrer::LEVEL_BOUNDED_UPSERT, [Uuid::v4()->toRfc4122(), self::TENANT_A, $item, $low, '1', StockTransferrer::LARGEST_QUANTITY]]],
            fn () => $this->transfer($item, $high, $low, '2', Uuid::v4()->toRfc4122()),
            [[StockTransferrer::LEVEL_DECREMENT, ['1', self::TENANT_A, $item, $high, '1']]],
        );

        self::assertTrue($holder['waiter_seen'], 'B never waited on the holder: '.json_encode($holder));
        self::assertNull($holder['sqlstate'], 'The holder failed (a deadlock means the levels were not taken in ascending id order): '.json_encode($holder));
        self::assertTrue($holder['committed']);
        self::assertSame(201, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        self::assertSame(['7.000000', '13.000000'], [$this->onHand($item, $high), $this->onHand($item, $low)]);
    }

    /** T-R1b: the twin with the source id LOWER — green under M-L1 by construction (source-first is ascending here). */
    #[Test]
    public function oppositeTransfersWithTheSourceIdLowerAlsoSerialise(): void
    {
        [$low, $high, $item] = $this->twoBins(sourceHigher: false);
        $this->level($item, $low, '10');
        $this->level($item, $high, '10');
        $this->actAs(self::TENANT_A, DefaultRoles::codesFor(DefaultRoles::WAREHOUSE_OPERATOR));
        self::assertLessThan(0, strcmp($low, $high));

        $holder = $this->raceAgainstAHolder(
            [[StockTransferrer::LEVEL_DECREMENT, ['1', self::TENANT_A, $item, $low, '1']]],
            fn () => $this->transfer($item, $low, $high, '2', Uuid::v4()->toRfc4122()),
            [[StockTransferrer::LEVEL_BOUNDED_UPSERT, [Uuid::v4()->toRfc4122(), self::TENANT_A, $item, $high, '1', StockTransferrer::LARGEST_QUANTITY]]],
        );

        self::assertTrue($holder['waiter_seen'], json_encode($holder));
        self::assertNull($holder['sqlstate'], json_encode($holder));
        self::assertSame(201, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        self::assertSame(['7.000000', '13.000000'], [$this->onHand($item, $low), $this->onHand($item, $high)]);
    }

    /**
     * T-R2: a pick in flight on the source; the transfer waits and refuses the shortage it then sees (M-S1 → 23514 → 500).
     * The holder's statement is `StockDepleter`'s conditional decrement transcribed (that class holds it inline, not as a
     * public constant); only the transfer's side is under test.
     */
    #[Test]
    public function aTransferWaitsForAPickAndRefusesTheShortageItThenSees(): void
    {
        [$from, $to, $item] = $this->twoBins(sourceHigher: true);
        $this->level($item, $from, '10');
        $this->actAs(self::TENANT_A, DefaultRoles::codesFor(DefaultRoles::WAREHOUSE_OPERATOR));

        $holder = $this->raceAgainstAHolder(
            [['UPDATE stock_level SET qty_on_hand = qty_on_hand - 8, version = version + 1 WHERE inventory_item_id = ?::uuid AND storage_location_id = ?::uuid AND qty_on_hand >= 8',
                [$item, $from]]],
            fn () => $this->transfer($item, $from, $to, '5', Uuid::v4()->toRfc4122()),
        );

        $this->assertWaitedAndCommitted($holder);
        self::assertSame(409, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        self::assertSame(['stock_level.on_hand_covers_the_transfer', StockTransferrer::onHandDoesNotCover('2.000000', '5.000000')], [$this->json()['rule'], $this->json()['detail']]);
        self::assertSame('2.000000', $this->onHand($item, $from));
        self::assertNull($this->onHand($item, $to));
    }

    /** T-R3: a count in flight on the source (its level `FOR UPDATE`, set to 3); the transfer waits and states the 3. */
    #[Test]
    public function aTransferWaitsForACountAndRefusesTheShortageItThenSees(): void
    {
        [$from, $to, $item] = $this->twoBins(sourceHigher: false);
        $this->level($item, $from, '10');
        $this->actAs(self::TENANT_A, DefaultRoles::codesFor(DefaultRoles::WAREHOUSE_OPERATOR));

        $holder = $this->raceAgainstAHolder(
            [
                ['SELECT 1 FROM stock_level WHERE inventory_item_id = ?::uuid AND storage_location_id = ?::uuid FOR UPDATE', [$item, $from]],
                ['UPDATE stock_level SET qty_on_hand = 3, version = version + 1 WHERE inventory_item_id = ?::uuid AND storage_location_id = ?::uuid', [$item, $from]],
            ],
            fn () => $this->transfer($item, $from, $to, '5', Uuid::v4()->toRfc4122()),
        );

        $this->assertWaitedAndCommitted($holder);
        self::assertSame(409, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        self::assertSame(StockTransferrer::onHandDoesNotCover('3.000000', '5.000000'), $this->json()['detail']);
        self::assertSame('3.000000', $this->onHand($item, $from));
        self::assertSame(0, $this->countAsOwnerForTenant(self::TENANT_A, "SELECT count(*) FROM stock_movement WHERE reason IN ('transfer_out', 'transfer_in') AND inventory_item_id = ?::uuid", [$item]));
    }

    /** T-R4 (M-I2): the same request in flight is a replay — never a 500; another body under the key is 409. */
    #[Test]
    public function aDuplicateInFlightIsAReplayAndADifferentBodyIsAConflict(): void
    {
        [$from, $to, $item] = $this->twoBins(sourceHigher: true);
        $this->level($item, $from, '10');
        $this->actAs(self::TENANT_A, DefaultRoles::codesFor(DefaultRoles::WAREHOUSE_OPERATOR));

        $key = Uuid::v4()->toRfc4122();
        $out = Uuid::v4()->toRfc4122();
        $holder = $this->raceAgainstAHolder(
            $this->firstRequestPosts($out, $item, $from, $to, '4', $key, $this->digest($item, $from, $to, '4')),
            fn () => $this->transfer($item, $from, $to, '4', $key),
        );
        $this->assertWaitedAndCommitted($holder);
        self::assertSame(201, $this->httpStatus(), 'A duplicate in flight was not answered as the replay: '.(string) $this->client()->getResponse()->getContent());
        self::assertSame($out, $this->json()['id']);
        self::assertSame(2, $this->countAsOwnerForTenant(self::TENANT_A, 'SELECT count(*) FROM stock_movement WHERE inventory_item_id = ?::uuid', [$item]));

        $key = Uuid::v4()->toRfc4122();
        $holder = $this->raceAgainstAHolder(
            $this->firstRequestPosts(Uuid::v4()->toRfc4122(), $item, $from, $to, '3', $key, $this->digest($item, $from, $to, '3')),
            fn () => $this->transfer($item, $from, $to, '4', $key),
        );
        $this->assertWaitedAndCommitted($holder);
        self::assertSame(409, $this->httpStatus(), 'A 23505 on the key was not translated: '.(string) $this->client()->getResponse()->getContent());
        self::assertStringEndsWith('idempotency-key-conflict', (string) $this->json()['type']);
    }

    /** T-R5 (M-K1): a hold of the source in flight; the non-owner's transfer waits, reads `quarantine`, is refused. */
    #[Test]
    public function aTransferOutOfABinBeingHeldWaitsAndMeetsTheReleaseRule(): void
    {
        [$from, $to, $item] = $this->twoBins(sourceHigher: true);
        $this->level($item, $from, '10');
        $this->actAs(self::TENANT_A, DefaultRoles::codesFor(DefaultRoles::WAREHOUSE_OPERATOR));

        $holder = $this->raceAgainstAHolder(
            [
                ['SELECT 1 FROM storage_location WHERE id = ?::uuid FOR UPDATE', [$from]],
                ["UPDATE storage_location SET kind = 'quarantine' WHERE id = ?::uuid", [$from]],
            ],
            fn () => $this->transfer($item, $from, $to, '4', Uuid::v4()->toRfc4122()),
        );

        $this->assertWaitedAndCommitted($holder);
        self::assertSame(409, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        self::assertSame('stock_level.release_needs_owner', $this->json()['rule']);
        self::assertSame('10.000000', $this->onHand($item, $from), 'Held stock was released by a non-owner.');
    }

    /**
     * T-R6 (M-K2): the owner's release of B's EMPTY quarantine destination, by `StorageLocationWriter`'s own statements, is in
     * flight; B, a non-owner moving quarantine → quarantine, waits, reads the committed `bin` and is refused.
     */
    #[Test]
    public function aTransferIntoABinBeingReleasedWaitsAndMeetsTheReleaseRule(): void
    {
        $warehouse = $this->seedWarehouse(self::TENANT_A, 'Race '.Uuid::v4()->toRfc4122());
        $q1 = $this->seedStorageLocation(self::TENANT_A, $warehouse, 'Q-01', 'quarantine');
        $q2 = $this->seedStorageLocation(self::TENANT_A, $warehouse, 'Q-02', 'quarantine');
        $item = $this->item();
        $this->level($item, $q1, '10');
        $this->actAs(self::TENANT_A, DefaultRoles::codesFor(DefaultRoles::WAREHOUSE_OPERATOR));

        $holder = $this->raceAgainstAHolder(
            [
                [StorageLocationWriter::PARENT_LOCK, [$warehouse]],
                [StorageLocationWriter::LOCATION_READ.' FOR UPDATE', [$q2, $warehouse]],
                [StorageLocationWriter::HELD_STOCK, [self::TENANT_A, $q2]],
                ["UPDATE storage_location SET kind = 'bin', updated_at = now() WHERE id = ?::uuid", [$q2]],
            ],
            fn () => $this->transfer($item, $q1, $q2, '4', Uuid::v4()->toRfc4122()),
        );

        $this->assertWaitedAndCommitted($holder);
        self::assertSame(409, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        self::assertSame('stock_level.release_needs_owner', $this->json()['rule']);
        self::assertNull($this->onHand($item, $q2), 'Held stock reached a released bin without the owner.');
    }

    /**
     * T-R7 — the (C′) invariant, sequential: a non-owner's quarantine → quarantine move (which empties the first bin) keeps
     * the item held, so a non-owner's count-up of the item in a pickable bin is still refused `held_item_needs_owner`. See
     * the class docblock for the half that is a review check, not a race.
     */
    #[Test]
    public function aMoveBetweenQuarantineBinsKeepsTheItemHeld(): void
    {
        $warehouse = $this->seedWarehouse(self::TENANT_A, 'Race '.Uuid::v4()->toRfc4122());
        $q1 = $this->seedStorageLocation(self::TENANT_A, $warehouse, 'Q-01', 'quarantine');
        $q2 = $this->seedStorageLocation(self::TENANT_A, $this->seedWarehouse(self::TENANT_A, 'Race '.Uuid::v4()->toRfc4122()), 'Q-02', 'quarantine');
        $pick = $this->seedStorageLocation(self::TENANT_A, $warehouse, 'A-01');
        $item = $this->item();
        $this->level($item, $q1, '10');
        $this->actAs(self::TENANT_A, DefaultRoles::codesFor(DefaultRoles::WAREHOUSE_OPERATOR));

        $this->transfer($item, $q1, $q2, '10', Uuid::v4()->toRfc4122());
        self::assertSame(201, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        self::assertSame(['0.000000', '10.000000'], [$this->onHand($item, $q1), $this->onHand($item, $q2)]);

        $this->send('POST', '/stock-movements/adjustments', [
            'inventory_item_id' => $item, 'storage_location_id' => $pick, 'counted_quantity' => '10', 'version' => null, 'note' => 'Found',
        ], [IdempotencyKeyGuard::HEADER => Uuid::v4()->toRfc4122()]);
        self::assertSame(409, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        self::assertSame(['stock_level.held_item_needs_owner', StockAdjuster::HELD_ITEM_NEEDS_OWNER], [$this->json()['rule'], $this->json()['detail']]);
        self::assertSame([['held' => '10.000000']], $this->fetchAsOwnerForTenant(self::TENANT_A, <<<'SQL'
            SELECT sum(s.qty_on_hand)::numeric(19,6)::text AS held FROM stock_level s
              JOIN storage_location l ON l.id = s.storage_location_id
             WHERE s.inventory_item_id = ?::uuid AND l.kind = 'quarantine' AND l.deleted_at IS NULL
            SQL, [$item]), 'The item\'s live-quarantine total fell.');
    }

    /**
     * Two bins of one warehouse and an item; the bin ids are drawn until their order is the one asked for.
     *
     * @return array{0: string, 1: string, 2: string} [source, destination, item]
     */
    private function twoBins(bool $sourceHigher): array
    {
        $warehouse = $this->seedWarehouse(self::TENANT_A, 'Race '.Uuid::v4()->toRfc4122());
        do {
            $a = Uuid::v4()->toRfc4122();
            $b = Uuid::v4()->toRfc4122();
        } while (($sourceHigher ? strcmp($a, $b) : strcmp($b, $a)) <= 0);
        foreach ([$a => 'R-01', $b => 'R-02'] as $id => $code) {
            $this->insertAsOwnerForTenant(self::TENANT_A,
                'INSERT INTO storage_location (id, tenant_id, warehouse_id, code) VALUES (?::uuid, ?::uuid, ?::uuid, ?)',
                [$id, self::TENANT_A, $warehouse, $code]);
        }

        return [$a, $b, $this->item()];
    }

    private function item(): string
    {
        return $this->seedItem(self::TENANT_A, 'XRACE-'.strtoupper(substr(Uuid::v4()->toRfc4122(), 0, 8)));
    }

    private function level(string $item, string $bin, string $onHand): void
    {
        $this->insertAsOwnerForTenant(self::TENANT_A,
            'INSERT INTO stock_level (id, tenant_id, inventory_item_id, storage_location_id, qty_on_hand) VALUES (?::uuid, ?::uuid, ?::uuid, ?::uuid, ?::numeric)',
            [Uuid::v4()->toRfc4122(), self::TENANT_A, $item, $bin, $onHand]);
    }

    private function transfer(string $item, string $from, string $to, string $quantity, string $key): void
    {
        $this->send('POST', '/stock-movements/transfers', [
            'inventory_item_id' => $item, 'source_storage_location_id' => $from, 'destination_storage_location_id' => $to, 'quantity' => $quantity,
        ], [IdempotencyKeyGuard::HEADER => $key]);
    }

    /**
     * What the first of two requests writes, as `StockTransferrer` writes it — both legs, the key's hash and the digest on
     * the out leg alone (the levels are the caller's to add).
     *
     * @return list<array{0: string, 1: list<string|null>}>
     */
    private function firstRequestPosts(string $out, string $item, string $from, string $to, string $quantity, string $key, string $digest): array
    {
        $in = Uuid::v4()->toRfc4122();

        return [[
            "INSERT INTO stock_movement (id, tenant_id, inventory_item_id, storage_location_id, quantity, reason, source_type, source_id, occurred_at, idempotency_key, request_digest)
             VALUES (?::uuid, ?::uuid, ?::uuid, ?::uuid, -?::numeric, 'transfer_out', 'stock_movement', ?::uuid, date_trunc('second', now()), ?, ?),
                    (?::uuid, ?::uuid, ?::uuid, ?::uuid, ?::numeric, 'transfer_in', 'stock_movement', ?::uuid, date_trunc('second', now()), NULL, NULL)",
            [$out, self::TENANT_A, $item, $from, $quantity, $in, IdempotencyKeyHash::of($key), $digest,
                $in, self::TENANT_A, $item, $to, $quantity, $out],
        ]];
    }

    private function digest(string $item, string $from, string $to, string $quantity): string
    {
        return RequestDigest::of(StockTransferrer::OPERATION, [
            'inventory_item_id' => RequestDigest::uuid($item),
            'source_storage_location_id' => RequestDigest::uuid($from),
            'destination_storage_location_id' => RequestDigest::uuid($to),
            'quantity' => $quantity,
            'note' => null,
        ]);
    }

    private function onHand(string $item, string $bin): ?string
    {
        $row = $this->fetchAsOwnerForTenant(self::TENANT_A,
            'SELECT qty_on_hand::text AS q FROM stock_level WHERE inventory_item_id = ?::uuid AND storage_location_id = ?::uuid', [$item, $bin]);

        return [] === $row ? null : (string) $row[0]['q'];
    }

    /** @param array{waiter_seen: bool, committed: bool, sqlstate: ?string, error: ?string} $holder */
    private function assertWaitedAndCommitted(array $holder): void
    {
        self::assertTrue($holder['waiter_seen'], 'The transfer never waited on the holder, so nothing here was a race: '.json_encode($holder));
        self::assertTrue($holder['committed'], 'The holder did not commit: '.json_encode($holder));
    }

    /**
     * `StockAdjustmentRaceTest::raceAgainstAHolder`'s shape, plus `HOLD_AFTER` (run once this request is seen waiting).
     *
     * @param list<array{0: string, 1: list<string|null>}> $before
     * @param list<array{0: string, 1: list<string|null>}> $after
     *
     * @return array{waiter_seen: bool, committed: bool, sqlstate: ?string, error: ?string}
     */
    private function raceAgainstAHolder(array $before, callable $request, array $after = []): array
    {
        $readyFile = tempnam(sys_get_temp_dir(), 'erp_xfer_');
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
                'HOLD_AFTER' => json_encode($after, \JSON_THROW_ON_ERROR),
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
