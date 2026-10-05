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
 * **`CreateAdjustmentDecision` — record which returned units an adjustment already credited** (G-29 / G-30 part 3, C1;
 * `docs/session13/g29-g30-identity-design.md` §3 and §2.4, under the owner's ruling of 2026-10-05: D1 (a) — only an
 * owner-marker holder records `distinct`; D1b (s-iii) — any `credit_note.issue` holder records `same`; D2 (b) — an owner
 * undoes a decision by appending a reversal; `SEC` S-1…S-7, `g29-g30-sec.md`).
 *
 * The statement order (design §3):
 *  1. E-1 (`credit_note.issue`) has already run — `PermissionGate`.
 *  2. Shape, per `kind` (§3 step 2; §2.4 "The reversal request"), then the request digest over every declared field
 *     (`acknowledged_return_ids` sorted and de-duplicated, final RED R-8).
 *  3. **1a, its own short transaction** (L-3): the return, for not-found only, and its fulfilment's live invoice.
 *  4. **`InvoiceRowLock`** on that invoice — the ONLY lock (a new return does not change a named line's open count; no
 *     writer takes the order lock after the invoice lock, F10) — then the invoice re-read: `void` → `invoice.is_not_void`.
 *  5. The replay, by key hash, under the lock: same digest → 200, the return **as it now reads**; else 409.
 *  6. The return is stamped for credit against the live invoice (`SalesReturnReader::stampedForCredit()`).
 *  7–8. `same` / `distinct` / `reversal`, each in its own method below, every bound read through `SalesReturnReader`'s
 *     named terms (the one copy).
 *  9. Allowed on `issued`, `overdue` and `paid` invoices: a `same` is how a return paid past (G-23's queue) leaves it.
 *  10. The INSERTs, naming the granted columns; `recorded_by_user_id` is the caller (A2.3a's rule).
 *  11. `credit_note.return_decided`, one row per decision row written, subject the credit note of the row's own
 *     adjustment line (`SEC` F-S1), insert branch only.
 *  12. 200: `GetSalesReturn`'s document, read back.
 *  13. 23505 outside the failed transaction: the key → conflict; the round-keyed `distinct` unique → `already_decided`;
 *     `adjustment_decision_reversed_once` → `already_reversed`.
 *
 * **The owner marker** (`OwnerPredicate::holdsMarker()` over the request's set) decides `distinct` and the reversal.
 * E-1's `credit_note.issue` still gates both, so the marker alone confers nothing; for these acts the refusal it exempts
 * from is unconditional, so the marker is the only route (`SEC` S-2, R-5).
 */
final readonly class AdjustmentDecisionWriter
{
    public const OPERATION = 'CreateAdjustmentDecision';

    public const SAME = 'same';

    public const DISTINCT = 'distinct';

    public const REVERSAL = 'reversal';

    private const KINDS = [self::SAME, self::DISTINCT, self::REVERSAL];

    /** numeric(19,6): `CreditNoteWriter::QUANTITY`'s rule. */
    private const QUANTITY = '/\A\d{1,13}(\.\d{1,6})?\z/';

    private const IDEMPOTENCY_INDEX = 'adjustment_decision_idem_unique';

    private const DISTINCT_INDEX = 'adjustment_decision_one_distinct_per_round';

    private const REVERSED_ONCE = 'adjustment_decision_reversed_once';

    /** The decision-row INSERT, every granted column named (`Version20261005000400`). */
    private const INSERT = <<<'SQL'
        INSERT INTO adjustment_decision
            (id, tenant_id, sales_return_line_id, credit_note_line_id, kind, quantity, reverses_id, decision_round,
             recorded_by_user_id, idempotency_key, request_digest)
        VALUES (?::uuid, ?::uuid, ?::uuid, ?::uuid, ?, ?::numeric, ?::uuid, ?::smallint, ?::uuid, ?, ?)
        SQL;

    public function __construct(
        private TenantContext $tenants,
        private CurrentTenant $tenant,
        private RequestIdentity $identities,
        private SalesReturnReader $reader,
        private CreditNoteAuditWriter $audit,
    ) {
    }

    public function decide(
        string $returnId,
        mixed $kind,
        mixed $salesReturnLineId,
        mixed $creditNoteLineId,
        mixed $quantity,
        mixed $reversesDecisionId,
        mixed $acknowledgedReturnIds,
        string $idempotencyKey,
    ): SalesReturnView {
        if (!Uuid::isValid($returnId)) {
            throw ApiProblem::notFound();
        }
        $returnId = Uuid::fromString($returnId)->toRfc4122();

        // 2.
        $request = self::shape($kind, $salesReturnLineId, $creditNoteLineId, $quantity, $reversesDecisionId, $acknowledgedReturnIds);
        $digest = RequestDigest::of(self::OPERATION, [
            'sales_return_id' => $returnId,
            'kind' => $request['kind'],
            'sales_return_line_id' => $request['returnLine'],
            'credit_note_line_id' => $request['adjustmentLine'],
            'quantity' => $request['quantity'],
            'reverses_decision_id' => $request['reverses'],
            'acknowledged_return_ids' => [] === $request['acknowledged'] ? null : $request['acknowledged'],
        ]);
        $keyHash = IdempotencyKeyHash::of($idempotencyKey);
        $identity = $this->identities->authenticated();

        // 3. 1a: the return (404 only) and its fulfilment's live invoice, before the lock.
        $located = $this->tenants->run($this->tenant->tenantId(), static fn (EntityManagerInterface $em): mixed => $em->getConnection()->fetchAssociative(
            \sprintf(<<<'SQL'
                SELECT r.id, live.id AS live_invoice_id
                  FROM sales_return r
                  LEFT JOIN invoice live ON live.tenant_id = r.tenant_id AND live.fulfilment_id = r.fulfilment_id AND live.status <> '%s'
                 WHERE r.id = ?::uuid
                SQL, Invoice::STATUS_VOID),
            [$returnId],
        ));
        if (false === $located) {
            throw ApiProblem::notFound();
        }
        if (null === $located['live_invoice_id']) {
            throw self::notAgainstThisInvoice();
        }
        $invoiceId = (string) $located['live_invoice_id'];

        try {
            return $this->tenants->run(
                $this->tenant->tenantId(),
                fn (EntityManagerInterface $em): SalesReturnView => $this->decideInside($em, $returnId, $invoiceId, $request, $identity, $keyHash, $digest),
            );
        } catch (UniqueConstraintViolationException $e) {
            // 13. Outside `run()`: the failed statement aborted the transaction.
            if (str_contains($e->getMessage(), self::IDEMPOTENCY_INDEX)) {
                throw ApiProblem::idempotencyKeyConflict();
            }
            if (str_contains($e->getMessage(), self::DISTINCT_INDEX)) {
                throw self::alreadyDecided();
            }
            if (str_contains($e->getMessage(), self::REVERSED_ONCE)) {
                throw self::alreadyReversed();
            }

            throw $e;
        }
    }

    /**
     * @param array{kind: string, returnLine: ?string, adjustmentLine: ?string, quantity: ?string, reverses: ?string, acknowledged: list<string>} $request
     */
    private function decideInside(EntityManagerInterface $em, string $returnId, string $invoiceId, array $request, Identity $identity, string $keyHash, string $digest): SalesReturnView
    {
        $connection = $em->getConnection();

        // 4.
        InvoiceRowLock::holdInside($em, $invoiceId);
        $status = $connection->fetchOne('SELECT status FROM invoice WHERE id = ?::uuid', [$invoiceId]);
        if (Invoice::STATUS_VOID === $status) {
            // Only if the pre-read went stale on an uncredited invoice (a credited one cannot be voided, V2-R-17).
            throw ApiProblem::businessRuleViolated('invoice.is_not_void', 'This return\'s invoice has been voided, so no decision about its credits can be recorded.');
        }

        // 5.
        $replay = $connection->fetchAssociative('SELECT id, request_digest FROM adjustment_decision WHERE idempotency_key = ?', [$keyHash]);
        if (false !== $replay) {
            if (!hash_equals((string) $replay['request_digest'], $digest)) {
                throw ApiProblem::idempotencyKeyConflict();
            }

            return $this->readBack($em, $returnId);
        }

        // 6.
        $return = $connection->fetchAssociative(\sprintf(<<<'SQL'
            SELECT r.id, r.fulfilment_id, live.fulfilment_id AS invoice_fulfilment_id, %s AS stamped
              FROM sales_return r
              JOIN invoice live ON live.tenant_id = r.tenant_id AND live.id = ?::uuid
             WHERE r.id = ?::uuid
            SQL, SalesReturnReader::stampedForCredit()), [$invoiceId, $returnId]);
        if (false === $return || (string) $return['fulfilment_id'] !== (string) $return['invoice_fulfilment_id'] || true !== (bool) $return['stamped']) {
            throw self::notAgainstThisInvoice();
        }

        $written = match ($request['kind']) {
            self::REVERSAL => $this->reverse($em, $returnId, $invoiceId, $request, $identity, $keyHash, $digest),
            default => $this->decidePair($em, $returnId, $invoiceId, $request, $identity, $keyHash, $digest),
        };

        // 11.
        foreach ($written as $row) {
            $this->audit->recordReturnDecided(
                tenantId: $identity->tenantId,
                actorUserId: $identity->userId,
                actorLabel: $identity->actorLabel,
                creditNoteId: $row['creditNote'],
                decisionId: $row['id'],
                kind: $row['kind'],
                salesReturnLineId: $row['returnLine'],
                creditNoteLineId: $row['adjustmentLine'],
                quantity: $row['quantity'],
                reversesDecisionId: $row['reverses'],
            );
        }

        // 12.
        return $this->readBack($em, $returnId);
    }

    /**
     * `same` or `distinct` (design §3 steps 6–10).
     *
     * @param array{kind: string, returnLine: ?string, adjustmentLine: ?string, quantity: ?string, reverses: ?string, acknowledged: list<string>} $request
     *
     * @return list<array{id: string, creditNote: string, kind: string, returnLine: string, adjustmentLine: string, quantity: ?string, reverses: ?string}>
     */
    private function decidePair(EntityManagerInterface $em, string $returnId, string $invoiceId, array $request, Identity $identity, string $keyHash, string $digest): array
    {
        $connection = $em->getConnection();
        $returnLine = (string) $request['returnLine'];
        $adjustmentLine = (string) $request['adjustmentLine'];

        // 6. The two lines, under the lock.
        $rl = $connection->fetchAssociative('SELECT rl.id, rl.fulfilment_line_id FROM sales_return_line rl WHERE rl.id = ?::uuid AND rl.sales_return_id = ?::uuid', [$returnLine, $returnId]);
        if (false === $rl) {
            throw ApiProblem::validationFailed([['pointer' => '/sales_return_line_id', 'code' => 'not_on_the_return',
                'message' => 'No line of this return has this id.']]);
        }
        $cnl = $connection->fetchAssociative(<<<'SQL'
            SELECT cnl.id, cnl.fulfilment_line_id, cn.id AS credit_note_id, cn.credit_note_number, cn.sales_return_id, cn.invoice_id
              FROM credit_note_line cnl JOIN credit_note cn ON cn.tenant_id = cnl.tenant_id AND cn.id = cnl.credit_note_id
             WHERE cnl.id = ?::uuid
            SQL, [$adjustmentLine]);
        if (false === $cnl || $invoiceId !== (string) $cnl['invoice_id']) {
            throw ApiProblem::validationFailed([['pointer' => '/credit_note_line_id', 'code' => 'unknown_reference',
                'message' => 'No line of a credit note on this return\'s invoice has this id.']]);
        }
        if (null !== $cnl['sales_return_id']) {
            throw ApiProblem::validationFailed([['pointer' => '/credit_note_line_id', 'code' => 'not_an_adjustment',
                'message' => 'This line belongs to a credit note for a return. A decision is about a credit made without a return.']]);
        }
        if ((string) $rl['fulfilment_line_id'] !== (string) $cnl['fulfilment_line_id']) {
            throw ApiProblem::validationFailed([['pointer' => '/credit_note_line_id', 'code' => 'not_the_same_shipped_line',
                'message' => 'This adjustment credited a different order line from the one this return line brought back.']]);
        }

        $pair = $this->pair($em, $returnLine, $adjustmentLine, $invoiceId);

        if (self::SAME === $request['kind']) {
            // 7.
            $q = (string) $request['quantity'];
            if (1 === bccomp($q, $pair['open_return_line'], 6)) {
                throw ApiProblem::businessRuleViolated('adjustment_decision.within_returned', \sprintf(
                    'This return line still has %s units open (not credited and not already recorded), so %s cannot be recorded as already credited.',
                    QuantityRendering::render($pair['open_return_line']), QuantityRendering::render($q)));
            }
            if (1 === bccomp($q, $pair['open_adjustment_line'], 6)) {
                throw ApiProblem::businessRuleViolated('adjustment_decision.within_adjusted', \sprintf(
                    '%s credited %s units of this order line that are not yet matched to a return, so %s cannot be recorded as those units.',
                    (string) $cnl['credit_note_number'], QuantityRendering::render($pair['open_adjustment_line']), QuantityRendering::render($q)));
            }
            if ($pair['live_distinct']) {
                // J-7: a pair says "different" or "q the same", never both in that order.
                throw self::alreadyDecided();
            }
            $round = 1;
        } else {
            // 8.
            if (!OwnerPredicate::holdsMarker($identity->permissionCodes)) {
                throw ApiProblem::businessRuleViolated('adjustment_decision.distinct_needs_the_owner',
                    'Only an owner can confirm that returned goods are different from units already credited without a return.');
            }
            $this->refuseAnImpossibleDistinct($em, $returnLine, $adjustmentLine, $invoiceId, $pair, (string) $cnl['credit_note_number']);
            if ($pair['live_distinct']) {
                throw self::alreadyDecided();
            }
            if (1 !== bccomp($pair['open_return_line'], '0', 6) || 1 !== bccomp($pair['open_adjustment_line'], '0', 6)) {
                throw ApiProblem::businessRuleViolated('adjustment_decision.nothing_to_decide',
                    'Either this return line or this adjustment has no units left that are not already credited or recorded, so there is nothing to decide.');
            }
            $round = (int) $pair['decision_round'];
        }

        // 10.
        $id = Uuid::v4()->toRfc4122();
        $connection->executeStatement(self::INSERT, [$id, $identity->tenantId, $returnLine, $adjustmentLine, $request['kind'],
            $request['quantity'], null, $round, $identity->userId, $keyHash, $digest]);

        return [['id' => $id, 'creditNote' => (string) $cnl['credit_note_id'], 'kind' => $request['kind'], 'returnLine' => $returnLine,
            'adjustmentLine' => $adjustmentLine, 'quantity' => $request['quantity'], 'reverses' => null]];
    }

    /**
     * **D2 (b), the owner's reversal** (design §2.4 as FINAL after final RED R-1…R-8).
     *
     * @param array{kind: string, returnLine: ?string, adjustmentLine: ?string, quantity: ?string, reverses: ?string, acknowledged: list<string>} $request
     *
     * @return list<array{id: string, creditNote: string, kind: string, returnLine: string, adjustmentLine: string, quantity: ?string, reverses: ?string}>
     */
    private function reverse(EntityManagerInterface $em, string $returnId, string $invoiceId, array $request, Identity $identity, string $keyHash, string $digest): array
    {
        $connection = $em->getConnection();
        if (!OwnerPredicate::holdsMarker($identity->permissionCodes)) {
            throw ApiProblem::businessRuleViolated('adjustment_decision.reversal_needs_the_owner',
                'Only an owner can undo a decision about returned goods and units already credited without a return.');
        }

        $target = $connection->fetchAssociative(<<<'SQL'
            SELECT ad.id, ad.kind, ad.quantity, ad.sales_return_line_id, ad.credit_note_line_id, rl.sales_return_id,
                   cnl.fulfilment_line_id, cn.id AS credit_note_id, cn.credit_note_number,
                   EXISTS (SELECT 1 FROM adjustment_decision x WHERE x.tenant_id = ad.tenant_id AND x.reverses_id = ad.id) AS reversed
              FROM adjustment_decision ad
              JOIN sales_return_line rl ON rl.tenant_id = ad.tenant_id AND rl.id = ad.sales_return_line_id
              JOIN credit_note_line cnl ON cnl.tenant_id = ad.tenant_id AND cnl.id = ad.credit_note_line_id
              JOIN credit_note cn ON cn.tenant_id = cnl.tenant_id AND cn.id = cnl.credit_note_id
             WHERE ad.id = ?::uuid
            SQL, [(string) $request['reverses']]);
        if (false === $target || $returnId !== (string) $target['sales_return_id']) {
            throw ApiProblem::validationFailed([['pointer' => '/reverses_decision_id', 'code' => 'unknown_reference',
                'message' => 'No decision recorded on this return has this id.']]);
        }

        // The named returns: each must be a return of this invoice's shipment; one no longer listed is ignored.
        $returnsOfShipment = [];
        foreach ($connection->fetchAllAssociative(<<<'SQL'
            SELECT r.id::text AS id, r.sales_return_number
              FROM sales_return r
              JOIN invoice live ON live.tenant_id = r.tenant_id AND live.id = ?::uuid AND live.fulfilment_id = r.fulfilment_id
            SQL, [$invoiceId]) as $row) {
            $returnsOfShipment[$row['id']] = (string) $row['sales_return_number'];
        }
        $errors = [];
        foreach ($request['acknowledged'] as $index => $named) {
            if (!isset($returnsOfShipment[$named])) {
                $errors[] = ['pointer' => '/acknowledged_return_ids/'.$index, 'code' => 'unknown_reference',
                    'message' => 'No return of the shipment this return\'s invoice bills has this id.'];
            }
        }
        if ([] !== $errors) {
            throw ApiProblem::validationFailed($errors);
        }

        if (self::REVERSAL === $target['kind']) {
            throw ApiProblem::businessRuleViolated('adjustment_decision.reverses_a_decision',
                'This is itself an undoing of a decision, and an undoing cannot be undone. Record the decision again instead.');
        }
        if (true === (bool) $target['reversed']) {
            throw self::alreadyReversed();
        }

        $written = [];
        $reversal = Uuid::v4()->toRfc4122();
        $connection->executeStatement(self::INSERT, [$reversal, $identity->tenantId, $target['sales_return_line_id'], $target['credit_note_line_id'],
            self::REVERSAL, null, $target['id'], 1, $identity->userId, $keyHash, $digest]);
        $written[] = ['id' => $reversal, 'creditNote' => (string) $target['credit_note_id'], 'kind' => self::REVERSAL,
            'returnLine' => (string) $target['sales_return_line_id'], 'adjustmentLine' => (string) $target['credit_note_line_id'],
            'quantity' => null, 'reverses' => (string) $target['id']];

        // Reversing a `distinct` moves no money and only reopens its pair (credits are irreversible, G-21).
        if (self::SAME !== $target['kind']) {
            return $written;
        }

        // FR-1, the cascade — written FIRST (R-7 (a)): every live `distinct` on either line reopens, because reversing a
        // `same` grows both lines' open counts. Each cascaded row's event names ITS OWN adjustment's note (SEC R-1).
        foreach ($connection->fetchAllAssociative(<<<'SQL'
            SELECT ad.id, ad.sales_return_line_id, ad.credit_note_line_id, cnl.credit_note_id
              FROM adjustment_decision ad
              JOIN credit_note_line cnl ON cnl.tenant_id = ad.tenant_id AND cnl.id = ad.credit_note_line_id
             WHERE ad.kind = 'distinct'
               AND (ad.sales_return_line_id = ?::uuid OR ad.credit_note_line_id = ?::uuid)
               AND NOT EXISTS (SELECT 1 FROM adjustment_decision x WHERE x.tenant_id = ad.tenant_id AND x.reverses_id = ad.id)
             ORDER BY ad.created_at, ad.id
            SQL, [$target['sales_return_line_id'], $target['credit_note_line_id']]) as $cascaded) {
            $id = Uuid::v4()->toRfc4122();
            $connection->executeStatement(self::INSERT, [$id, $identity->tenantId, $cascaded['sales_return_line_id'], $cascaded['credit_note_line_id'],
                self::REVERSAL, null, $cascaded['id'], 1, $identity->userId, null, null]);
            $written[] = ['id' => $id, 'creditNote' => (string) $cascaded['credit_note_id'], 'kind' => self::REVERSAL,
                'returnLine' => (string) $cascaded['sales_return_line_id'], 'adjustmentLine' => (string) $cascaded['credit_note_line_id'],
                'quantity' => null, 'reverses' => (string) $cascaded['id']];
        }

        // RED d1d4 F-1 / final RED R-1, R-3: the unmasking list. Every return line on the adjustment's shipped line,
        // OTHER than the reversed line itself, holding a return credit on this invoice on a credit note NUMBERED AFTER the
        // adjustment's note (numbers are minted under this lock, so their order is the serialisation order;
        // `created_at` is the transaction's start and is not). A live `same` with the adjustment does not exclude a line.
        $listed = $connection->fetchAllAssociative(<<<'SQL'
            SELECT DISTINCT rl.id, r.id::text AS sales_return_id, r.sales_return_number, sol.line_number
              FROM credit_note_line ccl
              JOIN credit_note ccn ON ccn.tenant_id = ccl.tenant_id AND ccn.id = ccl.credit_note_id
              JOIN sales_return_line rl ON rl.tenant_id = ccl.tenant_id AND rl.id = ccl.sales_return_line_id
              JOIN sales_return r ON r.tenant_id = rl.tenant_id AND r.id = rl.sales_return_id
              JOIN fulfilment_line fl ON fl.tenant_id = rl.tenant_id AND fl.id = rl.fulfilment_line_id
              JOIN sales_order_line sol ON sol.tenant_id = fl.tenant_id AND sol.id = fl.sales_order_line_id
             WHERE ccl.fulfilment_line_id = ?::uuid
               AND ccn.invoice_id = ?::uuid
               AND ccn.sales_return_id IS NOT NULL
               AND ccn.credit_note_number > ?
               AND rl.id <> ?::uuid
             ORDER BY r.sales_return_number, rl.id
            SQL, [$target['fulfilment_line_id'], $invoiceId, $target['credit_note_number'], $target['sales_return_line_id']]);

        $unnamed = array_values(array_filter($listed, static fn (array $row): bool => !\in_array($row['sales_return_id'], $request['acknowledged'], true)));
        if ([] !== $unnamed) {
            $returns = array_values(array_unique(array_map(static fn (array $row): string => (string) $row['sales_return_number'], $unnamed)));
            $shown = $identity->holds('sales_return.view') ? implode(', ', $returns) : 'a return';
            throw ApiProblem::businessRuleViolated('adjustment_decision.reversal_unmasks_a_credited_return', \sprintf(
                'Since this was recorded, %s on order line %d was credited without being matched to %s. Undoing it could pay for the same goods twice. '
                .'If %s brought back different goods, name %s in acknowledged_return_ids to confirm. If you cannot, the total credited is not over; '
                .'it may be short by up to %s units, and the product cannot repair that without paying twice.',
                $shown, (int) $unnamed[0]['line_number'], (string) $target['credit_note_number'], $shown,
                1 === \count($returns) ? 'it' : 'each', QuantityRendering::render((string) $target['quantity']),
            ));
        }

        // Record rows: each named line is written as a `distinct` with the adjustment, its round counted AFTER the
        // cascade (R-7 (a)). A line with open units is a real `distinct` over them, so §3 step 8 runs on it in full (the
        // pair and FR-9's joint check) and an impossible one refuses the whole reversal (R-3). A line with 0 open units
        // is exempt from `nothing_to_decide`.
        foreach ($listed as $row) {
            $pair = $this->pair($em, (string) $row['id'], (string) $target['credit_note_line_id'], $invoiceId);
            if ($pair['live_distinct']) {
                continue;
            }
            if (1 === bccomp($pair['open_return_line'], '0', 6)) {
                $this->refuseAnImpossibleDistinct($em, (string) $row['id'], (string) $target['credit_note_line_id'], $invoiceId, $pair, (string) $target['credit_note_number']);
            }
            $id = Uuid::v4()->toRfc4122();
            $connection->executeStatement(self::INSERT, [$id, $identity->tenantId, $row['id'], $target['credit_note_line_id'],
                self::DISTINCT, null, null, (int) $pair['decision_round'], $identity->userId, null, null]);
            $written[] = ['id' => $id, 'creditNote' => (string) $target['credit_note_id'], 'kind' => self::DISTINCT,
                'returnLine' => (string) $row['id'], 'adjustmentLine' => (string) $target['credit_note_line_id'],
                'quantity' => null, 'reverses' => null];
        }

        return $written;
    }

    /**
     * Every quantity the pair's checks read, through `SalesReturnReader`'s named terms, under the lock.
     *
     * @return array{open_return_line: string, open_adjustment_line: string, credited_by_returns: string, same_on_line: string, billed: string, proven_overlap: string, live_distinct: bool, decision_round: int, line_number: int}
     */
    private function pair(EntityManagerInterface $em, string $returnLine, string $adjustmentLine, string $invoiceId): array
    {
        $connection = $em->getConnection();
        $row = $connection->fetchAssociative(\sprintf(<<<'SQL'
            SELECT %1$s::numeric(19,6) AS open_return_line,
                   %2$s::numeric(19,6) AS open_adjustment_line,
                   %3$s AS credited_by_returns,
                   %4$s AS same_on_line,
                   %5$s::numeric(19,6) AS billed,
                   %6$s::numeric(19,6) AS proven_overlap,
                   %7$s AS live_distinct,
                   %8$s AS decision_round,
                   sol.line_number
              FROM sales_return_line rl
              JOIN credit_note_line cnl ON cnl.tenant_id = rl.tenant_id AND cnl.id = ?::uuid
              JOIN fulfilment_line fl ON fl.tenant_id = rl.tenant_id AND fl.id = rl.fulfilment_line_id
              JOIN sales_order_line sol ON sol.tenant_id = fl.tenant_id AND sol.id = fl.sales_order_line_id
              JOIN invoice live ON live.tenant_id = rl.tenant_id AND live.id = ?::uuid
             WHERE rl.id = ?::uuid
            SQL,
            SalesReturnReader::openOnReturnLine('rl'),
            SalesReturnReader::openOnAdjustmentLine('cnl'),
            SalesReturnReader::creditedByReturns('fl', 'live'),
            SalesReturnReader::sameOnLine('fl', 'live'),
            SalesReturnReader::billed('fl'),
            SalesReturnReader::provenOverlap('rl', 'cnl', 'fl', 'live'),
            SalesReturnReader::liveDistinctOnPair('rl', 'cnl'),
            SalesReturnReader::decisionRound('rl', 'cnl'),
        ), [$adjustmentLine, $invoiceId, $returnLine]);
        if (false === $row) {
            throw new \LogicException('A return line and an adjustment line just read under the lock could not be paired.');
        }

        return [
            'open_return_line' => (string) $row['open_return_line'],
            'open_adjustment_line' => (string) $row['open_adjustment_line'],
            'credited_by_returns' => (string) $row['credited_by_returns'],
            'same_on_line' => (string) $row['same_on_line'],
            'billed' => (string) $row['billed'],
            'proven_overlap' => (string) $row['proven_overlap'],
            'live_distinct' => (bool) $row['live_distinct'],
            'decision_round' => (int) $row['decision_round'],
            'line_number' => (int) $row['line_number'],
        ];
    }

    /**
     * §3 step 8's two impossibility checks (M-3): the pair's `distinctIsImpossible` and FR-9's joint form over the live
     * `distinct`s on either line. Either refuses `adjustment_decision.distinct_is_impossible`, naming at least {o}.
     *
     * @param array{open_return_line: string, open_adjustment_line: string, credited_by_returns: string, same_on_line: string, billed: string, proven_overlap: string, live_distinct: bool, decision_round: int, line_number: int} $pair
     */
    private function refuseAnImpossibleDistinct(EntityManagerInterface $em, string $returnLine, string $adjustmentLine, string $invoiceId, array $pair, string $creditNoteNumber): void
    {
        $connection = $em->getConnection();
        $overlap = $pair['proven_overlap'];
        if (1 !== bccomp($overlap, '0', 6)) {
            // FR-9: D(rl) = adjustment lines holding a live `distinct` with rl; D(cnl) = return lines holding one with cnl.
            $adjustmentSide = [$adjustmentLine => $pair['open_adjustment_line']];   // {cnl}
            foreach ($connection->fetchAllAssociative(\sprintf(<<<'SQL'
                SELECT cnl.id, %1$s::numeric(19,6) AS open
                  FROM credit_note_line cnl JOIN sales_return_line rl ON rl.tenant_id = cnl.tenant_id AND rl.id = ?::uuid
                 WHERE %2$s
                SQL, SalesReturnReader::openOnAdjustmentLine('cnl'), SalesReturnReader::liveDistinctOnPair('rl', 'cnl')), [$returnLine]) as $d) {
                $adjustmentSide[(string) $d['id']] = (string) $d['open'];
            }
            $returnSide = [$returnLine => $pair['open_return_line']];   // {rl}
            foreach ($connection->fetchAllAssociative(\sprintf(<<<'SQL'
                SELECT rl.id, %1$s::numeric(19,6) AS open
                  FROM sales_return_line rl JOIN credit_note_line cnl ON cnl.tenant_id = rl.tenant_id AND cnl.id = ?::uuid
                 WHERE %2$s
                SQL, SalesReturnReader::openOnReturnLine('rl'), SalesReturnReader::liveDistinctOnPair('rl', 'cnl')), [$adjustmentLine]) as $d) {
                $returnSide[(string) $d['id']] = (string) $d['open'];
            }
            // Each map is keyed by line id, so `D(rl) ∪ {cnl}` and `D(cnl) ∪ {rl}` are sets (the pair's own line is
            // already in them when the pair holds a live `distinct`).
            $overlap = SalesReturnReader::jointExcess($pair['credited_by_returns'], $pair['same_on_line'], $pair['billed'],
                $pair['open_return_line'], $pair['open_adjustment_line'], $adjustmentSide, $returnSide);
        }
        if (1 === bccomp($overlap, '0', 6)) {
            throw ApiProblem::businessRuleViolated('adjustment_decision.distinct_is_impossible', \sprintf(
                'Order line %d was billed %s; at least %s of these returned units must be units %s already credited. Record that many as already credited, then decide the rest.',
                $pair['line_number'], QuantityRendering::render($pair['billed']), QuantityRendering::render($overlap), $creditNoteNumber,
            ));
        }
    }

    private function readBack(EntityManagerInterface $em, string $returnId): SalesReturnView
    {
        return $this->reader->readInside($em, $returnId)
            ?? throw new \LogicException('The return just decided on is not visible to its own transaction.');
    }

    /**
     * Step 2, decidable without the database.
     *
     * @return array{kind: string, returnLine: ?string, adjustmentLine: ?string, quantity: ?string, reverses: ?string, acknowledged: list<string>}
     */
    private static function shape(mixed $kind, mixed $returnLine, mixed $adjustmentLine, mixed $quantity, mixed $reverses, mixed $acknowledged): array
    {
        if (null === $kind) {
            throw ApiProblem::validationFailed([['pointer' => '/kind', 'code' => 'required', 'message' => 'This field is required.']]);
        }
        if (!\is_string($kind) || !\in_array($kind, self::KINDS, true)) {
            throw ApiProblem::validationFailed([['pointer' => '/kind', 'code' => 'not_a_kind',
                'message' => 'This field must be one of: '.implode(', ', self::KINDS).'.']]);
        }

        $errors = [];
        $notForKind = static function (string $field) use ($kind, &$errors): void {
            $errors[] = ['pointer' => '/'.$field, 'code' => 'not_for_this_kind', 'message' => \sprintf('This field is not accepted when kind is %s.', $kind)];
        };
        $uuid = static function (mixed $value, string $field) use (&$errors): ?string {
            if (null === $value) {
                $errors[] = ['pointer' => '/'.$field, 'code' => 'required', 'message' => 'This field is required.'];

                return null;
            }
            if (!\is_string($value) || !Uuid::isValid($value)) {
                $errors[] = ['pointer' => '/'.$field, 'code' => 'not_a_uuid', 'message' => 'This field must be a UUID naming a row in this workspace.'];

                return null;
            }

            return Uuid::fromString($value)->toRfc4122();
        };

        $out = ['kind' => $kind, 'returnLine' => null, 'adjustmentLine' => null, 'quantity' => null, 'reverses' => null, 'acknowledged' => []];
        if (self::REVERSAL === $kind) {
            foreach (['sales_return_line_id' => $returnLine, 'credit_note_line_id' => $adjustmentLine, 'quantity' => $quantity] as $field => $value) {
                if (null !== $value) {
                    $notForKind($field);
                }
            }
            $out['reverses'] = $uuid($reverses, 'reverses_decision_id');
            if (null !== $acknowledged) {
                if (!\is_array($acknowledged) || !array_is_list($acknowledged)) {
                    $errors[] = ['pointer' => '/acknowledged_return_ids', 'code' => 'not_a_list', 'message' => 'This field must be a list of return ids.'];
                } else {
                    $ids = [];
                    foreach ($acknowledged as $index => $id) {
                        if (!\is_string($id) || !Uuid::isValid($id)) {
                            $errors[] = ['pointer' => '/acknowledged_return_ids/'.$index, 'code' => 'not_a_uuid',
                                'message' => 'This field must be a UUID naming a return in this workspace.'];
                            continue;
                        }
                        $ids[] = Uuid::fromString($id)->toRfc4122();
                    }
                    // R-8: sorted and de-duplicated, so a retry naming the same set in another order is a replay.
                    $ids = array_values(array_unique($ids));
                    sort($ids);
                    $out['acknowledged'] = $ids;
                }
            }
        } else {
            foreach (['reverses_decision_id' => $reverses, 'acknowledged_return_ids' => $acknowledged] as $field => $value) {
                if (null !== $value) {
                    $notForKind($field);
                }
            }
            $out['returnLine'] = $uuid($returnLine, 'sales_return_line_id');
            $out['adjustmentLine'] = $uuid($adjustmentLine, 'credit_note_line_id');
            if (self::SAME === $kind) {
                if (null === $quantity) {
                    $errors[] = ['pointer' => '/quantity', 'code' => 'required', 'message' => 'This field is required when kind is same.'];
                } elseif (!\is_string($quantity) || 1 !== preg_match(self::QUANTITY, $quantity) || 1 !== bccomp($quantity, '0', 6)) {
                    $errors[] = ['pointer' => '/quantity', 'code' => 'not_a_positive_decimal',
                        'message' => 'This field must be a positive decimal string with at most 13 integer digits and 6 fractional digits.'];
                } else {
                    $out['quantity'] = bcadd($quantity, '0', 6);
                }
            } elseif (null !== $quantity) {
                $notForKind('quantity');
            }
        }
        if ([] !== $errors) {
            throw ApiProblem::validationFailed($errors);
        }

        return $out;
    }

    private static function notAgainstThisInvoice(): ApiProblem
    {
        return ApiProblem::businessRuleViolated('adjustment_decision.return_is_against_this_invoice',
            'This return is not one an invoice credits: it has no live invoice, or it was already taken off the bill when the invoice was issued.');
    }

    private static function alreadyDecided(): ApiProblem
    {
        return ApiProblem::businessRuleViolated('adjustment_decision.already_decided',
            'This return line and this adjustment have already been recorded as different goods. Undo that first if it was wrong.');
    }

    private static function alreadyReversed(): ApiProblem
    {
        return ApiProblem::businessRuleViolated('adjustment_decision.already_reversed', 'This decision has already been undone.');
    }
}
