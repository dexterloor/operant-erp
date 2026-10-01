<?php

declare(strict_types=1);

namespace App\Modules\Sales\Application;

use App\Modules\AuditLog\Infrastructure\CreditNoteAuditWriter;
use App\Modules\Sales\Infrastructure\Entity\Invoice;
use App\Platform\Http\Idempotency\IdempotencyKeyHash;
use App\Platform\Http\Idempotency\RequestDigest;
use App\Platform\Http\Problem\ApiProblem;
use App\Platform\Security\Identity;
use App\Platform\Security\RequestIdentity;
use App\Platform\Tenancy\CurrentTenant;
use App\Platform\Tenancy\TenantContext;
use App\SharedKernel\Display\QuantityRendering;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * **`IssueCreditNote` — the only reduction of an issued invoice (OI-2), for goods a customer returned.** Sales step 7
 * C1 (2026-10-01), built from `docs/session13/output9-sales-8-design.md` §4.4 under the owner's rulings of 2026-09-30
 * (`output9-sales-8-ruling.md`: Q-A = B′ — a credit with no return is `IssueAdjustmentCreditNote`, C1b; Q-E — a paid
 * invoice cannot be credited yet; Q-B; Q-H) and ADR-008 A2.1b + SoD-5, applied in the same commit.
 *
 * The statement order (design §4.4):
 *   1. The E-1 gate (`credit_note.issue`) has already run — `PermissionGate`.
 *   2.–3. **`InvoiceRowLock`, the transaction's first statement** (V2-R-17's mechanism: the void, the payment, the
 *      reversal and every other credit take the same lock), then the invoice re-read under it; another workspace's
 *      invoice and a random id are the same 404. **No `SalesOrderRowLock`** (design step 3): nothing the credit reads is
 *      written concurrently except under this lock, or only GROWS a bound (a concurrent return makes more creditable).
 *   4. The replay, by key hash, under the lock (the fulfilment precedent): same digest → the note; else 409.
 *   5. Status, re-read: `void` → 409 `invoice.is_not_void` (V2-R-17's mirror); `paid` → 409 `invoice.is_not_paid` (Q-E).
 *   6. The return: visible and of this invoice's fulfilment, else 422 `/sales_return_id`; stamped for credit against
 *      THIS invoice ({@see SalesReturnReader::stampedForCredit()}, the shared predicate's stamp half — one text), else 409
 *      `credit_note.return_is_against_this_invoice`. Each line names a shipped line of the invoice's fulfilment
 *      (V2-S-9) that the return returned, else 422.
 *   7. Bounds per shipped line, under the lock: `credited(l) + q ≤ billed(l)` → 409 `credit_note.quantity_within_billed`;
 *      `q ≤ returned on that return line − credited against it` → 409 `credit_note.quantity_within_returned` (V2-S-9).
 *      `billed` and both credited terms are `SalesReturnReader`'s own expressions — one copy each.
 *   7a. **The recomputation must equal what was billed** (RED M-7): `Σ round(billed(l) × p, 4)` over every shipped line
 *      equals `invoice.total_amount`, else refused 500-class and NEVER credited — the issue formula changed, or the
 *      invoice was written outside the application (G-15).
 *   8. A line priced `≤ 0` is not creditable → 409 `credit_note.line_is_creditable` (defensive: `p < 0` only by SQL, M-12).
 *   8b. Each line's amount TELESCOPED, `round((C + q) × p, 4) − round(C × p, 4)` (on the database's numeric rounding), so
 *      the credits on a line never exceed what it billed; a line may telescope to 0 (RED L-1); a note whose every line
 *      is 0 → 409 `credit_note.amount_is_positive`.
 *   9. `CN-` + six digits (G-13's cap, `credit_note.number_series_is_exhausted`).
 *   10. One `INSERT … SELECT` (currency copied from the invoice, the issuer the caller, the total Σ amounts), then the lines.
 *   10a. 23505 after the failed transaction: the key → `idempotencyKeyConflict()`, the number →
 *      `credit_note.credit_note_number_is_unique_per_workspace` (credits on different invoices hold different locks).
 *   11. `credit_note.issued` `{"reason"}` (`SEC` S-5), insert branch only.
 *   12. 201, the note read back (`Location: /credit-notes/{id}`).
 *
 * **What it deliberately does NOT do:** credit without a return (C1b's operation, on its own code, owner-only — Q-A
 * B′); credit a paid invoice (Q-E, G-23); edit or void a credit note (Q-K, G-21: none can be undone).
 */
final readonly class CreditNoteWriter
{
    public const OPERATION_ISSUE = 'IssueCreditNote';

    /**
     * The credit reasons `IssueCreditNote` accepts: a credit for returned goods. `credit_note_return_iff_goods_returned`
     * ties the value to a named return; the adjustment reasons are C1b's.
     */
    public const ISSUE_REASONS = ['goods_returned'];

    /** numeric(19,6): at most 13 digits before the point. */
    private const QUANTITY = '/\A\d{1,13}(\.\d{1,6})?\z/';

    private const LINE_FIELDS = ['fulfilment_line_id', 'quantity'];

    /** `SalesReturnWriter::MAXIMUM_LINES`, for its reason: the work is done holding a row lock. */
    public const MAXIMUM_LINES = 200;

    private const IDEMPOTENCY_INDEX = 'credit_note_idem_unique';

    private const NUMBER_INDEX = 'credit_note_number_unique';

    public const LAST_NUMBER = 999999;

    public function __construct(
        private TenantContext $tenants,
        private CurrentTenant $tenant,
        private RequestIdentity $identities,
        private CreditNoteReader $reader,
        private CreditNoteAuditWriter $audit,
    ) {
    }

    /**
     * @param array<mixed>|null $rawLines `lines` as decoded, unchecked
     */
    public function issue(string $invoiceId, mixed $reason, mixed $salesReturnId, ?array $rawLines, string $idempotencyKey): CreditNoteView
    {
        if (!Uuid::isValid($invoiceId)) {
            throw ApiProblem::notFound();
        }
        $invoiceId = Uuid::fromString($invoiceId)->toRfc4122();

        $reason = self::reason($reason, self::ISSUE_REASONS);
        $salesReturnId = self::salesReturnId($salesReturnId);
        $lines = self::lines($rawLines);

        $digest = RequestDigest::of(self::OPERATION_ISSUE, [
            'invoice_id' => RequestDigest::uuid($invoiceId),
            'reason' => $reason,
            'sales_return_id' => RequestDigest::uuid($salesReturnId),
            'lines' => self::digestedLines($lines),
        ]);

        return $this->write($invoiceId, $reason, $salesReturnId, $lines, $idempotencyKey, $digest);
    }

    /**
     * @param list<array{fulfilmentLine: string, quantity: string}> $lines
     */
    private function write(string $invoiceId, string $reason, ?string $salesReturnId, array $lines, string $idempotencyKey, string $digest): CreditNoteView
    {
        $keyHash = IdempotencyKeyHash::of($idempotencyKey);
        $identity = $this->identities->authenticated();
        $id = Uuid::v4()->toRfc4122();

        try {
            return $this->tenants->run(
                $this->tenant->tenantId(),
                fn (EntityManagerInterface $em): CreditNoteView => $this->writeInside(
                    $em, $id, $invoiceId, $reason, $salesReturnId, $lines, $identity, $keyHash, $digest,
                ),
            );
        } catch (UniqueConstraintViolationException $e) {
            // 10a. Outside `run()`: the failed statement aborted the transaction. A same-invoice retry waited at the
            // invoice lock and read the first from the replay, so a key collision here is the key reused against
            // ANOTHER invoice — a different request (the invoice is digested).
            if (str_contains($e->getMessage(), self::IDEMPOTENCY_INDEX)) {
                throw ApiProblem::idempotencyKeyConflict();
            }

            if (str_contains($e->getMessage(), self::NUMBER_INDEX)) {
                throw ApiProblem::businessRuleViolated(
                    'credit_note.credit_note_number_is_unique_per_workspace',
                    'Another credit note was issued in this workspace at the same moment. Try again.',
                );
            }

            throw $e;
        }
    }

    /**
     * @param list<array{fulfilmentLine: string, quantity: string}> $lines
     */
    private function writeInside(
        EntityManagerInterface $em,
        string $id,
        string $invoiceId,
        string $reason,
        ?string $salesReturnId,
        array $lines,
        Identity $identity,
        string $keyHash,
        string $digest,
    ): CreditNoteView {
        $connection = $em->getConnection();

        // 2.–3.
        InvoiceRowLock::holdInside($em, $invoiceId);
        $invoice = $connection->fetchAssociative(
            'SELECT id, status, fulfilment_id, total_amount FROM invoice WHERE id = ?::uuid',
            [$invoiceId],
        );
        if (false === $invoice) {
            throw ApiProblem::notFound();
        }

        // 4.
        $replay = $connection->fetchAssociative('SELECT id, request_digest FROM credit_note WHERE idempotency_key = ?', [$keyHash]);
        if (false !== $replay) {
            if (!hash_equals((string) $replay['request_digest'], $digest)) {
                throw ApiProblem::idempotencyKeyConflict();
            }

            return $this->reader->readInside($em, (string) $replay['id'])
                ?? throw new \LogicException('A credit note found by its key is not readable in the same transaction.');
        }

        // 5.
        if (Invoice::STATUS_VOID === $invoice['status']) {
            throw ApiProblem::businessRuleViolated('invoice.is_not_void', 'This invoice has been voided, so it cannot be credited.');
        }
        if (Invoice::STATUS_PAID === $invoice['status']) {
            throw ApiProblem::businessRuleViolated('invoice.is_not_paid', 'This invoice has been paid. Reverse the payment before crediting it.');
        }
        $fulfilmentId = (string) $invoice['fulfilment_id'];

        // 6. The return, then the lines.
        $returnLines = [];
        if (null !== $salesReturnId) {
            $return = $connection->fetchAssociative(\sprintf(<<<'SQL'
                SELECT r.id, r.fulfilment_id, %s AS stamped
                  FROM sales_return r
                  JOIN invoice live ON live.tenant_id = r.tenant_id AND live.id = ?::uuid
                 WHERE r.id = ?::uuid
                SQL, SalesReturnReader::stampedForCredit()), [$invoiceId, $salesReturnId]);
            if (false === $return) {
                throw ApiProblem::validationFailed([['pointer' => '/sales_return_id', 'code' => 'unknown_reference',
                    'message' => 'No sales return in this workspace has this id.']]);
            }
            if ($fulfilmentId !== (string) $return['fulfilment_id'] || true !== (bool) $return['stamped']) {
                throw ApiProblem::businessRuleViolated(
                    'credit_note.return_is_against_this_invoice',
                    'This return is not one this invoice credits: it belongs to another shipment, or it was already taken off the bill when the invoice was issued.',
                );
            }
            foreach ($connection->fetchAllAssociative(\sprintf(
                'SELECT rl.id, rl.fulfilment_line_id, rl.quantity, %s AS credited FROM sales_return_line rl WHERE rl.sales_return_id = ?::uuid',
                SalesReturnReader::creditedOnReturnLine('rl'),
            ), [$salesReturnId]) as $row) {
                $returnLines[(string) $row['fulfilment_line_id']] = $row;
            }
        }

        // The shipped lines of the invoice's fulfilment, each with what it billed (the issuer's formula), what this
        // invoice has credited on it, and its price — under the lock.
        $shipped = [];
        foreach ($connection->fetchAllAssociative(\sprintf(<<<'SQL'
            SELECT fl.id, sol.line_number, sol.unit_price,
                   (fl.quantity - %1$s)::numeric(19,6) AS billed,
                   round((fl.quantity - %1$s) * sol.unit_price, 4) AS billed_amount,
                   %2$s AS credited
              FROM fulfilment_line fl
              JOIN sales_order_line sol ON sol.tenant_id = fl.tenant_id AND sol.id = fl.sales_order_line_id
              JOIN invoice live ON live.tenant_id = fl.tenant_id AND live.id = ?::uuid
             WHERE fl.fulfilment_id = ?::uuid
            SQL, SalesReturnReader::returnedBeforeIssue('fl'), SalesReturnReader::creditedOnFulfilmentLine('fl', 'live')),
            [$invoiceId, $fulfilmentId]) as $row) {
            $shipped[(string) $row['id']] = $row;
        }

        $errors = [];
        foreach ($lines as $index => $line) {
            if (!isset($shipped[$line['fulfilmentLine']])) {
                $errors[] = self::error($index, 'fulfilment_line_id', 'unknown_reference', 'No line of the shipment this invoice bills has this id.');
            } elseif (null !== $salesReturnId && !isset($returnLines[$line['fulfilmentLine']])) {
                $errors[] = self::error($index, 'fulfilment_line_id', 'not_on_the_return', 'The return named in sales_return_id took back nothing of this shipped line.');
            }
        }
        if ([] !== $errors) {
            throw ApiProblem::validationFailed($errors);
        }

        // 7.
        foreach ($lines as $line) {
            $row = $shipped[$line['fulfilmentLine']];
            if (1 === bccomp(bcadd((string) $row['credited'], $line['quantity'], 6), (string) $row['billed'], 6)) {
                throw ApiProblem::businessRuleViolated(
                    'credit_note.quantity_within_billed',
                    \sprintf('Order line %d was billed %s on this invoice and %s has already been credited, so %s more cannot be credited against it.',
                        (int) $row['line_number'],
                        QuantityRendering::render((string) $row['billed']),
                        QuantityRendering::render((string) $row['credited']),
                        QuantityRendering::render($line['quantity'])),
                );
            }
            if (null !== $salesReturnId) {
                $returned = $returnLines[$line['fulfilmentLine']];
                if (1 === bccomp(bcadd((string) $returned['credited'], $line['quantity'], 6), (string) $returned['quantity'], 6)) {
                    throw ApiProblem::businessRuleViolated(
                        'credit_note.quantity_within_returned',
                        \sprintf('This return took back %s of order line %d and %s of it has already been credited, so %s more cannot be credited against it.',
                            QuantityRendering::render((string) $returned['quantity']),
                            (int) $row['line_number'],
                            QuantityRendering::render((string) $returned['credited']),
                            QuantityRendering::render($line['quantity'])),
                    );
                }
            }
        }

        // 7a.
        $recomputed = '0';
        foreach ($shipped as $row) {
            $recomputed = bcadd($recomputed, (string) $row['billed_amount'], 4);
        }
        if (0 !== bccomp($recomputed, (string) $invoice['total_amount'], 4)) {
            throw new \RuntimeException(\sprintf(
                'Invoice %s: the billed lines recompute to %s but the invoice totals %s. The issue formula changed, or the '
                .'invoice was written outside the application (RESIDUAL_GAPS.md G-15). Nothing was credited.',
                $invoiceId, $recomputed, (string) $invoice['total_amount'],
            ));
        }

        // 8.
        foreach ($lines as $index => $line) {
            if (1 !== bccomp((string) $shipped[$line['fulfilmentLine']]['unit_price'], '0', 4)) {
                throw ApiProblem::businessRuleViolated(
                    'credit_note.line_is_creditable',
                    \sprintf('Order line %d was billed at no charge, so there is nothing to credit on it. Remove line %d.',
                        (int) $shipped[$line['fulfilmentLine']]['line_number'], $index),
                );
            }
        }

        // 8b. The telescope, on the database's numeric rounding — the issuer's and the queue's rounding.
        $amounts = [];
        $total = '0';
        foreach ($lines as $index => $line) {
            $row = $shipped[$line['fulfilmentLine']];
            $amounts[$index] = (string) $connection->fetchOne(
                'SELECT (round((?::numeric + ?::numeric) * ?::numeric, 4) - round(?::numeric * ?::numeric, 4))::numeric(19,4)',
                [$row['credited'], $line['quantity'], $row['unit_price'], $row['credited'], $row['unit_price']],
            );
            $total = bcadd($total, $amounts[$index], 4);
        }
        if (1 !== bccomp($total, '0', 4)) {
            throw ApiProblem::businessRuleViolated(
                'credit_note.amount_is_positive',
                'At these prices the quantities credit nothing: what remains of these lines rounds to no money. There is nothing to credit.',
            );
        }

        // 9.–10.
        $connection->executeStatement(<<<'SQL'
            INSERT INTO credit_note
                (id, tenant_id, invoice_id, sales_return_id, credit_note_number, reason, currency_code, total_amount,
                 issued_by_user_id, idempotency_key, request_digest)
            SELECT ?::uuid, inv.tenant_id, inv.id, ?::uuid, ?, ?, inv.currency_code, ?::numeric, ?::uuid, ?, ?
              FROM invoice inv WHERE inv.id = ?::uuid
            SQL, [$id, $salesReturnId, $this->nextNumber($em), $reason, $total, $identity->userId, $keyHash, $digest, $invoiceId]);

        foreach ($lines as $index => $line) {
            $connection->executeStatement(<<<'SQL'
                INSERT INTO credit_note_line
                    (id, tenant_id, credit_note_id, fulfilment_line_id, sales_return_line_id, quantity, amount)
                VALUES (?::uuid, ?::uuid, ?::uuid, ?::uuid, ?::uuid, ?::numeric, ?::numeric)
                SQL, [
                    Uuid::v4()->toRfc4122(), $identity->tenantId, $id, $line['fulfilmentLine'],
                    null === $salesReturnId ? null : (string) $returnLines[$line['fulfilmentLine']]['id'],
                    $line['quantity'], $amounts[$index],
                ]);
        }

        // 11.
        $this->audit->recordIssued(
            tenantId: $identity->tenantId,
            actorUserId: $identity->userId,
            actorLabel: $identity->actorLabel,
            creditNoteId: $id,
            reason: $reason,
        );

        // 12.
        return $this->reader->readInside($em, $id)
            ?? throw new \LogicException('The credit note just inserted is not visible to its own transaction.');
    }

    private function nextNumber(EntityManagerInterface $em): string
    {
        $next = (int) $em->getConnection()->fetchOne(<<<'SQL'
            SELECT COALESCE(MAX(substring(credit_note_number from 4)::bigint), 0) + 1
              FROM credit_note
             WHERE credit_note_number ~ '^CN-[0-9]+$'
            SQL);

        // CD-7 / G-13: `credit_note_number_shape` pins six digits, so the millionth is refused in domain terms, decided
        // BEFORE any formatting. It runs after the replay read, so a retry of the 999,999th still gets its note.
        if ($next > self::LAST_NUMBER) {
            throw ApiProblem::businessRuleViolated(
                'credit_note.number_series_is_exhausted',
                'This workspace has used every credit note number from CN-000001 to CN-999999, so no further credit note can be issued. Numbers are never reissued.',
            );
        }

        return 'CN-'.str_pad((string) $next, 6, '0', \STR_PAD_LEFT);
    }

    /** @param list<string> $accepted */
    private static function reason(mixed $value, array $accepted): string
    {
        if (null === $value) {
            throw ApiProblem::validationFailed([['pointer' => '/reason', 'code' => 'required', 'message' => 'This field is required.']]);
        }
        if (!\is_string($value) || !\in_array($value, $accepted, true)) {
            throw ApiProblem::validationFailed([['pointer' => '/reason', 'code' => 'not_a_reason',
                'message' => 'This field must be one of: '.implode(', ', $accepted).'.']]);
        }

        return $value;
    }

    /** Required (Q-A = B′: a credit without a return is `IssueAdjustmentCreditNote`). */
    private static function salesReturnId(mixed $value): string
    {
        if (null === $value) {
            throw ApiProblem::validationFailed([['pointer' => '/sales_return_id', 'code' => 'required',
                'message' => 'This field is required: a credit note issued here credits a return. A credit with no return is an adjustment.']]);
        }
        if (!\is_string($value) || !Uuid::isValid($value)) {
            throw ApiProblem::validationFailed([['pointer' => '/sales_return_id', 'code' => 'not_a_uuid', 'message' => 'This field must be a UUID naming a row in this workspace.']]);
        }

        return Uuid::fromString($value)->toRfc4122();
    }

    /**
     * The shape of `lines`, decidable without the database.
     *
     * @param array<mixed>|null $raw
     *
     * @return list<array{fulfilmentLine: string, quantity: string}>
     */
    private static function lines(?array $raw): array
    {
        if (null === $raw) {
            throw ApiProblem::validationFailed([['pointer' => '/lines', 'code' => 'required', 'message' => 'This field is required.']]);
        }
        if ([] === $raw) {
            throw ApiProblem::validationFailed([['pointer' => '/lines', 'code' => 'at_least_one_line', 'message' => 'A credit note must credit at least one line.']]);
        }
        if (!array_is_list($raw)) {
            throw ApiProblem::validationFailed([['pointer' => '/lines', 'code' => 'not_a_list', 'message' => 'This field must be a list of lines.']]);
        }
        if (\count($raw) > self::MAXIMUM_LINES) {
            throw ApiProblem::validationFailed([[
                'pointer' => '/lines', 'code' => 'too_many_lines',
                'message' => \sprintf('A credit note may credit at most %d lines in one request. Issue the rest as a further credit note.', self::MAXIMUM_LINES),
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
                    \sprintf('The line at /lines/%d already credits this shipped line. Send one line per shipped line.', $seen[$fulfilmentLine]));
                continue;
            }
            $seen[$fulfilmentLine] = $index;
            $lines[] = ['fulfilmentLine' => $fulfilmentLine, 'quantity' => bcadd((string) $quantity, '0', 6)];
        }
        if ([] !== $errors) {
            throw ApiProblem::validationFailed($errors);
        }

        return $lines;
    }

    /**
     * Sorted by `fulfilment_line_id` (a credit note has no line order; the sort is total because duplicates are refused).
     *
     * @param list<array{fulfilmentLine: string, quantity: string}> $lines
     *
     * @return list<array{fulfilment_line_id: string, quantity: string}>
     */
    private static function digestedLines(array $lines): array
    {
        usort($lines, static fn (array $a, array $b): int => $a['fulfilmentLine'] <=> $b['fulfilmentLine']);

        return array_map(static fn (array $line): array => ['fulfilment_line_id' => $line['fulfilmentLine'], 'quantity' => $line['quantity']], $lines);
    }

    /** @return array{pointer: string, code: string, message: string} */
    private static function error(int $index, string $field, string $code, string $message): array
    {
        return ['pointer' => '/lines/'.$index.'/'.$field, 'code' => $code, 'message' => $message];
    }
}
