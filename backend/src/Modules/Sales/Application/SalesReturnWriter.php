<?php

declare(strict_types=1);

namespace App\Modules\Sales\Application;

use App\Modules\AuditLog\Infrastructure\SalesReturnAuditWriter;
use App\Modules\Inventory\Application\Contract\ReturnStock;
use App\Modules\Inventory\Application\Contract\StockReturn;
use App\Modules\Inventory\Application\Contract\StockReturnLine;
use App\Modules\Inventory\Application\Contract\StockReturnRefused;
use App\Modules\Sales\Infrastructure\Entity\Invoice;
use App\Modules\UserManagement\Application\Contract\OwnerPredicate;
use App\Platform\Http\Idempotency\IdempotencyKeyHash;
use App\Platform\Http\Idempotency\RequestDigest;
use App\Platform\Http\Problem\ApiProblem;
use App\Platform\Http\Rfc3339Timestamp;
use App\Platform\Security\Identity;
use App\Platform\Security\RequestIdentity;
use App\Platform\Tenancy\CurrentTenant;
use App\Platform\Tenancy\TenantContext;
use App\SharedKernel\Display\QuantityRendering;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * **`CreateSalesReturn` — goods a customer sent back against one shipment, one transaction.** Sales step 7, commit R2
 * (2026-10-01), built from `docs/session13/output9-sales-8-design.md` §4.1 under the owner's rulings of 2026-09-30
 * (`output9-sales-8-ruling.md`: Q-L (a′), Q-M, Q-C, Q-G, Q-K) and `SEC`'s (`output9-sales-8-sec.md`, `-l5-1-sec.md`).
 *
 * `FulfilmentWriter`'s statement order, copied deliberately — each step says only what differs:
 *
 *  1. The E-1 gate (`sales_return.create`) has already run (`PermissionGate`).
 *  2. The fulfilment, for **not-found only** (E-2: another workspace's and a random uuid are one 404).
 *  3. `SalesOrderRowLock::holdInside()` on the fulfilment's ORDER — first (`ARCH` §6(c), W-5's sales document order):
 *     a return and `IssueInvoice` on one shipment serialise here, so "deducted xor creditable" is decided once.
 *  4. The replay read by the key's hash, under the lock; a digest mismatch is `idempotency-key-conflict`. A match
 *     returns the first return, 201 with the same body (`CreateFulfilment`'s choice, measured — design §13 item 4).
 *  5. Each line's `fulfilment_line_id` must be a line of THIS fulfilment (V2-S-9) — else `unknown_reference`.
 *  6. The per-line bound, read under the lock: returned ≤ shipped − returned before
 *     (`sales_return.quantity_within_shipped`); and `returned_at` not before the shipment
 *     (`sales_return.returned_after_shipment`).
 *  7. OI-2 R3: `recorded_in_error` once a LIVE invoice exists is refused (`sales_return.recorded_in_error_before_invoice`);
 *     the refusal says the shipment is invoiced, and names the number only to an `invoice.view` holder (R-14, `SEC` S-4).
 *  8. `invoice_id` := the fulfilment's live invoice, or NULL (design F-2: no clock decides "before issue").
 *  8a. **`deducts`** (owner ruling Q-L (a′)) := no live invoice AND (the owner marker, OR `fulfilment.create` held by
 *     someone other than the shipment's recorder). Decided ONCE and stored; a later role change reclassifies nothing.
 *     It refuses nothing: a non-qualifying return is simply creditable, so finance (SoD-5) sees it.
 *  9. The bins: a `recorded_in_error` line goes back to the bin it was taken from (Q-M; a different bin is
 *     `sales_return.error_returns_to_its_bin`, an archived origin bin `sales_return.origin_bin_is_live`, G-27); every
 *     other line names a bin, which `ReturnStock` checks is a live bin of the SHIPPING warehouse (quarantine allowed).
 * 10. The number: `RET-` + six digits, `MAX + 1`, the millionth refused before formatting (CD-7 / G-13).
 * 11. `INSERT sales_return`, `INSERT sales_return_line` × n — raw SQL naming the columns (grant: R2's migration).
 * 12. `ReturnStock::returnStock()` — movements, then `stock_level` last and ascending (W-5). Its refusals translated.
 * 13. `sales_return.created`, metadata `{"reason", "deducts"}`, on the insert branch only (`SEC` S-5, final item 5).
 * 14. The 201 body, read back inside the transaction.
 * 15. A 23505 outside the failed transaction: the key (`idempotency-key-conflict`, the order id is digested) or the
 *     number ("try again") — two returns on DIFFERENT orders hold different locks, so both uniques can race.
 *
 * **What it deliberately does NOT do:** touch the order or its lines (owner ruling Q-C: a return reopens nothing, G-25);
 * write a credit (that is `IssueCreditNote`'s, under `credit_note.issue`, SoD-5); refuse a return because nobody may
 * deduct it (Q-L (a′) classifies, never refuses).
 */
final readonly class SalesReturnWriter
{
    public const OPERATION = 'CreateSalesReturn';

    /**
     * The document's reason domain (`sales_return_reason_values`), as the owner ruled it (Q-I, 2026-10-01,
     * `Version20261001000400`): one value per party at fault, a catch-all, and OI-2 R3's `recorded_in_error`.
     */
    public const REASONS = ['damaged_in_transit', 'wrong_item_shipped', 'defective', 'no_longer_wanted', 'other', 'recorded_in_error'];

    public const RECORDED_IN_ERROR = 'recorded_in_error';

    /** numeric(19,6): at most 13 digits before the point. `\A…\z`, never `^…$`. */
    private const QUANTITY = '/\A\d{1,13}(\.\d{1,6})?\z/';

    private const LINE_FIELDS = ['fulfilment_line_id', 'quantity', 'storage_location_id'];

    /** `FulfilmentWriter::MAXIMUM_LINES`, for its reason: the work is done holding the order's row lock. */
    public const MAXIMUM_LINES = 200;

    private const CLOCK_SKEW_SECONDS = 60;

    private const IDEMPOTENCY_INDEX = 'sales_return_idem_unique';

    private const NUMBER_INDEX = 'sales_return_number_unique';

    public const LAST_NUMBER = 999999;

    public function __construct(
        private TenantContext $tenants,
        private CurrentTenant $tenant,
        private RequestIdentity $identities,
        private ReturnStock $stock,
        private SalesReturnReader $reader,
        private SalesReturnAuditWriter $audit,
    ) {
    }

    /**
     * @param array<mixed>|null $rawLines `lines` as decoded, unchecked
     */
    public function create(string $fulfilmentId, ?string $returnedAtValue, mixed $reason, ?array $rawLines, string $idempotencyKey): SalesReturnView
    {
        if (!Uuid::isValid($fulfilmentId)) {
            throw ApiProblem::notFound();
        }
        $fulfilmentId = Uuid::fromString($fulfilmentId)->toRfc4122();

        $returnedAt = self::returnedAt($returnedAtValue);
        $reason = self::reason($reason);
        $lines = self::lines($rawLines, self::RECORDED_IN_ERROR === $reason);

        $digest = RequestDigest::of(self::OPERATION, [
            'fulfilment_id' => RequestDigest::uuid($fulfilmentId),
            'returned_at' => RequestDigest::instant($returnedAtValue),
            'reason' => $reason,
            'lines' => self::digestedLines($lines),
        ]);
        $keyHash = IdempotencyKeyHash::of($idempotencyKey);
        $identity = $this->identities->authenticated();
        $id = Uuid::v4()->toRfc4122();

        try {
            return $this->tenants->run(
                $this->tenant->tenantId(),
                fn (EntityManagerInterface $em): SalesReturnView => $this->createInside(
                    $em, $id, $fulfilmentId, $returnedAt, $reason, $lines, $identity, $keyHash, $digest,
                ),
            );
        } catch (UniqueConstraintViolationException $e) {
            // 15. Outside `run()`: the failed statement aborted the transaction.
            if (str_contains($e->getMessage(), self::IDEMPOTENCY_INDEX)) {
                // A same-order retry waited at the row lock and read the first from the replay, so a collision here
                // is the key reused against ANOTHER shipment's order -- a different request (the fulfilment is digested).
                throw ApiProblem::idempotencyKeyConflict();
            }

            if (str_contains($e->getMessage(), self::NUMBER_INDEX)) {
                throw ApiProblem::businessRuleViolated(
                    'sales_return.sales_return_number_is_unique_per_workspace',
                    'Another return was recorded in this workspace at the same moment. Try again.',
                );
            }

            throw $e;
        }
    }

    /**
     * @param list<array{fulfilmentLine: string, quantity: string, bin: ?string}> $lines
     */
    private function createInside(
        EntityManagerInterface $em,
        string $id,
        string $fulfilmentId,
        \DateTimeImmutable $returnedAt,
        string $reason,
        array $lines,
        Identity $identity,
        string $keyHash,
        string $digest,
    ): SalesReturnView {
        $connection = $em->getConnection();

        // 2.
        $fulfilment = $connection->fetchAssociative(
            'SELECT sales_order_id, warehouse_id, shipped_at, shipped_by_user_id FROM fulfilment WHERE id = ?::uuid',
            [$fulfilmentId],
        );
        if (false === $fulfilment) {
            throw ApiProblem::notFound();
        }

        // 3.
        SalesOrderRowLock::holdInside($em, (string) $fulfilment['sales_order_id']);

        // 4.
        $replay = $connection->fetchAssociative(
            'SELECT id, request_digest FROM sales_return WHERE idempotency_key = ?',
            [$keyHash],
        );
        if (false !== $replay) {
            if (!hash_equals((string) $replay['request_digest'], $digest)) {
                throw ApiProblem::idempotencyKeyConflict();
            }

            return $this->reader->readInside($em, (string) $replay['id'])
                ?? throw new \LogicException('A sales return found by its key is not readable in the same transaction.');
        }

        // 5. The shipped lines of THIS fulfilment, each with what has already come back against it -- under the lock.
        $shipped = [];
        foreach ($connection->fetchAllAssociative(<<<'SQL'
            SELECT fl.id, fl.quantity, fl.storage_location_id, sol.inventory_item_id, sol.line_number,
                   COALESCE((SELECT SUM(srl.quantity) FROM sales_return_line srl WHERE srl.fulfilment_line_id = fl.id), 0)::numeric(19,6) AS returned
              FROM fulfilment_line fl
              JOIN sales_order_line sol ON sol.tenant_id = fl.tenant_id AND sol.id = fl.sales_order_line_id
             WHERE fl.fulfilment_id = ?::uuid
            SQL, [$fulfilmentId]) as $row) {
            $shipped[(string) $row['id']] = $row;
        }

        $errors = [];
        foreach ($lines as $index => $line) {
            if (!isset($shipped[$line['fulfilmentLine']])) {
                $errors[] = self::error($index, 'fulfilment_line_id', 'unknown_reference', 'No line of this shipment has this id.');
            }
        }
        if ([] !== $errors) {
            throw ApiProblem::validationFailed($errors);
        }

        // 6.
        if ($returnedAt < new \DateTimeImmutable((string) $fulfilment['shipped_at'])) {
            throw ApiProblem::businessRuleViolated(
                'sales_return.returned_after_shipment',
                'Goods cannot come back before they were shipped. Check the return date.',
            );
        }
        foreach ($lines as $line) {
            $row = $shipped[$line['fulfilmentLine']];
            $after = bcadd((string) $row['returned'], $line['quantity'], 6);
            if (1 === bccomp($after, (string) $row['quantity'], 6)) {
                throw ApiProblem::businessRuleViolated(
                    'sales_return.quantity_within_shipped',
                    \sprintf('Order line %d shipped %s on this shipment and %s has already come back, so %s more cannot be returned against it.',
                        (int) $row['line_number'],
                        QuantityRendering::render((string) $row['quantity']),
                        QuantityRendering::render((string) $row['returned']),
                        QuantityRendering::render($line['quantity'])),
                );
            }
        }

        // 7. and 8. The fulfilment's LIVE invoice, read under the order lock (`IssueInvoice` takes the same lock first).
        $live = $connection->fetchAssociative(
            'SELECT id, invoice_number FROM invoice WHERE fulfilment_id = ?::uuid AND status <> ?::text',
            [$fulfilmentId, Invoice::STATUS_VOID],
        );
        if (self::RECORDED_IN_ERROR === $reason && false !== $live) {
            $detail = 'This shipment has already been invoiced, so it can no longer be recorded as shipped in error.';
            if ($identity->holds('invoice.view')) {
                $detail .= ' It is on '.$live['invoice_number'].'.';
            }

            throw ApiProblem::businessRuleViolated('sales_return.recorded_in_error_before_invoice', $detail);
        }
        $invoiceId = false === $live ? null : (string) $live['id'];

        // 8a. Q-L (a′), decided once.
        $deducts = null === $invoiceId && (
            OwnerPredicate::holdsMarker($identity->permissionCodes)
            || ($identity->holds('fulfilment.create') && $identity->userId !== (string) $fulfilment['shipped_by_user_id'])
        );

        // 9. Bins.
        $bins = [];
        foreach ($lines as $index => $line) {
            $origin = (string) $shipped[$line['fulfilmentLine']]['storage_location_id'];
            if (self::RECORDED_IN_ERROR === $reason) {
                if (null !== $line['bin'] && $line['bin'] !== $origin) {
                    throw ApiProblem::businessRuleViolated(
                        'sales_return.error_returns_to_its_bin',
                        \sprintf('A shipment recorded in error goes back to the location it was taken from. Line %d left from a different location; omit the location or send that one.', $index),
                    );
                }
                $bins[$index] = $origin;
            } else {
                $bins[$index] = (string) $line['bin'];
            }
        }
        $codes = $this->refuseUnknownBins($em, $lines, $bins);
        if (self::RECORDED_IN_ERROR === $reason) {
            $archived = $connection->fetchFirstColumn(
                'SELECT id::text FROM storage_location WHERE id = ANY (?::uuid[]) AND deleted_at IS NOT NULL',
                ['{'.implode(',', array_unique($bins)).'}'],
            );
            if ([] !== $archived) {
                throw ApiProblem::businessRuleViolated(
                    'sales_return.origin_bin_is_live',
                    \sprintf('Location %s, where these goods were taken from, has been closed, so the shipment cannot be recorded in error. Reopen the location first.',
                        $codes[(string) $archived[0]] ?? (string) $archived[0]),
                );
            }
        }

        // 10. and 11.
        $connection->executeStatement(<<<'SQL'
            INSERT INTO sales_return
                (id, tenant_id, fulfilment_id, invoice_id, sales_return_number, reason, returned_at,
                 recorded_by_user_id, deducts, idempotency_key, request_digest)
            VALUES (?::uuid, ?::uuid, ?::uuid, ?::uuid, ?, ?, ?::timestamptz, ?::uuid, ?::boolean, ?, ?)
            SQL, [
                $id, $identity->tenantId, $fulfilmentId, $invoiceId, $this->nextReturnNumber($em), $reason,
                $returnedAt->format(\DateTimeInterface::RFC3339_EXTENDED), $identity->userId, $deducts ? 'true' : 'false',
                $keyHash, $digest,
            ]);

        foreach ($lines as $index => $line) {
            $connection->executeStatement(<<<'SQL'
                INSERT INTO sales_return_line
                    (id, tenant_id, sales_return_id, fulfilment_line_id, storage_location_id, quantity)
                VALUES (?::uuid, ?::uuid, ?::uuid, ?::uuid, ?::uuid, ?::numeric)
                SQL, [Uuid::v4()->toRfc4122(), $identity->tenantId, $id, $line['fulfilmentLine'], $bins[$index], $line['quantity']]);
        }

        // 12.
        try {
            $this->stock->returnStock(new StockReturn(
                salesReturnId: $id,
                warehouseId: (string) $fulfilment['warehouse_id'],
                occurredAt: $returnedAt,
                lines: array_map(static fn (array $line, int $index): StockReturnLine => new StockReturnLine(
                    (string) $shipped[$line['fulfilmentLine']]['inventory_item_id'],
                    $bins[$index],
                    $line['quantity'],
                ), $lines, array_keys($lines)),
            ));
        } catch (StockReturnRefused $e) {
            throw self::translated($e);
        }

        // 13.
        $this->audit->recordCreated(
            tenantId: $identity->tenantId,
            actorUserId: $identity->userId,
            actorLabel: $identity->actorLabel,
            salesReturnId: $id,
            reason: $reason,
            deducts: $deducts,
        );

        // 14.
        return $this->reader->readInside($em, $id)
            ?? throw new \LogicException('The sales return just inserted is not visible to its own transaction.');
    }

    /**
     * ST-5's oracle for the bins: another workspace's and one that does not exist are the same "unknown".
     *
     * @param list<array{fulfilmentLine: string, quantity: string, bin: ?string}> $lines
     * @param array<int, string>                                                  $bins
     *
     * @return array<string, string> bin id => its code
     */
    private function refuseUnknownBins(EntityManagerInterface $em, array $lines, array $bins): array
    {
        $codes = [];
        foreach ($em->getConnection()->fetchAllAssociative(
            'SELECT id::text AS id, code FROM storage_location WHERE id = ANY(?::uuid[])',
            ['{'.implode(',', array_unique($bins)).'}'],
        ) as $row) {
            $codes[(string) $row['id']] = (string) $row['code'];
        }

        $errors = [];
        foreach (array_keys($lines) as $index) {
            if (!isset($codes[$bins[$index]])) {
                $errors[] = self::error($index, 'storage_location_id', 'unknown_reference', 'No storage location in this workspace has this id.');
            }
        }
        if ([] !== $errors) {
            throw ApiProblem::validationFailed($errors);
        }

        return $codes;
    }

    /** `ReturnStock`'s refusals, pointed at this request's own fields. */
    private static function translated(StockReturnRefused $e): \Throwable
    {
        if ('warehouse.is_live' === $e->rule()) {
            return ApiProblem::businessRuleViolated($e->rule(),
                'The warehouse these goods were shipped from has been closed, so it cannot take them back.');
        }

        $index = $e->lineIndex();
        if (null !== $index && str_starts_with($e->rule(), 'storage_location.')) {
            return ApiProblem::validationFailed([self::error($index, 'storage_location_id', $e->rule(),
                'This location is not a live location of the warehouse the goods were shipped from.')]);
        }

        if (null !== $index && 'stock_level.returned_quantity_fits' === $e->rule()) {
            return ApiProblem::validationFailed([self::error($index, 'quantity', $e->rule(),
                'The quantity returned into this location, summed over this return, is larger than a stock level can hold.')]);
        }

        // posted-once, no-lines and quantity cannot arrive from here: the id is minted above, the list is non-empty
        // and `lines()` applied the quantity check first. A caller bug, a 500.
        return $e;
    }

    private function nextReturnNumber(EntityManagerInterface $em): string
    {
        $next = (int) $em->getConnection()->fetchOne(<<<'SQL'
            SELECT COALESCE(MAX(substring(sales_return_number from 5)::bigint), 0) + 1
              FROM sales_return
             WHERE sales_return_number ~ '^RET-[0-9]+$'
            SQL);

        // CD-7 / G-13: `sales_return_number_shape` pins six digits, so the millionth is refused here in domain terms,
        // decided BEFORE any formatting. It runs after the replay read, so a retry of the 999,999th still gets its return.
        if ($next > self::LAST_NUMBER) {
            throw ApiProblem::businessRuleViolated(
                'sales_return.number_series_is_exhausted',
                'This workspace has used every return number from RET-000001 to RET-999999, so no further return can be recorded. Numbers are never reissued.',
            );
        }

        return 'RET-'.str_pad((string) $next, 6, '0', \STR_PAD_LEFT);
    }

    /** `FulfilmentWriter::shippedAt()`'s rule: refused beyond a minute of skew, clamped inside it. Absent means now. */
    private static function returnedAt(?string $value): \DateTimeImmutable
    {
        $now = new \DateTimeImmutable();

        if (null === $value) {
            return $now;
        }

        $parsed = Rfc3339Timestamp::parse($value) ?? throw ApiProblem::validationFailed([
            ['pointer' => '/returned_at', 'code' => 'not_a_timestamp', 'message' => 'This field must be an RFC 3339 timestamp.'],
        ]);

        if ($parsed > $now->modify('+'.self::CLOCK_SKEW_SECONDS.' seconds')) {
            throw ApiProblem::validationFailed([
                ['pointer' => '/returned_at', 'code' => 'in_the_future', 'message' => 'Goods cannot come back in the future. Omit the field to mean now.'],
            ]);
        }

        return min($parsed, $now);
    }

    private static function reason(mixed $value): string
    {
        if (null === $value) {
            throw ApiProblem::validationFailed([['pointer' => '/reason', 'code' => 'required', 'message' => 'This field is required.']]);
        }
        if (!\is_string($value) || !\in_array($value, self::REASONS, true)) {
            throw ApiProblem::validationFailed([['pointer' => '/reason', 'code' => 'not_a_reason',
                'message' => 'This field must be one of: '.implode(', ', self::REASONS).'.']]);
        }

        return $value;
    }

    /**
     * The shape of `lines`, decidable without the database. A `storage_location_id` is required unless the return is
     * `recorded_in_error`, whose lines go back to the bin they left from (owner ruling Q-M; design §4.1 step 11).
     *
     * @param array<mixed>|null $raw
     *
     * @return list<array{fulfilmentLine: string, quantity: string, bin: ?string}>
     */
    private static function lines(?array $raw, bool $recordedInError): array
    {
        if (null === $raw) {
            throw ApiProblem::validationFailed([['pointer' => '/lines', 'code' => 'required', 'message' => 'This field is required.']]);
        }

        if ([] === $raw) {
            throw ApiProblem::validationFailed([['pointer' => '/lines', 'code' => 'at_least_one_line', 'message' => 'A return must take back at least one line.']]);
        }

        if (!array_is_list($raw)) {
            throw ApiProblem::validationFailed([['pointer' => '/lines', 'code' => 'not_a_list', 'message' => 'This field must be a list of lines.']]);
        }

        if (\count($raw) > self::MAXIMUM_LINES) {
            throw ApiProblem::validationFailed([[
                'pointer' => '/lines', 'code' => 'too_many_lines',
                'message' => \sprintf('A return may take back at most %d lines in one request. Record the rest as a further return against the same shipment.', self::MAXIMUM_LINES),
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

            $value = $line['fulfilment_line_id'] ?? null;
            if (null === $value) {
                $errors[] = self::error($index, 'fulfilment_line_id', 'required', 'This field is required.');
            } elseif (!\is_string($value) || !Uuid::isValid($value)) {
                $errors[] = self::error($index, 'fulfilment_line_id', 'not_a_uuid', 'This field must be a UUID naming a row in this workspace.');
            }

            $bin = $line['storage_location_id'] ?? null;
            if (null === $bin) {
                if (!$recordedInError) {
                    $errors[] = self::error($index, 'storage_location_id', 'required', 'This field is required.');
                }
            } elseif (!\is_string($bin) || !Uuid::isValid($bin)) {
                $errors[] = self::error($index, 'storage_location_id', 'not_a_uuid', 'This field must be a UUID naming a row in this workspace.');
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

            $fulfilmentLine = Uuid::fromString((string) $value)->toRfc4122();
            if (isset($seen[$fulfilmentLine])) {
                $errors[] = self::error($index, 'fulfilment_line_id', 'duplicate_line',
                    \sprintf('The line at /lines/%d already returns this shipped line. Send one line per shipped line.', $seen[$fulfilmentLine]));
                continue;
            }
            $seen[$fulfilmentLine] = $index;

            $lines[] = [
                'fulfilmentLine' => $fulfilmentLine,
                'quantity' => bcadd((string) $quantity, '0', 6),
                'bin' => null === $bin ? null : Uuid::fromString((string) $bin)->toRfc4122(),
            ];
        }

        if ([] !== $errors) {
            throw ApiProblem::validationFailed($errors);
        }

        return $lines;
    }

    /**
     * The lines as §6.2 digests them, sorted by `fulfilment_line_id` (a return has no line order; the sort is total
     * because `lines()` refuses a duplicate).
     *
     * @param list<array{fulfilmentLine: string, quantity: string, bin: ?string}> $lines
     *
     * @return list<array{fulfilment_line_id: string, quantity: string, storage_location_id: ?string}>
     */
    private static function digestedLines(array $lines): array
    {
        usort($lines, static fn (array $a, array $b): int => $a['fulfilmentLine'] <=> $b['fulfilmentLine']);

        return array_map(static fn (array $line): array => [
            'fulfilment_line_id' => $line['fulfilmentLine'],
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
