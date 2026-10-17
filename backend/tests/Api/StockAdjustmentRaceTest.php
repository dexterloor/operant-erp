<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Modules\Inventory\Application\StockAdjuster;
use App\Modules\UserManagement\Domain\DefaultRoles;
use App\Platform\Http\Idempotency\IdempotencyKeyGuard;
use App\Platform\Http\Idempotency\IdempotencyKeyHash;
use App\Platform\Http\Idempotency\RequestDigest;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Uid\Uuid;

/**
 * **The stock count's races, two connections each** (stock count C1, 2026-10-16; final RED M-1 / design R1.3). Connection A
 * is `support/hold_order_lock.php`, a child running ITS OWN raw SQL (`HOLD_BEFORE`), uncommitted, which commits only once
 * Postgres reports a backend blocked on it; connection B is the real HTTP request. **Only B's code is under test**, so
 * every race here is written with the COUNT as B (final RED M-1). The holder's own lock lands on a random
 * `purchase_order` id and matches no row (`StorageLocationWriteTest`'s shape; its whitelist is not widened).
 *
 * - **R-2″** (every Q1 option): A turns a pickable bin into quarantine (a hold: `FOR UPDATE`, as `UpdateStorageLocation`
 *   takes for a kind change); B, a non-owner, counts that bin DOWN. With the count's `FOR KEY SHARE` on the bin, B waits,
 *   reads `quarantine` and is refused `count_down_needs_owner`. Without it (**M5**) B reads `bin`, waits only at the
 *   movement's foreign-key check and writes held stock off → 201 → red.
 * - **R-2′** ((C)/(C′) only): item X is held in Q1; A releases the EMPTY quarantine bin Q2 (legal for anyone: it holds
 *   nothing); B, a non-owner, counts X UP into Q2. With the lock B reads the committed `bin` and is refused by the held-item
 *   gate. Under M5 B reads `quarantine`, counts it as a hold, and X ends pickable in Q2 → 201 → red.
 * - **R-5** (final RED M-1 a/b): A posts the SAME request's movement first (same key, same digest), uncommitted. On an
 *   existing level B waits at the level lock and its post-lock re-check answers the replay (**M23**: drop the re-check →
 *   `stale-record` → red); on a never-stocked bin there is no level lock, so B waits at the unique key and its 23505 is
 *   translated into the replay (**M24**: drop the translation → 500 → red). With a DIFFERENT digest both paths answer 409.
 *
 * **Not built here, disclosed:** rev 0's R-1 (count ∥ receipt), R-3 (two counts at one version) and R-4 (count ∥ pick) —
 * the level row lock serialises each, and the version check answers the loser `stale-record`; none is red-capable against a
 * mutation the build carries beyond M1, which `StockAdjustmentTest::aStaleVersionIsRefusedAndWritesNothing` already reddens.
 */
final class StockAdjustmentRaceTest extends PurchaseOrderTestCase
{
    private const HOLDER_DEADLINE_SECONDS = 10.0;

