<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Modules\Purchasing\Application\PurchaseOrderRowLock;
use App\Modules\Purchasing\Infrastructure\Entity\PurchaseOrder;
use App\Modules\Purchasing\Infrastructure\Entity\PurchaseOrderLine;
use App\Modules\Purchasing\Infrastructure\OrmPurchaseOrderReceiptStatus;
use App\Modules\Sales\Application\InvoiceRowLock;
use App\Modules\Sales\Application\SalesOrderRowLock;
use App\Modules\Sales\Infrastructure\Entity\Invoice;
use App\Modules\Sales\Infrastructure\Entity\SalesOrder;
use App\Modules\Sales\Infrastructure\Entity\SalesOrderLine;
use App\Modules\Sales\Infrastructure\OrmSalesOrderFulfilmentStatus;
use App\Modules\UserManagement\Application\RoleAdministrationLock;
use App\Modules\UserManagement\Domain\DefaultRoles;
use App\Modules\UserManagement\Infrastructure\Entity\AppUser;
use App\Modules\UserManagement\Infrastructure\Entity\Role;
use App\Modules\UserManagement\Infrastructure\Entity\RolePermission;
use App\Modules\UserManagement\Infrastructure\Entity\UserRole;
use App\Platform\Http\Idempotency\IdempotencyKeyGuard;
use App\Platform\Http\Idempotency\IdempotencyKeyHash;
use App\Platform\Http\Idempotency\RequestDigest;
use App\Platform\Tenancy\TenantContext;
use App\SharedKernel\ValueObject\AuditEvent;
use App\Tests\Support\PhpSourceScan;
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
    use InvoiceFixtures;
    use CreditNoteFixtures;

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
        $draft = $this->seedDraft(self::TENANT_A, 'PO-000001', $this->seedPrincipal(self::TENANT_A, []));
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
        $draft = $this->seedDraft(self::TENANT_A, 'PO-000001', $this->seedPrincipal(self::TENANT_A, []));
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
        $draft = $this->seedDraft(self::TENANT_A, 'PO-000001', $this->seedPrincipal(self::TENANT_A, []));

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
        $draft = $this->seedDraft(self::TENANT_A, 'PO-000001', $this->seedPrincipal(self::TENANT_A, []));
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
        $draft = $this->seedDraft(self::TENANT_A, 'PO-000001', $this->seedPrincipal(self::TENANT_A, []));
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
            // Since V2-R-12 the line create demands a key; without one it is a 428 that never reaches the order.
            [IdempotencyKeyGuard::HEADER => Uuid::v4()->toRfc4122()],
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
    private function orderedOrderToReceive(string $number = 'PO-000001'): array
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
        $draft = $this->seedDraft(self::TENANT_A, 'PO-000001', $this->seedPrincipal(self::TENANT_A, []));
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

        // The invoice's lock (sales step 6, C5) refuses the same way, for the same reason.
        $this->recorder()->start();
        try {
            InvoiceRowLock::holdInside($em, Uuid::v4()->toRfc4122());
            self::fail('The invoice lock ran outside a transaction.');
        } catch (\LogicException $e) {
            self::assertStringContainsString('transaction', $e->getMessage());
        } finally {
            $this->recorder()->stop();
        }
        self::assertSame([], $this->recorder()->matching('/FOR NO KEY UPDATE/'), 'The refused invoice lock was still sent.');
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
        $draft = $this->seedDraft(self::TENANT_A, 'PO-000001', $this->seedPrincipal(self::TENANT_A, []));
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
     * **Every writer with no path of its own takes the order's row lock as the
     * first thing it sends, called on its own** — one case per entry in
     * `LOCKS_FIRST_WITH_NO_PATH_OF_ITS_OWN`, derived from that list.
     *
     * Such a writer has no HTTP case, so nothing at the HTTP level can show that
     * it locks *itself* rather than inheriting its caller's lock. Each case drives
     * the method directly, inside a transaction that has taken no lock, and reads
     * the statements the database was sent: **the first one naming
     * `purchase_order` must be the lock**, before the `SELECT` that `find()`
     * issues and before anything else reads the row.
     *
     * Written this way rather than as "the first statement of all" on purpose —
     * `TenantContext` sends its own `set_config` first, and a check that
     * counted that would be asserting the framework rather than the writer.
     *
     * ⚠ **Until the five-calls follow-up review (2026-09-13) this was one
     * hand-written test for `OrmPurchaseOrderReceiptStatus::recordReceipt`.** The
     * accounting check reconciles *which* methods are listed, not *what they do*,
     * so a second entry whose reason said "it calls `holdInside()` first" and was
     * false went red nowhere. Now the list is the provider: a new entry is red
     * here until {@see self::noPathWriter()} can drive it, and once it can, its
     * reason is measured rather than read.
     *
     * **Mutations, run 2026-09-13:** (1) the `PurchaseOrderRowLock::holdInside()`
     * call removed from `OrmPurchaseOrderReceiptStatus` (1 occurrence) — red
     * here; `everyMethodThatWritesAPurchaseOrderIsCoveredByALockFirstCheck` stays
     * green. (2) The reviewer's case: a throwaway `src/` method that changes an
     * order through the entity WITHOUT locking, entered in the list with a false
     * "it locks first" reason, its `writes` pin and a driver — red here on that
     * case, and green in the accounting check, which is exactly the gap. (3) The
     * same entry with no driver — red here, naming it. Each reverted
     * `cmp`-identical or removed.
     */
    #[Test]
    #[DataProvider('everyWriterWithNoPathOfItsOwn')]
    public function aWriterWithNoPathOfItsOwnTakesTheOrdersLockAsItsFirstStatement(string $writer): void
    {
        ['run' => $run, 'then' => $then] = $this->noPathWriter($writer);

        $this->recorder()->start();
        try {
            $this->tenantContext()->run(self::TENANT_A, $run);
        } finally {
            $this->recorder()->stop();
        }

        $touching = array_values(array_filter(
            $this->recorder()->statements(),
            static fn (string $sql): bool => str_contains($sql, 'purchase_order'),
        ));

        self::assertNotSame([], $touching, $writer.' sent nothing naming purchase_order, so it did not run.');
        self::assertSame(PurchaseOrderRowLock::SQL, $touching[0],
            $writer.' read the order before locking it, and LOCKS_FIRST_WITH_NO_PATH_OF_ITS_OWN says it locks first. '
            .'Its caller may hold the lock today; this method must not depend on that.');
        $then();
    }

    /**
     * **The sales twin: every sales writer with no path of its own takes the order's row lock as the first thing
     * it sends, called on its own** (sales step 5, commit 4). Driven directly, inside a transaction that has taken
     * no lock; the first statement naming `sales_order` must be `SalesOrderRowLock::SQL`, before `find()`'s SELECT.
     *
     * **Mutation, run 2026-09-22:** the `holdInside()` call removed from `OrmSalesOrderFulfilmentStatus` — red here
     * (see the progress note); the accounting check stays green, which is the gap this closes.
     */
    #[Test]
    public function aSalesWriterWithNoPathOfItsOwnTakesTheOrdersLockAsItsFirstStatement(): void
    {
        self::assertSame(['OrmSalesOrderFulfilmentStatus::recordFulfilment'], array_keys(self::SALES_LOCKS_FIRST_WITH_NO_PATH_OF_ITS_OWN),
            'A sales no-path writer is listed that this test cannot drive. Add a driver below.');

        $order = $this->seedSalesOrder(self::TENANT_A, 'SO-178000', status: SalesOrder::STATUS_CONFIRMED, orderedAt: '2026-09-20T10:00:00Z');
        $subject = new OrmSalesOrderFulfilmentStatus();

        $this->recorder()->start();
        try {
            $this->tenantContext()->run(self::TENANT_A, static function (EntityManagerInterface $em) use ($subject, $order): void {
                $subject->recordFulfilment($em, $order, false);
                $em->flush();
            });
        } finally {
            $this->recorder()->stop();
        }

        $touching = array_values(array_filter($this->recorder()->statements(), static fn (string $sql): bool => str_contains($sql, 'sales_order')));
        self::assertNotSame([], $touching, 'recordFulfilment() sent nothing naming sales_order, so it did not run.');
        self::assertSame(SalesOrderRowLock::SQL, $touching[0],
            'OrmSalesOrderFulfilmentStatus::recordFulfilment read the order before locking it. Its caller holds the lock '
            .'today; this method must not depend on that.');
        $this->assertSalesOrderStatus($order, SalesOrder::STATUS_PARTIALLY_FULFILLED);
    }

    /** @return iterable<string, array{string}> */
    public static function everyWriterWithNoPathOfItsOwn(): iterable
    {
        foreach (array_keys(self::LOCKS_FIRST_WITH_NO_PATH_OF_ITS_OWN) as $writer) {
            yield $writer => [$writer];
        }
    }

    /**
     * How to call one no-path writer on its own: `run` is handed the tenant
     * transaction's EntityManager, `then` asserts it did its job. An entry with
     * no driver fails rather than being skipped.
     *
     * @return array{run: callable(EntityManagerInterface): mixed, then: callable(): void}
     */
    private function noPathWriter(string $writer): array
    {
        switch ($writer) {
            case 'OrmPurchaseOrderReceiptStatus::recordReceipt':
                $supplier = $this->seedSupplier(self::TENANT_A, 'Acme');
                $warehouse = $this->seedWarehouse(self::TENANT_A, 'Main');
                $order = $this->seedOrder(self::TENANT_A, 'PO-000001', $supplier, $warehouse, status: PurchaseOrder::STATUS_ORDERED);
                $subject = new OrmPurchaseOrderReceiptStatus();

                return [
                    'run' => static fn (EntityManagerInterface $em) => $subject->recordReceipt($em, $order, false),
                    'then' => fn () => $this->assertOrderStatus($order, PurchaseOrder::STATUS_PARTIALLY_RECEIVED),
                ];
        }

        self::fail($writer.' is in LOCKS_FIRST_WITH_NO_PATH_OF_ITS_OWN and this test cannot call it. Add a case to '
            .'noPathWriter() that drives it on its own, so its "locks first" reason is measured rather than trusted.');
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
        [$theirs, , , $theirWarehouse] = $this->orderedOrderToReceive('PO-000002');
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
     * **V2-R-12 (V4 B3a, F-1): the loser of a race for one line key across two
     * orders is a 409, never a 500.**
     *
     * `CreatePurchaseOrderLine`'s key lookup reads committed rows only, and the
     * order row lock serialises retries on the SAME order. A same-key request on
     * a DIFFERENT order is therefore refused by
     * `purchase_order_line_tenant_idem_live` at its `INSERT`, and the writer must
     * translate that violation **by the index's name** into §6.2's
     * `idempotency-key-conflict` — the key is spent on another order's line, so
     * "try again" would be advice into a wall (the goods receipt's reasoning,
     * `theLoserOfARaceForOneKeyAcrossTwoOrdersIsToldItIsAConflict`).
     *
     * A holds order 1 and `INSERT`s its keyed line **uncommitted**, so B's key
     * lookup cannot see it; B locks order 2, finds no replay, and blocks on the
     * unique. A sees the waiter and commits; B raises `23505`.
     *
     * **Mutation (a1):** delete the by-name mapping → the violation is rethrown
     * and this is red on a 500.
     */
    #[Test]
    public function aConcurrentSameKeyOnAnotherOrderIsAConflictNotA500(): void
    {
        $first = $this->seedDraft(self::TENANT_A, 'PO-000001');
        $this->seedSupplierProduct(self::TENANT_A, $first['supplier'], $first['item']);
        $second = $this->seedOrder(self::TENANT_A, 'PO-000002', $first['supplier'], $first['warehouse']);
        $key = Uuid::v4()->toRfc4122();

        $holder = $this->raceAgainstAHolder($first['order'], [[
            <<<'SQL'
                INSERT INTO purchase_order_line
                    (id, tenant_id, purchase_order_id, inventory_item_id, line_number,
                     quantity_ordered, unit_cost, lead_time_days, idempotency_key, request_digest)
                VALUES (?::uuid, ?::uuid, ?::uuid, ?::uuid, 1, 1, 1, 7, ?, ?)
                SQL,
            [Uuid::v4()->toRfc4122(), self::TENANT_A, $first['order'], $first['item'],
                hash('sha256', $key), str_repeat('c', 64)],
        ]], function () use ($second, $first, $key): void {
            $this->send('POST', '/purchase-orders/'.$second.'/lines', [
                'inventory_item_id' => $first['item'], 'quantity' => '1', 'unit_cost' => '1',
            ], [IdempotencyKeyGuard::HEADER => $key]);
        });

        $this->assertTheHolderWasWaitedOnAndCommitted($holder);

        self::assertSame(409, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        self::assertSame('urn:erp:problem:idempotency-key-conflict', $this->json()['type']);
        self::assertSame(1, $this->countAsOwnerForTenant(self::TENANT_A, 'SELECT count(*) FROM purchase_order_line'),
            "The loser's line survived, or the winner's did not.");
        self::assertSame('0.0000', (string) $this->fetchAsOwnerForTenant(self::TENANT_A,
            'SELECT total_amount FROM purchase_order WHERE id = ?::uuid', [$second])[0]['total_amount'],
            "The loser moved its order's total before it was refused.");
    }

    /**
     * **The residual this project has carried by name since step 6b, turned
     * into a check. Step 9, item 11.**
     *
     * `PurchaseOrderWriter::create()` answers a same-key request that commits
     * between its replay `SELECT` and its own `INSERT` with
     * `purchase_order.idempotency_key_is_in_flight` — *"already being processed.
     * Try again."* The comment above that `throw` makes **three** claims, and
     * until this test **nothing asserted any of them**: the loser gets no second
     * order; it is told to try again; *"the next attempt reads the committed row
     * and gets it"*.
     *
     * **The race is not closed here and this test does not claim it is.**
     * Handing the loser the winner's row in the same request needs a
     * `TenantContext` change — the failed `INSERT` aborts the transaction and
     * closes the `EntityManager`, so there is nothing left to read the winner
     * with — and that stays owed. What is asserted is what the residual *rests
     * on*, which is the part that was prose: a consolation argument doing a
     * check's work is the shape this project has now paid for repeatedly.
     *
     * **It also pins the deliberate difference from the receipt.**
     * `theLoserOfARaceForOneKeyAcrossTwoOrdersIsToldItIsAConflict` one method
     * down answers **409 `idempotency-key-conflict`**, and `GoodsReceiptWriter`'s
     * catch says in terms *"the two are NOT the same case, so do not harmonise
     * them"*: there, the order's row lock has already serialised every same-order
     * retry, so the only racer left is a **different** order and the key is
     * genuinely reused; here there is no row to lock, the racer may be a genuine
     * identical retry, and the next attempt is handed the order. Two tests side
     * by side make that asymmetry a thing somebody has to justify changing,
     * rather than a paragraph.
     *
     * **Made deterministic by the database, no clock.** A holds a decoy order
     * (so B never waits on a row lock) and `INSERT`s the winning order carrying
     * the hashed key — **uncommitted**, so B's replay read under READ COMMITTED
     * cannot see it. B then blocks on `purchase_order_tenant_idem_live` at its
     * own `INSERT`. A sees the waiter and commits; B raises `23505`. The winner's
     * `order_number` is far away (`PO-999999`) so B, numbering from the committed
     * maximum, cannot also collide on `purchase_order_tenant_number_live` — which
     * would leave the answer up to which index was checked first.
     *
     * The winner's `request_digest` is computed the way `seedOrder()` computes
     * it, which is the way `CreatePurchaseOrder` computes it, so the third
     * attempt is a **replay** and not a 409 — and if the writer's digested field
     * set ever changes, `PurchaseOrderCreateTest`'s pin reddens first.
     *
     * **Mutations:** delete the `IDEMPOTENCY_INDEX` arm of `create()`'s catch →
     * the `23505` escapes as a 500; change it to `idempotencyKeyConflict()` (the
     * receipt's answer) → red on the rule, which is the asymmetry being held.
     */
    #[Test]
    public function theLoserOfARaceToCreateOneOrderIsRefusedAndTheNextAttemptIsHandedIt(): void
    {
        $decoy = $this->seedDraft(self::TENANT_A, 'PO-000001');
        $key = Uuid::v4()->toRfc4122();
        $winner = Uuid::v4()->toRfc4122();
        $body = ['supplier_id' => $decoy['supplier'], 'warehouse_id' => $decoy['warehouse']];

        $holder = $this->raceAgainstAHolder($decoy['order'], [[
            <<<'SQL'
                INSERT INTO purchase_order
                    (id, tenant_id, order_number, supplier_id, warehouse_id, created_by_user_id,
                     status, currency_code, ordered_at, expected_at, deleted_at, idempotency_key, request_digest)
                VALUES (?::uuid, ?::uuid, 'PO-999999', ?::uuid, ?::uuid, NULL,
                        'draft', 'EUR', NULL, NULL, NULL, ?, ?)
                SQL,
            [
                $winner, self::TENANT_A, $decoy['supplier'], $decoy['warehouse'],
                IdempotencyKeyHash::of($key),
                RequestDigest::of('CreatePurchaseOrder', [
                    'supplier_id' => RequestDigest::uuid($decoy['supplier']),
                    'warehouse_id' => RequestDigest::uuid($decoy['warehouse']),
                    'currency_code' => null,
                    'expected_at' => RequestDigest::instant(null),
                ]),
            ],
        ]], function () use ($body, $key): void {
            $this->send('POST', '/purchase-orders', $body, [IdempotencyKeyGuard::HEADER => $key]);
        });

        $this->assertTheHolderWasWaitedOnAndCommitted($holder);

        // Claim 2 — one stable answer, and it is this one on purpose.
        self::assertSame(409, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        self::assertSame('purchase_order.idempotency_key_is_in_flight', $this->json()['rule'],
            'The loser of the create race is answered by something other than the in-flight rule. If this was a '
            .'deliberate harmonisation with the goods receipt, GoodsReceiptWriter\'s catch argues against it in terms '
            .'and has to be rewritten with this.');

        // Claim 1 — never a second order. The decoy and the winner, nothing else.
        self::assertSame(
            [['order_number' => 'PO-000001'], ['order_number' => 'PO-999999']],
            $this->fetchAsOwnerForTenant(self::TENANT_A, 'SELECT order_number FROM purchase_order ORDER BY order_number'),
            'The loser of the race wrote an order. This is the defect §6.1 names, reached through the one window the '
            .'replay read cannot cover.',
        );

        // Claim 3 — "the next attempt reads the committed row and gets it".
        $this->send('POST', '/purchase-orders', $body, [IdempotencyKeyGuard::HEADER => $key]);

        self::assertSame(201, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        self::assertSame($winner, $this->json()['id'],
            'The attempt after the race was not handed the order the race created. That is the sentence the residual '
            .'rests on: the client is told to try again because trying again works.');
        self::assertCount(2, $this->fetchAsOwnerForTenant(self::TENANT_A, 'SELECT id FROM purchase_order'),
            'The retry after the race minted a third order.');

        // Claim 4 (CD-7, `RED` F5) — the winner's `PO-999999` is now the committed maximum, so a create under a
        // FRESH key is refused by the exhausted series, while claim 3's replay above was still handed its order.
        // The only race-plus-cap evidence this file needs: the loser never reached the cap (it numbered from the
        // committed maximum, the decoy), and the replay read runs before the mint.
        $this->send('POST', '/purchase-orders', $body, [IdempotencyKeyGuard::HEADER => Uuid::v4()->toRfc4122()]);
        self::assertSame(409, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        self::assertSame('purchase_order.number_series_is_exhausted', $this->json()['rule']);
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
     * `aWriterWithNoPathOfItsOwnTakesTheOrdersLockAsItsFirstStatement` asserts it,
     * for every entry,
     * against the statements the database was actually sent.
     */
    private const LOCKS_FIRST_WITH_NO_PATH_OF_ITS_OWN = [
        'OrmPurchaseOrderReceiptStatus::recordReceipt' => [
            'writes' => ['ORM: names a purchase-order entity class and calls recordReceipt() on it'],
            'why' => 'It calls PurchaseOrderRowLock::holdInside() first. '
                .'Its only caller, GoodsReceiptWriter::createInside, already holds that lock, and the second take is '
                .'re-entrant for the same transaction, so this costs one cheap statement and removes the dependence '
                .'on the caller.',
        ],
    ];

    /** Writers that take no lock, each by name, with the writes it makes and its reason. Keep it short. */
    private const ALLOWED_WITHOUT_A_LOCK = [
        // ⚠ **Re-argued at step 8a, when this method stopped writing one row.**
        // Since the nested create (D-C) it inserts the order, inserts its lines
        // and rewrites `total_amount` — three writes across both tables. The
        // entry survives, and not because the reason was reused: the order is
        // created by this transaction and its id is a freshly minted UUID, so
        // until COMMIT there is no second transaction that can address the row
        // or its lines, and a lock would have nothing to serialise against.
        // That is a property of the write, not a claim about callers.
        'PurchaseOrderWriter::create' => [
            'writes' => [
                'SQL: INSERT INTO purchase_order',
                'via insertLineInside()',
                'via recomputeTotal()',
            ],
            'why' => 'It inserts the order, so there is no existing row to lock, and no other '
                .'transaction can address what it inserts until it commits. Its two races are answered by unique '
                .'indexes: purchase_order_tenant_idem_live (a replay of the same key) and '
                .'purchase_order_tenant_number_live (two creates picking the same order number). Its lines are '
                .'inserted in the same transaction and belong to an order nobody else can reach yet.',
        ],
    ];

    /**
     * **The Sales half of `ALLOWED_WITHOUT_A_LOCK`** — writers that take no sales-order row lock, each by
     * name, with the writes it makes and its reason. Keep it short.
     *
     * It is a second constant rather than entries in the list above because the two lists are compared
     * against two different derived sets, and a single list would let a purchase-order entry silently
     * account for a sales writer (or the reverse) the day a class writes both.
     *
     * ⚠ **This docblock said *"there is no sales `LOCK_FIRST_CHECKED` … yet"* and that has been FALSE
     * since `ConfirmSalesOrder` shipped on 2026-09-19** — it went stale in the commit that built the very
     * thing it said did not exist, and was found one commit later by `CancelSalesOrder`. `SALES_LOCK_FIRST_CHECKED`
     * exists below and names several operations. *(It read "**two**" until 2026-09-21 and "**three**"
     * within the same day, each figure falsified by the very commit that moved the sentence two lines
     * below it — twice, in two days. **The number is deliberately not written here any more: derive it
     * from the constant.** A count restated in prose beside the array it counts is a second copy of a set
     * nobody maintains, and this one has now gone stale twice in a row.)* What survives of the sentence is
     * the rule it stated, so the rule is kept and the false premise struck: **a writer that touches an
     * EXISTING sales order needs a lock-first check that actually runs**, the way
     * `theOrderRowLockIsTheFirstStatementToTouchTheOrder` runs for purchasing, and a list naming it.
     * `UpdateSalesOrder` paid its on 2026-09-21 and `CreateSalesOrderLine` paid its the same day.
     * There is still no sales `LOCKS_FIRST_WITH_NO_PATH_OF_ITS_OWN`, and that absence is not an exemption.
     */
    /**
     * **Confirm vs confirm: two reps commit the same draft to the customer at once.** A confirms and holds
     * the order; the HTTP request must wait, then read what A committed and refuse.
     *
     * Without the lock first the second confirmation reads `draft`, passes `confirm()`'s status guard,
     * counts the lines, waits only at its own `UPDATE`, and then writes `confirmed` over `confirmed` with
     * a **new `ordered_at`** — a customer commitment silently re-dated by a request that changed nothing
     * else. `ordered_at` is in the comparison below for exactly that reason.
     *
     * This is `aSecondCancellationWaitsAndIsRefusedByTheStatusGuard` on the selling side, and it is what
     * makes the Sales half of W-5 *behaviour* rather than *position*: the per-path check below covers
     * position, and the purchase-order docblock says in terms that position is a supplement and not
     * evidence on its own.
     */
    #[Test]
    public function aSecondConfirmationWaitsAndIsRefusedByTheStatusGuard(): void
    {
        $order = $this->seedConfirmableSalesOrder('SO-000001');

        $holder = $this->raceAgainstAHolder($order, before: [
            ["UPDATE sales_order SET status = 'confirmed', ordered_at = now(), updated_at = now() WHERE id = ?::uuid", [$order]],
        ], request: fn () => $this->send('POST', '/sales-orders/'.$order.'/confirmation'), table: 'sales_order');

        $this->assertTheHolderWasWaitedOnAndCommitted($holder);
        self::assertSame(409, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        self::assertSame('urn:erp:problem:business-rule-violated', $this->json()['type']);
        self::assertSame('sales_order.'.self::STATUS_RULE, $this->json()['rule']);
        $this->assertSalesOrderStatus($order, SalesOrder::STATUS_CONFIRMED);
    }

    /**
     * **Edit vs confirm: a rep moves the shipping address while the order is being committed to the
     * customer.** A confirms and holds the order; the `PATCH` must wait, then read `confirmed` and be
     * refused by §8.3a's edit window.
     *
     * ---------------------------------------------------------------------------
     * The harm, which is the reason this is a behaviour test and not only a position one
     * ---------------------------------------------------------------------------
     * Without the lock first, the update reads `draft`, passes `refuseUnlessDraft()`, waits only at its
     * own `UPDATE`, and then **silently rewrites the shipping address of an order that has already been
     * confirmed to the customer** — after the moment `SalesOrder::confirm()` is documented to make it a
     * commitment. Nothing afterwards could detect it: `sales_order` stores no address id, no trail row
     * is written by this operation, and the four `ship_to_*` columns have no residue
     * (`RESIDUAL_GAPS.md` G-6). `theSalesOrderRowLockIsTheFirstStatementToTouchTheOrder` proves the
     * lock's **position**; this proves what the position buys, against a real second connection.
     */
    #[Test]
    public function anUpdateWaitsForAConcurrentConfirmationAndIsRefusedByTheEditWindow(): void
    {
        $customer = $this->seedCustomer(self::TENANT_A, 'Race Customer');
        $warehouse = $this->seedWarehouse(self::TENANT_A, 'WH Race');
        $order = $this->seedSalesOrder(self::TENANT_A, 'SO-190000', $customer, $warehouse);
        $this->seedSalesOrderLine(self::TENANT_A, $order, $this->seedItem(self::TENANT_A, 'SKU-RACE'));
        $moved = $this->seedCustomerAddress(self::TENANT_A, $customer, 'shipping', '1 Race Street', 'York', 'YO1 1AA');

        $before = $this->salesOrderShipTo($order);

        $holder = $this->raceAgainstAHolder($order, before: [
            ["UPDATE sales_order SET status = 'confirmed', ordered_at = now(), updated_at = now() WHERE id = ?::uuid", [$order]],
        ], request: fn () => $this->send('PATCH', '/sales-orders/'.$order, ['ship_to_address_id' => $moved]), table: 'sales_order');

        $this->assertTheHolderWasWaitedOnAndCommitted($holder);
        self::assertSame(409, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        self::assertSame('urn:erp:problem:business-rule-violated', $this->json()['type']);
        self::assertSame('sales_order.is_editable_only_while_draft', $this->json()['rule']);
        $this->assertSalesOrderStatus($order, SalesOrder::STATUS_CONFIRMED);

        // The harm itself, not only its refusal: the confirmed order's snapshot is untouched.
        self::assertSame($before, $this->salesOrderShipTo($order),
            'A confirmed sales order\'s shipping address was rewritten by an update that should have been refused. '
            .'Nothing in the schema or the trail could ever detect this afterwards -- see RESIDUAL_GAPS.md G-6.');
    }

    /** @return array<string, mixed> */
    private function salesOrderShipTo(string $order): array
    {
        return $this->fetchAsOwnerForTenant(self::TENANT_A, <<<'SQL'
            SELECT ship_to_line, ship_to_city, ship_to_postal_code, ship_to_country_code
              FROM sales_order WHERE id = ?::uuid
            SQL, [$order])[0];
    }

    /**
     * **Confirm vs archive-the-last-line — the race the line guard names.** A archives the order's only
     * line and holds the order. The confirmation must wait, then count the lines A left, which is none,
     * and refuse with `sales_order.has_a_live_line`.
     *
     * It is the mirror of `anApprovalWaitsForTheArchiveOfTheLastLineAndThenRefuses`, and it is the case
     * that makes V2-R-22 Half 1's *"the count is read inside the transaction, under the lock"* a measured
     * claim rather than a described one: a count read before the lock would see A's line and confirm an
     * order that has none.
     */
    #[Test]
    public function aConfirmationWaitsForTheArchiveOfTheLastLineAndThenRefuses(): void
    {
        $order = $this->seedConfirmableSalesOrder('SO-000001');

        $holder = $this->raceAgainstAHolder($order, before: [
            ['UPDATE sales_order_line SET deleted_at = now() WHERE sales_order_id = ?::uuid', [$order]],
        ], request: fn () => $this->send('POST', '/sales-orders/'.$order.'/confirmation'), table: 'sales_order');

        $this->assertTheHolderWasWaitedOnAndCommitted($holder);
        self::assertSame(409, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        self::assertSame('urn:erp:problem:business-rule-violated', $this->json()['type']);
        self::assertSame('sales_order.has_a_live_line', $this->json()['rule']);
        $this->assertSalesOrderStatus($order, SalesOrder::STATUS_DRAFT);
    }

    /**
     * **Cancel vs cancel: two reps withdraw the same order at once.** A cancels and holds the order; the
     * HTTP request must wait, then read what A committed and refuse.
     *
     * Without the lock first the second cancellation reads `draft`, passes `cancel()`'s status guard,
     * waits only at its own `UPDATE`, and then writes `cancelled` over `cancelled` — and, because this
     * operation writes an audit row, **it would also append a SECOND `sales_order.cancelled` row naming a
     * second canceller for one cancellation.** That is the part with no purchase-side equivalent worth
     * restating: on an append-only table no role can `UPDATE` or `DELETE`, a duplicated trail row is
     * permanent, and `ADR-008` §2 leans this operation's whole control on that trail. So the audit count
     * is in the assertion below, not just the status.
     *
     * This is `aSecondCancellationWaitsAndIsRefusedByTheStatusGuard` on the selling side.
     */
    #[Test]
    public function aSecondSalesCancellationWaitsAndIsRefusedByTheStatusGuard(): void
    {
        $order = $this->seedConfirmableSalesOrder('SO-000001');

        $holder = $this->raceAgainstAHolder($order, before: [
            ["UPDATE sales_order SET status = 'cancelled', updated_at = now() WHERE id = ?::uuid", [$order]],
        ], request: fn () => $this->send('POST', '/sales-orders/'.$order.'/cancellation', ['reason' => 'Customer withdrew']), table: 'sales_order');

        $this->assertTheHolderWasWaitedOnAndCommitted($holder);
        self::assertSame(409, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        self::assertSame('urn:erp:problem:business-rule-violated', $this->json()['type']);
        self::assertSame('sales_order.'.self::STATUS_RULE, $this->json()['rule']);
        $this->assertSalesOrderStatus($order, SalesOrder::STATUS_CANCELLED);

        // The refused request wrote no trail row. A's `UPDATE` above is raw SQL and writes none either, so
        // zero is the honest expectation -- and it is asserted against the subject id rather than the whole
        // table, so a row written for some other order could not satisfy it.
        self::assertSame([], $this->fetchAsOwnerForTenant(self::TENANT_A,
            'SELECT id FROM audit_log WHERE event_type = ? AND subject_id = ?::uuid',
            [AuditEvent::SALES_ORDER_CANCELLED, $order]),
            'A cancellation refused by the status guard appended a sales_order.cancelled row anyway. The '
            .'audit write is inside the transaction cancel() refuses, so a row here means it is not.');
    }

    /**
     * **Delete vs confirm (sales slice 5): a rep discards a draft while a colleague commits it to the customer.**
     * A confirms and holds the order; the `DELETE` must wait, then read `confirmed` and be refused by L-8.
     *
     * Without the lock first, the delete would read `draft`, pass `refuseUnlessDraft()`, wait only at its own
     * `UPDATE`, and then archive an order that had just been confirmed — which
     * `sales_order_archived_only_while_draft` would refuse as a `23514`, i.e. an HTTP 500 where the caller should
     * be told the order is no longer a draft. So the lock is what turns a database refusal into a domain one.
     */
    #[Test]
    public function aSalesDeleteWaitsForAConcurrentConfirmationAndThenRefuses(): void
    {
        $order = $this->seedConfirmableSalesOrder('SO-000001');

        $holder = $this->raceAgainstAHolder($order, before: [
            ["UPDATE sales_order SET status = 'confirmed', ordered_at = now(), updated_at = now() WHERE id = ?::uuid", [$order]],
        ], request: fn () => $this->send('DELETE', '/sales-orders/'.$order), table: 'sales_order');

        $this->assertTheHolderWasWaitedOnAndCommitted($holder);
        self::assertSame(409, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        self::assertSame('sales_order.is_editable_only_while_draft', $this->json()['rule']);
        $this->assertSalesOrderStatus($order, SalesOrder::STATUS_CONFIRMED);
        self::assertSame(0, $this->countAsOwnerForTenant(self::TENANT_A,
            'SELECT count(*) FROM sales_order WHERE id = ?::uuid AND deleted_at IS NOT NULL', [$order]),
            'A delete refused by the edit window archived the confirmed order anyway.');
    }

    /**
     * **Delete vs delete: two reps discard the same draft at once.** A archives the order and holds it; the second
     * `DELETE` must wait, then read the archive A committed and be refused as `is_archived` — the `Idem = no`
     * answer — rather than re-stamping the order (and, through the cascade, nothing, since its lines are no longer
     * live).
     */
    #[Test]
    public function aSecondSalesDeleteWaitsAndIsRefusedAsArchived(): void
    {
        $order = $this->seedConfirmableSalesOrder('SO-000001');

        $holder = $this->raceAgainstAHolder($order, before: [
            ['UPDATE sales_order SET deleted_at = now(), updated_at = now() WHERE id = ?::uuid', [$order]],
        ], request: fn () => $this->send('DELETE', '/sales-orders/'.$order), table: 'sales_order');

        $this->assertTheHolderWasWaitedOnAndCommitted($holder);
        self::assertSame(409, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        self::assertSame('sales_order.is_archived', $this->json()['rule']);
    }

    /**
     * **The sales twin of `archivingALineWhileTheOrderIsArchivedWaitsInsteadOfDeadlocking`**, reachable on the
     * sales side for the first time now that `DeleteSalesOrder` archives an order through the API.
     *
     * A holds the order and waits until the line `DELETE` is blocked on it; only then does A archive the order, so
     * its cascade reaches for the line. `archiveLine()` takes the order lock FIRST, so the request is blocked on its
     * first statement holding nothing, A's cascade takes the line freely and commits, and the request reads an
     * archived order and refuses. Line-then-order would have deadlocked (40P01), which is the purchase twin's
     * recorded history.
     */
    #[Test]
    public function archivingASalesLineWhileTheOrderIsArchivedWaitsInsteadOfDeadlocking(): void
    {
        $order = $this->seedConfirmableSalesOrder('SO-000001');
        $line = $this->fetchAsOwnerForTenant(self::TENANT_A,
            'SELECT id::text AS id FROM sales_order_line WHERE sales_order_id = ?::uuid', [$order])[0]['id'];

        $holder = $this->raceAgainstAHolder(
            $order,
            before: [],
            request: fn () => $this->send('DELETE', '/sales-orders/'.$order.'/lines/'.$line),
            after: [
                ['UPDATE sales_order SET deleted_at = now(), updated_at = now() WHERE id = ?::uuid', [$order]],
            ],
            table: 'sales_order',
        );

        $this->assertTheHolderWasWaitedOnAndCommitted($holder);
        self::assertSame(409, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        self::assertSame('sales_order.is_archived', $this->json()['rule']);

        // The line was archived by the cascade, with its order, at the order's instant -- not by the refused request.
        self::assertSame([['same_instant' => true]], $this->fetchAsOwnerForTenant(self::TENANT_A, <<<'SQL'
            SELECT sol.deleted_at = so.deleted_at AS same_instant
              FROM sales_order_line sol JOIN sales_order so ON so.id = sol.sales_order_id
             WHERE sol.id = ?::uuid
            SQL, [$line]));
    }

    /**
     * **Two fulfilments of ONE order serialise on the order's row lock, and the second is refused as
     * over-fulfilled** (sales step 5, commit 4; design §6's first race). Connection A holds the order's lock and,
     * under it, raises the line's `quantity_fulfilled` by 4 — a hand-written stand-in for a concurrent
     * fulfilment's line write. The real request asks 3 more of a line of 5: it waits at the lock, then reads 4,
     * and 4 + 3 > 5 is refused in domain terms. Nothing it wrote survives.
     */
    #[Test]
    public function twoFulfilmentsOfOneOrderSerialiseAndTheSecondIsRefusedAsOverFulfilled(): void
    {
        $f = $this->confirmedSalesOrderWithStock('SO-191000', '5', ['10']);

        $holder = $this->raceAgainstAHolder(
            $f['order'],
            [['UPDATE sales_order_line SET quantity_fulfilled = quantity_fulfilled + 4 WHERE id = ?::uuid', [$f['line']]]],
            fn () => $this->send('POST', '/sales-orders/'.$f['order'].'/fulfilments',
                ['lines' => [['sales_order_line_id' => $f['line'], 'quantity' => '3', 'storage_location_id' => $f['bins'][0]]]],
                [IdempotencyKeyGuard::HEADER => Uuid::v4()->toRfc4122()]),
            table: 'sales_order',
        );

        $this->assertTheHolderWasWaitedOnAndCommitted($holder);
        self::assertSame(409, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        self::assertSame('sales_order_line.is_not_over_fulfilled', $this->json()['rule']);
        self::assertSame(0, $this->countAsOwnerForTenant(self::TENANT_A, 'SELECT count(*) FROM fulfilment'));
        self::assertSame([['q' => '10.000000']], $this->fetchAsOwnerForTenant(self::TENANT_A,
            'SELECT qty_on_hand::text AS q FROM stock_level WHERE storage_location_id = ?::uuid', [$f['bins'][0]]));
    }

    /**
     * **`IssueInvoice`'s step 9 — the three 23505 branches, each reached ALONE** (sales step 6, C3; design §19 C3,
     * D-3). Connection A holds an UNCOMMITTED invoice; the real issue of shipment F computes its number from committed
     * rows, inserts, and blocks on whichever unique A's row occupies. A commits; B raises 23505 outside its failed
     * transaction and re-reads, in a fresh one, whether F now has a live invoice.
     *
     *   - `number, live`:  A holds F's invoice under the next number → `invoice_number_unique` → live found → the
     *     already-invoiced 409 (R-14: the number only with `invoice.view`);
     *   - `number, none`:  A holds ANOTHER shipment's invoice under the next number → `invoice_number_unique` → no
     *     live invoice for F → "try again", naming nothing;
     *   - `not_void`:      A holds F's invoice under a number the test asserts is NOT the next → only
     *     `invoice_tenant_fulfilment_not_void` can refuse → the already-invoiced 409.
     *
     * Each runs with and without `invoice.view`. **A holds ANOTHER order's lock (G's), never F's** — reworked
     * 2026-10-01 (sales step 7 R2), when `IssueInvoice` began taking F's ORDER lock first (design §4.3, `RED` H-3).
     * The harness takes an order lock only because it must take one; with A on G's order, B passes F's lock and its
     * pre-check (nothing committed yet) and its wait falls on the index again, which `pg_blocking_pids` reports all the
     * same — so all three 23505 branches stay reached. *(Until then A took F's lock, under the premise "`IssueInvoice`
     * locks nothing, so B's wait is on the index"; with the lock, `number, none` would have waited on F's order and
     * returned 201, and the other two would have been answered by the pre-check, leaving the 23505 translation
     * unreached — silently.)* A fourth branch, `order lock`, keeps A on F's order: B waits on the ORDER, and is then
     * answered by the pre-check — the lock's own case.
     * **M-7:** the branch split itself is the measurement that a unique INDEX reports its name in the violation — the
     * writer routes on it, and branches 1 and 2 answer differently.
     *
     * @return iterable<string, array{string, bool}>
     */
    public static function invoiceIssueCollisions(): iterable
    {
        foreach (['number, live', 'number, none', 'not_void', 'order lock'] as $branch) {
            yield $branch.', with invoice.view' => [$branch, true];
            yield $branch.', without invoice.view' => [$branch, false];
        }
    }

    #[Test]
    #[DataProvider('invoiceIssueCollisions')]
    public function aConcurrentIssueIsAnsweredByWhatTheShipmentNowHas(string $branch, bool $holdsView): void
    {
        $f = $this->shippedForInvoicing('F');
        $other = $this->shippedForInvoicing('G');
        $next = 'INV-000001';
        self::assertSame(0, $this->countAsOwnerForTenant(self::TENANT_A, 'SELECT count(*) FROM invoice'),
            'Control: an invoice already exists, so the next number is not '.$next.'.');

        [$heldFulfilment, $heldNumber] = match ($branch) {
            'number, live' => [$f['fulfilment'], $next],
            'number, none' => [$other['fulfilment'], $next],
            'not_void', 'order lock' => [$f['fulfilment'], 'INV-000500'],
        };
        // Sales step 7 R2: A holds G's order (or, for `order lock`, F's) -- see the docblock.
        $heldOrder = 'order lock' === $branch ? $f['order'] : $other['order'];
        if ('not_void' === $branch) {
            self::assertNotSame($next, $heldNumber, 'The not_void branch must not also collide on the number.');
        }

        $this->actAs(self::TENANT_A, $holdsView ? DefaultRoles::codesFor(DefaultRoles::SALES_REP) : ['invoice.issue', 'fulfilment.view']);
        $holder = $this->raceAgainstAHolder($heldOrder, [[<<<'SQL'
            INSERT INTO invoice (id, tenant_id, fulfilment_id, sales_order_id, customer_id, invoice_number,
                                 currency_code, total_amount, due_at, issued_by_user_id)
            SELECT ?::uuid, f.tenant_id, f.id, so.id, so.customer_id, ?, so.currency_code, 1,
                   now() + interval '30 days', ?::uuid
              FROM fulfilment f JOIN sales_order so ON so.tenant_id = f.tenant_id AND so.id = f.sales_order_id
             WHERE f.id = ?::uuid
            SQL, [Uuid::v4()->toRfc4122(), $heldNumber, (string) $this->actorId, $heldFulfilment]]],
            fn () => $this->send('POST', '/fulfilments/'.$f['fulfilment'].'/invoices', null,
                [IdempotencyKeyGuard::HEADER => Uuid::v4()->toRfc4122()]),
            table: 'sales_order',
        );

        $this->assertTheHolderWasWaitedOnAndCommitted($holder);
        $raw = (string) $this->client()->getResponse()->getContent();
        self::assertSame(409, $this->httpStatus(), $raw);

        if ('number, none' === $branch) {
            self::assertSame('invoice.invoice_number_is_unique_per_workspace', $this->json()['rule']);
            self::assertSame('Another invoice was issued at the same moment. Try again.', $this->json()['detail']);
            self::assertStringNotContainsString('INV-', $raw, 'The "try again" answer named an invoice.');
        } else {
            self::assertSame('invoice.fulfilment_is_not_already_invoiced', $this->json()['rule']);
            if ($holdsView) {
                self::assertStringContainsString('It is '.$heldNumber.'.', $this->json()['detail']);
            } else {
                self::assertStringNotContainsString($heldNumber, $raw, 'A caller without invoice.view was told the number (R-14).');
            }
        }

        self::assertSame(1, $this->countAsOwnerForTenant(self::TENANT_A, 'SELECT count(*) FROM invoice'), 'The loser\'s invoice survived.');
        self::assertSame(0, $this->countAsOwnerForTenant(self::TENANT_A, "SELECT count(*) FROM audit_log WHERE event_type = 'invoice.issued'"),
            'The loser wrote a trail row.');
    }

    /**
     * **Pay ∥ pay (sales step 6, C5): two clerks record the same payment.** Connection A takes the invoice's lock and,
     * under it, records a payment as `erp_app` would (the four granted columns). The real `PayInvoice` must wait on
     * `InvoiceRowLock`, re-read the invoice under it, find it `paid` and be refused by the status guard — never a
     * second `invoice.paid`, never a recorder overwritten. Without the lock the request would read `issued`, pass the
     * guard and overwrite A's `paid_by` with its own caller. (Pay ∥ void is `aPaymentAndAVoidRaceAndTheSecondIsRefusedByTheStatusGuard`, C7.)
     */
    #[Test]
    public function aSecondPaymentWaitsForTheFirstAndIsRefusedByTheStatusGuard(): void
    {
        $i = $this->issuedInvoice('RACE-PAY');
        $first = $this->actAsRole(DefaultRoles::FINANCE);
        $this->actAsRole(DefaultRoles::FINANCE);

        $holder = $this->raceAgainstAHolder($i['invoice'], before: [
            ["UPDATE invoice SET status = 'paid', paid_at = now(), paid_by_user_id = ?::uuid, updated_at = now() WHERE id = ?::uuid", [$first, $i['invoice']]],
        ], request: fn () => $this->send('POST', '/invoices/'.$i['invoice'].'/payment', []), table: 'invoice');

        $this->assertTheHolderWasWaitedOnAndCommitted($holder);
        self::assertSame(409, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        self::assertSame('invoice.'.self::STATUS_RULE, $this->json()['rule']);
        self::assertSame([['p' => $first]], $this->fetchAsOwnerForTenant(self::TENANT_A,
            'SELECT paid_by_user_id::text AS p FROM invoice WHERE id = ?::uuid', [$i['invoice']]),
            'The second payment overwrote the first recorder.');
        self::assertSame(0, $this->countAsOwnerForTenant(self::TENANT_A,
            'SELECT count(*) FROM audit_log WHERE event_type = ?', [AuditEvent::INVOICE_PAID]),
            'The refused payment wrote a trail row (A\'s raw UPDATE writes none).');
    }

    /**
     * **Reverse ∥ reverse (sales step 6, C6): two colleagues undo the same payment.** A takes the invoice's lock and,
     * under it, reverses as `erp_app` would (the ORM's four columns, then B′'s `COALESCE`). The real
     * `ReverseInvoicePayment` must wait, re-read the invoice as `issued`, and be refused by the status guard — one
     * `invoice.payment_reversed` row (A's is raw SQL and writes none, so zero), B′'s column untouched by the loser.
     * (Pay ∥ reverse cannot race on one source: a reversal needs `paid`, a payment needs `issued` or `overdue`.)
     */
    #[Test]
    public function aSecondReversalWaitsForTheFirstAndIsRefusedByTheStatusGuard(): void
    {
        $i = $this->issuedInvoice('RACE-REV');
        $this->actAsRole(DefaultRoles::FINANCE);
        $this->send('POST', '/invoices/'.$i['invoice'].'/payment', []);
        self::assertSame(200, $this->httpStatus(), 'Fixture: the payment was refused.');
        $this->actAsRole(DefaultRoles::FINANCE);

        $holder = $this->raceAgainstAHolder($i['invoice'], before: [
            ["UPDATE invoice SET status = 'issued', paid_at = NULL, paid_by_user_id = NULL, updated_at = now() WHERE id = ?::uuid", [$i['invoice']]],
            ['UPDATE invoice SET payment_reversed_at = COALESCE(payment_reversed_at, now()) WHERE id = ?::uuid', [$i['invoice']]],
        ], request: fn () => $this->send('POST', '/invoices/'.$i['invoice'].'/payment-reversal', ['reason' => 'Concurrent']), table: 'invoice');

        $this->assertTheHolderWasWaitedOnAndCommitted($holder);
        self::assertSame(409, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        self::assertSame('invoice.'.self::STATUS_RULE, $this->json()['rule']);
        self::assertSame(0, $this->countAsOwnerForTenant(self::TENANT_A,
            'SELECT count(*) FROM audit_log WHERE event_type = ?', [AuditEvent::INVOICE_PAYMENT_REVERSED]),
            'The refused reversal wrote a trail row.');
    }

    /**
     * **Pay ∥ void (sales step 6, C7), both commit orders.** A takes the invoice's lock and, under it, either records a
     * payment or voids the invoice as `erp_app` would; the real request for the OTHER act must wait, re-read the invoice
     * under the lock and be refused by the status guard — a paid invoice is not voidable, a void one is not payable
     * (§8.3). The loser writes no trail row (A's are raw SQL and write none, so zero).
     *
     * @return iterable<string, array{string, string, string, string}>
     */
    public static function payAndVoidRaces(): iterable
    {
        yield 'A pays, the void waits' => ['pay', '/void', DefaultRoles::OWNER, AuditEvent::INVOICE_VOIDED];
        yield 'A voids, the payment waits' => ['void', '/payment', DefaultRoles::FINANCE, AuditEvent::INVOICE_PAID];
    }

    #[Test]
    #[DataProvider('payAndVoidRaces')]
    public function aPaymentAndAVoidRaceAndTheSecondIsRefusedByTheStatusGuard(string $holderAct, string $action, string $role, string $loserEvent): void
    {
        $i = $this->issuedInvoice('RACE-PV-'.$holderAct);
        $this->actAsRole($role);

        $before = 'pay' === $holderAct
            ? [["UPDATE invoice SET status = 'paid', paid_at = now(), paid_by_user_id = ?::uuid, updated_at = now() WHERE id = ?::uuid", [$i['issuer'], $i['invoice']]]]
            : [["UPDATE invoice SET status = 'void', updated_at = now() WHERE id = ?::uuid", [$i['invoice']]]];
        $body = '/void' === $action ? ['reason' => 'Concurrent'] : [];

        $holder = $this->raceAgainstAHolder($i['invoice'], before: $before,
            request: fn () => $this->send('POST', '/invoices/'.$i['invoice'].$action, $body), table: 'invoice');

        $this->assertTheHolderWasWaitedOnAndCommitted($holder);
        self::assertSame(409, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        self::assertSame('invoice.'.self::STATUS_RULE, $this->json()['rule']);
        self::assertSame([['status' => 'pay' === $holderAct ? 'paid' : 'void']], $this->fetchAsOwnerForTenant(self::TENANT_A,
            'SELECT status FROM invoice WHERE id = ?::uuid', [$i['invoice']]));
        self::assertSame(0, $this->countAsOwnerForTenant(self::TENANT_A,
            'SELECT count(*) FROM audit_log WHERE event_type = ?', [$loserEvent]), 'The refused request wrote a trail row.');
    }

    /**
     * **Reverse ∥ void on a PAID invoice (sales step 6, C7; B′).** A takes the lock and reverses the payment as
     * `erp_app` would (the ORM's four columns, then B′'s `COALESCE`). The real `VoidInvoice` waits, re-reads the invoice
     * as `issued` WITH `payment_reversed_at` set, and so: a marker-less delegate is refused by B′; the owner succeeds.
     * Without the lock-then-refresh the void would have read `paid` and been refused by the status guard for the wrong
     * reason — or, from a stale read, voided a reversed invoice as a delegate.
     *
     * @return iterable<string, array{list<string>, int}>
     */
    public static function reverseAndVoidRaces(): iterable
    {
        yield 'a marker-less delegate' => [['invoice.view', 'invoice.void'], 409];
        yield 'the owner' => [DefaultRoles::codesFor(DefaultRoles::OWNER), 200];
    }

    /** @param list<string> $voiderCodes */
    #[Test]
    #[DataProvider('reverseAndVoidRaces')]
    public function aVoidWaitingOnAReversalMeetsBPrimeUnderTheLock(array $voiderCodes, int $expected): void
    {
        $i = $this->issuedInvoice('RACE-RV-'.$expected);
        $this->actAsRole(DefaultRoles::FINANCE);
        $this->send('POST', '/invoices/'.$i['invoice'].'/payment', []);
        self::assertSame(200, $this->httpStatus(), 'Fixture: the payment was refused.');
        $this->actAs(self::TENANT_A, $voiderCodes);

        $holder = $this->raceAgainstAHolder($i['invoice'], before: [
            ["UPDATE invoice SET status = 'issued', paid_at = NULL, paid_by_user_id = NULL, updated_at = now() WHERE id = ?::uuid", [$i['invoice']]],
            ['UPDATE invoice SET payment_reversed_at = COALESCE(payment_reversed_at, now()) WHERE id = ?::uuid', [$i['invoice']]],
        ], request: fn () => $this->send('POST', '/invoices/'.$i['invoice'].'/void', ['reason' => 'Concurrent']), table: 'invoice');

        $this->assertTheHolderWasWaitedOnAndCommitted($holder);
        self::assertSame($expected, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        if (409 === $expected) {
            self::assertSame('invoice.is_not_payment_reversed', $this->json()['rule']);
        }
        self::assertSame([['status' => 409 === $expected ? 'issued' : 'void', 'r' => true]], $this->fetchAsOwnerForTenant(self::TENANT_A,
            'SELECT status, payment_reversed_at IS NOT NULL AS r FROM invoice WHERE id = ?::uuid', [$i['invoice']]));
        self::assertSame(409 === $expected ? 0 : 1, $this->countAsOwnerForTenant(self::TENANT_A,
            'SELECT count(*) FROM audit_log WHERE event_type = ?', [AuditEvent::INVOICE_VOIDED]));
    }

    /**
     * **A void in flight ∥ a re-issue of the same shipment (sales step 6, C7).** A takes the invoice's lock and voids it
     * WITHOUT committing. `IssueInvoice` takes no invoice lock (`INVOICE_ALLOWED_WITHOUT_A_LOCK`): its pre-check reads
     * the COMMITTED state, finds a live invoice and refuses 409 at once — it never waits on A, so A sees no waiter and
     * rolls back, which is the proof that nothing waited. After a void that DOES commit (through the API), a sequential
     * re-issue succeeds under a new number. (Had the pre-check been absent, the INSERT would have waited on A's index
     * entry for `invoice_tenant_fulfilment_not_void` and succeeded after A's commit — a re-issue racing a void that might
     * yet roll back.)
     */
    #[Test]
    public function aReissueDoesNotWaitOnAVoidInFlightAndSucceedsAfterItCommits(): void
    {
        $i = $this->issuedInvoice('RACE-VR');
        $this->actAsRole(DefaultRoles::SALES_REP);

        $holder = $this->raceAgainstAHolder($i['invoice'], before: [
            ["UPDATE invoice SET status = 'void', updated_at = now() WHERE id = ?::uuid", [$i['invoice']]],
        ], request: fn () => $this->send('POST', '/fulfilments/'.$i['shipment']['fulfilment'].'/invoices', null,
            ['Idempotency-Key' => Uuid::v4()->toRfc4122()]), table: 'invoice');

        self::assertSame(409, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        self::assertSame('invoice.fulfilment_is_not_already_invoiced', $this->json()['rule']);
        self::assertFalse($holder['waiter_seen'], 'The re-issue waited on the void in flight: '.json_encode($holder));
        self::assertFalse($holder['committed'], 'The holder committed with no waiter: '.json_encode($holder));
        self::assertSame(1, $this->countAsOwnerForTenant(self::TENANT_A, 'SELECT count(*) FROM invoice'));

        $this->actAsRole(DefaultRoles::OWNER);
        $this->send('POST', '/invoices/'.$i['invoice'].'/void', ['reason' => 'Committed now.']);
        self::assertSame(200, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        $this->actAsRole(DefaultRoles::SALES_REP);
        $this->send('POST', '/fulfilments/'.$i['shipment']['fulfilment'].'/invoices', null, ['Idempotency-Key' => Uuid::v4()->toRfc4122()]);
        self::assertSame(201, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        self::assertNotSame($i['number'], $this->json()['invoice_number']);
    }

    /**
     * **The invoice half of the position check: in every invoice write path the invoice's row lock is the first
     * statement that touches the invoice** (sales step 6, C5). Supplement, not evidence on its own — the race above
     * proves the behaviour. The lock is spelled here independently of `InvoiceRowLock::SQL`.
     *
     * @param array<string, string>|null $body
     */
    #[Test]
    #[DataProvider('everyInvoiceWritePath')]
    public function theInvoiceRowLockIsTheFirstStatementToTouchTheInvoice(string $method, string $path, ?array $body, int $status, string $actAs): void
    {
        $i = $this->issuedInvoice('LOCK-INV');
        if (str_contains($path, '{paid}')) {
            $path = str_replace('{paid}', '', $path);
            $this->actAsRole(DefaultRoles::FINANCE);
            $this->send('POST', '/invoices/'.$i['invoice'].'/payment', []);
            self::assertSame(200, $this->httpStatus(), 'Fixture: the payment was refused.');
        }
        $this->actAsRole($actAs);

        $this->recorder()->start();
        $this->send($method, strtr($path, ['{invoice}' => $i['invoice']]), $body);
        $this->recorder()->stop();

        self::assertSame($status, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());

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
            '/^\s*UPDATE\s+invoice\b/i', $statements,
        )));
        self::assertCount(1, $writing, 'Expected exactly one transaction writing the invoice.');
        // Every write of the invoice in that transaction comes after the lock, not only the first touch.

        $touching = array_values(preg_grep('/\binvoice\b/', $writing[0]));
        self::assertMatchesRegularExpression(
            '/^SELECT 1 FROM invoice WHERE id = \S+ FOR NO KEY UPDATE$/',
            $touching[0],
            "The first statement to touch the invoice is not its row lock. Statements, in order:\n".implode("\n", $touching),
        );
    }

    /** @return iterable<string, array{string, string, array<string, string>|null, int, string}> */
    public static function everyInvoiceWritePath(): iterable
    {
        // The invoice is issued through the API by a `sales_rep`; `finance` records the payment.
        yield 'PayInvoice' => ['POST', '/invoices/{invoice}/payment', [], 200, DefaultRoles::FINANCE];
        // `{paid}`: the invoice is first PAID through the API by another `finance` user, so the reversal really writes
        // (a refused drive issues no write). Two `UPDATE invoice` statements, one transaction.
        yield 'ReverseInvoicePayment' => ['POST', '/invoices/{invoice}/payment-reversal{paid}', ['reason' => 'Lock position'], 200, DefaultRoles::FINANCE];
        // The owner voids (`invoice.void` is `owner`'s alone among the default roles since A2.1a). One `UPDATE invoice`.
        yield 'VoidInvoice' => ['POST', '/invoices/{invoice}/void', ['reason' => 'Lock position'], 200, DefaultRoles::OWNER];
    }

    /**
     * **Return ∥ issue on one shipment serialise on the ORDER lock — deducted xor creditable, never both, never
     * neither** (sales step 7 R2; design §4.3 and §13 item 8; `ARCH` §6(c); `OwedAmendmentTripwireTest`'s
     * returned-deduction carrier). Both commit orders:
     *
     *   - `return first`: A holds F's order lock and, under it, records a DEDUCTING return of 0.5 (NULL stamp) as
     *     `CreateSalesReturn` would. The real `IssueInvoice` must WAIT on the order lock, then read the committed
     *     return and bill `round((1 − 0.5) × 2.5, 4) = 1.2500`. Without the lock it would read nothing, bill 2.5000,
     *     and leave a deducting return its invoice never deducted — neither deducted nor creditable.
     *   - `issue first`: A holds F's order lock and issues F's invoice under it. The real `CreateSalesReturn`, by a
     *     second warehouse person (who would deduct before invoicing), must WAIT, then find the live invoice: the
     *     return is stamped with it and does NOT deduct. Without the lock it would stamp NULL and deduct an invoice that
     *     already billed the goods.
     *
     * @return iterable<string, array{string}>
     */
    public static function returnAndIssueOrders(): iterable
    {
        yield 'return first' => ['return first'];
        yield 'issue first' => ['issue first'];
    }

    #[Test]
    #[DataProvider('returnAndIssueOrders')]
    public function aReturnAndAnIssueOfOneShipmentSerialiseOnTheOrderLock(string $order): void
    {
        $f = $this->shippedForInvoicing('RI');
        $shippedLine = (string) $this->fetchAsOwnerForTenant(self::TENANT_A,
            'SELECT id::text AS id FROM fulfilment_line WHERE fulfilment_id = ?::uuid', [$f['fulfilment']])[0]['id'];
        $bin = (string) $this->fetchAsOwnerForTenant(self::TENANT_A,
            'SELECT storage_location_id::text AS b FROM fulfilment_line WHERE id = ?::uuid', [$shippedLine])[0]['b'];
        $returnId = Uuid::v4()->toRfc4122();
        $invoiceId = Uuid::v4()->toRfc4122();

        if ('return first' === $order) {
            $this->actAs(self::TENANT_A, DefaultRoles::codesFor(DefaultRoles::SALES_REP));
            $holder = $this->raceAgainstAHolder($f['order'], [
                [<<<'SQL'
                    INSERT INTO sales_return (id, tenant_id, fulfilment_id, invoice_id, sales_return_number, reason, returned_at,
                                              recorded_by_user_id, deducts, idempotency_key, request_digest)
                    VALUES (?::uuid, ?::uuid, ?::uuid, NULL, 'RET-000001', 'other', now(), ?::uuid, true,
                            encode(sha256(gen_random_uuid()::text::bytea), 'hex'), encode(sha256(gen_random_uuid()::text::bytea), 'hex'))
                    SQL, [$returnId, self::TENANT_A, $f['fulfilment'], (string) $this->actorId]],
                ['INSERT INTO sales_return_line (id, tenant_id, sales_return_id, fulfilment_line_id, storage_location_id, quantity) VALUES (?::uuid, ?::uuid, ?::uuid, ?::uuid, ?::uuid, 0.5)',
                    [Uuid::v4()->toRfc4122(), self::TENANT_A, $returnId, $shippedLine, $bin]],
            ], fn () => $this->send('POST', '/fulfilments/'.$f['fulfilment'].'/invoices', null,
                [IdempotencyKeyGuard::HEADER => Uuid::v4()->toRfc4122()]), table: 'sales_order');

            $this->assertTheHolderWasWaitedOnAndCommitted($holder);
            self::assertSame(201, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
            self::assertSame('1.2500', $this->json()['total_amount'], 'The invoice did not deduct the return committed before it.');

            return;
        }

        $this->actAs(self::TENANT_A, DefaultRoles::codesFor(DefaultRoles::WAREHOUSE_OPERATOR));
        $holder = $this->raceAgainstAHolder($f['order'], [[<<<'SQL'
            INSERT INTO invoice (id, tenant_id, fulfilment_id, sales_order_id, customer_id, invoice_number,
                                 currency_code, total_amount, due_at, issued_by_user_id)
            SELECT ?::uuid, f.tenant_id, f.id, so.id, so.customer_id, 'INV-000001', so.currency_code, 2.5,
                   now() + interval '30 days', ?::uuid
              FROM fulfilment f JOIN sales_order so ON so.tenant_id = f.tenant_id AND so.id = f.sales_order_id
             WHERE f.id = ?::uuid
            SQL, [$invoiceId, (string) $this->actorId, $f['fulfilment']]]],
            fn () => $this->send('POST', '/fulfilments/'.$f['fulfilment'].'/sales-returns', [
                'reason' => 'other',
                'lines' => [['fulfilment_line_id' => $shippedLine, 'quantity' => '0.5', 'storage_location_id' => $bin]],
            ], [IdempotencyKeyGuard::HEADER => Uuid::v4()->toRfc4122()]),
            table: 'sales_order',
        );

        $this->assertTheHolderWasWaitedOnAndCommitted($holder);
        self::assertSame(201, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        self::assertSame($invoiceId, $this->json()['invoice']['id'], 'The return was not stamped with the invoice committed before it.');
        self::assertFalse($this->json()['deducts'], 'A return recorded after the invoice deducted it as well.');
    }

    /**
     * **W-5's cross-flow test for the THIRD edge — a return and a fulfilment over TWO shared stock levels never
     * deadlock** (sales step 7 R2; design §7; `ARCH`'s O-10 note: "Owed at R2: a return ∥ fulfilment run over two
     * shared levels, red under a descending returner"). `aFulfilmentAndAReceiptOverTwoSharedLevelsNeverDeadlock`'s
     * shape, with the roles of the two flows swapped: connection A (out of process) takes ANOTHER order's lock and
     * decrements L1 with the depletion's conditional statement (a hand-written copy of `StockDepleter`'s), waits until
     * the real `CreateSalesReturn` is blocked on L1, then decrements L2 and commits. The real return ascends, so it
     * blocks on L1 behind A; A takes L2 unopposed. 201, A committed, no `40P01`. **Under a descending `StockReturner`**
     * the return takes L2 first, then waits on L1 (A), and A's L2 waits on it: a cycle → `40P01`. ⚠ A is a copy of
     * `StockDepleter`'s lock order, not `StockDepleter`: O-10 stays "asserted, plus runs".
     */
    #[Test]
    public function aReturnAndAFulfilmentOverTwoSharedLevelsNeverDeadlock(): void
    {
        $f = $this->confirmedSalesOrderWithStock('SO-232000', '4', ['10', '10']);
        [$l1, $l2] = $f['bins'];
        $this->actAs(self::TENANT_A);
        $this->send('POST', '/sales-orders/'.$f['order'].'/fulfilments', ['lines' => [
            ['sales_order_line_id' => $f['line'], 'quantity' => '2', 'storage_location_id' => $l1],
            ['sales_order_line_id' => $f['line'], 'quantity' => '2', 'storage_location_id' => $l2],
        ]], [IdempotencyKeyGuard::HEADER => Uuid::v4()->toRfc4122()]);
        self::assertSame(201, $this->httpStatus(), 'Fixture: the shipment. '.(string) $this->client()->getResponse()->getContent());
        $shipped = [];
        foreach ($this->json()['lines'] as $line) {
            $shipped[$line['storage_location']['id']] = $line['id'];
        }
        $other = $this->seedSalesOrder(self::TENANT_A, 'SO-233000', status: SalesOrder::STATUS_CONFIRMED, orderedAt: '2026-09-20T10:00:00Z');

        $deplete = 'UPDATE stock_level SET qty_on_hand = qty_on_hand - ?::numeric, version = version + 1, updated_at = now()
                     WHERE inventory_item_id = ?::uuid AND storage_location_id = ?::uuid AND qty_on_hand >= ?::numeric';

        $holder = $this->raceAgainstAHolder(
            $other,
            [[$deplete, ['1', $f['item'], $l1, '1']]],
            fn () => $this->send('POST', '/fulfilments/'.$this->fulfilmentOf($f['order']).'/sales-returns', [
                'reason' => 'other',
                'lines' => [
                    ['fulfilment_line_id' => $shipped[$l2], 'quantity' => '1', 'storage_location_id' => $l2],
                    ['fulfilment_line_id' => $shipped[$l1], 'quantity' => '1', 'storage_location_id' => $l1],
                ],
            ], [IdempotencyKeyGuard::HEADER => Uuid::v4()->toRfc4122()]),
            after: [[$deplete, ['1', $f['item'], $l2, '1']]],
            table: 'sales_order',
        );

        self::assertNull($holder['sqlstate'], 'The holder failed -- a 40P01 here is the deadlock W-5 exists to prevent: '.json_encode($holder));
        $this->assertTheHolderWasWaitedOnAndCommitted($holder);
        self::assertSame(201, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        self::assertSame([['q' => '8.000000'], ['q' => '8.000000']], $this->fetchAsOwnerForTenant(self::TENANT_A,
            'SELECT qty_on_hand::text AS q FROM stock_level WHERE storage_location_id IN (?::uuid, ?::uuid) ORDER BY storage_location_id', [$l1, $l2]),
            'Each level should be 10 - 2 shipped - 1 depleted by A + 1 returned.');
    }

    /**
     * **V2-R-17 under the lock — void ∥ credit on one invoice, both commit orders: exactly one succeeds** (sales step 7
     * C1; design §4.4 step 3 / §13 item 8; the former `OwedAmendmentTripwireTest` credit-fields item 1).
     *   - `credit first`: A holds the invoice lock and inserts a credit note under it (as `IssueCreditNote` would). The
     *     real `VoidInvoice` waits on the lock, re-reads, finds the credit: 409 `invoice.is_not_credited`.
     *   - `void first`: A holds the invoice lock and voids it. The real `IssueCreditNote` waits, re-reads: 409
     *     `invoice.is_not_void`.
     * Mutations (run): drop either check → its order goes red.
     *
     * @return iterable<string, array{string}>
     */
    public static function voidAndCreditOrders(): iterable
    {
        yield 'credit first' => ['credit first'];
        yield 'void first' => ['void first'];
    }

    #[Test]
    #[DataProvider('voidAndCreditOrders')]
    public function aVoidAndACreditOfOneInvoiceRaceAndExactlyOneSucceeds(string $order): void
    {
        $i = $this->invoicedShipment('RACE-VC-'.$order, [['4', '10.0000', '2']]);
        $this->actAsRole(DefaultRoles::OWNER);
        $return = $this->returnOf($i, [[0, '1']]);
        $note = Uuid::v4()->toRfc4122();

        if ('credit first' === $order) {
            $holder = $this->raceAgainstAHolder($i['invoice'], before: [[<<<'SQL'
                INSERT INTO credit_note (id, tenant_id, invoice_id, sales_return_id, credit_note_number, reason, currency_code,
                                         total_amount, issued_by_user_id, idempotency_key, request_digest)
                SELECT ?::uuid, inv.tenant_id, inv.id, ?::uuid, 'CN-000001', 'goods_returned', inv.currency_code, 10, ?::uuid,
                       encode(sha256(gen_random_uuid()::text::bytea), 'hex'), encode(sha256(gen_random_uuid()::text::bytea), 'hex')
                  FROM invoice inv WHERE inv.id = ?::uuid
                SQL, [$note, $return, (string) $this->actorId, $i['invoice']]]],
                request: fn () => $this->send('POST', '/invoices/'.$i['invoice'].'/void', ['reason' => 'Concurrent']), table: 'invoice');

            $this->assertTheHolderWasWaitedOnAndCommitted($holder);
            self::assertSame([409, 'invoice.is_not_credited'], [$this->httpStatus(), $this->json()['rule'] ?? null], (string) $this->client()->getResponse()->getContent());
            self::assertSame([['status' => 'issued']], $this->fetchAsOwnerForTenant(self::TENANT_A, 'SELECT status FROM invoice WHERE id = ?::uuid', [$i['invoice']]));

            return;
        }

        $this->actAsRole(DefaultRoles::FINANCE);
        $holder = $this->raceAgainstAHolder($i['invoice'], before: [
            ["UPDATE invoice SET status = 'void', updated_at = now() WHERE id = ?::uuid", [$i['invoice']]],
        ], request: fn () => $this->credit($i, $return, [[0, '1']]), table: 'invoice');

        $this->assertTheHolderWasWaitedOnAndCommitted($holder);
        self::assertSame([409, 'invoice.is_not_void'], [$this->httpStatus(), $this->json()['rule'] ?? null], (string) $this->client()->getResponse()->getContent());
        self::assertSame(0, $this->countAsOwnerForTenant(self::TENANT_A, 'SELECT count(*) FROM credit_note'));
    }

    /**
     * **Credit ∥ credit on one shipped line: the bound is never exceeded** (§13 item 8). A holds the invoice lock and
     * credits the whole returned quantity under it; the real `IssueCreditNote` for the same return waits, re-reads what
     * is now credited, and is refused (`credit_note.quantity_within_billed`, step 7's first bound). Without the lock both would read nothing
     * credited and both commit.
     */
    #[Test]
    public function twoCreditsOfOneLineSerialiseAndTheSecondIsBounded(): void
    {
        $i = $this->invoicedShipment('RACE-CC', [['4', '10.0000', '2']]);
        $this->actAsRole(DefaultRoles::OWNER);
        $return = $this->returnOf($i, [[0, '2']]);
        $returnLine = (string) $this->fetchAsOwnerForTenant(self::TENANT_A, 'SELECT id::text AS l FROM sales_return_line WHERE sales_return_id = ?::uuid', [$return])[0]['l'];
        $note = Uuid::v4()->toRfc4122();
        $this->actAsRole(DefaultRoles::FINANCE);

        $holder = $this->raceAgainstAHolder($i['invoice'], before: [
            [<<<'SQL'
                INSERT INTO credit_note (id, tenant_id, invoice_id, sales_return_id, credit_note_number, reason, currency_code,
                                         total_amount, issued_by_user_id, idempotency_key, request_digest)
                SELECT ?::uuid, inv.tenant_id, inv.id, ?::uuid, 'CN-900001', 'goods_returned', inv.currency_code, 20, ?::uuid,
                       encode(sha256(gen_random_uuid()::text::bytea), 'hex'), encode(sha256(gen_random_uuid()::text::bytea), 'hex')
                  FROM invoice inv WHERE inv.id = ?::uuid
                SQL, [$note, $return, (string) $this->actorId, $i['invoice']]],
            ['INSERT INTO credit_note_line (id, tenant_id, credit_note_id, fulfilment_line_id, sales_return_line_id, quantity, amount) VALUES (?::uuid, ?::uuid, ?::uuid, ?::uuid, ?::uuid, 2, 20)',
                [Uuid::v4()->toRfc4122(), self::TENANT_A, $note, $i['lines'][0], $returnLine]],
        ], request: fn () => $this->credit($i, $return, [[0, '1']]), table: 'invoice');

        $this->assertTheHolderWasWaitedOnAndCommitted($holder);
        // Both bounds now fail (the return line and the shipped line are both used up); the billed bound is step 7's first.
        self::assertSame([409, 'credit_note.quantity_within_billed'], [$this->httpStatus(), $this->json()['rule'] ?? null], (string) $this->client()->getResponse()->getContent());
        self::assertSame(1, $this->countAsOwnerForTenant(self::TENANT_A, 'SELECT count(*) FROM credit_note'));
    }

    /**
     * **Return ∥ pay on one invoice serialise on the ORDER lock** (sales step 7 C1; design §4.6 (0); RED-3 N-2). Both
     * commit orders:
     *   - `return first`: A holds the shipment's order lock and records a creditable return under it (stamped with the
     *     live invoice, non-deducting). The real `PayInvoice` WAITS on the order lock — which it now takes before the
     *     invoice lock — then sees the return: 409 `invoice.has_no_uncredited_return`. Without the order lock it would
     *     read no return and commit `paid` beside an uncredited one.
     *   - `pay first`: A holds the order lock and records the payment under it. The real `CreateSalesReturn` waits, then
     *     is stamped with a PAID invoice: not creditable, `credit_blocked_by_payment` true to finance (G-23's queue).
     *
     * @return iterable<string, array{string}>
     */
    public static function returnAndPayOrders(): iterable
    {
        yield 'return first' => ['return first'];
        yield 'pay first' => ['pay first'];
    }

    #[Test]
    #[DataProvider('returnAndPayOrders')]
    public function aReturnAndAPaymentOfOneInvoiceSerialiseOnTheOrderLock(string $order): void
    {
        $i = $this->invoicedShipment('RACE-RP-'.$order, [['4', '10.0000', '2']]);

        if ('return first' === $order) {
            $returnId = Uuid::v4()->toRfc4122();
            $this->actAsRole(DefaultRoles::FINANCE);
            $holder = $this->raceAgainstAHolder($i['order'], before: [
                [<<<'SQL'
                    INSERT INTO sales_return (id, tenant_id, fulfilment_id, invoice_id, sales_return_number, reason, returned_at,
                                              recorded_by_user_id, deducts, idempotency_key, request_digest)
                    VALUES (?::uuid, ?::uuid, ?::uuid, ?::uuid, 'RET-900001', 'other', now(), ?::uuid, false,
                            encode(sha256(gen_random_uuid()::text::bytea), 'hex'), encode(sha256(gen_random_uuid()::text::bytea), 'hex'))
                    SQL, [$returnId, self::TENANT_A, $i['fulfilment'], $i['invoice'], (string) $this->actorId]],
                ['INSERT INTO sales_return_line (id, tenant_id, sales_return_id, fulfilment_line_id, storage_location_id, quantity) VALUES (?::uuid, ?::uuid, ?::uuid, ?::uuid, ?::uuid, 1)',
                    [Uuid::v4()->toRfc4122(), self::TENANT_A, $returnId, $i['lines'][0], $i['bin']]],
            ], request: fn () => $this->send('POST', '/invoices/'.$i['invoice'].'/payment', []), table: 'sales_order');

            $this->assertTheHolderWasWaitedOnAndCommitted($holder);
            self::assertSame([409, 'invoice.has_no_uncredited_return'], [$this->httpStatus(), $this->json()['rule'] ?? null], (string) $this->client()->getResponse()->getContent());
            self::assertSame([['status' => 'issued']], $this->fetchAsOwnerForTenant(self::TENANT_A, 'SELECT status FROM invoice WHERE id = ?::uuid', [$i['invoice']]));

            return;
        }

        $payer = $this->actAsRole(DefaultRoles::FINANCE);
        $this->actAsRole(DefaultRoles::OWNER);
        $holder = $this->raceAgainstAHolder($i['order'], before: [
            ["UPDATE invoice SET status = 'paid', paid_at = now(), paid_by_user_id = ?::uuid, updated_at = now() WHERE id = ?::uuid", [$payer, $i['invoice']]],
        ], request: fn () => $this->send('POST', '/fulfilments/'.$i['fulfilment'].'/sales-returns', ['reason' => 'other', 'lines' => [
            ['fulfilment_line_id' => $i['lines'][0], 'quantity' => '1', 'storage_location_id' => $i['bin']],
        ]], ['Idempotency-Key' => Uuid::v4()->toRfc4122()]), table: 'sales_order');

        $this->assertTheHolderWasWaitedOnAndCommitted($holder);
        self::assertSame(201, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        self::assertSame($i['invoice'], $this->json()['invoice']['id']);
        self::assertNull($this->json()['credit_target']);
        self::assertTrue($this->json()['credit_blocked_by_payment']);
    }

    /**
     * **The 23505 translations, each reached by a race across TWO invoices** (design §4.4 step 10a; RED M-4): credits on
     * different invoices hold different locks, so only the unique indexes answer.
     *   - the key: A holds invoice 1 and inserts a credit note keyed `K`, uncommitted; the real credit on invoice 2 with
     *     `K` finds no replay, blocks on `credit_note_idem_unique`, and when A commits is told `idempotency-key-conflict`;
     *   - the number: A inserts `CN-000001` on invoice 1, uncommitted; the real credit on invoice 2 mints the same number
     *     from the committed maximum, blocks on `credit_note_number_unique`, and is told
     *     `credit_note.credit_note_number_is_unique_per_workspace`.
     * Mutation (run): drop either by-name mapping → a 500.
     *
     * @return iterable<string, array{string}>
     */
    public static function creditUniqueRaces(): iterable
    {
        yield 'the key' => ['key'];
        yield 'the number' => ['number'];
    }

    #[Test]
    #[DataProvider('creditUniqueRaces')]
    public function theLoserOfARaceAcrossTwoInvoicesIsToldWhichUniqueRefusedIt(string $index): void
    {
        $first = $this->invoicedShipment('RACE-CU1-'.$index, [['4', '10.0000', '2']]);
        $this->actAsRole(DefaultRoles::OWNER);
        $firstReturn = $this->returnOf($first, [[0, '1']]);
        $second = $this->invoicedShipment('RACE-CU2-'.$index, [['4', '10.0000', '2']]);
        $this->actAsRole(DefaultRoles::OWNER);
        $secondReturn = $this->returnOf($second, [[0, '1']]);
        $key = Uuid::v4()->toRfc4122();
        $this->actAsRole(DefaultRoles::FINANCE);

        $holder = $this->raceAgainstAHolder($first['invoice'], before: [[<<<'SQL'
            INSERT INTO credit_note (id, tenant_id, invoice_id, sales_return_id, credit_note_number, reason, currency_code,
                                     total_amount, issued_by_user_id, idempotency_key, request_digest)
            SELECT ?::uuid, inv.tenant_id, inv.id, ?::uuid, ?, 'goods_returned', inv.currency_code, 10, ?::uuid, ?, ?
              FROM invoice inv WHERE inv.id = ?::uuid
            SQL, [Uuid::v4()->toRfc4122(), $firstReturn, 'key' === $index ? 'CN-999999' : 'CN-000001', (string) $this->actorId,
                'key' === $index ? hash('sha256', $key) : hash('sha256', Uuid::v4()->toRfc4122()), str_repeat('c', 64), $first['invoice']]]],
            request: fn () => $this->credit($second, $secondReturn, [[0, '1']], $key), table: 'invoice');

        $this->assertTheHolderWasWaitedOnAndCommitted($holder);
        self::assertSame(409, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        if ('key' === $index) {
            self::assertStringContainsString('idempotency-key-conflict', (string) $this->json()['type']);
        } else {
            self::assertSame('credit_note.credit_note_number_is_unique_per_workspace', $this->json()['rule']);
        }
        self::assertSame(1, $this->countAsOwnerForTenant(self::TENANT_A, 'SELECT count(*) FROM credit_note'));
    }

    private function fulfilmentOf(string $orderId): string
    {
        return (string) $this->fetchAsOwnerForTenant(self::TENANT_A,
            'SELECT id::text AS id FROM fulfilment WHERE sales_order_id = ?::uuid', [$orderId])[0]['id'];
    }

    /**
     * A confirmed order with one line of 5, shipped 1 through the API; the actor is left holding everything.
     *
     * @return array{order: string, fulfilment: string}
     */
    private function shippedForInvoicing(string $tag): array
    {
        $this->actAs(self::TENANT_A);
        $o = $this->confirmedSalesOrderWithStock(self::freshNumber('SO'), '5', ['5']);
        $this->send('POST', '/sales-orders/'.$o['order'].'/fulfilments',
            ['lines' => [['sales_order_line_id' => $o['line'], 'quantity' => '1', 'storage_location_id' => $o['bins'][0]]]],
            [IdempotencyKeyGuard::HEADER => Uuid::v4()->toRfc4122()]);
        self::assertSame(201, $this->httpStatus(), 'Fixture '.$tag.': '.(string) $this->client()->getResponse()->getContent());

        return ['order' => $o['order'], 'fulfilment' => $this->json()['id']];
    }

    /**
     * **Two orders racing on one bin: exactly one takes the stock, the other is refused in domain terms — never a
     * 23514, never a lost update, never negative stock** (owner ruling R1; design §6's second race). Connection A
     * holds ANOTHER order's lock and, under it, decrements the shared level by 7 with the depletion's own
     * conditional statement (a hand-written copy of `StockDepleter`'s). The real request for 7 of the same bin
     * blocks on the level's row lock, and when A commits, Postgres re-evaluates its `qty_on_hand >= 7` against 3
     * (EvalPlanQual) and matches nothing. **Under M3 (an unconditional decrement) this is a 23514 → 500.**
     *
     * ⚠ It proves the refusal, not lock ORDER: one shared row cannot deadlock. The order proof is the two-row
     * test below.
     */
    #[Test]
    public function twoOrdersRacingOnOneBinCommitOneAndRefuseTheOther(): void
    {
        $f = $this->confirmedSalesOrderWithStock('SO-192000', '7', ['10']);
        $other = $this->seedSalesOrder(self::TENANT_A, 'SO-193000', status: SalesOrder::STATUS_CONFIRMED, orderedAt: '2026-09-20T10:00:00Z');

        $holder = $this->raceAgainstAHolder(
            $other,
            [[
                'UPDATE stock_level SET qty_on_hand = qty_on_hand - ?::numeric, version = version + 1, updated_at = now()
                  WHERE inventory_item_id = ?::uuid AND storage_location_id = ?::uuid AND qty_on_hand >= ?::numeric',
                ['7', $f['item'], $f['bins'][0], '7'],
            ]],
            fn () => $this->send('POST', '/sales-orders/'.$f['order'].'/fulfilments',
                ['lines' => [['sales_order_line_id' => $f['line'], 'quantity' => '7', 'storage_location_id' => $f['bins'][0]]]],
                [IdempotencyKeyGuard::HEADER => Uuid::v4()->toRfc4122()]),
            table: 'sales_order',
        );

        $this->assertTheHolderWasWaitedOnAndCommitted($holder);
        self::assertSame(409, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        self::assertSame('stock_level.on_hand_covers_the_depletion', $this->json()['rule']);
        self::assertStringContainsString('which held 3 when this was checked', $this->json()['detail']);
        self::assertSame([['q' => '3.000000']], $this->fetchAsOwnerForTenant(self::TENANT_A,
            'SELECT qty_on_hand::text AS q FROM stock_level WHERE storage_location_id = ?::uuid', [$f['bins'][0]]));
        self::assertSame(0, $this->countAsOwnerForTenant(self::TENANT_A, 'SELECT count(*) FROM fulfilment'));
    }

    /**
     * **W-5's cross-flow test — a fulfilment and a receipt over TWO shared stock levels never deadlock** (design
     * §6, rebuilt after `RED` R-5: one shared row cannot deadlock under any ordering, so the design's first version
     * could not fail). One item, two bins of one warehouse, levels L1 < L2 in `(inventory_item_id,
     * storage_location_id)` order.
     *
     *  - **Connection A** (out of process): takes a purchase order's lock, then upserts L1 the way
     *    `StockReceiver` does, waits until the real request is blocked on it, then upserts L2, and commits.
     *  - **Connection B** (the real `CreateFulfilment`, over L1 + L2): ascends, so it blocks on L1 behind A; A takes
     *    L2 unopposed and commits; B proceeds. 201, A committed, no `40P01`.
     *  - **Under M4** (`StockDepleter` descending): B takes L2 first, then blocks on L1 (A); A's L2 blocks on B —
     *    a cycle, and Postgres aborts one with `40P01`. RED. (`RED` measured this exact cycle in a scratch database.)
     *
     * ⚠ **What this does NOT prove, in the design's words:** connection A is **a hand-written copy of
     * `StockReceiver`'s lock order**, not `StockReceiver`. It proves the depleter against that copy. If
     * `StockReceiver`'s order drifts, this test stays green. **O-10 stays "asserted, by inspection, plus this run".**
     */
    #[Test]
    public function aFulfilmentAndAReceiptOverTwoSharedLevelsNeverDeadlock(): void
    {
        $f = $this->confirmedSalesOrderWithStock('SO-212000', '4', ['10', '10']);
        [$l1, $l2] = $f['bins']; // ascending: confirmedSalesOrderWithStock() sorts them
        $supplier = $this->seedSupplier(self::TENANT_A, 'Acme W5');
        $po = $this->seedOrder(self::TENANT_A, 'PO-125000', $supplier, $f['warehouse'], status: PurchaseOrder::STATUS_ORDERED);

        $upsert = 'INSERT INTO stock_level (id, tenant_id, inventory_item_id, storage_location_id, qty_on_hand)
                   VALUES (?::uuid, ?::uuid, ?::uuid, ?::uuid, ?::numeric)
                   ON CONFLICT (tenant_id, inventory_item_id, storage_location_id) DO UPDATE
                       SET qty_on_hand = stock_level.qty_on_hand + EXCLUDED.qty_on_hand,
                           version     = stock_level.version + 1,
                           updated_at  = now()';

        $holder = $this->raceAgainstAHolder(
            $po,
            [[$upsert, [Uuid::v4()->toRfc4122(), self::TENANT_A, $f['item'], $l1, '1']]],
            fn () => $this->send('POST', '/sales-orders/'.$f['order'].'/fulfilments', ['lines' => [
                ['sales_order_line_id' => $f['line'], 'quantity' => '2', 'storage_location_id' => $l2],
                ['sales_order_line_id' => $f['line'], 'quantity' => '2', 'storage_location_id' => $l1],
            ]], [IdempotencyKeyGuard::HEADER => Uuid::v4()->toRfc4122()]),
            after: [[$upsert, [Uuid::v4()->toRfc4122(), self::TENANT_A, $f['item'], $l2, '1']]],
        );

        self::assertNull($holder['sqlstate'], 'The holder failed -- a 40P01 here is the deadlock W-5 exists to prevent: '.json_encode($holder));
        $this->assertTheHolderWasWaitedOnAndCommitted($holder);
        self::assertSame(201, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        self::assertSame([['q' => '9.000000'], ['q' => '9.000000']], $this->fetchAsOwnerForTenant(self::TENANT_A,
            'SELECT qty_on_hand::text AS q FROM stock_level WHERE storage_location_id IN (?::uuid, ?::uuid) ORDER BY storage_location_id', [$l1, $l2]),
            'Each level should be 10 + 1 received - 2 shipped.');
    }

    /**
     * **The Sales half of `theOrderRowLockIsTheFirstStatementToTouchTheOrder`: in every sales write path
     * the lock is the first statement that touches the order or its lines.**
     *
     * Supplement, not evidence on its own — the races above prove behaviour for the shapes they drive;
     * this covers position, so a later edit that adds a read above the lock is red here even where no race
     * test happens to reach it. The lock is spelled here independently of `SalesOrderRowLock::SQL`, so a
     * change to the lock's mode (say, to `FOR UPDATE`) is red here too.
     *
     * @param array<string, string>|null $body
     */
    #[Test]
    #[DataProvider('everySalesWritePath')]
    public function theSalesOrderRowLockIsTheFirstStatementToTouchTheOrder(string $method, string $path, ?array $body, int $status): void
    {
        $order = $this->seedConfirmableSalesOrder('SO-000001');
        // `UpdateSalesOrder` is the first sales write path whose body has to name a row. The
        // substitution is done here rather than in the provider because a `#[DataProvider]` is static
        // and cannot seed; `{warehouse}` is spelled in the body the same way `{order}` is in the path.
        $substitutions = [
            '{warehouse}' => $this->seedWarehouse(self::TENANT_A, 'WH-LOCK'),
            // Added 2026-09-21 with `CreateSalesOrderLine`, the same way and for the same reason: a
            // static provider cannot seed, so the item the body names is substituted here.
            '{item}' => $this->seedItem(self::TENANT_A, 'SKU-LOCK'),
        ];
        // `{line}` the same way, for `UpdateSalesOrderLine`: `seedConfirmableSalesOrder()` seeds exactly
        // one line and returns only the order, so the line is read back rather than the helper's
        // signature changed under its three other callers.
        $path = strtr($path, ['{line}' => (string) $this->fetchAsOwnerForTenant(self::TENANT_A,
            'SELECT id::text AS id FROM sales_order_line WHERE sales_order_id = ?::uuid', [$order])[0]['id']]);
        // `{archivedLine}` for `RestoreSalesOrderLine`, whose guard refuses a live row: a refused drive
        // issues no write, and `assertCount(1, $writing)` would fail before the position check ran.
        $archived = $this->seedSalesOrderLine(self::TENANT_A, $order,
            $this->seedItem(self::TENANT_A, 'SKU-LOCK-ARC'), lineNumber: 2);
        $this->insertAsOwnerForTenant(self::TENANT_A,
            'UPDATE sales_order_line SET deleted_at = now() WHERE id = ?::uuid', [$archived]);
        $path = strtr($path, ['{archivedLine}' => $archived]);
        // `{fulfil}` (sales step 5, commit 4): confirm the order and stock a bin of its warehouse for its live
        // line, so `CreateFulfilment`'s drive succeeds and really writes.
        if (str_contains($path, '{fulfil}')) {
            $path = str_replace('{fulfil}', '', $path);
            $line = $this->fetchAsOwnerForTenant(self::TENANT_A,
                'SELECT sol.id::text AS id, sol.inventory_item_id::text AS item, so.warehouse_id::text AS warehouse
                   FROM sales_order_line sol JOIN sales_order so ON so.id = sol.sales_order_id
                  WHERE sol.sales_order_id = ?::uuid AND sol.deleted_at IS NULL', [$order])[0];
            $this->insertAsOwnerForTenant(self::TENANT_A,
                "UPDATE sales_order SET status = ?, ordered_at = now() WHERE id = ?::uuid", [SalesOrder::STATUS_CONFIRMED, $order]);
            $bin = Uuid::v4()->toRfc4122();
            $this->insertAsOwnerForTenant(self::TENANT_A,
                'INSERT INTO storage_location (id, tenant_id, warehouse_id, code) VALUES (?::uuid, ?::uuid, ?::uuid, ?)',
                [$bin, self::TENANT_A, $line['warehouse'], 'LOCK-01']);
            $this->insertAsOwnerForTenant(self::TENANT_A,
                'INSERT INTO stock_level (id, tenant_id, inventory_item_id, storage_location_id, qty_on_hand) VALUES (?::uuid, ?::uuid, ?::uuid, ?::uuid, 5)',
                [Uuid::v4()->toRfc4122(), self::TENANT_A, $line['item'], $bin]);
            $substitutions['{line}'] = $line['id'];
            $substitutions['{bin}'] = $bin;
        }
        if (null !== $body) {
            array_walk_recursive($body, static function (mixed &$value) use ($substitutions): void {
                $value = \is_string($value) ? strtr($value, $substitutions) : $value;
            });
        }

        $this->recorder()->start();
        $this->send($method, strtr($path, ['{order}' => $order]), $body, ['Idempotency-Key' => Uuid::v4()->toRfc4122()]);
        $this->recorder()->stop();

        self::assertSame($status, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());

        // Split at each tenant-context statement, which `TenantContext::run()` issues as a transaction's
        // first statement. The pattern is written so `TenantContextContainmentTest`'s writer scan does not
        // read this matcher as a writer of tenant context: this file matches the statement, it never
        // issues it.
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
            '/^\s*(UPDATE|INSERT INTO)\s+sales_order(_line)?\b/i', $statements,
        )));
        self::assertCount(1, $writing, 'Expected exactly one transaction writing the sales order or its lines.');

        $touching = array_values(preg_grep('/\bsales_order(_line)?\b/', $writing[0]));
        self::assertMatchesRegularExpression(
            '/^SELECT 1 FROM sales_order WHERE id = \S+ FOR NO KEY UPDATE$/',
            $touching[0],
            "The first statement to touch the sales order is not its row lock. Statements, in order:\n".implode("\n", $touching),
        );
    }

    /** @return iterable<string, array{string, string, array<string, string>|null, int}> */
    public static function everySalesWritePath(): iterable
    {
        yield 'ConfirmSalesOrder' => ['POST', '/sales-orders/{order}/confirmation', null, 200];
        // `reason` is sent rather than omitted: §4.2 accepts it, and the body is what makes this the
        // deserializing sibling of the confirmation above. The lock's position is what is under test either
        // way -- the audit INSERT that consumes the reason happens after `cancel()`, so it cannot be the
        // first statement to touch the order and cannot mask a missing lock.
        yield 'CancelSalesOrder' => ['POST', '/sales-orders/{order}/cancellation', ['reason' => 'Customer withdrew'], 200];
        // `warehouse_id` is the field sent because it exercises the deserializer and the UPDATE without
        // dragging the ship-to resolver into a position test -- the resolver issues a SELECT of its own
        // against `customer_address`, which this assertion would then have to reason about. The order is
        // seeded `draft`, so the edit window lets it through and the statement is really issued: an
        // operation that refuses issues no UPDATE and `assertCount(1, $writing)` would fail first.
        yield 'UpdateSalesOrder' => ['PATCH', '/sales-orders/{order}', ['warehouse_id' => '{warehouse}'], 200];
        // 201, not 200: API-21 branch 4. The order is seeded `draft`, so the edit window lets it through
        // and the INSERT is really issued -- an operation refused by the window issues no write at all and
        // `assertCount(1, $writing)` would fail first, which is what stops a green here meaning nothing.
        yield 'CreateSalesOrderLine' => ['POST', '/sales-orders/{order}/lines', ['inventory_item_id' => '{item}', 'quantity' => '2', 'unit_price' => '3'], 201];
        // `{line}` is substituted the same way `{order}` and `{item}` are, and for the same reason: a
        // `#[DataProvider]` is static and cannot seed.
        yield 'UpdateSalesOrderLine' => ['PATCH', '/sales-orders/{order}/lines/{line}', ['quantity' => '2', 'unit_price' => '3'], 200];
        // 204 and no body: API-21 branch 3. A soft delete, so the statement this check watches for is an
        // `UPDATE` of `deleted_at` and not a `DELETE`.
        yield 'DeleteSalesOrderLine' => ['DELETE', '/sales-orders/{order}/lines/{line}', null, 204];
        // Driven at the ARCHIVED line, not the live one: the restore's own guard refuses a live row, and
        // a refused drive issues no write, so `assertCount(1, $writing)` would fail before the position
        // check said anything.
        yield 'RestoreSalesOrderLine' => ['POST', '/sales-orders/{order}/lines/{archivedLine}/restoration', null, 200];
        // 204, API-21 branch 3. The order is seeded `draft`, so L-8 lets it through and the `UPDATE sales_order
        // SET deleted_at` is really issued; the cascade's line UPDATE runs inside the trigger, below the PHP
        // statement log, so exactly one write is seen.
        yield 'DeleteSalesOrder' => ['DELETE', '/sales-orders/{order}', null, 204];
        // 201, API-21 branch 4 (sales step 5, commit 4). `{fulfil}` tells the test to CONFIRM the seeded order and
        // stock a bin for its line first: a draft is refused by `recordFulfilment()`'s guard and a refused drive
        // issues no write, so `assertCount(1, $writing)` would fail before the position check ran. The body is
        // nested, so the test substitutes recursively.
        yield 'CreateFulfilment' => ['POST', '/sales-orders/{order}/fulfilments{fulfil}',
            ['lines' => [['sales_order_line_id' => '{line}', 'quantity' => '1', 'storage_location_id' => '{bin}']]], 201];
    }

    /**
     * **The Sales half of `LOCK_FIRST_CHECKED`** — `everySalesWritePath()`'s case => the method it enters.
     * The keys must be exactly that provider's cases, so the map and the runtime check cannot drift apart.
     *
     * It arrived with `ConfirmSalesOrder`, which is what the sibling constant's docblock said the next
     * sales writer would need: *"a lock-first check that actually runs, the way
     * `theOrderRowLockIsTheFirstStatementToTouchTheOrder` runs for purchasing, and a list naming it"*.
     * `theSalesOrderRowLockIsTheFirstStatementToTouchTheOrder` is that check and this is that list.
     */
    private const SALES_LOCK_FIRST_CHECKED = [
        'ConfirmSalesOrder' => 'SalesOrderConfirmation::confirm',
        // Added 2026-09-19 with the operation. The scan named `SalesOrderCancellation::cancel` on its own,
        // with both of its derived ORM writes, before this entry existed -- the tripwire fired rather than
        // being anticipated, which is the whole point of it.
        'CancelSalesOrder' => 'SalesOrderCancellation::cancel',
        // Added 2026-09-21 with the operation, and read off this test's own failure rather than
        // predicted: the scan named `SalesOrderWriter::update` with `SQL: UPDATE sales_order` before
        // this entry existed. It is the first entry here whose write is a raw statement rather than an
        // ORM call -- which is deliberate, see `SalesOrderWriter::update()`'s docblock: an ORM write
        // reached through an association reddens neither this scan nor the write-ownership scan, and a
        // raw `UPDATE sales_order` reddens both.
        'UpdateSalesOrder' => 'SalesOrderWriter::update',
        // Added 2026-09-21 with the operation, and read off this test's own failure: the scan named
        // `SalesOrderWriter::createLine — via recomputeTotal() | via insertLineInside()` before this
        // entry existed. Both of its writes are in private helpers, so the scan credits the public method
        // with the helpers it reaches rather than with their SQL -- `create()`'s entry below records the
        // same shape.
        'CreateSalesOrderLine' => 'SalesOrderWriter::createLine',
        'UpdateSalesOrderLine' => 'SalesOrderWriter::updateLine',
        'DeleteSalesOrderLine' => 'SalesOrderWriter::archiveLine',
        'RestoreSalesOrderLine' => 'SalesOrderWriter::restoreLine',
        // Added 2026-09-21 (sales slice 5), read off the scan's own failure: `SalesOrderWriter::archive — SQL:
        // UPDATE sales_order` was named before this entry existed.
        'DeleteSalesOrder' => 'SalesOrderWriter::archive',
        // Added 2026-09-22 (sales step 5, commit 4), read off the scan's own failure: `FulfilmentWriter::create — via
        // createInside()` was named before this entry existed. Its three statements (the two fulfilment INSERTs and
        // `UPDATE sales_order_line SET quantity_fulfilled`) are in the private helper, so the scan credits `create()`.
        'CreateFulfilment' => 'FulfilmentWriter::create',
    ];

    /**
     * **The sales twin of `LOCKS_FIRST_WITH_NO_PATH_OF_ITS_OWN`** (sales step 5, commit 4): writers that take the
     * order's lock themselves and are entered through another writer rather than through a path of their own.
     * `OrmSalesOrderFulfilmentStatus::recordFulfilment` is `OrmPurchaseOrderReceiptStatus::recordReceipt`'s
     * mirror, and the scan named it before this list existed. Its reason is measured by
     * `aSalesWriterWithNoPathOfItsOwnTakesTheOrdersLockAsItsFirstStatement`, not trusted.
     */
    private const SALES_LOCKS_FIRST_WITH_NO_PATH_OF_ITS_OWN = [
        'OrmSalesOrderFulfilmentStatus::recordFulfilment' => [
            'writes' => ['ORM: names a sales-order entity class and calls recordFulfilment() on it'],
            'why' => 'It calls SalesOrderRowLock::holdInside() first. Its only caller, FulfilmentWriter::createInside, '
                .'already holds that lock, and the second take is re-entrant for the same transaction, so this costs one '
                .'cheap statement and removes the dependence on the caller.',
        ],
    ];

    private const SALES_ALLOWED_WITHOUT_A_LOCK = [
        'SalesOrderWriter::create' => [
            // Read off this test's own failure, not predicted: both statements are in private helpers, so
            // the scan credits `create()` with the helpers it reaches rather than with their SQL.
            'writes' => [
                'via insertOrderInside()',
                'via recomputeTotal()',
                'via insertLineInside()',
            ],
            'why' => 'It inserts the order, so there is no existing row to lock, and no other transaction can address '
                .'what it inserts until it commits. Its two races are answered by unique indexes: '
                .'sales_order_tenant_idem_live (a replay of the same key) and sales_order_tenant_number_live (two '
                .'creates picking the same order number). Its lines are inserted in the same transaction and belong to '
                .'an order nobody else can reach yet -- and so is the recomputeTotal() UPDATE that follows them, which '
                .'targets the row this transaction just inserted. '
                .'** This entry read "It issues no UPDATE at all -- total_amount is summed inside the order\'s own '
                .'INSERT -- which is why Version20260919000200 grants INSERT and nothing more" until 2026-09-21, and '
                .'BOTH halves are now false.** CreateSalesOrderLine deleted that expression, as insertOrderInside()\'s '
                .'own docblock said the commit building it would; and the UPDATE grant arrived earlier still, with '
                .'ConfirmSalesOrder (Version20260919000300), so the "INSERT and nothing more" clause had already been '
                .'overtaken on 2026-09-20. The scan is what found it: recomputeTotal() appeared in this method\'s write '
                .'list and the sentence beside it still denied the write existed.',
        ],
    ];

    /**
     * **What one run of the writer scan looks for — the scan is the same code for
     * every aggregate, and this is everything about it that is not.**
     *
     * - `sql`: statement text that writes either table;
     * - `dql`: DQL that writes either entity;
     * - `entities`: the two entity classes. Their short names are what the ORM
     *   arm recognises (`X::class`, `new X`), and their public non-accessor
     *   methods, by reflection, are its mutators;
     * - `label`: the words the ORM arm's description uses. The purchase-order
     *   run's descriptions are pinned verbatim in the lists above, so this label
     *   is part of that pin.
     *
     * There is no lock-helper parameter, and that is not an omission: this scan
     * derives **which** methods write, and the lists above say how each is
     * covered. That a covered writer really takes its lock first is proved by
     * the races and `theOrderRowLockIsTheFirstStatementToTouchTheOrder`, which
     * are per aggregate and cannot be parameterised into existence.
     *
     * @var array{label: string, sql: non-empty-string, dql: non-empty-string, entities: array{class-string, class-string}}
     */
    private const PURCHASE_ORDER_SCAN = [
        'label' => 'purchase-order',
        'sql' => '/\b(?:UPDATE|INSERT\s+INTO|DELETE\s+FROM)\s+purchase_order(?:_line)?\b/i',
        'dql' => '/\b(?:UPDATE|DELETE\s+FROM)\s+[\w\\\\]*PurchaseOrder(?:Line)?\b/',
        'entities' => [PurchaseOrder::class, PurchaseOrderLine::class],
    ];

    /**
     * W-5's other half — see `everyMethodThatWritesASalesOrderIsCoveredByALockFirstCheck()`.
     *
     * @var array{label: string, sql: non-empty-string, dql: non-empty-string, entities: array{class-string, class-string}}
     */
    private const SALES_ORDER_SCAN = [
        'label' => 'sales-order',
        'sql' => '/\b(?:UPDATE|INSERT\s+INTO|DELETE\s+FROM)\s+sales_order(?:_line)?\b/i',
        'dql' => '/\b(?:UPDATE|DELETE\s+FROM)\s+[\w\\\\]*SalesOrder(?:Line)?\b/',
        'entities' => [SalesOrder::class, SalesOrderLine::class],
    ];

    /**
     * **The invoice aggregate** (sales step 6, C5): `invoice` alone — it has no line table. `IssueInvoice` creates the
     * row and every later writer changes an existing one, so every writer but the issuer takes `InvoiceRowLock` first.
     *
     * @var array{label: string, sql: non-empty-string, dql: non-empty-string, entities: array{class-string}}
     */
    private const INVOICE_SCAN = [
        'label' => 'invoice',
        'sql' => '/\b(?:UPDATE|INSERT\s+INTO|DELETE\s+FROM)\s+invoice\b/i',
        'dql' => '/\b(?:UPDATE|DELETE\s+FROM)\s+[\w\\\\]*Invoice\b/',
        'entities' => [Invoice::class],
    ];

    /** `everyInvoiceWritePath()`'s case => the method it enters. The keys must be exactly that provider's cases. */
    private const INVOICE_LOCK_FIRST_CHECKED = [
        'PayInvoice' => 'InvoicePayment::pay',
        // Sales step 6, C6: the ORM flush of `reversePayment()` and B′'s `UPDATE invoice SET payment_reversed_at`.
        'ReverseInvoicePayment' => 'InvoicePaymentReversal::reverse',
        // Sales step 6, C7: the ORM flush of `void()` (status, updated_at).
        'VoidInvoice' => 'InvoiceVoiding::void',
    ];

    private const INVOICE_ALLOWED_WITHOUT_A_LOCK = [
        'InvoiceIssuer::issue' => [
            'writes' => ['via issueInside()'],
            // Rewritten 2026-10-01 (sales step 7 R2), measured rather than moved: IssueInvoice now takes the ORDER's
            // lock first (SalesOrderRowLock, design §4.3) but still no INVOICE lock -- this map is about the invoice row,
            // which it creates -- so the entry stays here and its reason changes. It read "Its two races are answered by
            // unique indexes", which the order lock made half false.
            'why' => 'It inserts the invoice, so there is no existing invoice row to lock, and no other transaction can '
                .'address what it inserts until it commits. It takes the shipment\'s ORDER lock first (sales step 7), '
                .'which serialises it against CreateSalesReturn and against a second issue of the same shipment; the races '
                .'that lock cannot see are still answered by unique indexes: invoice_tenant_fulfilment_not_void and '
                .'invoice_number_unique, each translated after the failed transaction (D-3). It changes no existing '
                .'invoice, which is the property that exempts it; a writer that does is not a create and takes the lock.',
        ],
    ];

    /**
     * Planted invoice writers for `everyMethodThatWritesAnInvoiceIsCoveredByALockFirstCheck()`'s control (design §19
     * C5): a raw `UPDATE invoice` with no lock, and `void()` called with no lock. Both must be found.
     */
    private const PLANTED_INVOICE_WRITERS = <<<'PHP'
        <?php
        final class PlantedInvoiceWriter
        {
            public function rawUpdate(object $c): void
            {
                $c->executeStatement("UPDATE invoice SET status = 'void' WHERE id = ?::uuid", ['x']);
            }

            public function voidWithoutALock(object $em): void
            {
                $invoice = $em->find(Invoice::class, 'x');
                $invoice->void();
            }

            public function readsOnly(object $c): void
            {
                $c->fetchOne('SELECT status FROM invoice WHERE id = ?::uuid', ['x']);
            }
        }
        PHP;

    /**
     * **Every method in `src/` that writes an invoice is covered by a lock-first check or allowed by name** — the
     * sales-order accounting's shape, for the invoice (sales step 6, C5; design §19 C5). A payment, reversal or void
     * that changes an existing invoice without `InvoiceRowLock` first could overwrite a concurrent transition's
     * recorder or status, because Doctrine's `UPDATE` carries no status predicate.
     *
     * Controls, in this test: the pattern matches its own table and entity; the planted writers (a raw `UPDATE invoice`
     * and an unlocked `void()`) are both found and the read-only method is not; the derived set is not empty.
     */
    #[Test]
    public function everyMethodThatWritesAnInvoiceIsCoveredByALockFirstCheck(): void
    {
        foreach (['UPDATE invoice SET x = 1', 'INSERT INTO invoice (id) VALUES (1)', 'DELETE FROM invoice WHERE id = 1'] as $statement) {
            self::assertMatchesRegularExpression(self::INVOICE_SCAN['sql'], $statement, 'The invoice scan\'s SQL pattern does not recognise a write to invoice.');
        }
        foreach (['UPDATE '.Invoice::class.' e SET e.x = 1', 'DELETE FROM '.Invoice::class.' e WHERE e.id = 1'] as $statement) {
            self::assertMatchesRegularExpression(self::INVOICE_SCAN['dql'], $statement, 'The invoice scan\'s DQL pattern does not recognise a write to Invoice.');
        }
        self::assertNotSame([], self::entityMutators(self::INVOICE_SCAN['entities']), 'Invoice has no mutator, so the ORM arm has no subject.');

        self::assertSame(['PlantedInvoiceWriter::rawUpdate', 'PlantedInvoiceWriter::voidWithoutALock'],
            array_keys(self::writersAmong([self::PLANTED_INVOICE_WRITERS], self::INVOICE_SCAN)),
            'The invoice scan does not find exactly the planted writers (a raw UPDATE invoice, an unlocked void()), so an '
            .'empty or partial result below may be a blind scan.');

        $derived = self::writersOf(self::INVOICE_SCAN);
        self::assertNotSame([], $derived, 'The invoice scan found no writer, yet InvoiceIssuer inserts invoices.');

        self::assertSame(
            array_keys(iterator_to_array(self::everyInvoiceWritePath())),
            array_keys(self::INVOICE_LOCK_FIRST_CHECKED),
            'INVOICE_LOCK_FIRST_CHECKED must name exactly the cases everyInvoiceWritePath() runs.');

        $accounted = [...array_values(self::INVOICE_LOCK_FIRST_CHECKED), ...array_keys(self::INVOICE_ALLOWED_WITHOUT_A_LOCK)];
        sort($accounted);
        $found = array_keys($derived);
        sort($found);

        $describe = implode("\n", array_map(
            static fn (string $method, array $writes): string => '  '.$method.' — '.implode(' | ', array_unique($writes)),
            array_keys($derived),
            $derived,
        ));

        self::assertSame($accounted, $found,
            "The methods that write an invoice are not the ones covered by a lock-first check. A writer of an EXISTING "
            ."invoice must call InvoiceRowLock::holdInside() first and get a case in everyInvoiceWritePath() and "
            ."INVOICE_LOCK_FIRST_CHECKED; only a writer that creates the row may be added to INVOICE_ALLOWED_WITHOUT_A_LOCK, "
            ."with its reason.\nWriters found in src/:\n".$describe);

        foreach (self::INVOICE_ALLOWED_WITHOUT_A_LOCK as $method => $pin) {
            self::assertSame($pin['writes'], $derived[$method] ?? [],
                $method.' writes something its entry does not account for. Its stated reason for taking no lock was '
                ."written about a different set of writes:\n  ".$pin['why']);
        }
    }

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

        $derived = self::writersOf(self::PURCHASE_ORDER_SCAN);
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

        // ⚠ **The two lists whose entries carry a REASON also pin what that
        // reason is about, and that half was a diagnostic until 2026-09-12.**
        // The membership check above compares key sets, so a method already in
        // one of these lists could grow a second and a third write — as
        // `create()` did at step 8a, gaining its lines and its total — with the
        // suite green and the reason quietly describing a method that no longer
        // exists. The reason strings were values nothing read. Now a new write
        // reddens here and whoever adds it has to re-read the sentence that
        // excuses the missing lock.
        foreach ([...self::ALLOWED_WITHOUT_A_LOCK, ...self::LOCKS_FIRST_WITH_NO_PATH_OF_ITS_OWN] as $method => $pin) {
            self::assertSame($pin['writes'], $derived[$method] ?? [],
                $method.' writes something its entry does not account for. Its stated reason for taking no lock, or '
                ."for locking with no path of its own, was written about a different set of writes:\n  ".$pin['why']);
        }
    }

    /**
     * **W-5's Sales half. It was a tripwire asserting `[]` until 2026-09-19; the first sales writer
     * arrived and it is now the purchase side's accounting, as its own failure message instructed.**
     *
     * `ARCHITECTURE.md` W-5 — *"Sales locks the order, then stock; Purchasing
     * locks the PO, then stock"* — binds both write edges. Step 6c built the
     * Purchasing half and the scan above keeps it built. The Sales half had no
     * writer to check, so nothing would have noticed the first one arriving without its
     * lock (five-calls follow-up, owner ruling on call 1, 2026-09-13).
     *
     * **What changed and what did not.** `SalesOrderWriter::create` is the arrival the old text predicted
     * *"will need an allow-list entry: a create has no existing row to lock, and its races belong to its
     * unique indexes"*. It is in `SALES_ALLOWED_WITHOUT_A_LOCK` with its writes pinned, exactly as
     * `PurchaseOrderWriter::create` is. **Every control below is unchanged and still runs** — the scanner
     * still has to prove it works before the accounting means anything, because an accounting over an
     * empty derived set passes as easily as an emptiness assertion did.
     *
     * **Why the controls are split in two.** The
     * purchase-order test refuses an empty result because, there, empty means
     * the scan is broken. Here the derived set was **correctly** empty until this commit, so that
     * guard would have been red for the right answer. The two questions are split and stay split:
     *
     *  - *does the scanner work?* — the purchase-order run is the positive
     *    control, **run inside this test**. It is the same `writersOf()`; it
     *    finds every purchase-order writer (ten on 2026-09-13) across SQL, the
     *    ORM flush arm, the ORM mutator arm and the private-helper reduction,
     *    byte-identical to what it found before this scan was parameterised; and
     *    this test requires it to be non-empty and to contain
     *    `PurchaseOrderApproval::approve`. *(Until the 2026-09-13 review the
     *    control lived only in the sibling test, so a scanner broken for every
     *    aggregate left this green — and a `--filter` run made it vacuous.)*
     *  - *is the SALES configuration able to see anything?* — the scan for one
     *    aggregate is only its regexes and its entity classes, so both are
     *    checked against the entities' own `#[ORM\Table]` names and class names
     *    below, for both scans — SQL `UPDATE`/`INSERT INTO`/`DELETE FROM` and
     *    DQL `UPDATE`/`DELETE FROM`. A regex that could never match its table would
     *    otherwise turn this into a test that passes forever.
     *
     * **That demand has been MET once, by `ConfirmSalesOrder` on 2026-09-19.** It takes
     * `SalesOrderRowLock::holdInside()` as the first statement of its transaction, it has a case in
     * `everySalesWritePath()` and an entry in `SALES_LOCK_FIRST_CHECKED`,
     * `theSalesOrderRowLockIsTheFirstStatementToTouchTheOrder` proves the position per path, and
     * `aSecondConfirmationWaitsAndIsRefusedByTheStatusGuard` proves the behaviour against a real second
     * connection. **What the NEXT sales writer has to do is the same** — take the lock as its first
     * statement and be given a check that runs and a list naming it; or be allow-listed with its reason,
     * as `SalesOrderWriter::create` is.
     * ⚠ **`SALES_ALLOWED_WITHOUT_A_LOCK` is not a second home for that answer** — a writer that touches an
     * **existing** sales order has a row to lock and the create's reason does not transfer. The next
     * arrival is already known:
     *
     *  - **F-5's order-status recompute** on fulfilment posting will change a
     *    sales order's status. **This test sees it only if it is written in a
     *    shape the scan recognises**: SQL text naming the table, or a method that
     *    names `SalesOrder::class` / `new SalesOrder` and calls one of its
     *    mutators or flushes — the `OrmPurchaseOrderReceiptStatus::recordReceipt`
     *    shape. If the recompute reaches the order **through an association** —
     *    `Fulfilment::getSalesOrder()`, `Invoice::getSalesOrder()`,
     *    `SalesOrderLine::getSalesOrder()` — and never names the class, this
     *    scan is blind to it and stays green. The failure message says so too,
     *    because that is where the next builder will read it.
     *
     * The accounting lives in this file rather than in a sales-side twin, and that was the first sales
     * writer's decision: the two halves share `writersOf()`, the planted control and the positive control,
     * and splitting them would have made two copies of the engine whose agreement nobody checks. The
     * blind spots are the purchase-order scan's —
     * see `PurchaseOrderRowLock`'s docblock — and one of them already writes
     * here: **`sales_order_cascade_deletion`**, the trigger that carries a sales
     * order's `deleted_at` to its lines, is a writer of `sales_order_line`
     * outside `src/` (its name is even assembled at runtime in the migration),
     * and it runs inside the order's own `UPDATE`, so it keeps order → lines.
     *
     * **The planted control (RED review, 2026-09-14).** At `2d9bc8a` this test
     * stayed green with a throwaway sales class running
     * `private const SQL = 'UPDATE sales_order …'` through `self::SQL` — the very
     * shape `PurchaseOrderRowLock` uses, so the first sales writer could copy it
     * past the tripwire — and with `"SELECT 1;\nUPDATE sales_order …"`, whose
     * escaped newline glued `n` to the keyword. The scan now matches each
     * literal's decoded runtime value, and credits a method with the literals of
     * the constants of its own class it reaches (`self::`, `static::`,
     * `OwnClass::`, and a constant defined as another). `PLANTED_SALES_WRITERS`
     * pins both shapes, a constant that is not a write, and a method that merely
     * spells the constant's name, inside this test.
     *
     * **Still blind, measured against the SQL pattern on 2026-09-14:**
     * `UPDATE ONLY sales_order`, `UPDATE public.sales_order`,
     * `UPDATE "sales_order"`, a comment between the keyword and the name, and
     * `MERGE INTO sales_order` — the purchase-order pattern has the same shape,
     * and its descriptions are pinned, so it was not widened under a defect fix.
     * Nor does it credit a constant of **another** class (`OtherClass::SQL`,
     * `parent::SQL`, an interface constant), or SQL built from pieces.
     *
     * **Followed since 2026-09-16 (V1-R-6(b), V4 B4c):** a property default read
     * as `$this->sql`, a static property read as `self::$SQL` / `static::$SQL`,
     * and `constant('self::SQL')` / `\constant("…")` with a literal argument —
     * each planted with a comment variant (a block comment after `$this->`, after
     * `static::` and inside `constant(`) and an escape
     * variant (`"SELECT 1;\nDELETE FROM sales_order_line …"` as the static
     * default, `"self\u{3a}\u{3a}SQL"`). All seven were missed before the fix
     * (the planted list lost exactly those seven), and `readsAPropertyOnly` stays
     * out. **Still not followed:** another object's property (`$o->sql`), a
     * property assigned anywhere but its declaration, and dynamic names
     * (`$this->{$n}`, `constant($name)`).
     *
     * **Mutations, run 2026-09-13, each removed or reverted afterwards:** a
     * throwaway public method under `src/` that runs `UPDATE sales_order …` —
     * red here, naming it, and the purchase-order scan stays green; a throwaway
     * method that names `SalesOrder::class` and calls `confirm()` with no flush —
     * red here as the ORM mutator arm; the sales SQL pattern misspelt
     * `sale_order` — red at the pattern check, before the scan could pass
     * vacuously.
     */
    #[Test]
    public function everyMethodThatWritesASalesOrderIsCoveredByALockFirstCheck(): void
    {
        foreach (['purchase order' => self::PURCHASE_ORDER_SCAN, 'sales order' => self::SALES_ORDER_SCAN] as $aggregate => $scan) {
            foreach ($scan['entities'] as $entity) {
                $table = (new \ReflectionClass($entity))->getAttributes(\Doctrine\ORM\Mapping\Table::class)[0]->newInstance()->name;
                self::assertIsString($table, $entity.' declares no table name, so the '.$aggregate.' scan cannot be checked against it.');

                foreach (["UPDATE $table SET x = 1", "INSERT INTO $table (id) VALUES (1)", "DELETE FROM $table WHERE id = 1"] as $statement) {
                    self::assertMatchesRegularExpression($scan['sql'], $statement,
                        'The '.$aggregate.' scan\'s SQL pattern does not recognise a write to its own table '.$table.'.');
                }
                foreach (['UPDATE '.$entity.' e SET e.x = 1', 'DELETE FROM '.$entity.' e WHERE e.id = 1'] as $statement) {
                    self::assertMatchesRegularExpression($scan['dql'], $statement,
                        'The '.$aggregate.' scan\'s DQL pattern does not recognise a write to its own entity '.$entity.'.');
                }
            }

            self::assertNotSame([], self::entityMutators($scan['entities']),
                'The '.$aggregate.' entities have no mutator, so the ORM mutator arm of that scan has no subject.');
        }

        // The positive control, here and not only in the sibling test: the same
        // engine, pointed at an aggregate that has writers, must find them.
        $control = self::writersOf(self::PURCHASE_ORDER_SCAN);
        self::assertNotSame([], $control,
            'The writer scan found no purchase-order writer, so the scanner itself is broken and the empty sales result '
            .'below proves nothing.');
        self::assertArrayHasKey('PurchaseOrderApproval::approve', $control,
            'The writer scan no longer finds PurchaseOrderApproval::approve, a known ORM writer, so its ORM arms are broken '
            .'and the empty sales result below proves nothing.');

        // The planted control, RED review 2026-09-14: the two shapes that walked
        // past this tripwire at `2d9bc8a`. `PurchaseOrderRowLock` runs its SQL
        // through `self::SQL`, so the first sales writer can copy that pattern;
        // and a double-quoted `"SELECT 1;\nUPDATE sales_order"` glued the `n` of
        // the escape to the keyword. Both must be found by the same writersAmong()
        // the tree scan below runs on.
        self::assertSame(self::PLANTED_SALES_WRITERS_EXPECTED, array_keys(self::writersAmong([self::PLANTED_SALES_WRITERS], self::SALES_ORDER_SCAN)),
            'The writer scan does not find exactly the planted sales-order writers — SQL held in a class constant, a '
            .'property default or a static property and run through self::/static::/ClassName::/$this->/constant(), or '
            .'SQL with an escaped newline before the keyword — so the empty '
            .'result below may be a blind scan, not an empty tree.');

        $derived = self::writersOf(self::SALES_ORDER_SCAN);
        self::assertNotSame([], $derived,
            'The sales scan found no writer at all. Since 2026-09-19 SalesOrderWriter::create writes both sales tables, '
            .'so an empty result here means the scan has stopped seeing them and the accounting below holds over '
            .'nothing.');

        self::assertSame(
            array_keys(iterator_to_array(self::everySalesWritePath())),
            array_keys(self::SALES_LOCK_FIRST_CHECKED),
            'SALES_LOCK_FIRST_CHECKED must name exactly the cases everySalesWritePath() runs.');

        $accounted = [
            ...array_values(self::SALES_LOCK_FIRST_CHECKED),
            ...array_keys(self::SALES_LOCKS_FIRST_WITH_NO_PATH_OF_ITS_OWN),
            ...array_keys(self::SALES_ALLOWED_WITHOUT_A_LOCK),
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
            "The methods that write a sales order or its lines are not the ones covered by a lock-first check, and "
            ."ARCHITECTURE.md W-5 requires Sales to lock the order first (then stock). A new writer must take a "
            ."sales-order row lock as the first statement of its transaction and be given a lock-first check that runs "
            ."and a list naming it; or, only if it creates the row it writes, be added to SALES_ALLOWED_WITHOUT_A_LOCK "
            ."with its reason.\n"
            ."NOTE: this scan is blind to a write reached through an association (Fulfilment::getSalesOrder(), "
            ."Invoice::getSalesOrder(), SalesOrderLine::getSalesOrder()) that never names SalesOrder::class, and to "
            ."writers outside src/ such as the sales_order_cascade_deletion trigger. A sales writer this test does not "
            ."list may still exist.\nWriters found in src/:\n".$describe);

        // The reason half, and it is the half that keeps working after the membership check stops changing:
        // a method already on the list could grow a second and a third write with the suite green and its
        // stated reason quietly describing a method that no longer exists. `PurchaseOrderWriter::create` did
        // exactly that at step 8a. So a new write reddens here and whoever adds it has to re-read the
        // sentence that excuses the missing lock.
        foreach ([...self::SALES_ALLOWED_WITHOUT_A_LOCK, ...self::SALES_LOCKS_FIRST_WITH_NO_PATH_OF_ITS_OWN] as $method => $pin) {
            self::assertSame($pin['writes'], $derived[$method] ?? [],
                $method.' writes something its entry does not account for. Its stated reason for taking no lock was '
                ."written about a different set of writes:\n  ".$pin['why']);
        }
    }

    /**
     * Planted sales-order writers for
     * `everyMethodThatWritesASalesOrderIsCoveredByALockFirstCheck()`'s control. `readsOnly` references a constant that is not a write and
     * `neverRuns` holds the write constant's name only as prose, so a scan that
     * credited every constant to every method would be red too.
     */
    private const PLANTED_SALES_WRITERS = <<<'PHP'
        <?php

        namespace App\Modules\Sales\Infrastructure;

        final class PlantedSalesWriter
        {
            private const SQL = 'UPDATE sales_order SET status = ? WHERE id = ?::uuid';

            private const ALIAS = self::SQL;

            private const READ = 'SELECT 1 FROM sales_order WHERE id = ?::uuid FOR NO KEY UPDATE';

            private string $sql = 'UPDATE sales_order SET status = ? WHERE id = ?::uuid';

            private static string $STATIC_SQL = "SELECT 1;\nDELETE FROM sales_order_line WHERE id = ?::uuid";

            private string $read = 'SELECT 1 FROM sales_order WHERE id = ?::uuid';

            public function viaSelf(object $c): void
            {
                $c->executeStatement(self::SQL, []);
            }

            public function viaStatic(object $c): void
            {
                $c->executeStatement(static::SQL, []);
            }

            public function viaOwnName(object $c): void
            {
                $c->executeStatement(PlantedSalesWriter::SQL, []);
            }

            public function viaAlias(object $c): void
            {
                $c->executeStatement(self::ALIAS, []);
            }

            public function viaEscapedNewline(object $c): void
            {
                $c->executeStatement("SELECT 1;\nUPDATE sales_order SET status = 'x'");
            }

            public function viaProperty(object $c): void
            {
                $c->executeStatement($this->sql, []);
            }

            public function viaPropertyAfterAComment(object $c): void
            {
                $c->executeStatement($this-> /* the statement */ sql, []);
            }

            public function viaStaticProperty(object $c): void
            {
                $c->executeStatement(self::$STATIC_SQL, []);
            }

            public function viaStaticPropertyAfterAComment(object $c): void
            {
                $c->executeStatement(static:: /* late */ $STATIC_SQL, []);
            }

            public function viaConstantFunction(object $c): void
            {
                $c->executeStatement(constant('self::SQL'), []);
            }

            public function viaConstantFunctionEscaped(object $c): void
            {
                $c->executeStatement(\constant("self\u{3a}\u{3a}SQL"), []);
            }

            public function viaConstantFunctionAfterAComment(object $c): void
            {
                $c->executeStatement(constant(/* by name */ 'PlantedSalesWriter::SQL'), []);
            }

            public function readsAPropertyOnly(object $c): void
            {
                $c->executeStatement($this->read, []);
            }

            public function readsOnly(object $c): void
            {
                $c->executeStatement(self::READ, []);
            }

            public function neverRuns(): string
            {
                return 'SQL';
            }
        }
        PHP;

    private const PLANTED_SALES_WRITERS_EXPECTED = [
        'PlantedSalesWriter::viaSelf',
        'PlantedSalesWriter::viaStatic',
        'PlantedSalesWriter::viaOwnName',
        'PlantedSalesWriter::viaAlias',
        'PlantedSalesWriter::viaEscapedNewline',
        'PlantedSalesWriter::viaProperty',
        'PlantedSalesWriter::viaPropertyAfterAComment',
        'PlantedSalesWriter::viaStaticProperty',
        'PlantedSalesWriter::viaStaticPropertyAfterAComment',
        'PlantedSalesWriter::viaConstantFunction',
        'PlantedSalesWriter::viaConstantFunctionEscaped',
        'PlantedSalesWriter::viaConstantFunctionAfterAComment',
    ];

    // =======================================================================
    // The role-administration lock (roles slice 2, C2) — `SECURITY_MODEL.md` §5.2.5, `ARCHITECTURE.md` W-5's amendment.
    // Not a row lock: one per-tenant advisory lock (`RoleAdministrationLock`), because API-20's count races across
    // DIFFERENT rows. The evidence copies this class's shape: a positional test, the refusal twins, two-connection races
    // (the holder's `role_administration` mode issues `RoleAdministrationLock::SQL` itself), and a derived writer scan.
    // =======================================================================

    /**
     * **The lock is the FIRST statement of the transaction that writes `user_role`**, pinned by the constant's exact text
     * — the strict form, because this lock is transaction-level (the order locks' rule is "first to touch the order").
     * Both role operations are `read: false`, so no provider reads a `HARD` row in an earlier transaction.
     */
    #[Test]
    #[DataProvider('everyRoleWritePath')]
    public function theRoleAdministrationLockIsTheFirstStatementOfTheWritingTransaction(string $case, int $status): void
    {
        $subject = $this->seedPrincipal(self::TENANT_A, DefaultRoles::codesFor(DefaultRoles::VIEWER));
        $role = $this->onlyRoleOf($subject);
        $grantable = $this->grantableRole();
        $this->actAsRole(DefaultRoles::ADMIN);

        $this->recorder()->start();
        match ($case) {
            'RevokeRole' => $this->send('DELETE', '/users/'.$subject.'/roles/'.$role),
            // Roles slice 2, C3: a one-code composed role (`role.view`, inside `admin`'s set, no X(admin) code, no SoD row).
            'AssignRole' => $this->send('POST', '/users/'.$subject.'/roles', ['role_id' => $grantable],
                [\App\Platform\Http\Idempotency\IdempotencyKeyGuard::HEADER => Uuid::v4()->toRfc4122()]),
        };
        $this->recorder()->stop();
        self::assertSame($status, $this->httpStatus(), $case.': '.$this->client()->getResponse()->getContent());

        $writing = array_values(array_filter($this->transactionsRecorded(), static fn (array $statements): bool => [] !== preg_grep(
            '/^\s*(?:DELETE\s+FROM|INSERT\s+INTO|UPDATE)\s+user_role\b/i', $statements,
        )));
        self::assertCount(1, $writing, 'Expected exactly one transaction writing user_role.');
        self::assertSame(RoleAdministrationLock::SQL, $writing[0][0] ?? null,
            "The first statement of the transaction writing user_role is not the role-administration lock. Statements, in order:\n"
            .implode("\n", $writing[0]));
    }

    /** @return iterable<string, array{string, int}> */
    public static function everyRoleWritePath(): iterable
    {
        yield 'RevokeRole' => ['RevokeRole', 204];
        yield 'AssignRole' => ['AssignRole', 201];
    }

    /** Refusal 1: outside a transaction nothing is sent — an autocommitted advisory lock is no lock. */
    #[Test]
    public function theRoleLockRefusesToRunOutsideATransaction(): void
    {
        $connection = $this->appConnection();
        self::assertSame(0, $connection->getTransactionNestingLevel(), 'Fixture: a transaction is already open.');
        $recorder = $this->appRecorder();
        $recorder->start();
        try {
            RoleAdministrationLock::holdInside($this->appEntityManager(), self::TENANT_A);
            self::fail('The role lock ran outside a transaction.');
        } catch (\LogicException $e) {
            self::assertStringContainsString('open transaction', $e->getMessage());
        } finally {
            $recorder->stop();
        }
        self::assertSame([], $recorder->statements(), 'The refused lock still sent a statement.');
    }

    /**
     * Refusals 2 and 3: an EMPTY tenant setting and a MISMATCHED one. The guarded statement is sent, refuses, and takes no
     * advisory lock (`pg_locks`), and nothing naming a role table or `app_user` follows.
     */
    #[Test]
    public function theRoleLockRefusesOnAnEmptyOrMismatchedTenantSettingAndTakesNothing(): void
    {
        $connection = $this->appConnection();
        foreach (['empty' => null, 'mismatched' => self::TENANT_B] as $case => $setting) {
            $connection->beginTransaction();
            try {
                if (null !== $setting) {
                    $connection->executeStatement("SELECT set_config('app.tenant_id', ?, true)", [$setting]);
                }
                try {
                    RoleAdministrationLock::holdInside($this->appEntityManager(), self::TENANT_A);
                    self::fail('The role lock ran on an '.$case.' tenant setting.');
                } catch (\LogicException $e) {
                    self::assertStringContainsString('was not taken', $e->getMessage(), $case);
                }
                self::assertSame(0, (int) $connection->fetchOne(
                    "SELECT count(*) FROM pg_locks WHERE locktype = 'advisory' AND pid = pg_backend_pid()"),
                    'The refused lock still took an advisory lock on an '.$case.' setting (spar P2).');
            } finally {
                $connection->rollBack();
            }
        }
    }

    /**
     * R2-8: one tenant spelled two ways takes ONE lock. `holdInside()` with the upper-case spelling must hold the advisory
     * lock keyed on `hashtext()` of the canonical lower-case text — which differs from the upper-case spelling's hash.
     */
    #[Test]
    public function theRoleLockKeysOnTheCanonicalTenantId(): void
    {
        $tenants = self::getContainer()->get(TenantContext::class);
        \assert($tenants instanceof TenantContext);

        // A tenant id WITH hex letters: the fixture tenants are all digits, so their two spellings are one string. No
        // tenant row is needed — the lock compares the setting `run()` writes with the id it is given, and reads no table.
        $tenant = 'abcdefab-cdef-4abc-8def-abcdefabcdef';
        $held = $tenants->run($tenant, static function (EntityManagerInterface $em) use ($tenant): array {
            RoleAdministrationLock::holdInside($em, strtoupper($tenant));

            return $em->getConnection()->fetchAllAssociative(<<<'SQL'
                SELECT l.classid::int AS class_id, l.objid::int AS obj_id, hashtext(?::text) AS lower, hashtext(?::text) AS upper
                  FROM pg_locks l WHERE l.locktype = 'advisory' AND l.pid = pg_backend_pid()
                SQL, [$tenant, strtoupper($tenant)]);
        });

        self::assertCount(1, $held);
        self::assertNotSame($held[0]['lower'], $held[0]['upper'], 'Control: the two spellings hash alike, so this proves nothing.');
        self::assertSame(RoleAdministrationLock::CLASS_ID, $held[0]['class_id']);
        self::assertSame($held[0]['lower'], $held[0]['obj_id'], 'The lock is keyed on a non-canonical spelling of the tenant.');
    }

    /**
     * **V2-R-3's race: two owners revoke each other; exactly one revoke happens.** The holder takes the role lock and, as
     * A, revokes B's owner grant and writes its `role.revoked`. The request — B revoking A, authenticated while A's revoke
     * is uncommitted, so B's token still reads as owner — waits, re-derives B's codes under the lock, finds `role.assign`
     * gone, and is refused with the gate's own 403 and one denial row (S-2). One owner left.
     * Mutations (roles-s2-design.md §5.1, spar P5): R3 → `marker_needs_owner`; A5 → `last_owner`; A5+R5 → 204, zero owners.
     */
    #[Test]
    public function twoOwnersRevokingEachOtherLeaveExactlyOne(): void
    {
        $a = $this->seedPrincipal(self::TENANT_A, DefaultRoles::codesFor(DefaultRoles::OWNER));
        $b = $this->seedPrincipal(self::TENANT_A, DefaultRoles::codesFor(DefaultRoles::OWNER));
        [$aRole, $bRole] = [$this->onlyRoleOf($a), $this->onlyRoleOf($b)];
        $this->assertTheRequestConnectionIsReadCommitted();
        $this->actAsUser(self::TENANT_A, $b);

        $holder = $this->raceAgainstAHolder(self::TENANT_A, before: [
            ['DELETE FROM user_role WHERE user_id = ?::uuid AND role_id = ?::uuid', [$b, $bRole]],
            [self::HOLDER_ROLE_REVOKED, [self::TENANT_A, $a, $b, $bRole]],
        ], request: fn () => $this->send('DELETE', '/users/'.$a.'/roles/'.$aRole), table: 'role_administration');

        $this->assertTheHolderWasWaitedOnAndCommitted($holder);
        self::assertSame(403, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        self::assertSame(RoleRevokeTest::ROLE_ASSIGN_DENIED, (string) $this->client()->getResponse()->getContent());
        self::assertSame(1, $this->countAsOwnerForTenant(self::TENANT_A,
            'SELECT count(*) FROM audit_log WHERE event_type = ?', [AuditEvent::ROLE_REVOKED]), 'Not exactly one revoke happened.');
        self::assertSame([['actor' => $b, 'permission_code' => 'role.assign', 'subject_type' => 'app_user', 'subject_id' => $a]],
            $this->denialRows(), 'The lock-time refusal did not write exactly one denial row naming the user.');
        self::assertSame(1, $this->countAsOwnerForTenant(self::TENANT_A, 'SELECT count(*) FROM user_role WHERE user_id = ?::uuid AND role_id = ?::uuid', [$a, $aRole]),
            'The refused revoke removed A\'s grant anyway: the workspace has no owner.');
    }

    /**
     * **S-6 + `SEC`'s S-2 (Acknowledgement A.3): an actor demoted while waiting is refused, and the refusal — which
     * COMMITS — writes nothing but its denial row.** The holder removes the actor's only `role.assign`-conferring role (a
     * non-marker role, so E-15b is not involved). The waiting request: 403, byte-identical to the gate's; exactly one
     * `audit_log` row in total, the denial; zero `role.*` rows; `user_role` row for row as the holder left it; the actor's
     * and subject's `app_user` rows untouched. And the denial names the SAME pair a gate denial of the same path names —
     * one resolver (`PermissionGate::denialSubjectOf()`), mutation hard-coding `'role'` → red.
     */
    #[Test]
    public function anActorDemotedWhileWaitingIsRefusedAndTheRefusalCommitsNothingElse(): void
    {
        $subject = $this->seedPrincipal(self::TENANT_A, DefaultRoles::codesFor(DefaultRoles::VIEWER));
        $actor = $this->seedPrincipal(self::TENANT_A, DefaultRoles::codesFor(DefaultRoles::ADMIN));
        $path = '/users/'.$subject.'/roles/'.$this->onlyRoleOf($subject);
        $this->assertTheRequestConnectionIsReadCommitted();
        $users = $this->appUserRows([$subject, $actor]);
        $this->actAsUser(self::TENANT_A, $actor);

        $holder = $this->raceAgainstAHolder(self::TENANT_A, before: [
            ['DELETE FROM user_role WHERE user_id = ?::uuid', [$actor]],
        ], request: fn () => $this->send('DELETE', $path), table: 'role_administration');
        $afterHolder = $this->userRoleRows();

        $this->assertTheHolderWasWaitedOnAndCommitted($holder);
        self::assertSame(403, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        self::assertSame(RoleRevokeTest::ROLE_ASSIGN_DENIED, (string) $this->client()->getResponse()->getContent());
        self::assertSame(1, $this->countAsOwnerForTenant(self::TENANT_A, 'SELECT count(*) FROM audit_log'),
            'The committed refusal wrote something besides its one denial row.');
        self::assertSame(0, $this->countAsOwnerForTenant(self::TENANT_A, "SELECT count(*) FROM audit_log WHERE event_type LIKE 'role.%'"));
        self::assertSame($afterHolder, $this->userRoleRows(), 'The committed refusal changed user_role.');
        self::assertSame(1, $this->countAsOwnerForTenant(self::TENANT_A, 'SELECT count(*) FROM user_role WHERE user_id = ?::uuid', [$subject]),
            'The subject lost the grant although the revoke was refused.');
        self::assertSame($users, $this->appUserRows([$subject, $actor]), 'The committed refusal flushed a change to a user row.');

        [$lockTime] = $this->denialRows();
        // The gate's row for the SAME path, from a caller holding nothing.
        $this->actAs(self::TENANT_A, []);
        $this->send('DELETE', $path);
        self::assertSame(403, $this->httpStatus());
        $gate = array_values(array_filter($this->denialRows(), fn (array $row): bool => $row['actor'] === $this->actorId));
        self::assertCount(1, $gate);
        self::assertSame([$gate[0]['subject_type'], $gate[0]['subject_id']], [$lockTime['subject_type'], $lockTime['subject_id']],
            'A denial under the lock names a different (subject_type, subject_id) pair from the gate\'s denial of the same path.');
        self::assertSame(['app_user', $subject], [$lockTime['subject_type'], $lockTime['subject_id']]);
    }

    /**
     * E-1 under the lock (`SEC` §2.2's hard constraint): the re-check comes before any read of the subject, so a demoted
     * actor is refused identically against a real grant and against ids that exist nowhere — same status, same bytes, one
     * denial row each. Mutation: move the re-check after the subject read → the random-id run answers 404, red.
     */
    #[Test]
    public function aDemotedActorIsRefusedTheSameWayWhetherOrNotTheSubjectExists(): void
    {
        $subject = $this->seedPrincipal(self::TENANT_A, DefaultRoles::codesFor(DefaultRoles::VIEWER));
        $answers = [];
        foreach ([
            'a real grant' => '/users/'.$subject.'/roles/'.$this->onlyRoleOf($subject),
            'ids that exist nowhere' => '/users/'.Uuid::v4()->toRfc4122().'/roles/'.Uuid::v4()->toRfc4122(),
        ] as $case => $path) {
            $actor = $this->seedPrincipal(self::TENANT_A, DefaultRoles::codesFor(DefaultRoles::ADMIN));
            $this->actAsUser(self::TENANT_A, $actor);
            $holder = $this->raceAgainstAHolder(self::TENANT_A, before: [
                ['DELETE FROM user_role WHERE user_id = ?::uuid', [$actor]],
            ], request: fn () => $this->send('DELETE', $path), table: 'role_administration');
            $this->assertTheHolderWasWaitedOnAndCommitted($holder);

            $answers[$case] = [$this->httpStatus(), (string) $this->client()->getResponse()->getContent()];
            self::assertCount(1, array_filter($this->denialRows(), static fn (array $row): bool => $row['actor'] === $actor), $case);
        }
        self::assertSame([403, RoleRevokeTest::ROLE_ASSIGN_DENIED], $answers['a real grant']);
        self::assertSame($answers['a real grant'], $answers['ids that exist nowhere'],
            'A demoted actor can tell a real grant from an absent one: the re-check does not come before the subject read.');
    }

    /**
     * `SEC` §2.4's order: an actor who is inactive AND loses `role.assign` while waiting gets the 403 and its row, not the
     * inactive 409 — the permission re-check is first.
     */
    #[Test]
    public function anInactiveActorDemotedWhileWaitingGetsThe403First(): void
    {
        $subject = $this->seedPrincipal(self::TENANT_A, DefaultRoles::codesFor(DefaultRoles::VIEWER));
        $actor = $this->seedPrincipal(self::TENANT_A, DefaultRoles::codesFor(DefaultRoles::ADMIN));
        $this->executeAsOwnerForTenant(self::TENANT_A, 'UPDATE app_user SET is_active = false WHERE id = ?::uuid', [$actor]);
        $this->actAsUser(self::TENANT_A, $actor);

        $holder = $this->raceAgainstAHolder(self::TENANT_A, before: [
            ['DELETE FROM user_role WHERE user_id = ?::uuid', [$actor]],
        ], request: fn () => $this->send('DELETE', '/users/'.$subject.'/roles/'.$this->onlyRoleOf($subject)), table: 'role_administration');

        $this->assertTheHolderWasWaitedOnAndCommitted($holder);
        self::assertSame(403, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        self::assertCount(1, $this->denialRows());
    }

    /**
     * **The grant twin of the demotion race** (roles slice 2, C3; design §5.2 `anActorDemotedWhileWaitingCannotGrant`;
     * `SEC` §C2 deviations §3: *"Its demotion twin asserts the same literal and the same pair equality"*). The holder removes
     * the actor's only `role.assign`-conferring role; the waiting grant is refused with the gate's 403, byte for byte; the
     * refusal commits exactly one `audit_log` row (the denial), zero `role.*` rows, `user_role` row for row as the holder
     * left it (the subject gained nothing), both `app_user` rows untouched; and the lock-time row names the SAME pair a
     * gate denial of the same path names. Mutation A5 (the actor's codes from the request `Identity`) → 201, red.
     */
    #[Test]
    public function anActorDemotedWhileWaitingCannotGrant(): void
    {
        $subject = $this->seedPrincipal(self::TENANT_A, []);
        $actor = $this->seedPrincipal(self::TENANT_A, DefaultRoles::codesFor(DefaultRoles::ADMIN));
        $path = '/users/'.$subject.'/roles';
        $body = ['role_id' => $this->grantableRole()];
        $this->assertTheRequestConnectionIsReadCommitted();
        $users = $this->appUserRows([$subject, $actor]);
        $this->actAsUser(self::TENANT_A, $actor);

        $holder = $this->raceAgainstAHolder(self::TENANT_A, before: [
            ['DELETE FROM user_role WHERE user_id = ?::uuid', [$actor]],
        ], request: fn () => $this->send('POST', $path, $body,
            [\App\Platform\Http\Idempotency\IdempotencyKeyGuard::HEADER => Uuid::v4()->toRfc4122()]), table: 'role_administration');
        $afterHolder = $this->userRoleRows();

        $this->assertTheHolderWasWaitedOnAndCommitted($holder);
        self::assertSame(403, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        self::assertSame(RoleRevokeTest::ROLE_ASSIGN_DENIED, (string) $this->client()->getResponse()->getContent());
        self::assertSame(1, $this->countAsOwnerForTenant(self::TENANT_A, 'SELECT count(*) FROM audit_log'),
            'The committed refusal wrote something besides its one denial row.');
        self::assertSame(0, $this->countAsOwnerForTenant(self::TENANT_A, "SELECT count(*) FROM audit_log WHERE event_type LIKE 'role.%'"));
        self::assertSame($afterHolder, $this->userRoleRows(), 'The committed refusal changed user_role.');
        self::assertSame(0, $this->countAsOwnerForTenant(self::TENANT_A, 'SELECT count(*) FROM user_role WHERE user_id = ?::uuid', [$subject]),
            'The subject gained the role although the grant was refused.');
        self::assertSame($users, $this->appUserRows([$subject, $actor]), 'The committed refusal flushed a change to a user row.');

        [$lockTime] = $this->denialRows();
        $this->actAs(self::TENANT_A, []);
        $this->send('POST', $path, $body);
        self::assertSame(403, $this->httpStatus());
        self::assertSame(RoleRevokeTest::ROLE_ASSIGN_DENIED, (string) $this->client()->getResponse()->getContent());
        $gate = array_values(array_filter($this->denialRows(), fn (array $row): bool => $row['actor'] === $this->actorId));
        self::assertCount(1, $gate);
        self::assertSame([$gate[0]['subject_type'], $gate[0]['subject_id']], [$lockTime['subject_type'], $lockTime['subject_id']],
            'A grant denial under the lock names a different (subject_type, subject_id) pair from the gate\'s denial of the same path.');
        self::assertSame(['app_user', $subject], [$lockTime['subject_type'], $lockTime['subject_id']]);
    }

    /**
     * **PC-13's race: two grants that together would split a duty; exactly one lands** (design §5.2
     * `twoConflictingGrantsToOneUserLeaveExactlyOne`; V2-R-3 for grants). The holder, as an owner, takes the role lock and
     * grants `finance` (SoD-3's controlled half, `invoice.pay`) to S, with its `role.assigned` row. The request — `admin`
     * granting `sales_rep` (SoD-3's preparing half, `invoice.issue`) to the same S, authenticated while the holder's grant is
     * uncommitted — waits, re-reads S's live roles under the lock, and is refused `separation_of_duty` naming SoD-3. S holds
     * `finance` alone. Both roles are SYSTEM roles with their default codes, so E-15c is not what decides.
     * **Mutation A5 leaves this GREEN, by design** (spar P5): it turns on the SUBJECT's set, re-read under the lock whatever
     * A5 does. Dropping the lock (R1) is what reddens it.
     */
    #[Test]
    public function twoConflictingGrantsToOneUserLeaveExactlyOne(): void
    {
        $subject = $this->seedPrincipal(self::TENANT_A, []);
        $finance = $this->systemRole(DefaultRoles::FINANCE);
        $salesRep = $this->systemRole(DefaultRoles::SALES_REP);
        $owner = $this->seedPrincipal(self::TENANT_A, DefaultRoles::codesFor(DefaultRoles::OWNER));
        $this->assertTheRequestConnectionIsReadCommitted();
        $this->actAsUser(self::TENANT_A, $this->seedPrincipal(self::TENANT_A, DefaultRoles::codesFor(DefaultRoles::ADMIN)));

        $holder = $this->raceAgainstAHolder(self::TENANT_A, before: [
            ['INSERT INTO user_role (id, tenant_id, user_id, role_id) VALUES (gen_random_uuid(), ?::uuid, ?::uuid, ?::uuid)', [self::TENANT_A, $subject, $finance]],
            [str_replace("'role.revoked'", "'role.assigned'", self::HOLDER_ROLE_REVOKED), [self::TENANT_A, $owner, $subject, $finance]],
        ], request: fn () => $this->send('POST', '/users/'.$subject.'/roles', ['role_id' => $salesRep],
            [\App\Platform\Http\Idempotency\IdempotencyKeyGuard::HEADER => Uuid::v4()->toRfc4122()]), table: 'role_administration');

        $this->assertTheHolderWasWaitedOnAndCommitted($holder);
        self::assertSame(409, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        self::assertSame('user_role.separation_of_duty', $this->json()['rule'] ?? null);
        self::assertStringContainsString('(SoD-3)', (string) ($this->json()['detail'] ?? ''));
        self::assertSame([['r' => $finance]], $this->fetchAsOwnerForTenant(self::TENANT_A,
            'SELECT role_id::text AS r FROM user_role WHERE user_id = ?::uuid', [$subject]), 'Both conflicting grants landed.');
        self::assertSame(1, $this->countAsOwnerForTenant(self::TENANT_A,
            'SELECT count(*) FROM audit_log WHERE event_type = ?', [AuditEvent::ROLE_ASSIGNED]), 'Not exactly one grant happened.');
    }

    /** A system role (`is_system = true`) holding `$name`'s default codes, as the seeded catalogue would. */
    private function systemRole(string $name): string
    {
        $role = Uuid::v4()->toRfc4122();
        $this->insertAsOwnerForTenant(self::TENANT_A, 'INSERT INTO role (id, tenant_id, name, is_system) VALUES (?::uuid, ?::uuid, ?, true)',
            [$role, self::TENANT_A, $name.' '.substr($role, 0, 8)]);
        foreach (DefaultRoles::codesFor($name) as $code) {
            $this->insertAsOwnerForTenant(self::TENANT_A,
                'INSERT INTO role_permission (id, tenant_id, role_id, permission_code) VALUES (gen_random_uuid(), ?::uuid, ?::uuid, ?)',
                [self::TENANT_A, $role, $code]);
        }

        return $role;
    }

    /**
     * **Every method in `src/` that writes a role-administration table, or ends a user's liveness, takes the role lock
     * first** (spar §2 item 5, P6; design §4.4). The domain is derived from `src/`: SQL/DQL writes of `user_role`, `role`,
     * `role_permission`, `role_grant_proposal`; SQL/DQL that sets `app_user.is_active` / `deleted_at` (keyed on the SET
     * list) or hard-deletes a user; the ORM arm (flush/persist/remove or a mutator) for `UserRole`, `RolePermission`,
     * `Role`; and for `AppUser` the **mutators only** — those assigning `isActive`/`deletedAt`, derived from its source —
     * because the flush clause would name `ChangePassword` and `LoginRecorder` (credential writers) as role writers.
     *
     * Named blind spots (inherited from the session-9 list): schema-qualified (`public.user_role`), `ONLY`, quoted
     * identifiers, `MERGE INTO`, `TRUNCATE`, SQL built by concatenation or keyword-less fragments, and an ORM write reached
     * through an association that never names the entity. A `WITH … DELETE FROM user_role` CTE IS seen.
     */
    #[Test]
    public function everyMethodThatWritesARoleAdministrationTableIsCoveredByALockFirstCheck(): void
    {
        foreach (['user_role', 'role', 'role_permission', 'role_grant_proposal'] as $table) {
            foreach (["UPDATE $table SET x = 1", "INSERT INTO $table (id) VALUES (1)", "DELETE FROM $table WHERE id = 1"] as $statement) {
                self::assertMatchesRegularExpression(self::ROLE_ADMIN_SCAN['sql'], $statement, 'The role scan does not see: '.$statement);
            }
        }
        foreach (['UPDATE roles SET x = 1', 'UPDATE role_grant_proposal_x SET x = 1', 'SELECT 1 FROM user_role'] as $statement) {
            self::assertDoesNotMatchRegularExpression(self::ROLE_ADMIN_SCAN['sql'], $statement, 'The role scan over-matches: '.$statement);
        }
        self::assertSame(['deactivate', 'delete'], self::livenessMutators(self::ROLE_ADMIN_SCAN['mutator_only']), 'AppUser\'s liveness mutators are not the two the scan was written for.');

        self::assertSame(self::PLANTED_ROLE_WRITERS_EXPECTED, array_keys(self::writersAmong([self::PLANTED_ROLE_WRITERS], self::ROLE_ADMIN_SCAN)),
            'The role scan does not find exactly the planted role-administration writers (and none of the credential-shaped '
            .'negatives), so the result below may be a blind scan.');

        $derived = self::writersOf(self::ROLE_ADMIN_SCAN);
        self::assertNotSame([], $derived, 'The role scan found no writer, yet RoleRevocation deletes from user_role.');

        self::assertSame(array_keys(iterator_to_array(self::everyRoleWritePath())), array_keys(self::ROLE_LOCK_FIRST_CHECKED),
            'ROLE_LOCK_FIRST_CHECKED must name exactly the cases everyRoleWritePath() runs.');

        $accounted = [...array_values(self::ROLE_LOCK_FIRST_CHECKED), ...array_keys(self::ROLE_LOCKS_FIRST_WITH_NO_PATH_OF_ITS_OWN), ...array_keys(self::ROLE_ALLOWED_WITHOUT_A_LOCK)];
        sort($accounted);
        $found = array_keys($derived);
        sort($found);

        self::assertSame($accounted, $found,
            "The methods that write a role-administration table (or end a user's liveness) are not the ones covered by a "
            ."lock-first check. SECURITY_MODEL.md §5.2.5: every such writer calls RoleAdministrationLock::holdInside() as the "
            ."FIRST statement of its transaction and gets a case in everyRoleWritePath() and ROLE_LOCK_FIRST_CHECKED.\nWriters found in src/:\n"
            .implode("\n", array_map(static fn (string $m, array $w): string => '  '.$m.' — '.implode(' | ', array_unique($w)), array_keys($derived), $derived)));
    }

    /**
     * The role-administration aggregate. `also`: the `app_user` liveness arms (SQL keyed on the SET list, plus a hard
     * delete — run by the spar, P6; and their DQL twin). `mutator_only`: `AppUser`, whose ORM arm counts only the mutators
     * assigning the named properties.
     *
     * @var array{label: string, sql: non-empty-string, dql: non-empty-string, also: list<non-empty-string>, entities: list<class-string>, mutator_only: array<class-string, list<string>>}
     */
    private const ROLE_ADMIN_SCAN = [
        'label' => 'role-administration',
        'sql' => '/\b(?:UPDATE|INSERT\s+INTO|DELETE\s+FROM)\s+(?:user_role|role(?:_permission|_grant_proposal)?)\b/i',
        'dql' => '/\b(?:UPDATE|DELETE\s+FROM)\s+[\w\\\\]*(?:UserRole|RolePermission|Role|RoleGrantProposal)\b/',
        'also' => [
            '/\b(?:UPDATE\s+app_user\b(?:(?!\bWHERE\b)[\s\S])*?\bSET\b(?:(?!\bWHERE\b)[\s\S])*\b(?:is_active|deleted_at)\s*=|DELETE\s+FROM\s+app_user\b)/i',
            '/\b(?:UPDATE\s+[\w\\\\]*AppUser\b(?:(?!\bWHERE\b)[\s\S])*?\bSET\b(?:(?!\bWHERE\b)[\s\S])*\b\w+\.(?:isActive|deletedAt)\s*=|DELETE\s+FROM\s+[\w\\\\]*AppUser\b)/',
        ],
        'entities' => [UserRole::class, RolePermission::class, Role::class],
        'mutator_only' => [AppUser::class => ['isActive', 'deletedAt']],
    ];

    /** `everyRoleWritePath()`'s case => the method it enters. */
    private const ROLE_LOCK_FIRST_CHECKED = [
        'RevokeRole' => 'RoleRevocation::revoke',
        'AssignRole' => 'RoleAssignment::assign',
    ];

    /** Empty, and kept: a future entry is a reviewed line, with its reason. */
    private const ROLE_LOCKS_FIRST_WITH_NO_PATH_OF_ITS_OWN = [];

    /** Empty, and kept: no role-administration writer is exempt from the lock (§5.2.5 names none). */
    private const ROLE_ALLOWED_WITHOUT_A_LOCK = [];

    /**
     * Planted role writers. Must be found: the sales plant set's shapes on `user_role`, plus role-specific ones. Must NOT:
     * a `ChangePassword`-shaped and a `LoginRecorder`-shaped credential writer (they name `AppUser` and flush), a
     * `last_login_at … WHERE deleted_at IS NULL` literal, a `SET password_hash`, and a `SELECT … FROM user_role`.
     */
    private const PLANTED_ROLE_WRITERS = <<<'PHP'
        <?php

        namespace App\Modules\UserManagement\Application;

        final class PlantedRoleWriter
        {
            private const SQL = 'DELETE FROM user_role WHERE id = ?::uuid';

            private const ALIAS = self::SQL;

            public function viaLiteral(object $c): void
            {
                $c->executeStatement('DELETE FROM user_role WHERE id = ?::uuid', []);
            }

            public function viaSelf(object $c): void
            {
                $c->executeStatement(self::SQL, []);
            }

            public function viaStatic(object $c): void
            {
                $c->executeStatement(static::SQL, []);
            }

            public function viaAlias(object $c): void
            {
                $c->executeStatement(self::ALIAS, []);
            }

            public function viaEscapedNewline(object $c): void
            {
                $c->executeStatement("SELECT 1;\nDELETE FROM user_role WHERE id = ?::uuid");
            }

            public function viaCte(object $c): void
            {
                $c->executeStatement('WITH gone AS (DELETE FROM user_role WHERE id = ?::uuid RETURNING id) SELECT count(*) FROM gone');
            }

            public function renamesARole(object $c): void
            {
                $c->executeStatement('UPDATE role SET name = ? WHERE id = ?::uuid');
            }

            public function proposes(object $c): void
            {
                $c->executeStatement('INSERT INTO role_grant_proposal (id) VALUES (?::uuid)');
            }

            public function deactivatesBySql(object $c): void
            {
                $c->executeStatement('UPDATE app_user SET is_active = false WHERE id = ?::uuid');
            }

            public function deletesBySqlOverTwoLines(object $c): void
            {
                $c->executeStatement("UPDATE app_user\n   SET updated_at = now(),\n       deleted_at = clock_timestamp()\n WHERE id = ?::uuid");
            }

            public function hardDeletesAUser(object $c): void
            {
                $c->executeStatement('DELETE FROM app_user WHERE id = ?::uuid');
            }

            public function deactivatesByDql(object $em): void
            {
                $em->createQuery('UPDATE App\Modules\UserManagement\Infrastructure\Entity\AppUser u SET u.isActive = false WHERE u.id = :id');
            }

            public function deactivatesByOrm(object $em): void
            {
                $user = $em->find(AppUser::class, 'x');
                $user->deactivate();
            }

            public function persistsAGrant(object $em): void
            {
                $em->persist(new UserRole('x', 'x', 'x', 'x'));
                $em->flush();
            }

            public function changesAPasswordLikeChangePassword(object $em): void
            {
                $user = $em->find(AppUser::class, 'x');
                $user->replacePasswordHash('x');
                $em->flush();
            }

            public function recordsALoginLikeLoginRecorder(object $em): void
            {
                $user = $em->find(AppUser::class, 'x');
                $user->recordLogin(new \DateTimeImmutable());
                $em->flush();
            }

            public function stampsALogin(object $c): void
            {
                $c->executeStatement('UPDATE app_user SET last_login_at = now() WHERE id = ?::uuid AND deleted_at IS NULL');
            }

            public function changesAHash(object $c): void
            {
                $c->executeStatement('UPDATE app_user SET password_hash = ? WHERE id = ?::uuid');
            }

            public function readsGrants(object $c): void
            {
                $c->fetchAllAssociative('SELECT role_id FROM user_role WHERE user_id = ?::uuid');
            }
        }
        PHP;

    private const PLANTED_ROLE_WRITERS_EXPECTED = [
        'PlantedRoleWriter::viaLiteral',
        'PlantedRoleWriter::viaSelf',
        'PlantedRoleWriter::viaStatic',
        'PlantedRoleWriter::viaAlias',
        'PlantedRoleWriter::viaEscapedNewline',
        'PlantedRoleWriter::viaCte',
        'PlantedRoleWriter::renamesARole',
        'PlantedRoleWriter::proposes',
        'PlantedRoleWriter::deactivatesBySql',
        'PlantedRoleWriter::deletesBySqlOverTwoLines',
        'PlantedRoleWriter::hardDeletesAUser',
        'PlantedRoleWriter::deactivatesByDql',
        'PlantedRoleWriter::deactivatesByOrm',
        'PlantedRoleWriter::persistsAGrant',
    ];

    /** What the race holder writes for its own revoke: a `role.revoked` row as `RoleAuditWriter` would, with an IP. */
    private const HOLDER_ROLE_REVOKED = <<<'SQL'
        INSERT INTO audit_log (id, tenant_id, occurred_at, event_type, actor_user_id, actor_label, subject_type, subject_id, ip_address, metadata)
        VALUES (gen_random_uuid(), ?::uuid, clock_timestamp(), 'role.revoked', ?::uuid, 'Holder', 'app_user', ?::uuid, '203.0.113.9'::inet, jsonb_build_object('role_id', ?::text))
        SQL;

    /**
     * `AppUser`'s mutators that end or restore liveness — public instance methods whose own source assigns
     * `$this->isActive` or `$this->deletedAt`. Derived, never listed.
     *
     * @param array<class-string, list<string>> $mutatorOnly
     *
     * @return list<string>
     */
    private static function livenessMutators(array $mutatorOnly): array
    {
        $names = [];
        foreach ($mutatorOnly as $class => $properties) {
            $reflection = new \ReflectionClass($class);
            $lines = file((string) $reflection->getFileName());
            \assert(\is_array($lines));
            foreach ($reflection->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
                if ($method->isStatic() || $method->isConstructor()) {
                    continue;
                }
                $body = implode('', \array_slice($lines, $method->getStartLine() - 1, $method->getEndLine() - $method->getStartLine() + 1));
                if (1 === preg_match('/\$this->(?:'.implode('|', $properties).')\s*=(?!=)/', $body)) {
                    $names[] = $method->getName();
                }
            }
        }
        sort($names);

        return $names;
    }

    /** A composed role holding only `role.view` — grantable by `admin` and by any `role.assign` holder with that code. */
    private function grantableRole(): string
    {
        $role = Uuid::v4()->toRfc4122();
        $this->insertAsOwnerForTenant(self::TENANT_A, 'INSERT INTO role (id, tenant_id, name) VALUES (?::uuid, ?::uuid, ?)',
            [$role, self::TENANT_A, 'Grantable '.substr($role, 0, 8)]);
        $this->insertAsOwnerForTenant(self::TENANT_A,
            "INSERT INTO role_permission (id, tenant_id, role_id, permission_code) VALUES (gen_random_uuid(), ?::uuid, ?::uuid, 'role.view')",
            [self::TENANT_A, $role]);

        return $role;
    }

    private function onlyRoleOf(string $userId): string
    {
        $rows = $this->fetchAsOwnerForTenant(self::TENANT_A, 'SELECT role_id::text AS r FROM user_role WHERE user_id = ?::uuid', [$userId]);
        self::assertCount(1, $rows);

        return (string) $rows[0]['r'];
    }

    /** @return list<list<string>> the recorded statements split into transactions at each `set_config` */
    private function transactionsRecorded(): array
    {
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

        return $transactions;
    }

    /** @return list<array{actor: string, permission_code: string, subject_type: ?string, subject_id: ?string}> */
    private function denialRows(): array
    {
        return $this->fetchAsOwnerForTenant(self::TENANT_A,
            'SELECT actor_user_id::text AS actor, permission_code, subject_type, subject_id::text AS subject_id FROM audit_log WHERE event_type = ? ORDER BY occurred_at, id',
            [AuditEvent::AUTHZ_PERMISSION_DENIED]);
    }

    /** @return list<array<string, mixed>> */
    private function userRoleRows(): array
    {
        return $this->fetchAsOwnerForTenant(self::TENANT_A, 'SELECT to_jsonb(ur)::text AS r FROM user_role ur ORDER BY id');
    }

    /**
     * @param list<string> $ids
     *
     * @return list<array<string, mixed>>
     */
    private function appUserRows(array $ids): array
    {
        return $this->fetchAsOwnerForTenant(self::TENANT_A,
            'SELECT to_jsonb(u)::text AS r FROM app_user u WHERE id = ANY (?::uuid[]) ORDER BY id', ['{'.implode(',', $ids).'}']);
    }

    private function appEntityManager(): EntityManagerInterface
    {
        $em = self::getContainer()->get('doctrine.orm.entity_manager');
        \assert($em instanceof EntityManagerInterface);

        return $em;
    }

    private function appConnection(): \Doctrine\DBAL\Connection
    {
        return $this->appEntityManager()->getConnection();
    }

    private function appRecorder(): \App\Tests\Support\SqlRecorder
    {
        $recorder = self::getContainer()->get('test.sql_recorder.app');
        \assert($recorder instanceof \App\Tests\Support\SqlRecorder);

        return $recorder;
    }

    /** §5.2.5: the lock is correct only under READ COMMITTED, which the race tests assert rather than assume. */
    private function assertTheRequestConnectionIsReadCommitted(): void
    {
        self::assertSame('read committed', $this->appConnection()->fetchOne('SHOW transaction_isolation'),
            'The request connection is not READ COMMITTED; the role lock\'s re-reads are only correct under it.');
    }

    /**
     * Every method in `src/` that writes the scan's two tables (for the
     * purchase-order run, `purchase_order` / `purchase_order_line`), reduced to
     * the methods a caller enters by: a private or protected writer is replaced
     * by its in-class callers, and one with no in-class caller is reported as
     * itself.
     *
     * @param array{label: string, sql: non-empty-string, dql: non-empty-string, entities: array{class-string, class-string}} $scan
     *
     * @return array<string, list<string>> `ShortClass::method` => what it writes
     */
    private static function writersOf(array $scan): array
    {
        return self::writersAmong(array_map(
            static fn (string $path): string => (string) file_get_contents($path),
            PhpSourceScan::phpFilesUnder(\dirname(__DIR__, 2).'/src'),
        ), $scan);
    }

    /**
     * {@see self::writersOf()} over the given PHP sources rather than `src/`,
     * so a planted fixture runs through exactly the same reduction.
     *
     * @param list<string>                                                                                                      $sources
     * @param array{label: string, sql: non-empty-string, dql: non-empty-string, entities: array{class-string, class-string}} $scan
     *
     * @return array<string, list<string>>
     */
    private static function writersAmong(array $sources, array $scan): array
    {
        $methods = [];

        foreach ($sources as $source) {
            foreach (self::methodsIn($source, $scan) as $key => $method) {
                if (isset($methods[$key])) {
                    throw new \LogicException(\sprintf('Two classes in src/ share the short name in %s; key the scan by FQCN.', $key));
                }
                $methods[$key] = $method;
            }
        }

        $reach = [];
        $entityMutators = self::entityMutators($scan['entities']);
        foreach ($methods as $key => $method) {
            $writes = $method['writes'];
            if ($method['entity'] && [] !== array_intersect($method['calls'], ['flush', 'persist', 'remove'])) {
                $writes[] = 'ORM: names a '.$scan['label'].' entity class and flushes, persists or removes';
            }
            if ($method['entity'] && [] !== ($mutators = array_intersect($method['calls'], $entityMutators))) {
                $writes[] = 'ORM: names a '.$scan['label'].' entity class and calls '.implode('(), ', $mutators).'() on it';
            }
            // `mutator_only` (roles slice 2, C2; spar P6): an entity whose ORM arm is its NAMED-property mutators alone —
            // `AppUser`, where flushing is also what the credential writers do, and only ending liveness is role
            // administration.
            if (($method['mutatorOnlyEntity'] ?? false) && [] !== ($mutators = array_intersect($method['calls'], self::livenessMutators($scan['mutator_only'] ?? [])))) {
                $writes[] = 'ORM: names a mutator-only entity class and calls '.implode('(), ', $mutators).'() on it';
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
     * @param array{class-string, class-string} $entities
     *
     * @return list<string>
     */
    private static function entityMutators(array $entities): array
    {
        $names = [];

        foreach ($entities as $class) {
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
     * string literals spell, the methods it calls, and whether it names one of
     * the scan's entity classes. Closures belong to the method they are in.
     *
     * The token walk itself is `App\Tests\Support\PhpSourceScan::scan()`,
     * shared with S-7(c)'s `WriteOwnershipTest`; this adapter is everything
     * about it that belongs to the lock-first scan.
     *
     * @param array{label: string, sql: non-empty-string, dql: non-empty-string, entities: array{class-string, class-string}} $scan
     *
     * @return array<string, array{public: bool, class: string, name: string, writes: list<string>, calls: list<string>, entity: bool}>
     */
    private static function methodsIn(string $source, array $scan): array
    {
        $entityShortNames = array_map(
            static fn (string $class): string => substr((string) strrchr('\\'.$class, '\\'), 1),
            $scan['entities'],
        );
        $mutatorOnlyShortNames = array_map(
            static fn (string $class): string => substr((string) strrchr('\\'.$class, '\\'), 1),
            array_keys($scan['mutator_only'] ?? []),
        );
        $methods = [];

        $scanned = PhpSourceScan::scan($source);
        foreach ($scanned['methods'] as $key => $method) {
            $writes = [];
            // The method's own literals and those of the constants it reaches
            // (`self::SQL`), each as its runtime value, escapes decoded.
            foreach (PhpSourceScan::literalsReachedBy($scanned, $key) as $literal) {
                // `also` (roles slice 2, C2): further write patterns — the role scan's `app_user` liveness arms.
                foreach ([$scan['sql'], $scan['dql'], ...($scan['also'] ?? [])] as $pattern) {
                    if (1 === preg_match($pattern, $literal['value'], $m)) {
                        $writes[] = 'SQL: '.preg_replace('/\s+/', ' ', $m[0]);
                        break;
                    }
                }
            }

            $methods[$key] = [
                'public' => $method['public'],
                'class' => $method['class'],
                'name' => $method['name'],
                'writes' => $writes,
                'calls' => $method['calls'],
                'entity' => [] !== array_intersect($method['named'], $entityShortNames),
                'mutatorOnlyEntity' => [] !== array_intersect($method['named'], $mutatorOnlyShortNames),
            ];
        }

        return $methods;
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
    private function raceAgainstAHolder(string $orderId, array $before, callable $request, array $after = [], string $table = 'purchase_order'): array
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
                'HOLD_TABLE' => $table,
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

    private function assertSalesOrderStatus(string $orderId, string $status): void
    {
        self::assertSame([['status' => $status]], $this->fetchAsOwnerForTenant(self::TENANT_A,
            'SELECT status FROM sales_order WHERE id = ?::uuid', [$orderId]));
    }

    /** A `draft` sales order with one live line, so a confirmation of it is not refused for being empty. */
    /**
     * A CONFIRMED sales order with one line of `$quantity`, and one stocked bin per entry of `$stock` in its
     * warehouse, the bins returned in ascending uuid order (W-5's order within one item).
     *
     * @param list<string> $stock
     *
     * @return array{order: string, line: string, item: string, warehouse: string, bins: list<string>}
     */
    private function confirmedSalesOrderWithStock(string $number, string $quantity, array $stock): array
    {
        $warehouse = $this->seedWarehouse(self::TENANT_A, 'WH '.$number);
        $order = $this->seedSalesOrder(self::TENANT_A, $number, warehouseId: $warehouse, status: SalesOrder::STATUS_CONFIRMED, orderedAt: '2026-09-20T10:00:00Z');
        $item = $this->seedItem(self::TENANT_A, 'SKU-'.$number);
        $line = $this->seedSalesOrderLine(self::TENANT_A, $order, $item, quantity: $quantity);
        $bins = [];
        foreach ($stock as $i => $qty) {
            $bin = Uuid::v4()->toRfc4122();
            $this->insertAsOwnerForTenant(self::TENANT_A,
                'INSERT INTO storage_location (id, tenant_id, warehouse_id, code) VALUES (?::uuid, ?::uuid, ?::uuid, ?)',
                [$bin, self::TENANT_A, $warehouse, 'R-0'.$i]);
            $this->insertAsOwnerForTenant(self::TENANT_A,
                'INSERT INTO stock_level (id, tenant_id, inventory_item_id, storage_location_id, qty_on_hand) VALUES (?::uuid, ?::uuid, ?::uuid, ?::uuid, ?::numeric)',
                [Uuid::v4()->toRfc4122(), self::TENANT_A, $item, $bin, $qty]);
            $bins[] = $bin;
        }
        sort($bins, \SORT_STRING);

        return ['order' => $order, 'line' => $line, 'item' => $item, 'warehouse' => $warehouse, 'bins' => $bins];
    }

    private function seedConfirmableSalesOrder(string $number): string
    {
        $order = $this->seedSalesOrder(self::TENANT_A, $number);
        $this->seedSalesOrderLine(self::TENANT_A, $order, $this->seedItem(self::TENANT_A, 'SKU-'.$number));

        return $order;
    }
}
