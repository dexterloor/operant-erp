<?php

declare(strict_types=1);

namespace App\Modules\Sales\Application;

use App\Modules\AuditLog\Infrastructure\CreditNoteAuditWriter;
use App\Modules\Sales\Infrastructure\Entity\Invoice;
use App\Modules\UserManagement\Application\Contract\OwnerPredicate;
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
 *      invoice and a random id are the same 404. **Per writer (G-29 / G-30 part 3 C2; design §4.2):**
 *      - **`IssueCreditNote` takes NO `SalesOrderRowLock`** (design step 3): nothing the return credit reads is written
 *        concurrently except under this lock, or only GROWS a bound (a concurrent return makes more creditable). *(This
 *        premise was written when a credit read no pending quantity. It still holds for THIS writer: a return recorded
 *        meanwhile only adds a return line nobody has named, and 8c reads only decisions, which are written only under
 *        this invoice lock.)*
 *      - **`IssueAdjustmentCreditNote` takes `SalesOrderRowLock`, THEN `InvoiceRowLock`** (as `PayInvoice`, `InvoicePayment`
 *        1a/2/2a): its step 10 reads the returns a concurrent `CreateSalesReturn` writes under the ORDER lock alone
 *        (`SalesReturnWriter`), so the pending bound is stable only if the order is locked too. The order of the two is
 *        the house's — no writer takes the order lock after the invoice lock (`LockOrderScanTest` derives it from
 *        `src/`), so there is no cycle. Its 1a pre-read finds the order in its own short transaction, and 2a asserts the
 *        invoice's order, its fulfilment's order and the locked order agree (a forged invoice, G-15: 500-class, nothing
 *        credited).
 *      - Decisions (`CreateAdjustmentDecision`) hold the invoice lock alone and are written only under it.
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
 * **`IssueAdjustmentCreditNote` (C1b, owner ruling Q-A = B′; ADR-008 A2.1c)** is {@see self::adjust()}: the same
 * document and the same steps for billed units that come back on no return — `damaged_goods_kept` (the customer received
 * them damaged and says they will keep them) or `short_shipped` (the customer says they did NOT receive them; definitions:
 * `API.md` §15.1 `IssueAdjustmentCreditNote`, 2026-10-02) — with no return —
 * so no return-line bound, `sales_return_id` NULL (`credit_note_return_iff_goods_returned`) — on its OWN code,
 * `credit_note.adjust`, held by the owner alone, so E-1 covers it mechanically. One extra refusal after the status
 * (design §4.4b (ii)): an invoice whose payment was EVER reversed (`payment_reversed_at`) is adjusted only by an
 * owner-marker holder (`OwnerPredicate`, never a role name) — `invoice.is_not_payment_reversed`, B′-void's rule reused
 * because the predicate is about the invoice. Each operation's request digest binds its own operation name
 * (`RequestDigest::of(OPERATION_*, …)`), and both share `credit_note_idem_unique`, so one key reused across the two
 * is `idempotency-key-conflict`, never a replay of the other kind's note.
 *
 * **What it deliberately does NOT do:** credit a paid invoice (Q-E, G-23); edit or void a credit note (Q-K, G-21: none
 * can be undone).
 */
