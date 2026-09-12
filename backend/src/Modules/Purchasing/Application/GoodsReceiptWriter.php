<?php

declare(strict_types=1);

namespace App\Modules\Purchasing\Application;

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
 *  2. The replay read, by the key's hash. Before any status check, so a retry
 *     of a receipt that completed the order (now `received`) is answered with
 *     that receipt and not refused as illegal from `received`. Under the lock,
 *     so two identical retries against one order queue, and the second READS
 *     the first's receipt instead of losing at the unique index.
 *  3. The order (not-found, archived) and its live lines, read under the lock.
 *  4. `PurchaseOrder::recordReceipt()` — the status guard and the new status,
 *     decided from the locked lines plus this request, BEFORE any write.
 *  5. Over-receipt, per order line, summed over this receipt.
 *  6. `INSERT goods_receipt` + lines, `UPDATE purchase_order_line`, as
 *     statements, so they are written before step 7 (`wrapInTransaction()`
 *     would flush an ORM-persisted receipt after the callback — after the stock).
 *  7. `ReceiveStock::receive()` — `stock_movement`, then `stock_level` last.
 *  8. `flush()` — the order's new status. Safe after the stock write: it
 *     rewrites the order row this transaction locked in step 1.
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
 *   - **`received_at` in the future is refused.** Goods cannot have arrived
 *     yet. There is no tolerance: a client that means "now" omits the field.
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

    private const IDEMPOTENCY_INDEX = 'goods_receipt_idem_unique';

    private const NUMBER_INDEX = 'goods_receipt_number_unique';

    public function __construct(
        private TenantContext $tenants,
        private CurrentTenant $tenant,
        private RequestIdentity $identities,
        private ReceiveStock $stock,
        private GoodsReceiptReader $reader,
        private PurchaseOrderReceiptStatus $orders,
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
        $receivedBy = $this->identities->authenticated()->userId;
        $id = Uuid::v4()->toRfc4122();

        try {
            return $this->tenants->run(
                $this->tenant->tenantId(),
                fn (EntityManagerInterface $em): GoodsReceiptView => $this->createInside(
                    $em, $id, $orderId, $lines, $receivedAt, $receivedBy, $keyHash, $digest,
                ),
            );
        } catch (UniqueConstraintViolationException $e) {
            // Outside `run()`: the failed statement aborted the transaction.
            if (str_contains($e->getMessage(), self::IDEMPOTENCY_INDEX)) {
                // Same key, committed meanwhile against ANOTHER order (a retry
                // on this order would have waited at the row lock and read it).
                throw ApiProblem::businessRuleViolated(
                    'goods_receipt.idempotency_key_is_in_flight',
                    'A request with this Idempotency-Key is already being processed. Try again.',
                );
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
        string $keyHash,
        string $digest,
    ): GoodsReceiptView {
        $connection = $em->getConnection();

        // 1.
        PurchaseOrderRowLock::holdInside($em, $orderId);

        // 2.
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

        // 3.
        $order = $connection->fetchAssociative(
            'SELECT warehouse_id, deleted_at FROM purchase_order WHERE id = ?::uuid',
            [$orderId],
        );
        if (false === $order) {
            throw ApiProblem::notFound();
        }
        if (null !== $order['deleted_at']) {
            throw ApiProblem::businessRuleViolated(
                'purchase_order.is_archived',
                'This purchase order is archived. Restore it before changing it.',
            );
        }

        $live = [];
        foreach ($connection->fetchAllAssociative(
            'SELECT id, inventory_item_id, quantity_ordered, quantity_received FROM purchase_order_line
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
        foreach ($lines as $index => $line) {
            $row = $live[$line['orderLine']];
            $after = bcadd((string) $row['quantity_received'], $receiving[$line['orderLine']], 6);
            if (1 === bccomp($after, (string) $row['quantity_ordered'], 6)) {
                throw ApiProblem::businessRuleViolated(
                    'purchase_order_line.is_not_over_received',
                    \sprintf('Line %d: this receipt would take the order line to %s received of %s ordered. '
                        .'Over-receipt is not accepted.', $index, $after, (string) $row['quantity_ordered']),
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
            throw self::translated($e);
        }

        // 8.
        $em->flush();

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

    /** `ReceiveStock`'s refusals, pointed at this request's own fields. */
    private static function translated(StockReceiptRefused $e): \Throwable
    {
        $index = $e->lineIndex();

        if (null === $index) {
            return 'warehouse.is_live' === $e->rule()
                ? ApiProblem::businessRuleViolated($e->rule(), $e->getMessage())
                // posted-once and no-lines cannot arrive from here: the id is
                // minted above and the list is non-empty. A caller bug, a 500.
                : $e;
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

        return 'GR-'.str_pad((string) $next, 6, '0', \STR_PAD_LEFT);
    }

    private static function receivedAt(?string $value): \DateTimeImmutable
    {
        if (null === $value) {
            return new \DateTimeImmutable();
        }

        $parsed = Rfc3339Timestamp::parse($value) ?? throw ApiProblem::validationFailed([
            ['pointer' => '/received_at', 'code' => 'not_a_timestamp', 'message' => 'This field must be an RFC 3339 timestamp.'],
        ]);

        if ($parsed > new \DateTimeImmutable()) {
            throw ApiProblem::validationFailed([
                ['pointer' => '/received_at', 'code' => 'in_the_future', 'message' => 'Goods cannot be received in the future. Omit the field to mean now.'],
            ]);
        }

        return $parsed;
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
                    \sprintf('Line %d already receives this order line into this location. Send one line per order line and location.', $seen[$orderLine."\0".$bin]));
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
