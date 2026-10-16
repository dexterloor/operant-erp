<?php

declare(strict_types=1);

namespace App\Modules\Purchasing\Application;

use App\Modules\AuditLog\Infrastructure\GoodsReceiptAuditWriter;
use App\Modules\Inventory\Application\Contract\ReceiveStock;
use App\Modules\Inventory\Application\Contract\StockReceipt;
use App\Modules\Inventory\Application\Contract\StockReceiptLine;
use App\Modules\Inventory\Application\Contract\StockReceiptRefused;
use App\Platform\Http\Idempotency\IdempotencyKeyHash;
use App\Platform\Http\Idempotency\RequestDigest;
use App\Platform\Http\Problem\ApiProblem;
use App\Platform\Http\Rfc3339Timestamp;
use App\Platform\Security\RequestIdentity;
use App\Platform\Tenancy\CurrentTenant;
use App\Platform\Tenancy\TenantContext;
use App\SharedKernel\Display\QuantityRendering;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * **`CreateGoodsReceipt` — FLOWS F-3 step 5, one transaction.**
 *
 * ===========================================================================
 * The order of statements, and why each is where it is
 * ===========================================================================
 *  1. `PurchaseOrderRowLock::holdInside()` — first, so a second receipt, a
 *     cancellation or an edit of this order waits here holding nothing (6c;
 *     W-5's "lock the purchase order first").
 *  2. The order row, for **not-found only** (§5.3 / ST-5). It answers before
 *     anything the request carries is judged, so a key reused against an order
 *     this caller cannot see is 404 rather than a 409 telling them the key was
 *     used in this workspace. **Amended 2026-09-12:** step 8-ii read the replay
 *     first and answered 409 there.
 *  3. The replay read, by the key's hash. Before any status check, so a retry
 *     of a receipt that completed the order (now `received`) is answered with
 *     that receipt and not refused as illegal from `received`; and before the
 *     **archived** refusal, which is the half that deliberately did NOT move up
 *     with the not-found check — an accepted receipt is a fact, and its retry is
 *     answered from the row whatever has happened to the order since. Under the
 *     lock, so two identical retries against one order queue, and the second
 *     READS the first's receipt instead of losing at the unique index.
 *  3a. The archived refusal, then the order's live lines, under the lock.
 *  4. `PurchaseOrder::recordReceipt()` — the status guard and the new status,
 *     decided from the locked lines plus this request, BEFORE any write.
 *  5. Over-receipt, per order line, summed over this receipt.
 *  6. `INSERT goods_receipt` + lines, `UPDATE purchase_order_line`, as
 *     statements, so they are written before step 7 (`wrapInTransaction()`
 *     would flush an ORM-persisted receipt after the callback — after the stock).
 *  7. `ReceiveStock::receive()` — `stock_movement`, then `stock_level` last.
 *  8. `flush()` — the order's new status. Safe after the stock write: it
 *     rewrites the order row this transaction locked in step 1.
 *  9. `goods_receipt.created` (B-4a, 2026-09-29) — one row naming the receiver,
 *     in this transaction, on the insert branch only (a replay returned at 3).
 *
 * Anything that fails rolls back all of it (W-2): `receive()` joins this
 * transaction and opens none.
 *
 * ===========================================================================
 * Decisions no document specifies, stated here rather than presented as derived
 * ===========================================================================
 *   - **One order line into two bins is allowed**; the same (order line, bin)
 *     twice in one receipt is refused. The table's grain is the bin, and a
 *     delivery split across two shelves is ordinary. Two lines for the same bin
 *     are refused rather than summed, so what is stored is what was sent.
 *   - **`received_at` more than a minute ahead of the server is refused; inside
 *     that it is clamped to the server's now.** Goods cannot have arrived yet,
 *     but a client stamping "now" off a clock a second fast is not asking for
 *     the future. See `receivedAt()` for why the tolerance exists at all.
 *   - `receipt_number` is `GR-` + `MAX + 1` over the workspace's receipts,
 *     `order_number`'s precedent, with the same race answered "try again".
 *   - Quantities are digested at scale 6, so `"6"` and `"6.000000"` are one
 *     request.
 */
final readonly class GoodsReceiptWriter
{
    private const OPERATION = 'CreateGoodsReceipt';

    /** numeric(19,6): at most 13 digits before the point. `\A…\z`, never `^…$`. */
    private const QUANTITY = '/\A\d{1,13}(\.\d{1,6})?\z/';

    private const LINE_FIELDS = ['purchase_order_line_id', 'quantity', 'storage_location_id'];

    /**
     * **The most lines one request may deliver, and the reason is this
     * operation's own rather than a copy of `CreatePurchaseOrder`'s.**
     *
     * 8a capped `CreatePurchaseOrder` at 200 and left this uncapped, and 8a's
     * reviewer found the asymmetry was **the wrong way round**. `create()` over
     * there inserts an order no other transaction can address until it commits
     * and takes **no row lock at all**. This one takes
     * `PurchaseOrderRowLock::holdInside()` on the order first and then, inside
     * that lock, writes one `goods_receipt_line` per request line, one `UPDATE
     * purchase_order_line` per order line touched and one stock movement per
     * line. **An uncapped receipt is therefore an unbounded amount of work held
     * in front of every other write to that order** — its approval, its
     * cancellation, every line edit, every other delivery — all of which queue
     * on the same lock. That is the reason, and it is a property of this write
     * rather than a claim about response sizes.
     *
     * **The two reasons 8a first gave for its own 200 are false and are not
     * repeated here** (see `PurchaseOrderWriter::MAXIMUM_LINES`, which records
     * them as false): the number is `API.md` §10.1 / API-17's page ceiling, not
     * §4.2's, and `ListPurchaseOrderLines` is unpaginated, so nothing overflows a
     * page. What the shared number means is only this: **the largest batch this
     * API accepts in one request against one purchase order is one magnitude,
     * and is reviewed in one place.**
     *
     * The tie is asserted in
     * `GoodsReceiptCreateTest::aReceiptWithMoreLinesThanTheCapIsRefusedBeforeAnythingIsLocked`
     * rather than by importing `PurchaseOrderWriter::MAXIMUM_LINES` here: that
     * import would add a new class-pair edge to `deptrac.yaml`, which registers
     * those edges per class pair, for a value neither class derives from the
     * other.
     *
     * **Public because the refusal message names the number**, and because that
     * test reads it.
     */
    public const MAXIMUM_LINES = 200;

    /**
     * How far ahead of the server a client's clock may be before `received_at`
     * is refused rather than clamped. See {@see self::receivedAt()}.
     */
    private const CLOCK_SKEW_SECONDS = 60;

    private const IDEMPOTENCY_INDEX = 'goods_receipt_idem_unique';

    private const NUMBER_INDEX = 'goods_receipt_number_unique';

    public function __construct(
        private TenantContext $tenants,
        private CurrentTenant $tenant,
        private RequestIdentity $identities,
        private ReceiveStock $stock,
        private GoodsReceiptReader $reader,
        private PurchaseOrderReceiptStatus $orders,
        private GoodsReceiptAuditWriter $audit,
    ) {
    }

    /**
     * @param string|null       $receivedAtValue `received_at` as sent
     * @param array<mixed>|null $rawLines        `lines` as decoded, unchecked
     */
    public function create(string $orderId, ?string $receivedAtValue, ?array $rawLines, string $idempotencyKey): GoodsReceiptView
    {
        if (!Uuid::isValid($orderId)) {
            throw ApiProblem::notFound();
        }

        $receivedAt = self::receivedAt($receivedAtValue);
        $lines = self::lines($rawLines);

        $digest = RequestDigest::of(self::OPERATION, [
            'purchase_order_id' => RequestDigest::uuid($orderId),
            'received_at' => RequestDigest::instant($receivedAtValue),
            'lines' => self::digestedLines($lines),
        ]);
        $keyHash = IdempotencyKeyHash::of($idempotencyKey);
        $identity = $this->identities->authenticated();
        $receivedBy = $identity->userId;
        $id = Uuid::v4()->toRfc4122();

        try {
            return $this->tenants->run(
                $this->tenant->tenantId(),
                fn (EntityManagerInterface $em): GoodsReceiptView => $this->createInside(
                    $em, $id, $orderId, $lines, $receivedAt, $receivedBy, $identity->actorLabel, $keyHash, $digest,
                ),
            );
        } catch (UniqueConstraintViolationException $e) {
            // Outside `run()`: the failed statement aborted the transaction.
            if (str_contains($e->getMessage(), self::IDEMPOTENCY_INDEX)) {
                // Same key, committed between this request's replay read and its
                // own INSERT. It can only have been against ANOTHER order: a
                // second receipt on THIS order would have waited at the row lock
                // and then read the first from the replay. Another order means
                // another `purchase_order_id`, which is digested — so this is a
                // key reused for a different request, and §6.2 answers that with
                // `idempotency-key-conflict`.
                //
                // It said "already being processed, try again" until 2026-09-12,
                // which was advice into a wall: the winner is committed, the key
                // is spent, and every retry from here conflicts. It also gave two
                // different problems for one situation depending on whether the
                // winner committed a microsecond before or after the replay read.
                //
                // `PurchaseOrderWriter`'s equivalent catch still says "try
                // again", and the two are NOT the same case, so do not
                // harmonise them: `CreatePurchaseOrder` has no row to lock, so
                // its racer may be a genuine identical retry, and the next
                // attempt reads the committed row and is handed the order. Here
                // the order lock has already serialised every same-order retry,
                // so the only thing left to lose to is a different order.
                throw ApiProblem::idempotencyKeyConflict();
            }

            if (str_contains($e->getMessage(), self::NUMBER_INDEX)) {
                throw ApiProblem::businessRuleViolated(
                    'goods_receipt.receipt_number_is_unique_per_workspace',
                    'Another delivery was recorded in this workspace at the same moment. Try again.',
                );
            }

            throw $e;
        }
    }

    /**
     * @param list<array{orderLine: string, quantity: string, bin: string}> $lines
     */
    private function createInside(
        EntityManagerInterface $em,
        string $id,
        string $orderId,
        array $lines,
        \DateTimeImmutable $receivedAt,
        string $receivedBy,
        string $receivedByLabel,
        string $keyHash,
        string $digest,
    ): GoodsReceiptView {
        $connection = $em->getConnection();

        // 1.
        PurchaseOrderRowLock::holdInside($em, $orderId);

        // 2. §5.3 / ST-5: an order this caller cannot see is not-found, and that
        // is answered BEFORE anything the request carries is judged — otherwise
        // the refusal tells the caller their key was used here, which is a fact
        // about this workspace's traffic that they have not earned. Only the
        // not-found half runs here; `deleted_at` is checked after the replay.
        $order = $connection->fetchAssociative(
            'SELECT warehouse_id, deleted_at FROM purchase_order WHERE id = ?::uuid',
            [$orderId],
        );
        if (false === $order) {
            throw ApiProblem::notFound();
        }

        // 3.
        $replay = $connection->fetchAssociative(
            'SELECT id, request_digest FROM goods_receipt WHERE idempotency_key = ?',
            [$keyHash],
        );
        if (false !== $replay) {
            if (!hash_equals((string) $replay['request_digest'], $digest)) {
                throw ApiProblem::idempotencyKeyConflict();
            }

            return $this->reader->readInside($em, (string) $replay['id'])
                ?? throw new \LogicException('A receipt found by its key is not readable in the same transaction.');
        }

        // The archived refusal stays BEHIND the replay on purpose: a receipt
        // that was accepted is a fact, and a retry of the request that created
        // it is answered from the row whatever has happened to the order since.
        if (null !== $order['deleted_at']) {
            throw ApiProblem::businessRuleViolated(
                'purchase_order.is_archived',
                'This purchase order is archived, so nothing can be received against it.',
            );
        }

        $live = [];
        foreach ($connection->fetchAllAssociative(
            'SELECT id, inventory_item_id, line_number, quantity_ordered, quantity_received FROM purchase_order_line
              WHERE purchase_order_id = ?::uuid AND deleted_at IS NULL',
            [$orderId],
        ) as $row) {
            $live[(string) $row['id']] = $row;
        }

        // §5.3 clause 2: a line of another order, or an archived one, is not a
        // line of this order, and the path pair is answered as not-found.
        $receiving = [];
        foreach ($lines as $line) {
            if (!isset($live[$line['orderLine']])) {
                throw ApiProblem::notFound();
            }
            $receiving[$line['orderLine']] = bcadd($receiving[$line['orderLine']] ?? '0', $line['quantity'], 6);
        }

        $allReceived = true;
        foreach ($live as $lineId => $row) {
            $after = bcadd((string) $row['quantity_received'], $receiving[$lineId] ?? '0', 6);
            if (bccomp($after, (string) $row['quantity_ordered'], 6) < 0) {
                $allReceived = false;
            }
        }

        // 4. The status guard fires here, before anything is written.
        $this->orders->recordReceipt($em, $orderId, $allReceived);

        // 5. F-3 step 3: over-receipt is blocked at the line level.
        foreach ($lines as $line) {
            $row = $live[$line['orderLine']];
            $after = bcadd((string) $row['quantity_received'], $receiving[$line['orderLine']], 6);
            if (1 === bccomp($after, (string) $row['quantity_ordered'], 6)) {
                throw ApiProblem::businessRuleViolated(
                    'purchase_order_line.is_not_over_received',
                    // `business-rule-violated` carries no pointer, so the line is
                    // named in the message. The rule is about the ORDER line, so
                    // it is named by the `line_number` the receive page shows
                    // (V2-C-19, 2026-09-16) -- not by the request's 0-based
                    // pointer, which is not the user's terms (ST-3).
                    //
                    // Both numbers are QR-2 values and neither is an echo (QR-5,
                    // CD-1 step 2): `$after` is `bcadd(..., 6)` output past the
                    // normalisation boundary below, and `quantity_ordered` is a
                    // stored `numeric(19,6)` column. Rendered, not interpolated
                    // raw -- this refusal read "would reach 11.000000 received of
                    // 10.000000 ordered" until 2026-09-18, which is the defect
                    // `STATES.md` QR was written to close.
                    \sprintf('Order line %d would reach %s received of %s ordered. Over-receipt is not accepted.',
                        (int) $row['line_number'],
                        QuantityRendering::render($after),
                        QuantityRendering::render((string) $row['quantity_ordered'])),
                );
            }
        }

        $this->refuseUnknownBins($em, $lines);

        // 6.
        $connection->executeStatement(<<<'SQL'
            INSERT INTO goods_receipt
                (id, tenant_id, purchase_order_id, warehouse_id, received_by_user_id,
                 receipt_number, received_at, idempotency_key, request_digest)
            VALUES (?::uuid, ?::uuid, ?::uuid, ?::uuid, ?::uuid, ?, ?::timestamptz, ?, ?)
            SQL, [
                $id, $this->tenant->tenantId(), $orderId, (string) $order['warehouse_id'], $receivedBy,
                $this->nextReceiptNumber($em), $receivedAt->format(\DateTimeInterface::RFC3339_EXTENDED), $keyHash, $digest,
            ]);

        foreach ($lines as $line) {
            $connection->executeStatement(<<<'SQL'
                INSERT INTO goods_receipt_line
                    (id, tenant_id, goods_receipt_id, purchase_order_line_id, storage_location_id, quantity_received)
                VALUES (?::uuid, ?::uuid, ?::uuid, ?::uuid, ?::uuid, ?::numeric)
                SQL, [Uuid::v4()->toRfc4122(), $this->tenant->tenantId(), $id, $line['orderLine'], $line['bin'], $line['quantity']]);
        }

        foreach ($receiving as $lineId => $quantity) {
            $connection->executeStatement(
                'UPDATE purchase_order_line SET quantity_received = quantity_received + ?::numeric, updated_at = now() WHERE id = ?::uuid',
                [$quantity, $lineId],
            );
        }

        // 7.
        try {
            $this->stock->receive(new StockReceipt(
                goodsReceiptId: $id,
                warehouseId: (string) $order['warehouse_id'],
                occurredAt: $receivedAt,
                lines: array_map(static fn (array $line): StockReceiptLine => new StockReceiptLine(
                    (string) $live[$line['orderLine']]['inventory_item_id'],
                    $line['bin'],
                    $line['quantity'],
                ), $lines),
            ));
        } catch (StockReceiptRefused $e) {
            throw self::translated($e, $lines, $live);
        }

        // 8.
        $em->flush();

        // 9. B-4a (roles slice 2, C1): the row naming the receiver, on the INSERT branch only — step 3's replay
        // returned above, so a retry of this request writes no second row.
        $this->audit->recordCreated(
            tenantId: $this->tenant->tenantId(),
            actorUserId: $receivedBy,
            actorLabel: $receivedByLabel,
            goodsReceiptId: $id,
        );

        return $this->reader->readInside($em, $id)
            ?? throw new \LogicException('The receipt just inserted is not visible to its own transaction.');
    }

    /**
     * ST-5's oracle for the bins: another workspace's bin and one that does not
     * exist are the same "unknown". This runs before the line INSERT because
     * that INSERT's composite foreign key would otherwise refuse such a bin as a
     * 23503 with no pointer. Whether the bin belongs to THIS order's warehouse,
     * and is live, is `ReceiveStock`'s check (step 7) and is not repeated here.
     *
     * @param list<array{orderLine: string, quantity: string, bin: string}> $lines
     */
    private function refuseUnknownBins(EntityManagerInterface $em, array $lines): void
    {
        $known = $em->getConnection()->fetchFirstColumn(
            'SELECT id::text FROM storage_location WHERE id = ANY(?::uuid[])',
            ['{'.implode(',', array_unique(array_column($lines, 'bin'))).'}'],
        );

        $errors = [];
        foreach ($lines as $index => $line) {
            if (!\in_array($line['bin'], $known, true)) {
                $errors[] = self::error($index, 'storage_location_id', 'unknown_reference', 'No storage location in this workspace has this id.');
            }
        }

        if ([] !== $errors) {
            throw ApiProblem::validationFailed($errors);
        }
    }

    /**
     * `ReceiveStock`'s refusals, pointed at this request's own fields.
     *
     * @param list<array{orderLine: string, quantity: string, bin: string}> $lines
     * @param array<string, array<string, mixed>>                           $live  order line id => its row
     */
    private static function translated(StockReceiptRefused $e, array $lines, array $live): \Throwable
    {
        $index = $e->lineIndex();

        if (null === $index) {
            return 'warehouse.is_live' === $e->rule()
                ? ApiProblem::businessRuleViolated($e->rule(), $e->getMessage())
                // posted-once and no-lines cannot arrive from here: the id is
                // minted above and the list is non-empty. A caller bug, a 500.
                : $e;
        }

        // A fact about the bin, not the request (`StockReceiptRefused::receivedQuantityWouldOverfillTheLevel`): a 409,
        // as a depletion the bin cannot cover is. Sentence: `UX` O-1 (`docs/session13/stock-adjustment-ux.md` §2.4), with
        // "Line %d" read as V2-C-19's ordered-line number (a 409 carries no pointer). Code `stock_level.on_hand_fits`:
        // UX's suggestion, SEC TO CONFIRM. C1 appends O-1's count sentence once the count exists (ST-3).
        if ('stock_level.on_hand_fits' === $e->rule()) {
            // Named by the ordered line's `line_number`, the number the receive page shows (V2-C-19's rule for a
            // `business-rule-violated` message), never the request's 0-based index.
            return ApiProblem::businessRuleViolated($e->rule(), \sprintf(
                'Order line %d: receiving this would take the stock of this item in this location past the largest quantity that can be recorded. Nothing was received.',
                (int) $live[$lines[$index]['orderLine']]['line_number'],
            ));
        }

        $field = str_starts_with($e->rule(), 'storage_location.') ? 'storage_location_id' : 'quantity';

        return ApiProblem::validationFailed([self::error($index, $field, $e->rule(), $e->getMessage())]);
    }

    private function nextReceiptNumber(EntityManagerInterface $em): string
    {
        $next = (int) $em->getConnection()->fetchOne(<<<'SQL'
            SELECT COALESCE(MAX(substring(receipt_number from 4)::bigint), 0) + 1
              FROM goods_receipt
             WHERE receipt_number ~ '^GR-[0-9]+$'
            SQL);

        // CD-7 (owner ruling CD7-1, 2026-09-28): `goods_receipt_number_shape` pins the number to exactly six digits, so the
        // millionth is refused here in domain terms rather than minted seven digits wide (which text-sorts beside
        // `…100000`) or left to the CHECK as a 23514 -> 500. Counted over every row, archived ones included (Q4:
        // numbers are never reissued). ⚠ This runs from the INSERT, i.e. AFTER the replay read, deliberately: a
        // retry of the 999,999th create must still get its own document back (D-B) -- do not hoist it into an
        // early pre-flight.
        if ($next > 999999) {
            throw ApiProblem::businessRuleViolated(
                'goods_receipt.number_series_is_exhausted',
                'This workspace has used every goods receipt number from GR-000001 to GR-999999, so no further receipt can be recorded. Numbers are never reissued.',
            );
        }

        return 'GR-'.str_pad((string) $next, 6, '0', \STR_PAD_LEFT);
    }

    /**
     * **A future `received_at` is refused, but a clock slightly fast is not — it
     * is clamped to the server's own now.**
     *
     * Step 8-ii refused anything after now with no tolerance at all, on the
     * reasoning that goods cannot have arrived yet. The reasoning is right and
     * the rule was still wrong for the client this field has. **F-3 never
     * collects `received_at`**, so the operator does not type it: the requests
     * that carry one are either deliberately backdating a delivery, which is
     * unaffected, or mechanically stamping *"now"* from the sending machine's
     * clock. An unsynchronised clock one second fast made the second kind a 422
     * saying goods cannot be received in the future, about a delivery standing on
     * the dock — an answer with nothing the caller can do about it.
     *
     * Stating in §4.2 that the field is *for backdating only, omit it to mean
     * now* was the alternative and does not fix it: a client that stamps now is
     * still refused, and now has documentation saying it should have known.
     *
     * **Clamped rather than stored as sent**, so `goods_receipt.received_at` and
     * the `stock_movement.occurred_at` taken from it are never in the future,
     * which is what any later read of the ledger by date depends on. The client
     * can see the stored instant: §4.4 branch 4 returns the row.
     *
     * **The digest is unaffected** — `RequestDigest::instant()` is given the
     * value **as sent** (§6.2: what was sent, never what the write resolved it
     * to), so a genuine retry of the same request is still a replay, and two
     * different stamps a second apart are still two different requests.
     */
    private static function receivedAt(?string $value): \DateTimeImmutable
    {
        $now = new \DateTimeImmutable();

        if (null === $value) {
            return $now;
        }

        $parsed = Rfc3339Timestamp::parse($value) ?? throw ApiProblem::validationFailed([
            ['pointer' => '/received_at', 'code' => 'not_a_timestamp', 'message' => 'This field must be an RFC 3339 timestamp.'],
        ]);

        if ($parsed > $now->modify('+'.self::CLOCK_SKEW_SECONDS.' seconds')) {
            throw ApiProblem::validationFailed([
                ['pointer' => '/received_at', 'code' => 'in_the_future', 'message' => 'Goods cannot be received in the future. Omit the field to mean now.'],
            ]);
        }

        return min($parsed, $now);
    }

    /**
     * The shape of `lines`, decidable without the database. Ids are returned in
     * canonical form and quantities at scale 6.
     *
     * @param array<mixed>|null $raw
     *
     * @return list<array{orderLine: string, quantity: string, bin: string}>
     */
    private static function lines(?array $raw): array
    {
        if (null === $raw) {
            throw ApiProblem::validationFailed([['pointer' => '/lines', 'code' => 'required', 'message' => 'This field is required.']]);
        }

        // D-C / C6: a receipt with nothing on it records no delivery.
        if ([] === $raw) {
            throw ApiProblem::validationFailed([['pointer' => '/lines', 'code' => 'at_least_one_line', 'message' => 'A goods receipt must record at least one line.']]);
        }

        if (!array_is_list($raw)) {
            throw ApiProblem::validationFailed([['pointer' => '/lines', 'code' => 'not_a_list', 'message' => 'This field must be a list of lines.']]);
        }

        // Checked here, before `create()` opens a transaction and takes the
        // order's row lock -- a request refused for its size must never have
        // held that lock.
        if (\count($raw) > self::MAXIMUM_LINES) {
            throw ApiProblem::validationFailed([[
                'pointer' => '/lines', 'code' => 'too_many_lines',
                'message' => \sprintf('A goods receipt may record at most %d lines in one request. '
                    .'Record the first %d and send the rest as a further receipt against the same order.',
                    self::MAXIMUM_LINES, self::MAXIMUM_LINES),
            ]]);
        }

        $errors = [];
        $lines = [];
        $seen = [];

        foreach ($raw as $index => $line) {
            if (!\is_array($line) || array_is_list($line) && [] !== $line) {
                $errors[] = ['pointer' => '/lines/'.$index, 'code' => 'not_an_object', 'message' => 'Each line must be an object.'];
                continue;
            }

            foreach (array_diff(array_keys($line), self::LINE_FIELDS) as $unknown) {
                $errors[] = self::error($index, (string) $unknown, 'undeclared', 'This field is not accepted.');
            }

            foreach (['purchase_order_line_id', 'storage_location_id'] as $field) {
                $value = $line[$field] ?? null;
                if (null === $value) {
                    $errors[] = self::error($index, $field, 'required', 'This field is required.');
                } elseif (!\is_string($value) || !Uuid::isValid($value)) {
                    $errors[] = self::error($index, $field, 'not_a_uuid', 'This field must be a UUID naming a row in this workspace.');
                }
            }

            $quantity = $line['quantity'] ?? null;
            if (null === $quantity) {
                $errors[] = self::error($index, 'quantity', 'required', 'This field is required.');
            } elseif (!\is_string($quantity) || 1 !== preg_match(self::QUANTITY, $quantity) || 1 !== bccomp($quantity, '0', 6)) {
                $errors[] = self::error($index, 'quantity', 'not_a_positive_decimal', 'This field must be a positive decimal string with at most 13 integer digits and 6 fractional digits.');
            }

            if ([] !== $errors) {
                continue;
            }

            $orderLine = Uuid::fromString((string) $line['purchase_order_line_id'])->toRfc4122();
            $bin = Uuid::fromString((string) $line['storage_location_id'])->toRfc4122();

            if (isset($seen[$orderLine."\0".$bin])) {
                $errors[] = self::error($index, 'storage_location_id', 'duplicate_line',
                    \sprintf('The line at /lines/%d already receives this order line into this location. '
                        .'Send one line per order line and location.', $seen[$orderLine."\0".$bin]));
                continue;
            }
            $seen[$orderLine."\0".$bin] = $index;

            $lines[] = ['orderLine' => $orderLine, 'quantity' => bcadd((string) $quantity, '0', 6), 'bin' => $bin];
        }

        if ([] !== $errors) {
            throw ApiProblem::validationFailed($errors);
        }

        return $lines;
    }

    /**
     * The lines as §6.2 digests them — **sorted by `(purchase_order_line_id,
     * storage_location_id)`, because a goods receipt has no line order.**
     *
     * `RequestDigest` keeps list order deliberately, and for
     * `CreatePurchaseOrder` that is right: `purchase_order_line.line_number` is
     * minted from the position, so line 1 and line 2 swapped is a different
     * order. **`goods_receipt_line` has no line number at all.** Its row is
     * identified by the order line and the bin, `GoodsReceiptReader` sorts the
     * read shape by the order line's `line_number` and then the bin rather than
     * by anything the request carried, and the same two lines sent the other way
     * round record exactly the same delivery. Digesting the request order made
     * that retry a 409 — the client did what P-5 asks and was told it asked for
     * something else.
     *
     * **The sort is total, which is what makes it safe to sort at all.** A
     * partial order would make the digest depend on the input order again for the
     * entries it cannot separate. `self::lines()` already refuses the same
     * `(order line, bin)` pair twice in one receipt as a duplicate line, so no
     * two entries here can compare equal, and `quantity` is not needed as a
     * tie-break — deliberately, since a value that cannot break a tie must not be
     * allowed to decide an order.
     *
     * @param list<array{orderLine: string, quantity: string, bin: string}> $lines
     *
     * @return list<array{purchase_order_line_id: string, quantity: string, storage_location_id: string}>
     */
    private static function digestedLines(array $lines): array
    {
        usort($lines, static fn (array $a, array $b): int
            => [$a['orderLine'], $a['bin']] <=> [$b['orderLine'], $b['bin']]);

        return array_map(static fn (array $line): array => [
            'purchase_order_line_id' => $line['orderLine'],
            'quantity' => $line['quantity'],
            'storage_location_id' => $line['bin'],
        ], $lines);
    }

    /** @return array{pointer: string, code: string, message: string} */
    private static function error(int $index, string $field, string $code, string $message): array
    {
        return ['pointer' => '/lines/'.$index.'/'.$field, 'code' => $code, 'message' => $message];
    }
}
