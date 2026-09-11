<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Modules\Purchasing\Infrastructure\Entity\PurchaseOrder;
use App\SharedKernel\ValueObject\AuditEvent;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Uid\Uuid;

/**
 * **Step 6c: every purchase-order write path locks the order row first.**
 *
 * Before 6c every write here was an unlocked read, a check, then a write. Under
 * READ COMMITTED a second `UPDATE` of the order row waits for the first to
 * commit and then goes ahead **without re-checking**, so two people could undo
 * each other: an approval could land on an order whose last line was archived
 * a moment ago, or put a cancelled order back to `ordered`.
 *
 * ---------------------------------------------------------------------------
 * How a race is made deterministic here, without a sleep deciding the outcome
 * ---------------------------------------------------------------------------
 * Connection A is `support/hold_order_lock.php`, a child process. It opens a
 * transaction, takes the order's row lock, makes its change, and then waits
 * until **Postgres reports a backend blocked on it** before it commits.
 * Connection B is the real HTTP request, sent in-process.
 *
 * So the order of events is set by the database, and B's answer shows whether
 * B **re-read after waiting**:
 *
 *   - locked first → B blocks on its first statement, A commits, B reads
 *     what A committed and refuses (409);
 *   - not locked first → B reads the old state, checks pass, and B blocks
 *     only at its own `UPDATE` of the order. A commits, B's `UPDATE`
 *     proceeds on stale checks, and the write goes through (2xx).
 *
 * **A wait-and-time-out test was considered and rejected:** every write path
 * eventually updates `purchase_order` (at least through `recomputeTotal()`),
 * so a path without the lock still blocks, just later, and still times out.
 * Such a test stays green with the lock removed. The re-check shape does not.
 *
 * A runs on the **unpooled** endpoint. The pool holds one backend, so a pooled
 * A would make B wait at PgBouncer rather than on the row.
 */
final class PurchaseOrderRowLockTest extends PurchaseOrderTestCase
{
    /** Only a failing run reaches it; see the holder script. */
    private const HOLDER_DEADLINE_SECONDS = 10.0;

