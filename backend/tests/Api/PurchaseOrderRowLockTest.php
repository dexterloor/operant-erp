<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Modules\Purchasing\Application\PurchaseOrderRowLock;
use App\Modules\Purchasing\Infrastructure\Entity\PurchaseOrder;
use App\Modules\Purchasing\Infrastructure\Entity\PurchaseOrderLine;
use App\Modules\Purchasing\Infrastructure\OrmPurchaseOrderReceiptStatus;
use App\SharedKernel\ValueObject\AuditEvent;
use Doctrine\ORM\EntityManagerInterface;
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
     * The status-guard half of the `rule` member, spelled independently of
     * `IllegalTransition::RULE` so a rename there is red here rather than
     * compared with itself.
     */
    private const STATUS_RULE = 'status_transition_is_legal';

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
        self::assertSame('purchase_order.'.self::STATUS_RULE, $this->json()['rule']);
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
        self::assertSame('purchase_order.'.self::STATUS_RULE, $this->json()['rule']);
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

    /**
     * **Step 8 — a receipt waits for a cancellation, then is refused.** Without
     * the lock first, the receipt reads `ordered`, passes, and blocks only at its
     * own `UPDATE` of the order line; A commits the cancellation, and the
     * receipt posts stock against a cancelled order (201).
     */
    #[Test]
    public function aReceiptWaitsForACancellationAndIsRefusedByTheStatusGuard(): void
    {
        [$order, $line, $bin] = $this->orderedOrderToReceive();

        $holder = $this->raceAgainstAHolder($order, before: [], request: fn () => $this->send(
            'POST', '/purchase-orders/'.$order.'/goods-receipts',
            ['lines' => [['purchase_order_line_id' => $line, 'quantity' => '1', 'storage_location_id' => $bin]]],
            ['Idempotency-Key' => Uuid::v4()->toRfc4122()],
        ), after: [
            ["UPDATE purchase_order SET status = 'cancelled', updated_at = now() WHERE id = ?::uuid", [$order]],
        ]);

        $this->assertTheHolderWasWaitedOnAndCommitted($holder);
        self::assertSame(409, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        self::assertSame('purchase_order.'.self::STATUS_RULE, $this->json()['rule']);
        $this->assertOrderStatus($order, PurchaseOrder::STATUS_CANCELLED);
        self::assertSame(0, $this->countAsOwnerForTenant(self::TENANT_A, 'SELECT count(*) FROM goods_receipt'));
    }

    /**
     * **Step 8 — receipt vs receipt on one order.** A completes the line and the
     * order while holding the lock. The second receipt must wait, then see
     * `received`. Without the lock first it reads 0 received, passes the
     * over-receipt check on stale numbers, and adds to a line already complete.
     */
    #[Test]
    public function aSecondReceiptWaitsForTheFirstAndCannotOverReceive(): void
    {
        [$order, $line, $bin] = $this->orderedOrderToReceive();

        $holder = $this->raceAgainstAHolder($order, before: [], request: fn () => $this->send(
            'POST', '/purchase-orders/'.$order.'/goods-receipts',
            ['lines' => [['purchase_order_line_id' => $line, 'quantity' => '1', 'storage_location_id' => $bin]]],
            ['Idempotency-Key' => Uuid::v4()->toRfc4122()],
        ), after: [
            ['UPDATE purchase_order_line SET quantity_received = quantity_ordered WHERE id = ?::uuid', [$line]],
            ["UPDATE purchase_order SET status = 'received', updated_at = now() WHERE id = ?::uuid", [$order]],
        ]);

        $this->assertTheHolderWasWaitedOnAndCommitted($holder);
        self::assertSame(409, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        self::assertSame('purchase_order.'.self::STATUS_RULE, $this->json()['rule']);
        self::assertSame([['q' => '10.000000']], $this->fetchAsOwnerForTenant(self::TENANT_A,
            'SELECT quantity_received::text AS q FROM purchase_order_line WHERE id = ?::uuid', [$line]));
    }

    /** @return array{string, string, string, string} order, its one line (10 ordered), a bin of its warehouse, that warehouse */
    private function orderedOrderToReceive(string $number = 'PO-1'): array
    {
        $draft = $this->seedDraft(self::TENANT_A, $number, $this->seedPrincipal(self::TENANT_A, []));
        $line = $this->seedLine(self::TENANT_A, $draft['order'], $draft['item']);
        $bin = Uuid::v4()->toRfc4122();
        $this->insertAsOwnerForTenant(self::TENANT_A,
            "INSERT INTO storage_location (id, tenant_id, warehouse_id, code) VALUES (?::uuid, ?::uuid, ?::uuid, 'A-01')",
            [$bin, self::TENANT_A, $draft['warehouse']]);
        $this->insertAsOwnerForTenant(self::TENANT_A,
            "UPDATE purchase_order SET status = 'ordered', ordered_at = now() WHERE id = ?::uuid", [$draft['order']]);

        return [$draft['order'], $line, $bin, $draft['warehouse']];
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
    public function theOrderRowLockIsTheFirstStatementToTouchTheOrder(string $method, string $path, ?array $body, int $status, string $orderStatus = PurchaseOrder::STATUS_DRAFT): void
    {
        $draft = $this->seedDraft(self::TENANT_A, 'PO-1', $this->seedPrincipal(self::TENANT_A, []));
        $line = $this->seedLine(self::TENANT_A, $draft['order'], $draft['item']);
        $otherItem = $this->seedItem(self::TENANT_A, 'SKU-OTHER');
        $this->seedSupplierProduct(self::TENANT_A, $draft['supplier'], $otherItem);
        $bin = Uuid::v4()->toRfc4122();
        $this->insertAsOwnerForTenant(self::TENANT_A,
            "INSERT INTO storage_location (id, tenant_id, warehouse_id, code) VALUES (?::uuid, ?::uuid, ?::uuid, 'A-01')",
            [$bin, self::TENANT_A, $draft['warehouse']]);
        $placeholders = ['{order}' => $draft['order'], '{line}' => $line, '{item}' => $otherItem, '{bin}' => $bin];

        // A receipt writes only against a placed order (step 8).
        if (PurchaseOrder::STATUS_DRAFT !== $orderStatus) {
            $this->insertAsOwnerForTenant(self::TENANT_A,
                'UPDATE purchase_order SET status = ?, ordered_at = now() WHERE id = ?::uuid', [$orderStatus, $draft['order']]);
        }

        $this->recorder()->start();
        $this->send(
            $method,
            strtr($path, $placeholders),
            null === $body ? null : self::filled($body, $placeholders),
            ['Idempotency-Key' => Uuid::v4()->toRfc4122()],
        );
        $this->recorder()->stop();

        self::assertSame($status, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());

        // Split the recording into transactions at each tenant-context
        // statement, which `TenantContext::run()` issues as a transaction's
        // first statement. The PATCH/DELETE operations have an API Platform
        // provider that reads the row in its OWN earlier transaction. That read
        // decides only not-found, and that answer cannot go stale into a wrong
        // write: `purchase_order` and `purchase_order_line` are both
        // `SchemaManifest::SOFT` and nothing hard-deletes either, so a row the
        // provider found still exists when the write locks it. Everything the
        // write actually checks (status, `deleted_at`, the line) it re-reads
        // under the lock. So the pin is on the one transaction that writes.
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

        // Step 8: the goods receipt writes `purchase_order_line.quantity_received`
        // and `purchase_order.status`, so it is a member of this domain.
        yield 'CreateGoodsReceipt' => ['POST', '/purchase-orders/{order}/goods-receipts', [
            'lines' => [['purchase_order_line_id' => '{line}', 'quantity' => '1', 'storage_location_id' => '{bin}']],
        ], 201, PurchaseOrder::STATUS_ORDERED];
    }

    /**
     * Placeholders filled at any depth: a receipt's body nests its lines.
     *
     * @param array<mixed>          $body
     * @param array<string, string> $placeholders
     *
     * @return array<mixed>
     */
    private static function filled(array $body, array $placeholders): array
    {
        return array_map(
            static fn (mixed $v): mixed => \is_array($v) ? self::filled($v, $placeholders) : strtr((string) $v, $placeholders),
            $body,
        );
    }

    /**
     * **An autocommitted lock is no lock.** Outside a transaction the `SELECT
     * … FOR NO KEY UPDATE` runs as its own implicit transaction and the lock is
     * released when the statement ends, so a caller that forgot the
     * transaction would get no protection and no error. The helper refuses
     * instead, before sending anything, as `TenantContext::establishOn()`
     * refuses a `set_config` with no transaction to bind to.
     */
    #[Test]
    public function theLockRefusesToRunOutsideATransaction(): void
    {
        $em = $this->em();
        self::assertSame(0, $em->getConnection()->getTransactionNestingLevel(), 'The premise: no transaction is open.');

        $this->recorder()->start();
        try {
            PurchaseOrderRowLock::holdInside($em, Uuid::v4()->toRfc4122());
            self::fail('The lock ran outside a transaction, where it is released as soon as it is taken.');
        } catch (\LogicException $e) {
            self::assertStringContainsString('transaction', $e->getMessage());
        } finally {
            $this->recorder()->stop();
        }

        self::assertSame([], $this->recorder()->matching('/FOR NO KEY UPDATE/'), 'The refused lock was still sent.');
    }

    /**
     * **A transition decides on the order as it is under the lock, even when
     * the entity was already loaded.** `find()` answers from the identity map
     * without a query when the entity is already managed, and then the status
     * `place()` or `cancel()` checks was read before the lock, possibly before
     * a concurrent change committed. Here the test is that earlier caller: it
     * loads the draft, then the order is cancelled underneath it, then the
     * transition runs in the same unit of work.
     */
    #[Test]
    #[DataProvider('ormTransitions')]
    public function aTransitionReReadsAnOrderThatWasAlreadyLoaded(string $path, string $event): void
    {
        $draft = $this->seedDraft(self::TENANT_A, 'PO-1', $this->seedPrincipal(self::TENANT_A, []));
        $this->seedLine(self::TENANT_A, $draft['order'], $draft['item']);

        $loaded = $this->tenantContext()->run(self::TENANT_A, static fn (EntityManagerInterface $em): ?PurchaseOrder => $em->find(PurchaseOrder::class, Uuid::fromString($draft['order'])));
        self::assertInstanceOf(PurchaseOrder::class, $loaded, 'The premise: the entity is managed before the request.');

        $this->insertAsOwnerForTenant(self::TENANT_A,
            "UPDATE purchase_order SET status = 'cancelled', updated_at = now() WHERE id = ?::uuid", [$draft['order']]);

        $this->send('POST', '/purchase-orders/'.$draft['order'].$path, [], ['Idempotency-Key' => Uuid::v4()->toRfc4122()]);

        self::assertSame(409, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        self::assertSame('purchase_order.'.self::STATUS_RULE, $this->json()['rule']);
        $this->assertOrderStatus($draft['order'], PurchaseOrder::STATUS_CANCELLED);
        self::assertSame([], $this->fetchAsOwnerForTenant(self::TENANT_A,
            'SELECT id FROM audit_log WHERE event_type = ?', [$event]), 'A refused transition wrote a trail row.');
    }

    /** @return iterable<string, array{string, string}> */
    public static function ormTransitions(): iterable
    {
        yield 'ApprovePurchaseOrder' => ['/approval', AuditEvent::PURCHASE_ORDER_PLACED];
        yield 'CancelPurchaseOrder' => ['/cancellation', AuditEvent::PURCHASE_ORDER_CANCELLED];
    }

    /**
     * **`OrmPurchaseOrderReceiptStatus::recordReceipt` takes the order's row
     * lock as the first thing it sends, called on its own.**
     *
     * It is the one writer here with no path of its own, so nothing at the HTTP
     * level can show that it locks *itself* rather than inheriting its caller's
     * lock. This drives it directly, inside a transaction that has taken no
     * lock, and reads the statements the database was sent: **the first one
     * naming `purchase_order` must be the lock**, before the `SELECT` that
     * `find()` issues and before anything else reads the row.
     *
     * Written this way rather than as "the first statement of all" on purpose —
     * `TenantContext` sends its own `set_config` first, and a check that
     * counted that would be asserting the framework rather than the writer.
     *
     * **Mutation, run:** the `PurchaseOrderRowLock::holdInside()` call removed
     * from the class (1 occurrence, counted; reverted `cmp`-identical). Red
     * here and **nowhere else in this file** — which is the point of the test
     * existing. `everyMethodThatWritesAPurchaseOrderIsCoveredByALockFirstCheck`
     * stays green under it, because that check reconciles *which* methods are
     * accounted for and not *what they do*; the name is still in
     * `LOCKS_FIRST_WITH_NO_PATH_OF_ITS_OWN`, so a reason recorded there and then
     * quietly falsified is invisible to it. That is the whole gap this test
     * closes. **The arm is mutated separately:** the mutator clause removed from
     * `writersOfPurchaseOrders()` (1 occurrence) — red there, because the method
     * is accounted for and no longer found.
     */
    #[Test]
    public function theReceiptStatusWriterTakesTheOrdersLockAsItsFirstStatement(): void
    {
        $supplier = $this->seedSupplier(self::TENANT_A, 'Acme');
        $warehouse = $this->seedWarehouse(self::TENANT_A, 'Main');
        $order = $this->seedOrder(self::TENANT_A, 'PO-1', $supplier, $warehouse, status: PurchaseOrder::STATUS_ORDERED);

        $writer = new OrmPurchaseOrderReceiptStatus();

        $this->recorder()->start();
        try {
            $this->tenantContext()->run(
                self::TENANT_A,
                static fn (EntityManagerInterface $em) => $writer->recordReceipt($em, $order, false),
            );
        } finally {
            $this->recorder()->stop();
        }

        $touching = array_values(array_filter(
            $this->recorder()->statements(),
            static fn (string $sql): bool => str_contains($sql, 'purchase_order'),
        ));

        self::assertNotSame([], $touching, 'The writer sent nothing naming purchase_order, so it did not run.');
        self::assertSame(PurchaseOrderRowLock::SQL, $touching[0],
            "The receipt's status writer read the order before locking it. Its caller happens to hold the lock "
            .'today; this method must not depend on that.');
        $this->assertOrderStatus($order, PurchaseOrder::STATUS_PARTIALLY_RECEIVED);
    }

    /**
     * **The one race the order's row lock cannot cover, and what the loser is
     * told.** A key is not scoped to an order, so two receipts against *two
     * different orders* under one key hold two different locks and never wait
     * for each other. The unique `goods_receipt_idem_unique (tenant_id,
     * idempotency_key)` is what refuses the second, at its `INSERT`.
     *
     * **Step 8-ii answered that loser `business-rule-violated`
     * `goods_receipt.idempotency_key_is_in_flight`, saying *"already being
     * processed. Try again."* — advice into a wall.** The winner's row is
     * committed and permanent, the key is spent, and every retry from here is a
     * conflict. §6.2's answer for a key reused for a different request is
     * `idempotency-key-conflict` (409), and it is the same answer the replay read
     * gives one statement earlier when the winner committed a moment sooner. A
     * caller should not get two different problems for one situation depending on
     * which microsecond it arrived in.
     *
     * **Made deterministic by the database, with the holder used the other way
     * round.** A holds a *different* order (so it never blocks B) and, before B
     * is sent, `INSERT`s its own `goods_receipt` carrying the key —
     * **uncommitted**, so B's replay read under READ COMMITTED cannot see it. B
     * then takes its own order's lock, finds no replay, and blocks on the unique
     * index at its own `INSERT`. A sees the waiter, commits, and B's insert
     * raises `23505`. No clock decides anything.
     *
     * A's `receipt_number` is deliberately far away (`GR-999999`) so that B,
     * numbering from the committed maximum, does not also collide on
     * `goods_receipt_number_unique` — which would leave the index that refused
     * it, and so the answer, up to the order the two are checked in.
     *
     * *Red before the fix: 409 expected, `business-rule-violated` returned.*
     */
    #[Test]
    public function theLoserOfARaceForOneKeyAcrossTwoOrdersIsToldItIsAConflict(): void
    {
        [$mine, $myLine, $myBin] = $this->orderedOrderToReceive();
        [$theirs, , , $theirWarehouse] = $this->orderedOrderToReceive('PO-2');
        $user = $this->seedPrincipal(self::TENANT_A, []);
        $key = Uuid::v4()->toRfc4122();

        $holder = $this->raceAgainstAHolder($theirs, [[
            <<<'SQL'
                INSERT INTO goods_receipt
                    (id, tenant_id, purchase_order_id, warehouse_id, received_by_user_id,
                     receipt_number, received_at, idempotency_key, request_digest)
                VALUES (?::uuid, ?::uuid, ?::uuid, ?::uuid, ?::uuid, 'GR-999999', now(), ?, ?)
                SQL,
            [Uuid::v4()->toRfc4122(), self::TENANT_A, $theirs, $theirWarehouse, $user,
                hash('sha256', $key), str_repeat('c', 64)],
        ]], function () use ($mine, $myLine, $myBin, $key): void {
            $this->send('POST', '/purchase-orders/'.$mine.'/goods-receipts', [
                'lines' => [['purchase_order_line_id' => $myLine, 'quantity' => '1', 'storage_location_id' => $myBin]],
            ], ['Idempotency-Key' => $key]);
        });

        $this->assertTheHolderWasWaitedOnAndCommitted($holder);

        self::assertSame(409, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        self::assertStringContainsString('idempotency-key-conflict', (string) $this->json()['type']);
        self::assertSame(1, $this->countAsOwnerForTenant(self::TENANT_A, 'SELECT count(*) FROM goods_receipt'),
            "The loser's receipt survived, or the winner's did not.");
        self::assertSame(0, $this->countAsOwnerForTenant(self::TENANT_A, 'SELECT count(*) FROM stock_movement'),
            'The loser posted stock before it was refused.');
    }

    /**
     * Which lock-first check covers each writer: `everyWritePath()`'s case =>
     * the method it enters. The keys must be exactly that provider's cases, so
     * the map and the runtime check cannot drift apart.
     */
    private const LOCK_FIRST_CHECKED = [
        'ApprovePurchaseOrder' => 'PurchaseOrderApproval::approve',
        'CancelPurchaseOrder' => 'PurchaseOrderCancellation::cancel',
        'UpdatePurchaseOrder' => 'PurchaseOrderWriter::update',
        'DeletePurchaseOrder' => 'PurchaseOrderWriter::archive',
        'CreatePurchaseOrderLine' => 'PurchaseOrderWriter::createLine',
        'UpdatePurchaseOrderLine' => 'PurchaseOrderWriter::updateLine',
        'DeletePurchaseOrderLine' => 'PurchaseOrderWriter::archiveLine',
        'CreateGoodsReceipt' => 'GoodsReceiptWriter::create',
    ];

    /**
     * **Writers that take the lock themselves and are entered through another
     * writer rather than through a path of their own.**
     *
     * A third category was needed because the other two do not fit.
     * `LOCK_FIRST_CHECKED` is keyed by `everyWritePath()`'s HTTP cases, and
     * this method has no case of its own — `CreateGoodsReceipt` already maps to
     * `GoodsReceiptWriter::create`, which is where the receipt's lock is
     * checked over HTTP. And `ALLOWED_WITHOUT_A_LOCK` would be a false
     * statement: it does take one.
     *
     * **The reason is a fact rather than a promise.** The obvious entry —
     * *"always called from a writer that already holds the lock"* — is true
     * today and is exactly the kind of claim this project has watched go stale:
     * it rests on there being no second caller, which nothing enforces. So the
     * method locks first itself, and
     * `theReceiptStatusWriterTakesTheOrdersLockAsItsFirstStatement` asserts it
     * against the statements the database was actually sent.
     */
    private const LOCKS_FIRST_WITH_NO_PATH_OF_ITS_OWN = [
        'OrmPurchaseOrderReceiptStatus::recordReceipt' => 'It calls PurchaseOrderRowLock::holdInside() first. '
            .'Its only caller, GoodsReceiptWriter::createInside, already holds that lock, and the second take is '
            .'re-entrant for the same transaction, so this costs one cheap statement and removes the dependence '
            .'on the caller.',
    ];

    /** Writers that take no lock, each by name and with its reason. Keep it short. */
    private const ALLOWED_WITHOUT_A_LOCK = [
        // ⚠ **Re-argued at step 8a, when this method stopped writing one row.**
        // Since the nested create (D-C) it inserts the order, inserts its lines
        // and rewrites `total_amount` — three writes across both tables. The
        // entry survives, and not because the reason was reused: the order is
        // created by this transaction and its id is a freshly minted UUID, so
        // until COMMIT there is no second transaction that can address the row
        // or its lines, and a lock would have nothing to serialise against.
        // That is a property of the write, not a claim about callers.
        'PurchaseOrderWriter::create' => 'It inserts the order, so there is no existing row to lock, and no other '
            .'transaction can address what it inserts until it commits. Its two races are answered by unique '
            .'indexes: purchase_order_tenant_idem_live (a replay of the same key) and '
            .'purchase_order_tenant_number_live (two creates picking the same order number). Its lines are '
            .'inserted in the same transaction and belong to an order nobody else can reach yet.',
    ];

    /** Statement text that writes either table. */
    private const WRITE_SQL = '/\b(?:UPDATE|INSERT\s+INTO|DELETE\s+FROM)\s+purchase_order(?:_line)?\b/i';

    /** DQL that writes either entity. */
    private const WRITE_DQL = '/\b(?:UPDATE|DELETE\s+FROM)\s+[\w\\\\]*PurchaseOrder(?:Line)?\b/';

    /**
     * **The domain is derived from `src/`, not restated.** A new method that
     * writes a purchase order or its lines, and is neither covered by a
     * lock-first check nor allowed by name, is red here. That is the case step
     * 8's goods receipt will be: it writes `purchase_order.status` and
     * `purchase_order_line.quantity_received`.
     *
     * What the scan cannot see is named in `PurchaseOrderRowLock`'s docblock.
     */
    #[Test]
    public function everyMethodThatWritesAPurchaseOrderIsCoveredByALockFirstCheck(): void
    {
        self::assertSame(
            array_keys(iterator_to_array(self::everyWritePath())),
            array_keys(self::LOCK_FIRST_CHECKED),
            'LOCK_FIRST_CHECKED must name exactly the cases everyWritePath() runs.',
        );

        $derived = self::writersOfPurchaseOrders();
        self::assertNotSame([], $derived, 'The scan found no writer at all, so it is not scanning.');

        $accounted = [
            ...array_values(self::LOCK_FIRST_CHECKED),
            ...array_keys(self::LOCKS_FIRST_WITH_NO_PATH_OF_ITS_OWN),
            ...array_keys(self::ALLOWED_WITHOUT_A_LOCK),
        ];
        sort($accounted);
        $found = array_keys($derived);
        sort($found);

        $describe = implode("\n", array_map(
            static fn (string $method, array $writes): string => '  '.$method.' — '.implode(' | ', array_unique($writes)),
            array_keys($derived),
            $derived,
        ));

        self::assertSame($accounted, $found,
            "The methods that write a purchase order or its lines are not the ones covered by a lock-first check.\n"
            ."A new writer must call PurchaseOrderRowLock::holdInside() first and get a case in everyWritePath() "
            ."and LOCK_FIRST_CHECKED; or, if it has no path of its own, lock first and be named in "
            ."LOCKS_FIRST_WITH_NO_PATH_OF_ITS_OWN; or be added to ALLOWED_WITHOUT_A_LOCK with its reason.\n"
            ."Writers found in src/:\n".$describe);
    }

    /**
     * Every method in `src/` that writes `purchase_order` / `purchase_order_line`,
     * reduced to the methods a caller enters by: a private or protected writer
     * is replaced by its in-class callers, and one with no in-class caller is
     * reported as itself.
     *
     * @return array<string, list<string>> `ShortClass::method` => what it writes
     */
    private static function writersOfPurchaseOrders(): array
    {
        $methods = [];
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(\dirname(__DIR__, 2).'/src', \FilesystemIterator::SKIP_DOTS));

        /** @var \SplFileInfo $file */
        foreach ($files as $file) {
            if ('php' !== $file->getExtension()) {
                continue;
            }
            foreach (self::methodsIn((string) file_get_contents($file->getPathname())) as $key => $method) {
                if (isset($methods[$key])) {
                    throw new \LogicException(\sprintf('Two classes in src/ share the short name in %s; key the scan by FQCN.', $key));
                }
                $methods[$key] = $method;
            }
        }

        $reach = [];
        foreach ($methods as $key => $method) {
            $writes = $method['writes'];
            if ($method['entity'] && [] !== array_intersect($method['calls'], ['flush', 'persist', 'remove'])) {
                $writes[] = 'ORM: names a purchase-order entity class and flushes, persists or removes';
            }
            if ($method['entity'] && [] !== ($mutators = array_intersect($method['calls'], self::entityMutators()))) {
                $writes[] = 'ORM: names a purchase-order entity class and calls '.implode('(), ', $mutators).'() on it';
            }
            if ([] !== $writes) {
                $reach[$key] = $writes;
            }
        }

        // Private and protected writers are reached through their in-class
        // callers. Repeat until nothing new is reached (a helper of a helper).
        //
        // ⚠ **A caller already in the list still collects the helper it reaches,
        // and until 2026-09-12 it did not.** The clause read
        // `if (!isset($reach[$caller]))`, so a method that already wrote one of
        // the two tables directly never recorded the helpers it called. Found by
        // step 8a: `PurchaseOrderWriter::create()` gained the nested lines, and
        // the scan went on reporting it as `INSERT INTO purchase_order` alone —
        // membership was right, the **description** was wrong, and that
        // description is the only thing a reader of `ALLOWED_WITHOUT_A_LOCK` has
        // to judge whether its reason is still true. It would have said "it
        // inserts the row, so there is no existing row to lock" about a method
        // that also inserts lines and rewrites the order's total.
        //
        // It terminates for the same reason as before: each (caller, helper)
        // pair is added at most once and there are finitely many.
        do {
            $changed = false;
            foreach (array_keys($reach) as $key) {
                if ($methods[$key]['public']) {
                    continue;
                }
                $via = 'via '.$methods[$key]['name'].'()';
                foreach (self::inClassCallersOf($methods, $key) as $caller) {
                    if (!\in_array($via, $reach[$caller] ?? [], true)) {
                        $reach[$caller][] = $via;
                        $changed = true;
                    }
                }
            }
        } while ($changed);

        return array_filter(
            $reach,
            static fn (string $key): bool => $methods[$key]['public'] || [] === self::inClassCallersOf($methods, $key),
            \ARRAY_FILTER_USE_KEY,
        );
    }

    /**
     * **The state-changing methods of the two entity classes, by reflection —
     * the second half of the ORM arm, and the half step 8-ii walked through.**
     *
     * The arm originally fired only on `flush`, `persist` or `remove`, on the
     * reasoning that an ORM write is not a write until it is flushed.
     * `OrmPurchaseOrderReceiptStatus::recordReceipt` disproved it: it names
     * `PurchaseOrder::class`, calls `find()`, `refresh()` and the entity's own
     * `recordReceipt()`, and flushes **nothing** — its caller flushes, three
     * steps later, after the stock is posted. So it changed a purchase order's
     * status and the scan did not see it, while `PurchaseOrderRowLock`'s
     * blind-spot list said the ORM gap was limited to methods that do *not*
     * name the entity class. **A method that hands the write to somebody else
     * to flush is the ordinary shape here, not an exotic one.**
     *
     * **Derived, never listed.** The names come from the entity classes
     * themselves — every public instance method that is not an accessor — for
     * the reason this project has measured twice: an exhaustive check that
     * restates a constant is not a check, and a new transition added to
     * `PurchaseOrder` would not be in a list. Accessors are excluded by the
     * `get`/`is`/`has` prefixes the entities use throughout; a mutator named
     * outside that convention is caught, and a *reader* named against it would
     * only ever add a writer to the scan, which fails loudly rather than
     * quietly.
     *
     * **What it still cannot see** is unchanged and is in the helper's
     * docblock: a method that never names either class — an entity handed in as
     * a parameter, or reached through a repository or an association.
     *
     * @return list<string>
     */
    private static function entityMutators(): array
    {
        $names = [];

        foreach ([PurchaseOrder::class, PurchaseOrderLine::class] as $class) {
            foreach ((new \ReflectionClass($class))->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
                if ($method->isStatic() || $method->isConstructor() || 1 === preg_match('/^(?:get|is|has)[A-Z]/', $method->getName())) {
                    continue;
                }

                $names[] = $method->getName();
            }
        }

        return array_values(array_unique($names));
    }

    /**
     * @param array<string, array{public: bool, class: string, name: string, writes: list<string>, calls: list<string>, entity: bool}> $methods
     *
     * @return list<string>
     */
    private static function inClassCallersOf(array $methods, string $key): array
    {
        $callers = [];
        foreach ($methods as $callerKey => $caller) {
            if ($callerKey !== $key
                && $caller['class'] === $methods[$key]['class']
                && \in_array($methods[$key]['name'], $caller['calls'], true)
            ) {
                $callers[] = $callerKey;
            }
        }

        return $callers;
    }

    /**
     * The methods of every class-like in one file, each with the writes its
     * string literals spell, the methods it calls, and whether it names a
     * purchase-order entity class. Closures belong to the method they are in.
     *
     * @return array<string, array{public: bool, class: string, name: string, writes: list<string>, calls: list<string>, entity: bool}>
     */
    private static function methodsIn(string $source): array
    {
        $tokens = token_get_all($source);
        $methods = [];
        $depth = 0;
        $class = null;
        $classDepth = null;
        $pendingClass = null;
        $pendingMethod = null;
        $pendingPublic = true;
        $modifiers = [];
        $current = null;
        $methodDepth = null;

        foreach ($tokens as $i => $token) {
            $id = \is_array($token) ? $token[0] : null;
            $text = \is_array($token) ? $token[1] : $token;

            if (\in_array($id, [\T_CLASS, \T_TRAIT, \T_ENUM, \T_INTERFACE], true) && \T_DOUBLE_COLON !== self::idAt($tokens, self::previousMeaningful($tokens, $i))) {
                if (\T_STRING === self::idAt($tokens, self::nextMeaningful($tokens, $i))) {
                    $pendingClass = $tokens[self::nextMeaningful($tokens, $i)][1];
                }
                continue;
            }

            $atClassBody = null !== $classDepth && $depth === $classDepth && null === $current;

            if ($atClassBody && \in_array($id, [\T_PUBLIC, \T_PROTECTED, \T_PRIVATE], true)) {
                $modifiers[] = $id;
                continue;
            }

            if ($atClassBody && \T_FUNCTION === $id) {
                $next = self::nextMeaningful($tokens, $i);
                if ('&' === ($tokens[$next] ?? null)) {
                    $next = self::nextMeaningful($tokens, $next);
                }
                if (\T_STRING === self::idAt($tokens, $next)) {
                    $pendingMethod = $tokens[$next][1];
                    $pendingPublic = !\in_array(\T_PRIVATE, $modifiers, true) && !\in_array(\T_PROTECTED, $modifiers, true);
                }
                $modifiers = [];
                continue;
            }

            if ('{' === $text || \T_CURLY_OPEN === $id || \T_DOLLAR_OPEN_CURLY_BRACES === $id) {
                ++$depth;
                if (null !== $pendingClass && null === $classDepth) {
                    $class = $pendingClass;
                    $classDepth = $depth;
                    $pendingClass = null;
                } elseif (null !== $pendingMethod && null === $current && null !== $classDepth && $depth === $classDepth + 1) {
                    $current = $class.'::'.$pendingMethod;
                    $methods[$current] = ['public' => $pendingPublic, 'class' => (string) $class, 'name' => $pendingMethod, 'writes' => [], 'calls' => [], 'entity' => false];
                    $methodDepth = $depth;
                    $pendingMethod = null;
                }
                continue;
            }

            if ('}' === $text) {
                if (null !== $current && $depth === $methodDepth) {
                    $current = null;
                    $methodDepth = null;
                } elseif (null !== $classDepth && $depth === $classDepth) {
                    $class = null;
                    $classDepth = null;
                }
                --$depth;
                continue;
            }

            if ($atClassBody && ';' === $text) {
                // An abstract or interface method: a signature with no body.
                $pendingMethod = null;
                $modifiers = [];
                continue;
            }

            if (null === $current) {
                continue;
            }

            if (\T_CONSTANT_ENCAPSED_STRING === $id || \T_ENCAPSED_AND_WHITESPACE === $id) {
                if (1 === preg_match(self::WRITE_SQL, $text, $m) || 1 === preg_match(self::WRITE_DQL, $text, $m)) {
                    $methods[$current]['writes'][] = 'SQL: '.preg_replace('/\s+/', ' ', $m[0]);
                }
                continue;
            }

            if (\in_array($id, [\T_STRING, \T_NAME_QUALIFIED, \T_NAME_FULLY_QUALIFIED], true)) {
                $previous = self::idAt($tokens, self::previousMeaningful($tokens, $i));
                $nextIndex = self::nextMeaningful($tokens, $i);
                $next = null === $nextIndex ? null : $tokens[$nextIndex];

                if (\in_array($previous, [\T_OBJECT_OPERATOR, \T_NULLSAFE_OBJECT_OPERATOR, \T_DOUBLE_COLON], true) && '(' === $next) {
                    $methods[$current]['calls'][] = $text;
                }

                $short = substr((string) strrchr('\\'.$text, '\\'), 1);
                if (\in_array($short, ['PurchaseOrder', 'PurchaseOrderLine'], true)) {
                    $isClassConstant = \T_DOUBLE_COLON === self::idAt($tokens, $nextIndex)
                        && \T_CLASS === self::idAt($tokens, self::nextMeaningful($tokens, (int) $nextIndex));
                    if ($isClassConstant || \T_NEW === $previous) {
                        $methods[$current]['entity'] = true;
                    }
                }
            }
        }

        return $methods;
    }

    /** @param list<mixed> $tokens */
    private static function nextMeaningful(array $tokens, int $i): ?int
    {
        for ($j = $i + 1, $n = \count($tokens); $j < $n; ++$j) {
            if (!\is_array($tokens[$j]) || !\in_array($tokens[$j][0], [\T_WHITESPACE, \T_COMMENT, \T_DOC_COMMENT], true)) {
                return $j;
            }
        }

        return null;
    }

    /** @param list<mixed> $tokens */
    private static function previousMeaningful(array $tokens, int $i): ?int
    {
        for ($j = $i - 1; $j >= 0; --$j) {
            if (!\is_array($tokens[$j]) || !\in_array($tokens[$j][0], [\T_WHITESPACE, \T_COMMENT, \T_DOC_COMMENT], true)) {
                return $j;
            }
        }

        return null;
    }

    /** @param list<mixed> $tokens */
    private static function idAt(array $tokens, ?int $i): ?int
    {
        return null !== $i && \is_array($tokens[$i]) ? $tokens[$i][0] : null;
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
            // A child still running here means the test failed before the
            // holder finished (an assertion inside the wait, or the request
            // threw). Stop it rather than let `proc_close()` wait on it.
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
