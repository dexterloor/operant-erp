<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Modules\Inventory\Application\ItemWriter;
use App\Platform\Http\Idempotency\IdempotencyKeyGuard;
use App\SharedKernel\ValueObject\AuditEvent;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Uid\Uuid;

/**
 * **G5-C2a — `DeleteItem` / `RestoreItem` record who did it, and a second concurrent delete is told the truth**
 * (`docs/session13/g5-g6-bulk-export-design.md` §5.5 and Response to RED M-4; `g5-g6-final-red.md` L-5).
 *
 * - **The events.** One `inventory_item.deleted` row per archive and one `inventory_item.restored` row per restore,
 *   subject `('inventory_item', id)`, business under PC-11 (4a), `{}`, no IP, no permission code; **none on any refusal**
 *   (a repeat delete, an unknown or foreign id, a restore of a live row, a restore that collides on SKU). Until this commit
 *   both operations wrote no row at all, and nothing recorded that an item archive was unattributed.
 * - **The shipped defect (owner notice N-8, flagged 2026-10-22).** `ItemWriter::archive()` read the row with no lock and
 *   its `UPDATE` carried no `deleted_at IS NULL`, so of two simultaneous deletes of one item both answered 204 and the
 *   second moved `deleted_at` (and `updated_at`) forward. `restore()` had the same shape. Now the item row is locked
 *   before the checks ({@see ItemWriter::DELETE_LOCK} `FOR UPDATE`, {@see ItemWriter::RESTORE_LOCK} `FOR NO KEY UPDATE`)
 *   and each `UPDATE` carries its live-row predicate with a row-count check, so the second is told *"already archived"* /
 *   *"not archived"* and nothing changes.
 *
 * **Review checks, not evidence (RED `g5-c2a-red.md` L-6):** the "no audit row on a refusal" assertions cannot go red while
 * the refusal throws before the audit call, and the live-row predicates are reachable only once the row lock is weakened
 * (mutation M2, predicate dropped with the lock kept, stays green). The evidence is the shipped-shape mutations M3 / M4 /
 * M7 (lock and predicate, or lock and row-count check, dropped), which turn the concurrent tests red.
 *
 * **The races are real second connections** (`support/hold_order_lock.php`, `CustomerDeleteTest`'s shape): the holder runs
 * the WRITER'S OWN statements, uncommitted, and commits only once the request is seen waiting on it. Each fails if the
 * request never waited.
 */
final class ItemDeleteTest extends PurchaseOrderTestCase
{
    private const HOLDER_DEADLINE_SECONDS = 10.0;