    /**
     * **Approve vs archive-last-line — the race 6a's line guard named.** A
     * archives the order's only line and holds the order. The approval must
     * wait, then count the lines A left, which is none.
     */
    #[Test]
    public function anApprovalWaitsForTheArchiveOfTheLastLineAndThenRefuses(): void
    {
        $draft = $this->seedDraft(self::TENANT_A, 'PO-1', $this->seedPrincipal(self::TENANT_A, []));
        $line = $this->seedLine(self::TENANT_A, $draft['order'], $draft['item']);

        $holder = $this->raceAgainstAHolder($draft['order'], before: [
            ['UPDATE purchase_order_line SET deleted_at = now(), updated_at = now() WHERE id = ?::uuid', [$line]],
        ], request: fn () => $this->send('POST', '/purchase-orders/'.$draft['order'].'/approval', [], [
            'Idempotency-Key' => Uuid::v4()->toRfc4122(),
        ]));

        $this->assertTheHolderWasWaitedOnAndCommitted($holder);
        self::assertSame(409, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        self::assertSame('purchase_order.has_a_live_line', $this->json()['rule']);
        $this->assertOrderStatus($draft['order'], PurchaseOrder::STATUS_DRAFT);
        self::assertSame([], $this->fetchAsOwnerForTenant(self::TENANT_A,
            'SELECT id FROM audit_log WHERE event_type = ?', [AuditEvent::PURCHASE_ORDER_PLACED]),
            'A refused approval wrote a trail row.');
    }

    /**
     * **Approve vs cancel.** A cancels the draft and holds the order. Without the
     * lock the approval read `draft`, waited only at its own `UPDATE`, and then
     * put the cancelled order back to `ordered`.
     *
     * The other direction (A approves, the real request cancels) is not a
     * test here: cancelling an `ordered` order is legal, so that request
     * succeeds with or without the lock and could not tell them apart.
     */
    #[Test]
    public function anApprovalWaitsForACancellationAndDoesNotBringTheOrderBack(): void
    {
        $draft = $this->seedDraft(self::TENANT_A, 'PO-1', $this->seedPrincipal(self::TENANT_A, []));
        $this->seedLine(self::TENANT_A, $draft['order'], $draft['item']);

        $holder = $this->raceAgainstAHolder($draft['order'], before: [
            ["UPDATE purchase_order SET status = 'cancelled', updated_at = now() WHERE id = ?::uuid", [$draft['order']]],
        ], request: fn () => $this->send('POST', '/purchase-orders/'.$draft['order'].'/approval', [], [
            'Idempotency-Key' => Uuid::v4()->toRfc4122(),
        ]));

        $this->assertTheHolderWasWaitedOnAndCommitted($holder);
        self::assertSame(409, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        self::assertSame('urn:erp:problem:business-rule-violated', $this->json()['type']);
        $this->assertOrderStatus($draft['order'], PurchaseOrder::STATUS_CANCELLED);
        self::assertSame([], $this->fetchAsOwnerForTenant(self::TENANT_A,
            'SELECT id FROM audit_log WHERE event_type = ?', [AuditEvent::PURCHASE_ORDER_PLACED]),
            'A refused approval wrote a trail row.');
    }

    /**
     * **Cancel vs cancel: two people withdraw the same draft at once.** A
     * cancels and holds the order. Without the lock the second cancellation
     * read `draft`, passed `cancel()`'s guard, waited at its `UPDATE`, then
     * wrote `cancelled` over `cancelled` and a **second** trail row for a
     * cancellation that had already happened.
     */
    #[Test]
    public function aSecondCancellationWaitsAndIsRefusedByTheStatusGuard(): void
    {
        $draft = $this->seedDraft(self::TENANT_A, 'PO-1', $this->seedPrincipal(self::TENANT_A, []));

        $holder = $this->raceAgainstAHolder($draft['order'], before: [
            ["UPDATE purchase_order SET status = 'cancelled', updated_at = now() WHERE id = ?::uuid", [$draft['order']]],
        ], request: fn () => $this->send('POST', '/purchase-orders/'.$draft['order'].'/cancellation', []));

        $this->assertTheHolderWasWaitedOnAndCommitted($holder);
        self::assertSame(409, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        self::assertSame('urn:erp:problem:business-rule-violated', $this->json()['type']);
        $this->assertOrderStatus($draft['order'], PurchaseOrder::STATUS_CANCELLED);
        self::assertSame([], $this->fetchAsOwnerForTenant(self::TENANT_A,
            'SELECT id FROM audit_log WHERE event_type = ?', [AuditEvent::PURCHASE_ORDER_CANCELLED]),
            'The refused cancellation wrote a trail row for a cancellation it did not make.');
    }

    /**
     * **The deadlock the brief said exists today: archive a line while the
     * whole order is archived.** Before 6c `archiveLine()` locked the **line**
     * (its `UPDATE`) and then the **order** (`recomputeTotal()`), while
     * archiving the order locks the **order** and then, through
     * `purchase_order_cascade_deletion`, its **lines**. Opposite orders over
     * the same two rows.
     *
     * A holds the order and waits until the request is blocked on it. Only
     * then does A archive the order, so its cascade reaches for the line:
     *
     *   - line-then-order (before 6c): the request already holds the line and
     *     waits for the order; A holds the order and waits for the line.
     *     Postgres aborts one of them with 40P01;
     *   - order-first (6c): the request is blocked on its **first** statement
     *     holding nothing, A's cascade takes the line freely and commits, and
     *     the request reads an archived order and refuses.
     */
    #[Test]
    public function archivingALineWhileTheOrderIsArchivedWaitsInsteadOfDeadlocking(): void
    {
        $draft = $this->seedDraft(self::TENANT_A, 'PO-1', $this->seedPrincipal(self::TENANT_A, []));
        $line = $this->seedLine(self::TENANT_A, $draft['order'], $draft['item']);

        $holder = $this->raceAgainstAHolder(
            $draft['order'],
            before: [],
            request: fn () => $this->send('DELETE', '/purchase-orders/'.$draft['order'].'/lines/'.$line),
            after: [
                ['UPDATE purchase_order SET deleted_at = now(), updated_at = now() WHERE id = ?::uuid', [$draft['order']]],
            ],
        );

        $this->assertTheHolderWasWaitedOnAndCommitted($holder);
        self::assertSame(409, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        self::assertSame('purchase_order.is_archived', $this->json()['rule']);

        // The line was archived by the cascade, with its order, at the order's
        // instant, and not by the refused request.
        self::assertSame([['same_instant' => true]], $this->fetchAsOwnerForTenant(self::TENANT_A, <<<'SQL'
            SELECT pol.deleted_at = po.deleted_at AS same_instant
              FROM purchase_order_line pol JOIN purchase_order po ON po.id = pol.purchase_order_id
             WHERE pol.id = ?::uuid
            SQL, [$line]));
    }

    /**
     * **Every draft-only write vs an approval that commits first.** A places the
     * order and holds it. Each request must wait, see `ordered` and refuse, so
     * nobody changes an order after an independent approver has placed it.
     * For the line writes that is the case next to a DSoD-1 bypass: the
     * creator reprices what the approver approved.
     *
     * Without the lock each of these read `draft`, passed `refuseUnlessDraft()`,
     * and waited only at its own `UPDATE` of the order (`recomputeTotal()` for
     * the line writes), which then went ahead.
     *
     * @param array<string, string>|null $body
     */
    #[Test]
    #[DataProvider('draftOnlyWrites')]
    public function aDraftOnlyWriteWaitsForAnApprovalAndThenRefuses(string $method, string $path, ?array $body): void
    {
        $draft = $this->seedDraft(self::TENANT_A, 'PO-1', $this->seedPrincipal(self::TENANT_A, []));
        $line = $this->seedLine(self::TENANT_A, $draft['order'], $draft['item']);
        $otherItem = $this->seedItem(self::TENANT_A, 'SKU-OTHER');
        $this->seedSupplierProduct(self::TENANT_A, $draft['supplier'], $otherItem);

        $placeholders = ['{order}' => $draft['order'], '{line}' => $line, '{item}' => $otherItem];
        $before = $this->contentOf($draft['order']);

        $holder = $this->raceAgainstAHolder($draft['order'], before: [
            ["UPDATE purchase_order SET status = 'ordered', ordered_at = now(), updated_at = now() WHERE id = ?::uuid", [$draft['order']]],
        ], request: fn () => $this->send(
            $method,
            strtr($path, $placeholders),
            null === $body ? null : array_map(static fn (string $v): string => strtr($v, $placeholders), $body),
        ));

        $this->assertTheHolderWasWaitedOnAndCommitted($holder);
        self::assertSame(409, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        self::assertSame('purchase_order.is_editable_only_while_draft', $this->json()['rule']);
        $this->assertOrderStatus($draft['order'], PurchaseOrder::STATUS_ORDERED);
        self::assertSame($before, $this->contentOf($draft['order']),
            'A refused write changed the order or its lines.');
    }

    /** @return iterable<string, array{string, string, array<string, string>|null}> */
    public static function draftOnlyWrites(): iterable
    {
        yield 'UpdatePurchaseOrder' => ['PATCH', '/purchase-orders/{order}', ['expected_at' => '2026-04-02T00:00:00+00:00']];
        yield 'DeletePurchaseOrder' => ['DELETE', '/purchase-orders/{order}', null];
        yield 'CreatePurchaseOrderLine' => ['POST', '/purchase-orders/{order}/lines', [
            'inventory_item_id' => '{item}', 'quantity' => '1.000000', 'unit_cost' => '9.0000',
        ]];
        yield 'UpdatePurchaseOrderLine' => ['PATCH', '/purchase-orders/{order}/lines/{line}', ['unit_cost' => '9.0000']];
        yield 'DeletePurchaseOrderLine' => ['DELETE', '/purchase-orders/{order}/lines/{line}', null];
    }

    /**
     * **Supplement, not evidence on its own: in every write path the lock is
     * the first statement that touches the order or its lines.** The races
     * above prove behaviour for the shapes they drive; this covers position,
     * so a later edit that adds a read above the lock is red here even where
     * no race test happens to reach it.
     *
     * The lock is spelled here independently of `PurchaseOrderRowLock::SQL`,
     * so a change to the lock's mode (say, to `FOR UPDATE`) is red here too.
     *
     * @param array<string, string>|null $body
     */
    #[Test]
    #[DataProvider('everyWritePath')]
    public function theOrderRowLockIsTheFirstStatementToTouchTheOrder(string $method, string $path, ?array $body, int $status): void
    {
        $draft = $this->seedDraft(self::TENANT_A, 'PO-1', $this->seedPrincipal(self::TENANT_A, []));
        $line = $this->seedLine(self::TENANT_A, $draft['order'], $draft['item']);
        $otherItem = $this->seedItem(self::TENANT_A, 'SKU-OTHER');
        $this->seedSupplierProduct(self::TENANT_A, $draft['supplier'], $otherItem);
        $placeholders = ['{order}' => $draft['order'], '{line}' => $line, '{item}' => $otherItem];

        $this->recorder()->start();
        $this->send(
            $method,
            strtr($path, $placeholders),
            null === $body ? null : array_map(static fn (string $v): string => strtr($v, $placeholders), $body),
            ['Idempotency-Key' => Uuid::v4()->toRfc4122()],
        );
        $this->recorder()->stop();

        self::assertSame($status, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());

        // Split the recording into transactions at each tenant-context
        // statement, which `TenantContext::run()` issues as a transaction's
        // first statement. The PATCH/DELETE operations have an API Platform
        // provider that reads the row in its OWN earlier transaction; that read
        // decides nothing (the write re-reads under the lock), so the pin is on
        // the one transaction that writes.
        //
        // The pattern is written so `TenantContextContainmentTest`'s writer
        // scan does not read this matcher as a writer of tenant context: this
        // file matches the statement, it never issues it.
        $transactions = [];
        foreach ($this->recorder()->statements() as $sql) {
            if (1 === preg_match('/^SELECT set_config\(/', $sql)) {
                $transactions[] = [];
                continue;
            }
            if ([] !== $transactions) {
                $transactions[\count($transactions) - 1][] = $sql;
            }
        }

        $writing = array_values(array_filter($transactions, static fn (array $statements): bool => [] !== preg_grep(
            '/^\s*(UPDATE|INSERT INTO)\s+purchase_order(_line)?\b/i', $statements,
        )));
        self::assertCount(1, $writing, 'Expected exactly one transaction writing the order or its lines.');

        $touching = array_values(preg_grep('/\bpurchase_order(_line)?\b/', $writing[0]));
        self::assertMatchesRegularExpression(
            '/^SELECT 1 FROM purchase_order WHERE id = \S+ FOR NO KEY UPDATE$/',
            $touching[0],
            "The first statement to touch the order is not its row lock. Statements, in order:\n".implode("\n", $touching),
        );
    }

    /** @return iterable<string, array{string, string, array<string, string>|null, int}> */
    public static function everyWritePath(): iterable
    {
        yield 'ApprovePurchaseOrder' => ['POST', '/purchase-orders/{order}/approval', [], 200];
        yield 'CancelPurchaseOrder' => ['POST', '/purchase-orders/{order}/cancellation', [], 200];

        foreach (self::draftOnlyWrites() as $name => [$method, $path, $body]) {
            yield $name => [$method, $path, $body, match ($method) {
                'POST' => 201,
                'DELETE' => 204,
                default => 200,
            }];
        }
    }

    // -----------------------------------------------------------------------

    /**
     * Everything a draft-only write could change: the order's editable columns
     * and total, and every line (live or not). The status is left out because
     * the holder changes it.
     *
     * @return list<array<string, mixed>>
     */
    private function contentOf(string $orderId): array
    {
        return $this->fetchAsOwnerForTenant(self::TENANT_A, <<<'SQL'
            SELECT po.supplier_id, po.warehouse_id, po.expected_at, po.total_amount, po.deleted_at AS order_deleted_at,
                   pol.id AS line_id, pol.inventory_item_id, pol.quantity_ordered, pol.unit_cost, pol.deleted_at AS line_deleted_at
              FROM purchase_order po
              LEFT JOIN purchase_order_line pol ON pol.purchase_order_id = po.id
             WHERE po.id = ?::uuid
             ORDER BY pol.line_number, pol.id
            SQL, [$orderId]);
    }

    /**
     * Starts A, waits for it to hold the lock, sends B, then collects A's
     * report. B returns only once A has committed (or A's deadline passed).
     *
     * @param list<array{0: string, 1: list<string>}> $before statements A runs holding the lock, before B is sent
     * @param list<array{0: string, 1: list<string>}> $after  statements A runs once B is seen waiting on it
     *
     * @return array{waiter_seen: bool, committed: bool, sqlstate: ?string, error: ?string}
     */
    private function raceAgainstAHolder(string $orderId, array $before, callable $request, array $after = []): array
    {
        $readyFile = tempnam(sys_get_temp_dir(), 'erp_po_lock_');
        file_put_contents($readyFile, '');

        $proc = proc_open(
            [PHP_BINARY, __DIR__.'/support/hold_order_lock.php'],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            null,
            [
                'APP_DIRECT_DATABASE_URL' => $_ENV['APP_DIRECT_DATABASE_URL'],
                'HOLD_TENANT' => self::TENANT_A,
                'HOLD_ORDER' => $orderId,
                'HOLD_BEFORE' => json_encode($before, \JSON_THROW_ON_ERROR),
                'HOLD_AFTER' => json_encode($after, \JSON_THROW_ON_ERROR),
                'HOLD_READY_FILE' => $readyFile,
                'HOLD_DEADLINE_SECONDS' => (string) self::HOLDER_DEADLINE_SECONDS,
                'PATH' => (string) getenv('PATH'),
            ],
        );
        self::assertIsResource($proc);

        try {
            // Waits on a condition (the file has content), not on a clock.
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

    /** @param array{waiter_seen: bool, committed: bool, sqlstate: ?string, error: ?string} $holder */
    private function assertTheHolderWasWaitedOnAndCommitted(array $holder): void
    {
        self::assertTrue($holder['waiter_seen'],
            'The request never waited on the holder, so nothing here was a race: '.json_encode($holder));
        self::assertTrue($holder['committed'], 'The holder did not commit: '.json_encode($holder));
    }

    private function assertOrderStatus(string $orderId, string $status): void
    {
        self::assertSame([['status' => $status]], $this->fetchAsOwnerForTenant(self::TENANT_A,
            'SELECT status FROM purchase_order WHERE id = ?::uuid', [$orderId]));
    }
}