    #[Test]
    public function aCountDownWaitsForAHoldAndIsThenRefused(): void
    {
        $s = $this->stock('bin', '10', 1);
        $this->actAs(self::TENANT_A, DefaultRoles::codesFor(DefaultRoles::WAREHOUSE_OPERATOR));

        $holder = $this->raceAgainstAHolder(
            [
                ['SELECT 1 FROM storage_location WHERE id = ?::uuid FOR UPDATE', [$s['bin']]],
                ["UPDATE storage_location SET kind = 'quarantine' WHERE id = ?::uuid", [$s['bin']]],
            ],
            fn () => $this->recount($s['item'], $s['bin'], '7', 1, Uuid::v4()->toRfc4122()),
        );

        $this->assertWaitedAndCommitted($holder);
        self::assertSame(409, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        self::assertSame('stock_level.count_down_needs_owner', $this->json()['rule']);
        self::assertSame(['10.000000', 1], $this->level($s['item'], $s['bin']), 'Held stock was written off by a non-owner.');
    }

    #[Test]
    public function aCountUpIntoABinBeingReleasedWaitsAndMeetsTheHeldItemGate(): void
    {
        $held = $this->stock('quarantine', '3', 1);
        $q2 = $this->seedStorageLocation(self::TENANT_A, $held['warehouse'], 'Q-02', 'quarantine');
        $this->actAs(self::TENANT_A, DefaultRoles::codesFor(DefaultRoles::WAREHOUSE_OPERATOR));

        $holder = $this->raceAgainstAHolder(
            [
                ['SELECT 1 FROM storage_location WHERE id = ?::uuid FOR UPDATE', [$q2]],
                ["UPDATE storage_location SET kind = 'bin' WHERE id = ?::uuid", [$q2]],
            ],
            fn () => $this->recount($held['item'], $q2, '3', null, Uuid::v4()->toRfc4122()),
        );

        $this->assertWaitedAndCommitted($holder);
        self::assertSame(409, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        self::assertSame('stock_level.held_item_needs_owner', $this->json()['rule']);
        self::assertSame([], $this->fetchAsOwnerForTenant(self::TENANT_A, 'SELECT 1 FROM stock_level WHERE storage_location_id = ?::uuid', [$q2]),
            'A held item became pickable stock in a released bin without the owner.');
    }

    #[Test]
    public function aDuplicateInFlightOnAStockedBinIsAReplay(): void
    {
        $s = $this->stock('bin', '10', 3);
        $this->actAs(self::TENANT_A, DefaultRoles::codesFor(DefaultRoles::WAREHOUSE_OPERATOR));
        $key = Uuid::v4()->toRfc4122();
        $movement = Uuid::v4()->toRfc4122();

        $holder = $this->raceAgainstAHolder(
            [
                ['SELECT 1 FROM stock_level WHERE inventory_item_id = ?::uuid AND storage_location_id = ?::uuid FOR UPDATE', [$s['item'], $s['bin']]],
                ...$this->firstRequestPosts($movement, $s, '7', 3, $key, $this->digest($s, '7', 3)),
                ['UPDATE stock_level SET qty_on_hand = 7, version = version + 1 WHERE inventory_item_id = ?::uuid AND storage_location_id = ?::uuid', [$s['item'], $s['bin']]],
            ],
            fn () => $this->recount($s['item'], $s['bin'], '7', 3, $key),
        );

        $this->assertWaitedAndCommitted($holder);
        self::assertSame(201, $this->httpStatus(), 'A duplicate in flight was not answered as the replay: '.(string) $this->client()->getResponse()->getContent());
        self::assertSame($movement, $this->json()['id']);
        self::assertSame(1, $this->countAsOwnerForTenant(self::TENANT_A, 'SELECT count(*) FROM stock_movement WHERE storage_location_id = ?::uuid', [$s['bin']]));
    }

    #[Test]
    public function aDuplicateInFlightOnANeverStockedBinIsAReplay(): void
    {
        $s = $this->stock('bin', null, null);
        $this->actAs(self::TENANT_A, DefaultRoles::codesFor(DefaultRoles::WAREHOUSE_OPERATOR));
        $key = Uuid::v4()->toRfc4122();
        $movement = Uuid::v4()->toRfc4122();

        $holder = $this->raceAgainstAHolder(
            $this->firstRequestPosts($movement, $s, '5', null, $key, $this->digest($s, '5', null)),
            fn () => $this->recount($s['item'], $s['bin'], '5', null, $key),
        );

        $this->assertWaitedAndCommitted($holder);
        self::assertSame(201, $this->httpStatus(), 'A duplicate in flight was not answered as the replay: '.(string) $this->client()->getResponse()->getContent());
        self::assertSame($movement, $this->json()['id']);
    }

    #[Test]
    public function aDifferentBodyInFlightUnderOneKeyIsAConflictOnEitherPath(): void
    {
        $stocked = $this->stock('bin', '10', 3);
        $empty = $this->stock('bin', null, null);
        $this->actAs(self::TENANT_A, DefaultRoles::codesFor(DefaultRoles::WAREHOUSE_OPERATOR));

        $key = Uuid::v4()->toRfc4122();
        $holder = $this->raceAgainstAHolder(
            [
                ['SELECT 1 FROM stock_level WHERE inventory_item_id = ?::uuid AND storage_location_id = ?::uuid FOR UPDATE', [$stocked['item'], $stocked['bin']]],
                ...$this->firstRequestPosts(Uuid::v4()->toRfc4122(), $stocked, '8', 3, $key, $this->digest($stocked, '8', 3)),
            ],
            fn () => $this->recount($stocked['item'], $stocked['bin'], '7', 3, $key),
        );
        $this->assertWaitedAndCommitted($holder);
        self::assertSame(409, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        self::assertStringEndsWith('idempotency-key-conflict', (string) $this->json()['type']);

        $key = Uuid::v4()->toRfc4122();
        $holder = $this->raceAgainstAHolder(
            $this->firstRequestPosts(Uuid::v4()->toRfc4122(), $empty, '6', null, $key, $this->digest($empty, '6', null)),
            fn () => $this->recount($empty['item'], $empty['bin'], '5', null, $key),
        );
        $this->assertWaitedAndCommitted($holder);
        self::assertSame(409, $this->httpStatus(), 'A 23505 on the key was not translated: '.(string) $this->client()->getResponse()->getContent());
        self::assertStringEndsWith('idempotency-key-conflict', (string) $this->json()['type']);
    }

    /**
     * **L-14 / mutation M17:** a bin showed no row, so the counter quotes `version: null`; meanwhile another writer (a
     * receipt, another count) creates the row, uncommitted. B's `INSERT … ON CONFLICT DO NOTHING` waits on it, inserts
     * nothing once it commits, re-reads the row in a new statement and answers `stale-record` with THAT row's figures; the
     * rollback takes B's movement with it. `DO UPDATE` instead (M17) would overwrite a figure the counter never saw → 201 → red.
     */
    #[Test]
    public function aCountIntoABinThatGainsARowMeanwhileIsStaleAndWritesNothing(): void
    {
        $s = $this->stock('bin', null, null);
        $this->actAs(self::TENANT_A, DefaultRoles::codesFor(DefaultRoles::WAREHOUSE_OPERATOR));

        $holder = $this->raceAgainstAHolder(
            [['INSERT INTO stock_level (id, tenant_id, inventory_item_id, storage_location_id, qty_on_hand) VALUES (?::uuid, ?::uuid, ?::uuid, ?::uuid, 2)',
                [Uuid::v4()->toRfc4122(), self::TENANT_A, $s['item'], $s['bin']]]],
            fn () => $this->recount($s['item'], $s['bin'], '5', null, Uuid::v4()->toRfc4122()),
        );

        $this->assertWaitedAndCommitted($holder);
        self::assertSame(409, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        self::assertSame(['qty_on_hand' => '2.000000', 'version' => 1], $this->json()['current']);
        self::assertSame(['2.000000', 1], $this->level($s['item'], $s['bin']));
        self::assertSame(0, $this->countAsOwnerForTenant(self::TENANT_A, 'SELECT count(*) FROM stock_movement WHERE storage_location_id = ?::uuid', [$s['bin']]),
            'The refused count left its movement.');
    }

    /**
     * What the first of two requests writes, as `StockAdjuster` writes it — the movement with the key's hash and the
     * request's digest (the level is the caller's to add).
     *
     * @param array{item: string, bin: string} $s
     *
     * @return list<array{0: string, 1: list<string|null>}>
     */
    private function firstRequestPosts(string $movement, array $s, string $counted, ?int $version, string $key, string $digest): array
    {
        return [[
            "INSERT INTO stock_movement (id, tenant_id, inventory_item_id, storage_location_id, quantity, reason, occurred_at, idempotency_key, request_digest)
             VALUES (?::uuid, ?::uuid, ?::uuid, ?::uuid, 1, 'adjustment', date_trunc('second', now()), ?, ?)",
            [$movement, self::TENANT_A, $s['item'], $s['bin'], IdempotencyKeyHash::of($key), $digest],
        ]];
    }

    /** @param array{item: string, bin: string} $s */
    private function digest(array $s, string $counted, ?int $version): string
    {
        return RequestDigest::of(StockAdjuster::OPERATION, [
            'inventory_item_id' => $s['item'], 'storage_location_id' => $s['bin'], 'counted_quantity' => $counted,
            'version' => $version, 'note' => 'Recounted',
        ]);
    }

    /** @return array{warehouse: string, bin: string, item: string} */
    private function stock(string $kind, ?string $onHand, ?int $version): array
    {
        $warehouse = $this->seedWarehouse(self::TENANT_A, 'Race '.Uuid::v4()->toRfc4122());
        $bin = $this->seedStorageLocation(self::TENANT_A, $warehouse, 'R-01', $kind);
        $item = $this->seedItem(self::TENANT_A, 'RACE-'.strtoupper(substr(Uuid::v4()->toRfc4122(), 0, 8)));
        if (null !== $onHand) {
            $this->insertAsOwnerForTenant(self::TENANT_A,
                'INSERT INTO stock_level (id, tenant_id, inventory_item_id, storage_location_id, qty_on_hand, version) VALUES (?::uuid, ?::uuid, ?::uuid, ?::uuid, ?::numeric, ?)',
                [Uuid::v4()->toRfc4122(), self::TENANT_A, $item, $bin, $onHand, $version]);
        }

        return ['warehouse' => $warehouse, 'bin' => $bin, 'item' => $item];
    }

    private function recount(string $item, string $bin, string $counted, ?int $version, string $key): void
    {
        $this->send('POST', '/stock-movements/adjustments', [
            'inventory_item_id' => $item, 'storage_location_id' => $bin, 'counted_quantity' => $counted, 'version' => $version, 'note' => 'Recounted',
        ], [IdempotencyKeyGuard::HEADER => $key]);
    }

    /** @return array{0: string, 1: int} */
    private function level(string $item, string $bin): array
    {
        $row = $this->fetchAsOwnerForTenant(self::TENANT_A,
            'SELECT qty_on_hand::text AS q, version FROM stock_level WHERE inventory_item_id = ?::uuid AND storage_location_id = ?::uuid', [$item, $bin]);

        return [(string) $row[0]['q'], (int) $row[0]['version']];
    }

    /** @param array{waiter_seen: bool, committed: bool, sqlstate: ?string, error: ?string} $holder */
    private function assertWaitedAndCommitted(array $holder): void
    {
        self::assertTrue($holder['waiter_seen'], 'The count never waited on the holder, so nothing here was a race: '.json_encode($holder));
        self::assertTrue($holder['committed'], 'The holder did not commit: '.json_encode($holder));
    }

    /**
     * `StorageLocationWriteTest::raceAgainstAHolder`'s shape exactly: the holder's own lock is on a random `purchase_order`
     * id and matches no row; the real locks and writes are `HOLD_BEFORE`'s, committed once this request is seen waiting.
     *
     * @param list<array{0: string, 1: list<string|null>}> $before
     *
     * @return array{waiter_seen: bool, committed: bool, sqlstate: ?string, error: ?string}
     */
    private function raceAgainstAHolder(array $before, callable $request): array
    {
        $readyFile = tempnam(sys_get_temp_dir(), 'erp_count_');
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