final readonly class CreditNoteWriter
{
    public const OPERATION_ISSUE = 'IssueCreditNote';

    public const OPERATION_ADJUST = 'IssueAdjustmentCreditNote';

    /** The adjustment reasons (design §12 Q-I's credit reasons for a credit with no return). */
    public const ADJUST_REASONS = ['damaged_goods_kept', 'short_shipped'];

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

        return $this->write($invoiceId, $reason, $salesReturnId, $lines, [], $idempotencyKey, $digest);
    }

    /**
     * **`IssueAdjustmentCreditNote`** (C1b): a credit for billed units received damaged and kept (`damaged_goods_kept`) or
     * not received at all (`short_shipped`) — no return, owner-held code. **Since G-29 / G-30 part 3 C2 it is held to the
     * returns still waiting for a credit and asks the owner about them** (design §4.2): the pending bound (step 10, G-29),
     * the acknowledgement `acknowledged_return_ids` (step 11, an owner-marker act that writes one `distinct` decision per
     * open return line, step 12), and the order → invoice lock order. `short_shipped` carries the same bound.
     *
     * @param array<mixed>|null $rawLines `lines` as decoded, unchecked
     */
    public function adjust(string $invoiceId, mixed $reason, ?array $rawLines, mixed $acknowledgedReturnIds, string $idempotencyKey): CreditNoteView
    {
        if (!Uuid::isValid($invoiceId)) {
            throw ApiProblem::notFound();
        }
        $invoiceId = Uuid::fromString($invoiceId)->toRfc4122();

        $reason = self::reason($reason, self::ADJUST_REASONS);
        $lines = self::lines($rawLines);
        $acknowledged = self::acknowledgedShape($acknowledgedReturnIds);

        // FR-14 / design §4.2 step 2: `acknowledged_return_ids` is INSIDE the digest, sorted and de-duplicated, so a retry
        // naming a different set is a different request and conflicts — it never replays the first note with its
        // `distinct` rows.
        $digest = RequestDigest::of(self::OPERATION_ADJUST, [
            'invoice_id' => RequestDigest::uuid($invoiceId),
            'reason' => $reason,
            'lines' => self::digestedLines($lines),
            'acknowledged_return_ids' => [] === $acknowledged ? null : $acknowledged,
        ]);

        return $this->write($invoiceId, $reason, null, $lines, $acknowledged, $idempotencyKey, $digest);
    }

    /**
     * @param list<array{fulfilmentLine: string, quantity: string}> $lines
     * @param list<string>                                         $acknowledged sorted, de-duplicated (the adjust path only)
     */
    private function write(string $invoiceId, string $reason, ?string $salesReturnId, array $lines, array $acknowledged, string $idempotencyKey, string $digest): CreditNoteView
    {
        $keyHash = IdempotencyKeyHash::of($idempotencyKey);
        $identity = $this->identities->authenticated();
        $id = Uuid::v4()->toRfc4122();

        // 1a (the adjust path only). The lock key is read BEFORE the locks, in its own short transaction (`InvoicePayment`
        // 1a's form): the order lock must come first, and the order is found through the invoice.
        $orderId = null;
        if (null === $salesReturnId) {
            $found = $this->tenants->run($this->tenant->tenantId(), static fn (EntityManagerInterface $em): mixed => $em->getConnection()->fetchOne(
                'SELECT f.sales_order_id FROM invoice i JOIN fulfilment f ON f.tenant_id = i.tenant_id AND f.id = i.fulfilment_id WHERE i.id = ?::uuid',
                [$invoiceId],
            ));
            if (false === $found || null === $found) {
                throw ApiProblem::notFound();
            }
            $orderId = (string) $found;
        }

        try {
            return $this->tenants->run(
                $this->tenant->tenantId(),
                fn (EntityManagerInterface $em): CreditNoteView => $this->writeInside(
                    $em, $id, $invoiceId, $orderId, $reason, $salesReturnId, $lines, $acknowledged, $identity, $keyHash, $digest,
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
        ?string $orderId,
        string $reason,
        ?string $salesReturnId,
        array $lines,
        array $acknowledged,
        Identity $identity,
        string $keyHash,
        string $digest,
    ): CreditNoteView {
        $connection = $em->getConnection();

        // 2.–3. The adjust path locks the ORDER first, then the invoice (design §4.2; see the class docblock).
        if (null !== $orderId) {
            SalesOrderRowLock::holdInside($em, $orderId);
        }
        InvoiceRowLock::holdInside($em, $invoiceId);
        $invoice = $connection->fetchAssociative(
            'SELECT i.id, i.status, i.fulfilment_id, i.total_amount, i.payment_reversed_at, i.sales_order_id::text AS invoice_order, f.sales_order_id::text AS fulfilment_order
               FROM invoice i JOIN fulfilment f ON f.tenant_id = i.tenant_id AND f.id = i.fulfilment_id WHERE i.id = ?::uuid',
            [$invoiceId],
        );
        if (false === $invoice) {
            throw ApiProblem::notFound();
        }
        // 2a (the adjust path): the invoice's order, its fulfilment's order and the locked order agree, else the invoice was
        // written outside the application (G-15) and the lock protected the wrong row — 500-class, nothing credited.
        if (null !== $orderId && ($invoice['invoice_order'] !== $invoice['fulfilment_order'] || $invoice['fulfilment_order'] !== $orderId)) {
            throw new \RuntimeException(\sprintf(
                'Invoice %s names order %s, its fulfilment\'s order is %s, and the order locked was %s. The invoice was written '
                .'outside the application (RESIDUAL_GAPS.md G-15). Nothing was credited.',
                $invoiceId, (string) $invoice['invoice_order'], (string) $invoice['fulfilment_order'], $orderId,
            ));
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
        // 5b. An adjustment (no return) on an invoice whose payment was ever reversed: owners only (design §4.4b (ii)).
        if (null === $salesReturnId && null !== $invoice['payment_reversed_at'] && !OwnerPredicate::holdsMarker($identity->permissionCodes)) {
            throw ApiProblem::businessRuleViolated(
                'invoice.is_not_payment_reversed',
                'A payment on this invoice was reversed, so only an owner can credit it without a return.',
            );
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
                'SELECT rl.id, rl.fulfilment_line_id, rl.quantity, %s AS credited, %s AS same FROM sales_return_line rl WHERE rl.sales_return_id = ?::uuid',
                SalesReturnReader::creditedOnReturnLine('rl'),
                SalesReturnReader::sameOnReturnLine('rl'),
            ), [$salesReturnId]) as $row) {
                $returnLines[(string) $row['fulfilment_line_id']] = $row;
            }
        }

        // The shipped lines of the invoice's fulfilment, each with what it billed (the issuer's formula), what this
        // invoice has credited on it, and its price — under the lock.
        $shipped = [];
        foreach ($connection->fetchAllAssociative(\sprintf(<<<'SQL'
            SELECT fl.id, sol.line_number, sol.unit_price,
                   %1$s::numeric(19,6) AS billed,
                   round(%1$s * sol.unit_price, 4) AS billed_amount,
                   %2$s AS credited,
                   %3$s::numeric(19,6) AS pending
              FROM fulfilment_line fl
              JOIN sales_order_line sol ON sol.tenant_id = fl.tenant_id AND sol.id = fl.sales_order_line_id
              JOIN invoice live ON live.tenant_id = fl.tenant_id AND live.id = ?::uuid
             WHERE fl.fulfilment_id = ?::uuid
            SQL, SalesReturnReader::billed('fl'), SalesReturnReader::creditedOnFulfilmentLine('fl', 'live'), SalesReturnReader::pending('fl')),
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
                // G-29 / G-30 part 3 (design §2.3): units recorded as ALREADY CREDITED by an adjustment (`same`) leave the
                // return line's room through the one named term, `sameOnReturnLine`.
                if (1 === bccomp(bcadd(bcadd((string) $returned['credited'], (string) $returned['same'], 6), $line['quantity'], 6), (string) $returned['quantity'], 6)) {
                    throw ApiProblem::businessRuleViolated(
                        'credit_note.quantity_within_returned',
                        \sprintf('This return took back %s of order line %d and %s of it has already been credited%s, so %s more cannot be credited against it.',
                            QuantityRendering::render((string) $returned['quantity']),
                            (int) $row['line_number'],
                            QuantityRendering::render((string) $returned['credited']),
                            $this->sameClause($em, (string) $returned['id'], (string) $returned['same'], $identity),
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

        // 8c (IssueCreditNote only; design §4.1). After 8b, so every shipped refusal keeps its answer: a return line this
        // credit takes that has an UNDECIDED pair with an adjustment on the same shipped line is refused until the pair is
        // decided (`same`, by finance; `distinct`, by an owner). Decisions are written only under this invoice lock.
        if (null !== $salesReturnId) {
            $this->refuseUndecidedPairs($em, $invoiceId, array_map(
                static fn (array $line): string => (string) $returnLines[$line['fulfilmentLine']]['id'],
                $lines,
            ), $identity);
        }

        // 10. (the adjust path; design §4.2). After 8b (L-1): `credited(l) + pending(l) + q ≤ billed(l)` — an adjustment
        // may not take the room a pending return needs. This closes G-29.
        $named = [];
        if (null === $salesReturnId) {
            $this->refuseTheRoomOfAPendingReturn($em, $invoiceId, $lines, $shipped, $identity);
            // 11.
            $named = $this->acknowledgedPendingReturns($em, $invoiceId, (string) $invoice['fulfilment_id'], $lines, $acknowledged, $identity);
        }

        // 9.–10.
        $connection->executeStatement(<<<'SQL'
            INSERT INTO credit_note
                (id, tenant_id, invoice_id, sales_return_id, credit_note_number, reason, currency_code, total_amount,
                 issued_by_user_id, idempotency_key, request_digest)
            SELECT ?::uuid, inv.tenant_id, inv.id, ?::uuid, ?, ?, inv.currency_code, ?::numeric, ?::uuid, ?, ?
              FROM invoice inv WHERE inv.id = ?::uuid
            SQL, [$id, $salesReturnId, $this->nextNumber($em), $reason, $total, $identity->userId, $keyHash, $digest, $invoiceId]);

        $newLines = [];
        foreach ($lines as $index => $line) {
            $newLines[$line['fulfilmentLine']] = Uuid::v4()->toRfc4122();
            $connection->executeStatement(<<<'SQL'
                INSERT INTO credit_note_line
                    (id, tenant_id, credit_note_id, fulfilment_line_id, sales_return_line_id, quantity, amount)
                VALUES (?::uuid, ?::uuid, ?::uuid, ?::uuid, ?::uuid, ?::numeric, ?::numeric)
                SQL, [
                    $newLines[$line['fulfilmentLine']], $identity->tenantId, $id, $line['fulfilmentLine'],
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

        // 12. (the adjust path): one `distinct` row per (open return line on an adjusted line of a NAMED pending return, new
        // adjustment line), the caller recording it (the owner marker was checked at step 11); each writes its event.
        foreach ($named as $row) {
            $adjustmentLine = $newLines[$row['fulfilment_line_id']];
            $round = (int) $connection->fetchOne(SalesReturnReader::decisionRoundSql(), [$adjustmentLine, $row['return_line_id']]);
            $decision = Uuid::v4()->toRfc4122();
            $connection->executeStatement(<<<'SQL'
                INSERT INTO adjustment_decision
                    (id, tenant_id, sales_return_line_id, credit_note_line_id, kind, quantity, reverses_id, decision_round,
                     recorded_by_user_id, idempotency_key, request_digest)
                VALUES (?::uuid, ?::uuid, ?::uuid, ?::uuid, 'distinct', NULL, NULL, ?::smallint, ?::uuid, NULL, NULL)
                SQL, [$decision, $identity->tenantId, $row['return_line_id'], $adjustmentLine, $round, $identity->userId]);
            $this->audit->recordReturnDecided(
                tenantId: $identity->tenantId,
                actorUserId: $identity->userId,
                actorLabel: $identity->actorLabel,
                creditNoteId: $id,
                decisionId: $decision,
                kind: 'distinct',
                salesReturnLineId: (string) $row['return_line_id'],
                creditNoteLineId: $adjustmentLine,
                quantity: null,
                reversesDecisionId: null,
            );
        }

        // 13.
        return $this->reader->readInside($em, $id)
            ?? throw new \LogicException('The credit note just inserted is not visible to its own transaction.');
    }

    /**
     * **Step 8c** (design §4.1): refuse 409 `credit_note.adjustment_decision_owed` while a return line this credit takes has
     * an undecided pair with an adjustment. The copy is neutral unless the PAIR is arithmetically impossible
     * (`provenOverlap > 0`, J-1), then it says how many units are proven the same (FR-4). Adjustment numbers are an
     * `invoice.view` holder's; anyone else is told the same thing without them (T-12).
     *
     * @param list<string> $returnLineIds
     */
    private function refuseUndecidedPairs(EntityManagerInterface $em, string $invoiceId, array $returnLineIds, Identity $identity): void
    {
        $pairs = $em->getConnection()->fetchAllAssociative(SalesReturnReader::undecidedPairsSql(), [$invoiceId, '{'.implode(',', $returnLineIds).'}']);
        if ([] === $pairs) {
            return;
        }

        $numbers = $identity->holds('invoice.view');
        $byLine = [];
        foreach ($pairs as $pair) {
            $byLine[(int) $pair['line_number']][] = $pair;
        }
        $sentences = [];
        foreach ($byLine as $lineNumber => $group) {
            $impossible = null;
            foreach ($group as $pair) {
                if (1 === bccomp((string) $pair['proven_overlap'], '0', 6)) {
                    $impossible = $pair;
                    break;
                }
            }
            if (!$numbers) {
                $sentences[] = \sprintf('Order line %d also has units credited without a return. Before crediting this return, record whether its goods are those units: if they are, record that they were already credited; if they are other goods, an owner must record that.', $lineNumber);
            } elseif (null !== $impossible) {
                $sentences[] = \sprintf('Order line %d cannot hold both: at least %s of these %s returned units are units %s already credited. Record at least %s as already credited. For the rest, record only what you know; if they are other goods, an owner must record that.',
                    $lineNumber, QuantityRendering::render((string) $impossible['proven_overlap']), QuantityRendering::render((string) $impossible['open_return_line']),
                    (string) $impossible['credit_note_number'], QuantityRendering::render((string) $impossible['proven_overlap']));
            } else {
                $open = '0';
                foreach ($group as $pair) {
                    $open = bcadd($open, (string) $pair['open_adjustment_line'], 6);
                }
                $sentences[] = \sprintf('Order line %d also has %s credited without a return (%s). Before crediting this return, record whether its goods are those units: if they are, record that they were already credited; if they are other goods, an owner must record that.',
                    $lineNumber, QuantityRendering::render($open), implode(', ', array_values(array_unique(array_map(static fn (array $pair): string => (string) $pair['credit_note_number'], $group)))));
            }
        }

        throw ApiProblem::businessRuleViolated('credit_note.adjustment_decision_owed', implode(' ', $sentences));
    }

    /**
     * **Step 10** (design §4.2; closes G-29): `credited(l) + pending(l) + q ≤ billed(l)`, else 409
     * `credit_note.adjustment_within_pending_returns`. The copy branches on the CAUSE (J-2, FR-6), in the order (i) → (i′) →
     * (ii): an existing adjustment already overlaps a pending return (proven), or may (undecided), or — G-29's own case, no
     * adjustment yet — the NEW quantity is what overlaps and "record that…" would be a dead end.
     *
     * @param list<array{fulfilmentLine: string, quantity: string}> $lines
     * @param array<string, array<string, mixed>>                  $shipped
     */
    private function refuseTheRoomOfAPendingReturn(EntityManagerInterface $em, string $invoiceId, array $lines, array $shipped, Identity $identity): void
    {
        foreach ($lines as $line) {
            $row = $shipped[$line['fulfilmentLine']];
            $need = bcadd(bcadd((string) $row['credited'], (string) $row['pending'], 6), $line['quantity'], 6);
            if (1 !== bccomp($need, (string) $row['billed'], 6)) {
                continue;
            }

            $connection = $em->getConnection();
            $pendingLines = $connection->fetchAllAssociative(SalesReturnReader::pendingReturnLinesSql(), [$invoiceId, '{'.$line['fulfilmentLine'].'}']);
            $returnNumbers = array_values(array_unique(array_map(static fn (array $r): string => (string) $r['sales_return_number'], $pendingLines)));
            $ret = $identity->holds('sales_return.view') ? implode(', ', $returnNumbers) : 'a return';
            $numbers = $identity->holds('invoice.view');
            $pairs = [] === $pendingLines ? [] : $connection->fetchAllAssociative(
                SalesReturnReader::undecidedPairsSql(),
                [$invoiceId, '{'.implode(',', array_map(static fn (array $r): string => (string) $r['return_line_id'], $pendingLines)).'}'],
            );
            $impossible = null;
            foreach ($pairs as $pair) {
                if (1 === bccomp((string) $pair['proven_overlap'], '0', 6)) {
                    $impossible = $pair;
                    break;
                }
            }
            $hasError = [] !== array_filter($pendingLines, static fn (array $r): bool => SalesReturnWriter::RECORDED_IN_ERROR === $r['reason']);
            $room = bcsub(bcsub((string) $row['billed'], (string) $row['credited'], 6), (string) $row['pending'], 6);

            if (null !== $impossible) {
                $retOf = $identity->holds('sales_return.view') ? (string) $impossible['sales_return_number'] : 'a return';
                $detail = \sprintf('%s brought back at least %s units that %s already credited. Record that, then adjust only what remains.',
                    $retOf, QuantityRendering::render((string) $impossible['proven_overlap']), $numbers ? (string) $impossible['credit_note_number'] : 'an adjustment');
            } elseif ([] !== $pairs) {
                $first = $pairs[0];
                $retOf = $identity->holds('sales_return.view') ? (string) $first['sales_return_number'] : 'a return';
                $detail = \sprintf('%s may have brought back units %s already credited. If so, record that first; it frees room.',
                    $retOf, $numbers ? (string) $first['credit_note_number'] : 'an adjustment');
            } elseif ($hasError) {
                $errors = array_values(array_unique(array_map(static fn (array $r): string => (string) $r['sales_return_number'],
                    array_filter($pendingLines, static fn (array $r): bool => SalesReturnWriter::RECORDED_IN_ERROR === $r['reason']))));
                $detail = \sprintf('Credit %s first.', $identity->holds('sales_return.view') ? implode(', ', $errors) : 'the return');
            } elseif (1 !== bccomp($room, '0', 6)) {
                $detail = \sprintf('Nothing on this line can be adjusted while %s waits; if these are the returned units, credit the return.', $ret);
            } else {
                $detail = \sprintf('Order line %d was billed %s; %s has been credited and %s came back on %s, still waiting. If the units you mean are the returned ones, credit the return instead. Otherwise adjust at most %s, naming the return.',
                    (int) $row['line_number'], QuantityRendering::render((string) $row['billed']), QuantityRendering::render((string) $row['credited']),
                    QuantityRendering::render((string) $row['pending']), $ret, QuantityRendering::render($room));
            }

            throw ApiProblem::businessRuleViolated('credit_note.adjustment_within_pending_returns', $detail);
        }
    }

    /**
     * **Step 11** (design §4.2): every return stamped for credit against this invoice with open units on an adjusted line
     * must be NAMED (`acknowledged_return_ids`), else 409 `credit_note.has_no_pending_return`; naming one is a `distinct`
     * assertion, so it needs the owner marker (409 `adjustment_decision.distinct_needs_the_owner`), and a `recorded_in_error`
     * return cannot be named (409 `credit_note.error_return_cannot_be_acknowledged`, `PayInvoice`'s guard mirrored). An id
     * that is no return of this invoice's shipment is 422 on its pointer; a named return that is no longer pending is
     * ignored (stale). Return numbers and ids go only to a `sales_return.view` holder.
     *
     * @param list<array{fulfilmentLine: string, quantity: string}> $lines
     * @param list<string>                                         $acknowledged
     *
     * @return list<array{return_line_id: string, fulfilment_line_id: string}> the open return lines to record `distinct` for
     */
    private function acknowledgedPendingReturns(EntityManagerInterface $em, string $invoiceId, string $fulfilmentId, array $lines, array $acknowledged, Identity $identity): array
    {
        $connection = $em->getConnection();
        $ofShipment = [];
        foreach ($connection->fetchAllAssociative('SELECT id::text AS id FROM sales_return WHERE fulfilment_id = ?::uuid', [$fulfilmentId]) as $row) {
            $ofShipment[(string) $row['id']] = true;
        }
        $errors = [];
        foreach ($acknowledged as $index => $returnId) {
            if (!isset($ofShipment[$returnId])) {
                $errors[] = ['pointer' => '/acknowledged_return_ids/'.$index, 'code' => 'unknown_reference',
                    'message' => 'No return of the shipment this invoice bills has this id.'];
            }
        }
        if ([] !== $errors) {
            throw ApiProblem::validationFailed($errors);
        }

        $pending = $connection->fetchAllAssociative(
            SalesReturnReader::pendingReturnLinesSql(),
            [$invoiceId, '{'.implode(',', array_map(static fn (array $line): string => $line['fulfilmentLine'], $lines)).'}'],
        );
        $byReturn = [];
        foreach ($pending as $row) {
            $byReturn[(string) $row['return_id']][] = $row;
        }

        foreach ($acknowledged as $returnId) {
            if (isset($byReturn[$returnId]) && SalesReturnWriter::RECORDED_IN_ERROR === $byReturn[$returnId][0]['reason']) {
                throw ApiProblem::businessRuleViolated(
                    'credit_note.error_return_cannot_be_acknowledged',
                    $identity->holds('sales_return.view')
                        ? \sprintf('%s records goods that were never shipped. Credit it before adjusting this line.', (string) $byReturn[$returnId][0]['sales_return_number'])
                        : 'A return on this line records goods that were never shipped. Credit it before adjusting this line.',
                );
            }
        }

        $unnamed = array_diff_key($byReturn, array_flip($acknowledged));
        if ([] !== $unnamed) {
            if ($identity->holds('sales_return.view')) {
                $clauses = [];
                foreach ($unnamed as $rows) {
                    $open = '0';
                    foreach ($rows as $r) {
                        $open = bcadd($open, (string) $r['open_return_line'], 6);
                    }
                    $clauses[] = \sprintf('%s brought back %s of order line %d, not yet credited', (string) $rows[0]['sales_return_number'], QuantityRendering::render($open), (int) $rows[0]['line_number']);
                }
                $detail = implode('; ', $clauses).'. If those are the units you mean, credit the return instead. If you are crediting other units, name the return to confirm they are different: '
                    .implode(', ', array_map(static fn (array $rows): string => $rows[0]['return_id'].' ('.$rows[0]['sales_return_number'].')', $unnamed)).'.';
            } else {
                $detail = 'Goods on the lines you are crediting came back on a return that has not been credited yet. If those are the units you mean, credit the return instead. If you are crediting other units, name the return to confirm they are different.';
            }

            throw ApiProblem::businessRuleViolated('credit_note.has_no_pending_return', $detail);
        }

        $effective = array_values(array_intersect_key($byReturn, array_flip($acknowledged)));
        if ([] === $effective) {
            return [];
        }
        // Naming a return is a `distinct` assertion: the owner marker, an exemption from a refusal every `credit_note.adjust`
        // holder otherwise meets (`SEC` S-2; the refusal is unconditional, so the marker is the only route).
        if (!OwnerPredicate::holdsMarker($identity->permissionCodes)) {
            throw ApiProblem::businessRuleViolated('adjustment_decision.distinct_needs_the_owner',
                'Only an owner can confirm that returned goods are different from units already credited without a return.');
        }

        $rows = [];
        foreach ($effective as $returnRows) {
            foreach ($returnRows as $r) {
                $rows[] = ['return_line_id' => (string) $r['return_line_id'], 'fulfilment_line_id' => (string) $r['fulfilment_line_id']];
            }
        }

        return $rows;
    }

    /**
     * The shape of `acknowledged_return_ids`: absent or a list of uuids, sorted and de-duplicated so a retry naming the same
     * set in another order is a replay (final RED R-8's form). Which returns they are is step 11's.
     *
     * @return list<string>
     */
    private static function acknowledgedShape(mixed $value): array
    {
        if (null === $value) {
            return [];
        }
        if (!\is_array($value) || !array_is_list($value)) {
            throw ApiProblem::validationFailed([['pointer' => '/acknowledged_return_ids', 'code' => 'not_a_list',
                'message' => 'This field must be a list of return ids.']]);
        }
        $errors = [];
        $ids = [];
        foreach ($value as $index => $id) {
            if (!\is_string($id) || !Uuid::isValid($id)) {
                $errors[] = ['pointer' => '/acknowledged_return_ids/'.$index, 'code' => 'not_a_uuid',
                    'message' => 'This field must be a UUID naming a return in this workspace.'];
                continue;
            }
            $ids[] = Uuid::fromString($id)->toRfc4122();
        }
        if ([] !== $errors) {
            throw ApiProblem::validationFailed($errors);
        }
        $ids = array_values(array_unique($ids));
        sort($ids);

        return $ids;
    }

    /**
     * FR-7's amendment to the shipped `quantity_within_returned` copy: when units of the line were recorded as already
     * credited, say so and name the adjustment. **The whole clause is an `invoice.view` holder's** (`SEC` §5: `same`
     * quantities are decision members), so anyone else reads the shipped sentence unchanged.
     */
    private function sameClause(EntityManagerInterface $em, string $returnLineId, string $same, Identity $identity): string
    {
        if (1 !== bccomp($same, '0', 6) || !$identity->holds('invoice.view')) {
            return '';
        }
        $numbers = $em->getConnection()->fetchFirstColumn(SalesReturnReader::adjustmentsRecordedSameOnReturnLineSql(), [$returnLineId]);

        return \sprintf(' and %s were recorded as already credited without a return (%s)', QuantityRendering::render($same), implode(', ', $numbers));
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
