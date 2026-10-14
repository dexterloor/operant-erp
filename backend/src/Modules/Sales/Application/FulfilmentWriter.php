<?php

declare(strict_types=1);

namespace App\Modules\Sales\Application;

use App\Modules\AuditLog\Infrastructure\FulfilmentAuditWriter;
use App\Modules\Inventory\Application\Contract\DepleteStock;
use App\Modules\Inventory\Application\Contract\StockDepletion;
use App\Modules\Inventory\Application\Contract\StockDepletionLine;
use App\Modules\Inventory\Application\Contract\StockDepletionRefused;
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
 * **`CreateFulfilment` — FLOWS F-5 step 5, one transaction.** Stock actually
 * moves here. Sales step 5, commit 4 (2026-09-22), under owner rulings R1–R9
 * (`docs/session12/output9-sales-6-ruling.md`).
 *
 * `GoodsReceiptWriter`'s statement order, copied deliberately — each step says
 * only what differs:
 *
 *  1. `SalesOrderRowLock::holdInside()` — first. A second fulfilment, a
 *     cancellation, a confirm replay of this order waits here holding nothing
 *     (W-5's "Sales locks the order, then stock").
 *  2. The order row, for **not-found only** (§5.3 / ST-5), before anything the
 *     request carries is judged.
 *  3. The replay read by the key's hash, under the lock, **before the status
 *     guard** — so the retry of the fulfilment that completed the order (now
 *     `fulfilled`) is answered with that fulfilment, not refused as illegal from
 *     `fulfilled`. `FulfilmentCreateTest` pins it.
 *  3a. The archived refusal (reachable only for a draft, which step 4 would refuse
 *     too; kept here for parity and for its message), then the order's live lines
 *     under the lock. Lines are frozen after confirm (§8.3a), so the order lock is
 *     enough. A line that is not a live line of THIS order is not-found (§5.3
 *     clause 2, V2-S-9's first half) — which also covers an archived line (CD-4's
 *     child-side note).
 *  4. `SalesOrder::recordFulfilment()` through `SalesOrderFulfilmentStatus` — the
 *     status guard and the new status, BEFORE any write.
 *  5. Over-fulfilment, per order line, summed over the request →
 *     `sales_order_line.is_not_over_fulfilled`, the domain-terms face of
 *     `sales_order_line_qty_sane`'s third clause, which stays as the backstop.
 *  6. Unknown bins → `validation-failed` `unknown_reference`, before the line
 *     INSERT's foreign key can 23503.
 *  7. `INSERT fulfilment` (with the key, the digest and the shipper), `INSERT
 *     fulfilment_line` × n, `UPDATE sales_order_line SET quantity_fulfilled`, as
 *     statements, so they run before the stock (W-5's caller half).
 *  8. `DepleteStock::deplete()` — movements, then `stock_level` last and
 *     ascending, each a conditional decrement. Its refusals are translated here.
 *  9. `flush()` — the order's status. Safe after the stock write: it rewrites the
 *     row this transaction locked in step 1.
 * 10. `fulfilment.created` (B-4a, 2026-09-29) — one row naming the shipper, in this
 *     transaction, on the insert branch only (a replay returned at step 3).
 *
 * Anything that fails rolls back all of it (W-2).
 *
 * ---------------------------------------------------------------------------
 * What it deliberately does NOT do
 * ---------------------------------------------------------------------------
 *  - **No invoice** (SoD-4, D-A): `IssueInvoice` is a separate operation under
 *    `invoice.issue`, and issuing one here would collapse the separation.
 *  - **No shipper ≠ confirmer rule.** Since B-4a (2026-09-29) the trail holds both
 *    `sales_order.confirmed` and `fulfilment.created`, so SoD-4's comparison is
 *    answerable — evidence, not enforcement. ⚠ This bullet read *"No `audit_log`
 *    row"* until then; `shipped_by` (owner ruling R6) is still the column.
 *  - **No reservation and no priority** (owner ruling R1, model A renewed):
 *    overselling is reachable, and it is refused HERE, at the pick, in domain
 *    terms — never as negative stock and never as a 500. Whoever picks first wins.
 *  - **No `stale-record`**: nothing in the request carries a version and the stock
 *    write is an atomic conditional decrement, so no optimistic-lock failure can
 *    arise (dropped from §4.2's Errors cell, design Q7).
 *
 * ---------------------------------------------------------------------------
 * Decisions stated rather than presented as derived (`GoodsReceiptWriter`'s)
 * ---------------------------------------------------------------------------
 *   - One order line may be split across two bins; the same (line, bin) twice in
 *     one request is refused as a duplicate line.
 *   - `MAXIMUM_LINES = 200`, for the lock-held-work reason.
 *   - `shipped_at` more than a minute ahead of the server is refused; inside that
 *     it is clamped. The digest uses the value as sent.
 *   - `fulfilment_number` is `FUL-` + `MAX + 1` over the workspace's fulfilments,
 *     six digits (IA D-f's `^[A-Z]{2,4}-[0-9]{6}$`; the prefix is the build's to
 *     choose and is chosen here, not ruled). A number race between two orders is
 *     "try again": only different orders can race, same-order requests serialise
 *     on the lock.
 *   - An archived ITEM still ships (the goods exist and were ordered before
 *     archival); an archived WAREHOUSE is refused (`warehouse.is_live`).
 */
final readonly class FulfilmentWriter
{
    private const OPERATION = 'CreateFulfilment';

    /** numeric(19,6): at most 13 digits before the point. `\A…\z`, never `^…$`. */
    private const QUANTITY = '/\A\d{1,13}(\.\d{1,6})?\z/';

    private const LINE_FIELDS = ['sales_order_line_id', 'quantity', 'storage_location_id'];

    /** `GoodsReceiptWriter::MAXIMUM_LINES`, for its reason: the work is done holding the order's row lock. */
    public const MAXIMUM_LINES = 200;

    private const CLOCK_SKEW_SECONDS = 60;

    private const IDEMPOTENCY_INDEX = 'fulfilment_idem_unique';

    private const NUMBER_INDEX = 'fulfilment_number_unique';

    public function __construct(
        private TenantContext $tenants,
        private CurrentTenant $tenant,
        private RequestIdentity $identities,
        private DepleteStock $stock,
        private FulfilmentReader $reader,
        private SalesOrderFulfilmentStatus $orders,
        private FulfilmentAuditWriter $audit,
    ) {
    }

    /**
     * @param string|null       $shippedAtValue `shipped_at` as sent
     * @param array<mixed>|null $rawLines       `lines` as decoded, unchecked
     */
    public function create(string $orderId, ?string $shippedAtValue, ?array $rawLines, string $idempotencyKey): FulfilmentView
    {
        if (!Uuid::isValid($orderId)) {
            throw ApiProblem::notFound();
        }
        $orderId = Uuid::fromString($orderId)->toRfc4122();

        $shippedAt = self::shippedAt($shippedAtValue);
        $lines = self::lines($rawLines);

        $digest = RequestDigest::of(self::OPERATION, [
            'sales_order_id' => RequestDigest::uuid($orderId),
            'shipped_at' => RequestDigest::instant($shippedAtValue),
            'lines' => self::digestedLines($lines),
        ]);
        $keyHash = IdempotencyKeyHash::of($idempotencyKey);
        $identity = $this->identities->authenticated();
        $shippedBy = $identity->userId;
        $id = Uuid::v4()->toRfc4122();

        try {
            return $this->tenants->run(
                $this->tenant->tenantId(),
                fn (EntityManagerInterface $em): FulfilmentView => $this->createInside(
                    $em, $id, $orderId, $lines, $shippedAt, $shippedBy, $identity->actorLabel, $keyHash, $digest,
                ),
            );
        } catch (UniqueConstraintViolationException $e) {
            // Outside `run()`: the failed statement aborted the transaction.
            if (str_contains($e->getMessage(), self::IDEMPOTENCY_INDEX)) {
                // Same key, committed between this request's replay read and its own INSERT. It can only have
                // been against ANOTHER order (a same-order retry waited at the row lock and read the first from
                // the replay), and the order id is digested — so this is a key reused for a different request.
                // `GoodsReceiptWriter`'s answer and reasoning.
                throw ApiProblem::idempotencyKeyConflict();
            }

            if (str_contains($e->getMessage(), self::NUMBER_INDEX)) {
                throw ApiProblem::businessRuleViolated(
                    'fulfilment.fulfilment_number_is_unique_per_workspace',
                    'Another shipment was recorded in this workspace at the same moment. Try again.',
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
        \DateTimeImmutable $shippedAt,
        string $shippedBy,
        string $shippedByLabel,
        string $keyHash,
        string $digest,
    ): FulfilmentView {
        $connection = $em->getConnection();

        // 1.
        SalesOrderRowLock::holdInside($em, $orderId);

        // 2.
        $order = $connection->fetchAssociative(
            'SELECT warehouse_id, deleted_at FROM sales_order WHERE id = ?::uuid',
            [$orderId],
        );
        if (false === $order) {
            throw ApiProblem::notFound();
        }

        // 3.
        $replay = $connection->fetchAssociative(
            'SELECT id, request_digest FROM fulfilment WHERE idempotency_key = ?',
            [$keyHash],
        );
        if (false !== $replay) {
            if (!hash_equals((string) $replay['request_digest'], $digest)) {
                throw ApiProblem::idempotencyKeyConflict();
            }

            return $this->reader->readInside($em, (string) $replay['id'])
                ?? throw new \LogicException('A fulfilment found by its key is not readable in the same transaction.');
        }

        // 3a.
        if (null !== $order['deleted_at']) {
            throw ApiProblem::businessRuleViolated(
                'sales_order.is_archived',
                'This sales order is archived, so nothing can be shipped against it.',
            );
        }

        $live = [];
        foreach ($connection->fetchAllAssociative(<<<'SQL'
            SELECT sol.id, sol.inventory_item_id, sol.line_number, sol.quantity, sol.quantity_fulfilled, i.sku
              FROM sales_order_line sol
              JOIN inventory_item i ON i.tenant_id = sol.tenant_id AND i.id = sol.inventory_item_id
             WHERE sol.sales_order_id = ?::uuid AND sol.deleted_at IS NULL
            SQL, [$orderId]) as $row) {
            $live[(string) $row['id']] = $row;
        }

        $fulfilling = [];
        foreach ($lines as $line) {
            if (!isset($live[$line['orderLine']])) {
                throw ApiProblem::notFound();
            }
            $fulfilling[$line['orderLine']] = bcadd($fulfilling[$line['orderLine']] ?? '0', $line['quantity'], 6);
        }

        $allFulfilled = true;
        foreach ($live as $lineId => $row) {
            $after = bcadd((string) $row['quantity_fulfilled'], $fulfilling[$lineId] ?? '0', 6);
            if (bccomp($after, (string) $row['quantity'], 6) < 0) {
                $allFulfilled = false;
            }
        }

        // 4. The status guard fires here, before anything is written.
        $this->orders->recordFulfilment($em, $orderId, $allFulfilled);

        // 5. Over-fulfilment, at the order line.
        foreach ($fulfilling as $lineId => $quantity) {
            $row = $live[$lineId];
            $after = bcadd((string) $row['quantity_fulfilled'], $quantity, 6);
            if (1 === bccomp($after, (string) $row['quantity'], 6)) {
                throw ApiProblem::businessRuleViolated(
                    'sales_order_line.is_not_over_fulfilled',
                    // Named by the `line_number` the pick screen shows, and both figures are QR-2 values: `$after`
                    // is `bcadd(..., 6)` output past the normalisation boundary, `quantity` a stored column.
                    \sprintf('Order line %d would reach %s shipped of %s ordered. Shipping more than was ordered is not accepted.',
                        (int) $row['line_number'],
                        QuantityRendering::render($after),
                        QuantityRendering::render((string) $row['quantity'])),
                );
            }
        }

        // 6.
        $codes = $this->refuseUnknownBins($em, $lines);

        // 7.
        $connection->executeStatement(<<<'SQL'
            INSERT INTO fulfilment
                (id, tenant_id, sales_order_id, warehouse_id, shipped_by_user_id,
                 fulfilment_number, shipped_at, idempotency_key, request_digest)
            VALUES (?::uuid, ?::uuid, ?::uuid, ?::uuid, ?::uuid, ?, ?::timestamptz, ?, ?)
            SQL, [
                $id, $this->tenant->tenantId(), $orderId, (string) $order['warehouse_id'], $shippedBy,
                $this->nextFulfilmentNumber($em), $shippedAt->format(\DateTimeInterface::RFC3339_EXTENDED), $keyHash, $digest,
            ]);

        foreach ($lines as $line) {
            $connection->executeStatement(<<<'SQL'
                INSERT INTO fulfilment_line
                    (id, tenant_id, fulfilment_id, sales_order_line_id, storage_location_id, quantity)
                VALUES (?::uuid, ?::uuid, ?::uuid, ?::uuid, ?::uuid, ?::numeric)
                SQL, [Uuid::v4()->toRfc4122(), $this->tenant->tenantId(), $id, $line['orderLine'], $line['bin'], $line['quantity']]);
        }

        foreach ($fulfilling as $lineId => $quantity) {
            $connection->executeStatement(
                'UPDATE sales_order_line SET quantity_fulfilled = quantity_fulfilled + ?::numeric, updated_at = now() WHERE id = ?::uuid',
                [$quantity, $lineId],
            );
        }

        // 8.
        try {
            $this->stock->deplete(new StockDepletion(
                fulfilmentId: $id,
                warehouseId: (string) $order['warehouse_id'],
                occurredAt: $shippedAt,
                lines: array_map(static fn (array $line): StockDepletionLine => new StockDepletionLine(
                    (string) $live[$line['orderLine']]['inventory_item_id'],
                    $line['bin'],
                    $line['quantity'],
                ), $lines),
            ));
        } catch (StockDepletionRefused $e) {
            throw self::translated($e, $lines, $live, $codes);
        }

        // 9.
        $em->flush();

        // 10. B-4a (roles slice 2, C1): the row naming the shipper, on the INSERT branch only — step 3's replay
        // returned above, so a retry of this request writes no second row.
        $this->audit->recordCreated(
            tenantId: $this->tenant->tenantId(),
            actorUserId: $shippedBy,
            actorLabel: $shippedByLabel,
            fulfilmentId: $id,
        );

        return $this->reader->readInside($em, $id)
            ?? throw new \LogicException('The fulfilment just inserted is not visible to its own transaction.');
    }

    /**
     * ST-5's oracle for the bins: another workspace's bin and one that does not exist are the same "unknown".
     * Whether the bin is a pickable location of THIS order's warehouse is `DepleteStock`'s check (R2) and is not
     * repeated here.
     *
     * @param list<array{orderLine: string, quantity: string, bin: string}> $lines
     *
     * @return array<string, string> bin id => its code, for the refusal messages
     */
    private function refuseUnknownBins(EntityManagerInterface $em, array $lines): array
    {
        $codes = [];
        foreach ($em->getConnection()->fetchAllAssociative(
            'SELECT id::text AS id, code FROM storage_location WHERE id = ANY(?::uuid[])',
            ['{'.implode(',', array_unique(array_column($lines, 'bin'))).'}'],
        ) as $row) {
            $codes[(string) $row['id']] = (string) $row['code'];
        }

        $errors = [];
        foreach ($lines as $index => $line) {
            if (!isset($codes[$line['bin']])) {
                $errors[] = self::error($index, 'storage_location_id', 'unknown_reference', 'No storage location in this workspace has this id.');
            }
        }

        if ([] !== $errors) {
            throw ApiProblem::validationFailed($errors);
        }

        return $codes;
    }

    /**
     * `DepleteStock`'s refusals, pointed at this request's own fields (design §5.3).
     *
     * **The on-hand refusal is a 409, not a 422**: it is a fact about the bin's state, not about the request's
     * shape. Its message names the item's `sku`, the bin's `code`, **every** order `line_number` that asked that
     * bin for that item (two lines for one item into one bin are one decrement — RED R-4(c)), and what the bin
     * held **when this was checked** (read after the refused decrement, without a lock — RED R-4(a)); a bin with
     * no stock at all reads 0 (R-4(b)). Disclosing on-hand is harmless for every default holder of
     * `fulfilment.create`, all of which hold `stock_level.view`; a composed role without it is `SEC`'s recorded
     * acceptance (PC-9's second exception).
     *
     * @param list<array{orderLine: string, quantity: string, bin: string}> $lines
     * @param array<string, array<string, mixed>>                           $live
     * @param array<string, string>                                         $codes
     */
    private static function translated(StockDepletionRefused $e, array $lines, array $live, array $codes): \Throwable
    {
        if ('stock_level.on_hand_covers_the_depletion' === $e->rule()) {
            $lineNumbers = [];
            $sku = '';
            foreach ($e->lineIndexes() as $index) {
                $row = $live[$lines[$index]['orderLine']];
                $lineNumbers[] = (int) $row['line_number'];
                $sku = (string) $row['sku'];
            }
            sort($lineNumbers);
            $lineNumbers = array_values(array_unique($lineNumbers));

            return ApiProblem::businessRuleViolated(
                $e->rule(),
                \sprintf('%s %s ask%s for %s of %s from location %s, which held %s when this was checked. Nothing was shipped.',
                    1 === \count($lineNumbers) ? 'Order line' : 'Order lines',
                    implode(', ', $lineNumbers),
                    1 === \count($lineNumbers) ? 's' : '',
                    QuantityRendering::render((string) $e->requested()),
                    $sku,
                    $codes[(string) $e->storageLocationId()] ?? (string) $e->storageLocationId(),
                    QuantityRendering::render((string) $e->onHand())),
            );
        }

        if ('warehouse.is_live' === $e->rule()) {
            return ApiProblem::businessRuleViolated($e->rule(), $e->getMessage());
        }

        $index = $e->lineIndex();
        if (null !== $index && str_starts_with($e->rule(), 'storage_location.')) {
            return ApiProblem::validationFailed([self::error($index, 'storage_location_id', $e->rule(), $e->getMessage())]);
        }

        // posted-once and no-lines cannot arrive from here: the id is minted above and the list is non-empty; a
        // quantity refusal cannot either, `lines()` applied the same check first. A caller bug, a 500.
        return $e;
    }

    private function nextFulfilmentNumber(EntityManagerInterface $em): string
    {
        $next = (int) $em->getConnection()->fetchOne(<<<'SQL'
            SELECT COALESCE(MAX(substring(fulfilment_number from 5)::bigint), 0) + 1
              FROM fulfilment
             WHERE fulfilment_number ~ '^FUL-[0-9]+$'
            SQL);

        // CD-7 (owner ruling CD7-1, 2026-09-28): `fulfilment_number_shape` pins the number to exactly six digits, so the
        // millionth is refused here in domain terms rather than minted seven digits wide (which text-sorts beside
        // `…100000`) or left to the CHECK as a 23514 -> 500. Counted over every row, archived ones included (Q4:
        // numbers are never reissued). ⚠ This runs from the INSERT, i.e. AFTER the replay read, deliberately: a
        // retry of the 999,999th create must still get its own document back (D-B) -- do not hoist it into an
        // early pre-flight.
        if ($next > 999999) {
            throw ApiProblem::businessRuleViolated(
                'fulfilment.number_series_is_exhausted',
                'This workspace has used every shipment number from FUL-000001 to FUL-999999, so no further shipment can be recorded. Numbers are never reissued.',
            );
        }

        return 'FUL-'.str_pad((string) $next, 6, '0', \STR_PAD_LEFT);
    }

    /** `GoodsReceiptWriter::receivedAt()`, for its reasons: refused beyond a minute of skew, clamped inside it. */
    private static function shippedAt(?string $value): \DateTimeImmutable
    {
        $now = new \DateTimeImmutable();

        if (null === $value) {
            return $now;
        }

        $parsed = Rfc3339Timestamp::parse($value) ?? throw ApiProblem::validationFailed([
            ['pointer' => '/shipped_at', 'code' => 'not_a_timestamp', 'message' => 'This field must be an RFC 3339 timestamp.'],
        ]);

        if ($parsed > $now->modify('+'.self::CLOCK_SKEW_SECONDS.' seconds')) {
            throw ApiProblem::validationFailed([
                ['pointer' => '/shipped_at', 'code' => 'in_the_future', 'message' => 'Goods cannot be shipped in the future. Omit the field to mean now.'],
            ]);
        }

        return min($parsed, $now);
    }

    /**
     * The shape of `lines`, decidable without the database. Ids canonical, quantities at scale 6.
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

        if ([] === $raw) {
            throw ApiProblem::validationFailed([['pointer' => '/lines', 'code' => 'at_least_one_line', 'message' => 'A fulfilment must ship at least one line.']]);
        }

        if (!array_is_list($raw)) {
            throw ApiProblem::validationFailed([['pointer' => '/lines', 'code' => 'not_a_list', 'message' => 'This field must be a list of lines.']]);
        }

        // Before the transaction and the order's row lock: a request refused for its size never held that lock.
        if (\count($raw) > self::MAXIMUM_LINES) {
            throw ApiProblem::validationFailed([[
                'pointer' => '/lines', 'code' => 'too_many_lines',
                'message' => \sprintf('A fulfilment may ship at most %d lines in one request. Ship the first %d and send '
                    .'the rest as a further fulfilment against the same order.', self::MAXIMUM_LINES, self::MAXIMUM_LINES),
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

            foreach (['sales_order_line_id', 'storage_location_id'] as $field) {
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

            $orderLine = Uuid::fromString((string) $line['sales_order_line_id'])->toRfc4122();
            $bin = Uuid::fromString((string) $line['storage_location_id'])->toRfc4122();

            if (isset($seen[$orderLine."\0".$bin])) {
                $errors[] = self::error($index, 'storage_location_id', 'duplicate_line',
                    \sprintf('The line at /lines/%d already ships this order line from this location. '
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
     * The lines as §6.2 digests them — sorted by `(sales_order_line_id, storage_location_id)`, because a
     * fulfilment has no line order (`GoodsReceiptWriter::digestedLines()`'s argument; the sort is total because
     * `lines()` refuses a duplicate pair).
     *
     * @param list<array{orderLine: string, quantity: string, bin: string}> $lines
     *
     * @return list<array{sales_order_line_id: string, quantity: string, storage_location_id: string}>
     */
    private static function digestedLines(array $lines): array
    {
        usort($lines, static fn (array $a, array $b): int => [$a['orderLine'], $a['bin']] <=> [$b['orderLine'], $b['bin']]);

        return array_map(static fn (array $line): array => [
            'sales_order_line_id' => $line['orderLine'],
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