    #[Test]
    public function aDeleteWritesExactlyOneItemDeletedRow(): void
    {
        $item = $this->seedItem(self::TENANT_A, 'AUD-DEL');

        $this->send('DELETE', '/items/'.$item);

        self::assertSame(204, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        self::assertSame([$this->businessRow(AuditEvent::INVENTORY_ITEM_DELETED, 'inventory_item', $item)], $this->auditRowsOf(AuditEvent::INVENTORY_ITEM_DELETED));
        self::assertSame([], $this->auditRowsOf(AuditEvent::INVENTORY_ITEM_RESTORED), 'A delete wrote a restore row.');
    }

    #[Test]
    public function aRestoreWritesExactlyOneItemRestoredRow(): void
    {
        $item = $this->seedItem(self::TENANT_A, 'AUD-RES');
        $this->send('DELETE', '/items/'.$item);
        self::assertSame(204, $this->httpStatus());

        $this->send('POST', '/items/'.$item.'/restoration');

        self::assertSame(200, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        self::assertSame([$this->businessRow(AuditEvent::INVENTORY_ITEM_RESTORED, 'inventory_item', $item)], $this->auditRowsOf(AuditEvent::INVENTORY_ITEM_RESTORED));
        self::assertCount(1, $this->auditRowsOf(AuditEvent::INVENTORY_ITEM_DELETED));
    }

    /** A sequential repeat was already refused before this commit; it must also write no second row. */
    #[Test]
    public function aRepeatedDeleteIsRefusedAndWritesNoSecondRow(): void
    {
        $item = $this->seedItem(self::TENANT_A, 'AUD-REP');
        $this->send('DELETE', '/items/'.$item);
        $before = $this->stored($item);

        $this->send('DELETE', '/items/'.$item);

        self::assertSame(409, $this->httpStatus());
        self::assertSame('inventory_item.is_archived', $this->json()['rule']);
        self::assertSame($before, $this->stored($item), 'A refused delete moved the row.');
        self::assertCount(1, $this->auditRowsOf(AuditEvent::INVENTORY_ITEM_DELETED), 'A refused delete wrote a trail row.');
    }

    #[Test]
    public function refusedRestoresWriteNoRow(): void
    {
        // Seeded before any request: a refused request closes the shared entity manager the seeders use.
        $live = $this->seedItem(self::TENANT_A, 'AUD-LIVE');
        $archived = $this->seedItem(self::TENANT_A, 'AUD-TAKEN');

        $this->send('POST', '/items/'.$live.'/restoration');
        self::assertSame(409, $this->httpStatus());
        self::assertSame('inventory_item.is_not_archived', $this->json()['rule']);

        // A live item took the archived one's SKU meanwhile: the 23505 rolls the restore back, row and all.
        $this->send('DELETE', '/items/'.$archived);
        self::assertSame(204, $this->httpStatus());
        $this->send('POST', '/items', ['sku' => 'AUD-TAKEN', 'name' => 'Taken', 'unit_of_measure_code' => 'EA'], [IdempotencyKeyGuard::HEADER => Uuid::v4()->toRfc4122()]);
        self::assertSame(201, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        $this->send('POST', '/items/'.$archived.'/restoration');
        self::assertSame(409, $this->httpStatus());
        self::assertSame('inventory_item.sku_is_unique_per_workspace', $this->json()['rule']);

        self::assertSame([], $this->auditRowsOf(AuditEvent::INVENTORY_ITEM_RESTORED));
    }

    #[Test]
    public function anUnknownOrForeignItemIsTheSame404AndWritesNothing(): void
    {
        $theirs = $this->seedItem(self::TENANT_B, 'AUD-THEIRS');

        foreach ([Uuid::v4()->toRfc4122(), $theirs, 'not-a-uuid'] as $id) {
            $this->send('DELETE', '/items/'.$id);
            self::assertSame(404, $this->httpStatus(), 'DELETE '.$id);
            $this->send('POST', '/items/'.$id.'/restoration');
            self::assertSame(404, $this->httpStatus(), 'restore '.$id);
        }

        self::assertSame([], $this->auditRowsOf(AuditEvent::INVENTORY_ITEM_DELETED));
        self::assertSame([], $this->auditRowsOf(AuditEvent::INVENTORY_ITEM_RESTORED));
        self::assertSame([], $this->auditRowsOf(AuditEvent::INVENTORY_ITEM_DELETED, self::TENANT_B));
        self::assertNull($this->storedIn(self::TENANT_B, $theirs)['deleted_at']);
    }

    /**
     * **N-8, the shipped defect.** A delete in flight (the writer's own lock and UPDATE, uncommitted) ∥ a second delete of
     * the same item: the second waits, then is told the item is already archived, and the first delete's stamp stands.
     * Before this commit the second answered 204 and moved `deleted_at` forward (measured red, `g5-c2a-progress.md`).
     */
    #[Test]
    public function aSecondConcurrentDeleteIsToldAlreadyArchivedAndMovesNothing(): void
    {
        $item = $this->seedItem(self::TENANT_A, 'RACE-DEL');

        $holder = $this->raceAgainstAHolder([[ItemWriter::DELETE_LOCK, [$item]], [ItemWriter::ARCHIVE, [$item]]], function () use ($item): void {
            $this->send('DELETE', '/items/'.$item);
        }, report: [[self::STAMPS, [$item]]]);

        self::assertTrue($holder['waiter_seen'], 'The second delete never waited on the first. '.json_encode($holder));
        self::assertTrue($holder['committed'], 'The holder did not commit: '.json_encode($holder));
        $firstStamp = $this->stored($item);
        self::assertNotNull($firstStamp['deleted_at']);

        self::assertSame(409, $this->httpStatus(), 'The second of two concurrent deletes was told it succeeded: '.$this->client()->getResponse()->getContent());
        self::assertSame('inventory_item.is_archived', $this->json()['rule']);
        self::assertSame('This item is already archived. It\'s in the archived items.', $this->json()['detail']);
        self::assertSame([], $this->auditRowsOf(AuditEvent::INVENTORY_ITEM_DELETED), 'The holder wrote no row and the refused delete must write none.');
        self::assertSame($holder['report'][0][0], $firstStamp, 'The second delete moved the first one\'s archive stamp.');
    }

    /**
     * **The same shape on `UpdateItem` (RED `g5-c2a-red.md` M-1, a shipped defect, flagged the same day).** A delete in flight
     * ∥ an edit of the same item: the edit waits, then is refused as archived (ST-9) and changes nothing. Before this commit
     * it read the row unlocked, its UPDATE re-matched after the delete committed, and it answered 200 with `archived_at` set.
     */
    #[Test]
    public function anEditRacingADeleteWaitsAndIsRefusedAsArchived(): void
    {
        $item = $this->seedItem(self::TENANT_A, 'RACE-EDIT');

        $holder = $this->raceAgainstAHolder([[ItemWriter::DELETE_LOCK, [$item]], [ItemWriter::ARCHIVE, [$item]]], function () use ($item): void {
            $this->send('PATCH', '/items/'.$item, ['name' => 'Renamed meanwhile']);
        });

        self::assertTrue($holder['waiter_seen'], 'The edit never waited on the delete. '.json_encode($holder));
        self::assertTrue($holder['committed'], 'The holder did not commit: '.json_encode($holder));
        self::assertSame(409, $this->httpStatus(), 'An edit landed on an item archived at the same moment: '.$this->client()->getResponse()->getContent());
        self::assertSame('inventory_item.is_archived', $this->json()['rule']);
        self::assertSame('RACE-EDIT', $this->fetchAsOwnerForTenant(self::TENANT_A, 'SELECT name FROM inventory_item WHERE id = ?::uuid', [$item])[0]['name']);
    }

    /** The same race for restore: the second of two concurrent restores is told the item is not archived. */
    #[Test]
    public function aSecondConcurrentRestoreIsToldNotArchivedAndMovesNothing(): void
    {
        $item = $this->seedItem(self::TENANT_A, 'RACE-RES');
        $this->send('DELETE', '/items/'.$item);
        self::assertSame(204, $this->httpStatus());

        $holder = $this->raceAgainstAHolder([[ItemWriter::RESTORE_LOCK, [$item]], [ItemWriter::RESTORE, [$item]]], function () use ($item): void {
            $this->send('POST', '/items/'.$item.'/restoration');
        }, report: [[self::STAMPS, [$item]]]);

        self::assertTrue($holder['waiter_seen'], 'The second restore never waited on the first. '.json_encode($holder));
        self::assertTrue($holder['committed'], 'The holder did not commit: '.json_encode($holder));
        self::assertSame(409, $this->httpStatus(), 'The second of two concurrent restores was told it succeeded: '.$this->client()->getResponse()->getContent());
        self::assertSame('inventory_item.is_not_archived', $this->json()['rule']);
        self::assertNull($this->stored($item)['deleted_at']);
        self::assertSame([], $this->auditRowsOf(AuditEvent::INVENTORY_ITEM_RESTORED));
        self::assertSame($holder['report'][0][0], $this->stored($item), 'The second restore moved updated_at.');
    }

    // -----------------------------------------------------------------------

    /** Read by the holder inside its own transaction, just before its COMMIT, and by the test afterwards. */
    private const STAMPS = 'SELECT deleted_at::text AS deleted_at, updated_at::text AS updated_at FROM inventory_item WHERE id = ?::uuid';

    /** @return array{deleted_at: ?string, updated_at: string} */
    private function stored(string $id): array
    {
        return $this->storedIn(self::TENANT_A, $id);
    }

    /** @return array{deleted_at: ?string, updated_at: string} */
    private function storedIn(string $tenant, string $id): array
    {
        /** @var array{deleted_at: ?string, updated_at: string} */
        return $this->fetchAsOwnerForTenant($tenant, self::STAMPS, [$id])[0];
    }

    /**
     * `CustomerDeleteTest::raceAgainstAHolder`'s shape (a copy, not extracted, the earlier copies' reason): the holder's
     * own lock is on a random `sales_order` id, so it matches no row; the real locks are `HOLD_BEFORE`'s. `HOLD_REPORT`
     * reads the holder's own writes back just before its COMMIT, so a test can compare them with what survives.
     *
     * @param list<array{0: string, 1: list<string>}> $before
     * @param list<array{0: string, 1: list<string>}> $report
     *
     * @return array{waiter_seen: bool, committed: bool, sqlstate: ?string, error: ?string, report: list<list<array<string, mixed>>>}
     */
    private function raceAgainstAHolder(array $before, callable $request, array $report = []): array
    {
        $readyFile = tempnam(sys_get_temp_dir(), 'erp_item_delete_');
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
                'HOLD_TABLE' => 'sales_order',
                'HOLD_BEFORE' => json_encode($before, \JSON_THROW_ON_ERROR),
                'HOLD_AFTER' => json_encode([], \JSON_THROW_ON_ERROR),
                'HOLD_REPORT' => json_encode($report, \JSON_THROW_ON_ERROR),
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
