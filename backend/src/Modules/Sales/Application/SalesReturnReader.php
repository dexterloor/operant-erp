<?php

declare(strict_types=1);

namespace App\Modules\Sales\Application;

use App\Modules\Sales\Infrastructure\Entity\Invoice;
use App\Platform\Http\Pagination\Cursor;
use App\Platform\Security\RequestIdentity;
use App\Platform\Tenancy\CurrentTenant;
use App\Platform\Tenancy\TenantContext;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * **The read half of `/sales-returns` — `GetSalesReturn` (sales step 7 R2; built with the create so the 201's
 * `Location` answers, V2-R-4).** `FulfilmentReader`'s shape: one statement for the header, one for the lines.
 *
 * ---------------------------------------------------------------------------
 * The ONE creditable predicate (design §4.2; `SEC` L5-1 §1.4, the R2 form)
 * ---------------------------------------------------------------------------
 * A return **owes a credit** when the fulfilment has a LIVE invoice, and
 *   - its stamp is that invoice, or a VOID invoice of the same fulfilment (the reissue after an uncredited void), or
 *     NULL with `deducts = false` (Q-L (a′): a pre-invoice return nobody may deduct);
 *   - and some line would credit a positive amount, priced by `IssueCreditNote`'s own telescope (design §4.4 step 8b):
 *     `round((C + r) × p, 4) − round(C × p, 4) > 0`, with `r = min(returned − credited on this return line,
 *     billed − C)`, `C` = credited on the SHIPPED line, `p = unit_price > 0`, `billed = shipped −
 *     returned_before_issue` (the deducting returns of that shipped line). "Creditable" and "a credit would be
 *     positive" therefore can never disagree, and no return is creditable against an invoice credited in full.
 * It is **creditable** when it owes a credit AND the live invoice is not `paid` (`RED-3` N-3; Q-E: a paid invoice
 * cannot be credited yet); it is **blocked by payment** when it owes a credit AND the live invoice IS `paid`.
 * `credit_target` (the invoice a credit must be issued against) is the live invoice exactly when creditable, computed
 * from THIS predicate and never inline (`SEC` L5-1 FINAL item 6) — so dropping "not paid" from it moves both.
 *
 * **The credited quantity is TWO named terms since sales step 7 C0** (`SEC` L5-1 §1.4's full C0 form and its
 * CORRECTED FINAL C0 note; `output9-sales-8-c0-amendments.md` A-8). Chunk A's single `0::numeric(19,6)` stood for two
 * different quantities:
 *   - {@see self::CREDITED_ON_RETURN_LINE} — credited against THIS return line (`credit_note_line.sales_return_line_id`):
 *     the first `LEAST` position, and the line's served `quantity_credited`;
 *   - {@see self::CREDITED_ON_FULFILMENT_LINE} — credited against the SHIPPED line on the live invoice, whoever's
 *     return it was and including adjustment credits (C1b, no return line): `credited(l)` in `billed(l) − credited(l)`,
 *     the second position, and the telescope's `C`.
 * Each is a correlated scalar subquery (an aggregate cannot sit in this `WHERE`), `COALESCE(…, 0::numeric(19,6))`: a
 * bare `0` would serialize `quantity_credited` at scale 0 (`SEC` §1.4's scale trap). Pinned by
 * `SalesReturnCreditedTermsTest` (the 0.0001 rounding row and the other-return row; mutations M-8 / M-9).
 *
 * **L5-1, decided (b) by `SEC` 2026-10-01:** `credit_target` stays null unless creditable, and PC-9's third deliberate
 * exception states the four bounded facts a `sales_return.view` holder without `invoice.view` can infer from it and
 * from `creditable` membership. `credit_blocked_by_payment` — the field that NAMES payment as the cause — is served
 * only to an `invoice.view` holder and is ABSENT otherwise (`RED-4` M4-1).
 */
final readonly class SalesReturnReader
{
    /** Credited against one return line `%1$s` (see the class docblock). */
    private const CREDITED_ON_RETURN_LINE = <<<'SQL'
        COALESCE((SELECT SUM(cnl.quantity) FROM credit_note_line cnl
                   WHERE cnl.tenant_id = %1$s.tenant_id AND cnl.sales_return_line_id = %1$s.id), 0::numeric(19,6))
        SQL;

    /** Credited against shipped line `%1$s` on invoice `%2$s`, adjustments and other returns included. */
    private const CREDITED_ON_FULFILMENT_LINE = <<<'SQL'
        COALESCE((SELECT SUM(cfl.quantity) FROM credit_note_line cfl
                    JOIN credit_note cfn ON cfn.tenant_id = cfl.tenant_id AND cfn.id = cfl.credit_note_id
                   WHERE cfl.tenant_id = %1$s.tenant_id AND cfl.fulfilment_line_id = %1$s.id
                     AND cfn.invoice_id = %2$s.id), 0::numeric(19,6))
        SQL;

    public function __construct(
        private TenantContext $tenants,
        private CurrentTenant $tenant,
        private RequestIdentity $identities,
    ) {
    }

    public function get(string $id): ?SalesReturnView
    {
        if (!Uuid::isValid($id)) {
            return null;
        }

        return $this->tenants->run(
            $this->tenant->tenantId(),
            fn (EntityManagerInterface $em): ?SalesReturnView => $this->readInside($em, Uuid::fromString($id)->toRfc4122()),
        );
    }

    /** Inside a transaction the caller already holds. */
    public function readInside(EntityManagerInterface $em, string $id): ?SalesReturnView
    {
        $connection = $em->getConnection();

        $row = $connection->fetchAssociative(self::headerSql().' WHERE r.id = ?::uuid', [$id]);
        if (false === $row) {
            return null;
        }

        $lines = $connection->fetchAllAssociative(self::linesSql().' WHERE srl.sales_return_id = ?::uuid'.self::LINES_ORDER, [$id]);

        return $this->view($row, $lines, $this->decisionsByLine($em, $lines));
    }

    /**
     * `adjustment_decisions[]` per return line, for an `invoice.view` holder only (M-4); `null` — the members absent —
     * for anyone else, and no statement is run.
     *
     * @param list<array<string, mixed>> $lines
     *
     * @return array<string, list<AdjustmentDecisionView>>|null
     */
    private function decisionsByLine(EntityManagerInterface $em, array $lines): ?array
    {
        if (!$this->identities->authenticated()->holds('invoice.view')) {
            return null;
        }
        $byLine = [];
        if ([] === $lines) {
            return $byLine;
        }
        foreach ($em->getConnection()->fetchAllAssociative(self::decisionsOnReturnLinesSql(),
            ['{'.implode(',', array_map(static fn (array $line): string => (string) $line['id'], $lines)).'}']) as $d) {
            $byLine[(string) $d['sales_return_line_id']][] = new AdjustmentDecisionView(
                id: (string) $d['id'],
                creditNote: new CreditNoteNumberRef((string) $d['credit_note_id'], (string) $d['credit_note_number']),
                kind: (string) $d['kind'],
                quantity: null === $d['quantity'] ? null : (string) $d['quantity'],
                reversesDecisionId: null === $d['reverses_id'] ? null : (string) $d['reverses_id'],
                recordedBy: new SalesActorRef((string) $d['recorded_by_id'], (string) $d['recorded_by_display_name']),
                recordedAt: (string) SalesOrderRow::timestamp($d['created_at']),
            );
        }

        return $byLine;
    }

    /**
     * **`ListSalesReturns` (sales step 7 R3)** — one keyset page, `sales_return_number` newest first. Each row is
     * byte-for-byte `GetSalesReturn`'s document: the same header statement (wrapped, so the filters read the SAME
     * predicate outcomes the read serves — `creditable` IS `credit_target`'s condition, design :236-238's one-predicate
     * requirement) and the same lines statement, batched with `= ANY`.
     *
     * Filters: `fulfilment_id`, `invoice_id` (the stamp: the invoice the return was recorded against), `creditable`
     * (the credit issuer's work queue, V2-C-16), `deducts` (`finance`'s review list — a list to reconcile against goods
     * actually received, not a detector, G-24), `credit_blocked_by_payment` (G-23's queue; the provider admits it only
     * for an `invoice.view` holder) and `q` (the document number, by prefix). An id of another workspace is a predicate
     * no visible row satisfies: an empty page, never a 404.
     */
    public function list(SalesReturnListCriteria $criteria): SalesReturnPage
    {
        return $this->tenants->run($this->tenant->tenantId(), function (EntityManagerInterface $em) use ($criteria): SalesReturnPage {
            $connection = $em->getConnection();
            // One statement, `GetSalesReturn`'s header, with the filters in its own WHERE: the derived ones are the very
            // expressions the SELECT list serves (`creditable()`, `blockedByPayment()`), never a second copy.
            $where = [];
            $parameters = [];

            if (null !== $criteria->fulfilmentId) {
                $where[] = 'r.fulfilment_id = ?::uuid';
                $parameters[] = $criteria->fulfilmentId;
            }
            if (null !== $criteria->invoiceId) {
                $where[] = 'r.invoice_id = ?::uuid';
                $parameters[] = $criteria->invoiceId;
            }
            foreach ([[self::creditable(), $criteria->creditable], ['r.deducts', $criteria->deducts],
                [self::blockedByPayment(), $criteria->creditBlockedByPayment],
                [self::adjustmentDecisionOwed(), $criteria->adjustmentDecisionOwed]] as [$expression, $value]) {
                if (null !== $value) {
                    $where[] = '('.$expression.') IS '.($value ? 'TRUE' : 'FALSE');
                }
            }
            // (s-iii), the owner's review list (design §5): returns carrying a live `same`, optionally decided since.
            if ($criteria->adjustmentDecidedSame) {
                $where[] = self::hasLiveSame(null !== $criteria->decidedSince);
                if (null !== $criteria->decidedSince) {
                    $parameters[] = $criteria->decidedSince;
                }
            }
            if (null !== $criteria->search) {
                $where[] = 'r.sales_return_number LIKE ?';
                $parameters[] = addcslashes($criteria->search, '%_\\').'%';
            }
            $cursor = $criteria->after ?? $criteria->before;
            if (null !== $cursor) {
                // API-18: (sort key, id) as ONE row comparison; newest first, so forward continues BELOW the cursor.
                $where[] = \sprintf('(r.sales_return_number, r.id) %s (?, ?::uuid)', $criteria->isBackwards() ? '>' : '<');
                $parameters[] = $cursor->sortKey;
                $parameters[] = $cursor->id;
            }

            $direction = $criteria->isBackwards() ? 'ASC' : 'DESC';
            $sql = self::headerSql()
                .([] === $where ? '' : ' WHERE '.implode(' AND ', $where))
                .\sprintf(' ORDER BY r.sales_return_number %s, r.id %s LIMIT ?', $direction, $direction);
            $parameters[] = $criteria->limit + 1;

            $rows = $connection->fetchAllAssociative($sql, $parameters);
            $hasMore = \count($rows) > $criteria->limit;
            if ($hasMore) {
                array_splice($rows, $criteria->limit);
            }
            if ($criteria->isBackwards()) {
                $rows = array_reverse($rows);
            }

            $linesByReturn = [];
            $allLines = [];
            if ([] !== $rows) {
                foreach ($connection->fetchAllAssociative(
                    self::linesSql().' WHERE srl.sales_return_id = ANY (?::uuid[])'.self::LINES_ORDER,
                    ['{'.implode(',', array_map(static fn (array $row): string => (string) $row['id'], $rows)).'}'],
                ) as $line) {
                    $linesByReturn[(string) $line['sales_return_id']][] = $line;
                    $allLines[] = $line;
                }
            }
            $decisions = $this->decisionsByLine($em, $allLines);

            $next = $previous = null;
            if ([] !== $rows) {
                $first = new Cursor((string) $rows[0]['sales_return_number'], (string) $rows[0]['id']);
                $lastRow = $rows[array_key_last($rows)];
                $last = new Cursor((string) $lastRow['sales_return_number'], (string) $lastRow['id']);
                [$next, $previous] = $criteria->isBackwards()
                    ? [$last->encode(), $hasMore ? $first->encode() : null]
                    : [$hasMore ? $last->encode() : null, null !== $criteria->after ? $first->encode() : null];
            }

            return new SalesReturnPage(
                items: array_map(fn (array $row): SalesReturnView => $this->view($row, $linesByReturn[(string) $row['id']] ?? [], $decisions), $rows),
                nextCursor: $next,
                previousCursor: $previous,
                // API-22: an EXISTS probe with no filter, only when the page is empty.
                tenantHasRows: [] === $rows ? (bool) $connection->fetchOne('SELECT EXISTS (SELECT 1 FROM sales_return)') : true,
            );
        });
    }

    /**
     * The header, with the predicate's two outcomes. The statuses are the entity's constants, interpolated (no
     * placeholder), so the statement binds only the id.
     */
    private static function headerSql(): string
    {
        return \sprintf(<<<'SQL'
            SELECT r.id, r.sales_return_number, r.reason, r.returned_at, r.deducts, r.created_at,
                   f.id AS fulfilment_id, f.fulfilment_number,
                   stamp.id AS invoice_id, stamp.invoice_number,
                   u.id AS recorded_by_id, u.display_name AS recorded_by_display_name,
                   live.id AS live_invoice_id, live.invoice_number AS live_invoice_number,
                   %1$s AS creditable,
                   %2$s AS credit_blocked_by_payment,
                   %4$s AS adjustment_decision_owed
              FROM sales_return r
              JOIN fulfilment f ON f.tenant_id = r.tenant_id AND f.id = r.fulfilment_id
              JOIN app_user u ON u.tenant_id = r.tenant_id AND u.id = r.recorded_by_user_id
              LEFT JOIN invoice stamp ON stamp.tenant_id = r.tenant_id AND stamp.id = r.invoice_id
              LEFT JOIN invoice live ON live.tenant_id = r.tenant_id AND live.fulfilment_id = r.fulfilment_id AND live.status <> '%3$s'
            SQL, self::creditable(), self::blockedByPayment(), Invoice::STATUS_VOID, self::adjustmentDecisionOwed());
    }

    /** Creditable: owes a credit, and the live invoice is not paid (`RED-3` N-3). Needs `r` and `live`. */
    public static function creditable(): string
    {
        return \sprintf("(live.id IS NOT NULL AND %s AND live.status <> '%s')", self::owesACredit(), Invoice::STATUS_PAID);
    }

    /** Blocked by payment: owes a credit, and the live invoice IS paid (Q-E; G-23's queue). Needs `r` and `live`. */
    public static function blockedByPayment(): string
    {
        return \sprintf("(live.id IS NOT NULL AND %s AND live.status = '%s')", self::owesACredit(), Invoice::STATUS_PAID);
    }

    /**
     * **The shared predicate, minus the payment split**: the return's stamp makes it a credit's to settle, and some
     * line would credit a positive amount. Needs `r` (the return) and `live` (the fulfilment's live invoice) in scope;
     * binds no placeholder. `ListSalesReturns` (R3) and `IssueCreditNote` (C1) use THIS text.
     */
    public static function owesACredit(): string
    {
        return \sprintf(<<<'SQL'
            (
                %3$s
                AND EXISTS (
                    SELECT 1
                      FROM sales_return_line rl
                      JOIN fulfilment_line rfl ON rfl.tenant_id = rl.tenant_id AND rfl.id = rl.fulfilment_line_id
                      JOIN sales_order_line rsol ON rsol.tenant_id = rfl.tenant_id AND rsol.id = rfl.sales_order_line_id
                     WHERE rl.tenant_id = r.tenant_id AND rl.sales_return_id = r.id
                       AND rsol.unit_price > 0
                       AND LEAST(%1$s, %2$s - %4$s) > 0
                       AND round((%4$s + LEAST(%1$s, %2$s - %4$s)) * rsol.unit_price, 4)
                           - round(%4$s * rsol.unit_price, 4) > 0
                )
            )
            SQL,
            self::openOnReturnLine('rl'),
            self::billed('rfl'),
            self::stampedForCredit(),
            self::creditedOnFulfilmentLine('rfl', 'live'),
        );
    }

    /**
     * **The stamp half of the shared predicate**: the return is a credit's to settle against the fulfilment's live
     * invoice — its stamp IS that invoice, or a void invoice of the same fulfilment (the reissue after an uncredited
     * void, F-2), or it is NULL and does not deduct (Q-L (a′)). Needs `r` and `live`. `IssueCreditNote` (C1) refuses a
     * return outside it (`credit_note.return_is_against_this_invoice`) with THIS text; the line half is its bounds and
     * its positive-amount refusal, priced by the same telescope.
     */
    public static function stampedForCredit(): string
    {
        return \sprintf(<<<'SQL'
            (r.invoice_id = live.id
             OR (r.invoice_id IS NULL AND NOT r.deducts)
             OR EXISTS (SELECT 1 FROM invoice v
                         WHERE v.tenant_id = r.tenant_id AND v.id = r.invoice_id
                           AND v.fulfilment_id = r.fulfilment_id AND v.status = '%s'))
            SQL, Invoice::STATUS_VOID);
    }

    /** Credited against return line `$alias` (the first `LEAST` position; the served `quantity_credited`). */
    public static function creditedOnReturnLine(string $alias): string
    {
        return \sprintf(self::CREDITED_ON_RETURN_LINE, $alias);
    }

    /** Credited against shipped line `$lineAlias` on invoice `$invoiceAlias` — every credit, adjustments included. */
    public static function creditedOnFulfilmentLine(string $lineAlias, string $invoiceAlias): string
    {
        return \sprintf(self::CREDITED_ON_FULFILMENT_LINE, $lineAlias, $invoiceAlias);
    }

    /**
     * `returned_before_issue(l)`: the quantity of shipped line `$alias` that came back on DEDUCTING returns (Q-L (a′)),
     * which `IssueInvoice` takes off the bill (`sales_return ⋈ sales_return_line`; `deducts` is the header's column).
     */
    public static function returnedBeforeIssue(string $alias): string
    {
        return \sprintf(<<<'SQL'
            COALESCE((SELECT SUM(dl.quantity)
                        FROM sales_return_line dl
                        JOIN sales_return dr ON dr.tenant_id = dl.tenant_id AND dr.id = dl.sales_return_id
                       WHERE dl.tenant_id = %1$s.tenant_id AND dl.fulfilment_line_id = %1$s.id AND dr.deducts), 0::numeric(19,6))
            SQL, $alias);
    }

    // ---------------------------------------------------------------------------
    // G-29 / G-30 part 3 — the adjustment-decision terms, written ONCE, here (design §2.3; test 20 holds the literal
    // `adjustment_decision` to this class, AdjustmentDecisionWriter and CreditNoteWriter's INSERT). A row is LIVE while no
    // `reversal` row names it (D2 (b), §2.4). Each fragment correlates on the aliases it is given and binds no
    // placeholder; inner aliases are unique per fragment, so fragments nest.
    // ---------------------------------------------------------------------------

    /** Live (not reversed) decision row `$alias`. */
    private static function live(string $alias, string $inner): string
    {
        return \sprintf('NOT EXISTS (SELECT 1 FROM adjustment_decision %2$s WHERE %2$s.tenant_id = %1$s.tenant_id AND %2$s.reverses_id = %1$s.id)', $alias, $inner);
    }

    /** `sameOnReturnLine(rl)`: Σ live `same` quantities on return line `$rl`. */
    public static function sameOnReturnLine(string $rl): string
    {
        return \sprintf(<<<'SQL'
            COALESCE((SELECT SUM(sdr.quantity) FROM adjustment_decision sdr
                       WHERE sdr.tenant_id = %1$s.tenant_id AND sdr.sales_return_line_id = %1$s.id AND sdr.kind = 'same'
                         AND %2$s), 0::numeric(19,6))
            SQL, $rl, self::live('sdr', 'sdrx'));
    }

    /** `sameOnAdjustmentLine(cnl)`: Σ live `same` quantities on adjustment line `$cnl`. */
    public static function sameOnAdjustmentLine(string $cnl): string
    {
        return \sprintf(<<<'SQL'
            COALESCE((SELECT SUM(sda.quantity) FROM adjustment_decision sda
                       WHERE sda.tenant_id = %1$s.tenant_id AND sda.credit_note_line_id = %1$s.id AND sda.kind = 'same'
                         AND %2$s), 0::numeric(19,6))
            SQL, $cnl, self::live('sda', 'sdax'));
    }

    /** `openOnReturnLine(rl)` = returned − credited against it − recorded as already credited. */
    public static function openOnReturnLine(string $rl): string
    {
        return \sprintf('(%1$s.quantity - %2$s - %3$s)', $rl, self::creditedOnReturnLine($rl), self::sameOnReturnLine($rl));
    }

    /** `openOnAdjustmentLine(cnl)` = adjusted − recorded as returned. A bare quantity: the adjustment filter is `undecidedPair`'s. */
    public static function openOnAdjustmentLine(string $cnl): string
    {
        return \sprintf('(%1$s.quantity - %2$s)', $cnl, self::sameOnAdjustmentLine($cnl));
    }

    /** A live `distinct` row exists for the pair (`$rl`, `$cnl`). */
    public static function liveDistinctOnPair(string $rl, string $cnl): string
    {
        return \sprintf(<<<'SQL'
            EXISTS (SELECT 1 FROM adjustment_decision ldp
                     WHERE ldp.tenant_id = %1$s.tenant_id AND ldp.sales_return_line_id = %1$s.id
                       AND ldp.credit_note_line_id = %2$s.id AND ldp.kind = 'distinct' AND %3$s)
            SQL, $rl, $cnl, self::live('ldp', 'ldpx'));
    }

    /**
     * **`undecidedPair(rl, cnl)`** (J-13): `$cnl` is a line of an ADJUSTMENT (no return) on invoice `$live`, on `$rl`'s
     * shipped line; both have open units; and no live `distinct` decides the pair. The adjustment filter is in this
     * term's own text, so a return credit's line never forms a pair (test 22, M21).
     */
    public static function undecidedPair(string $rl, string $cnl, string $live): string
    {
        return \sprintf(<<<'SQL'
            (EXISTS (SELECT 1 FROM credit_note upn
                      WHERE upn.tenant_id = %2$s.tenant_id AND upn.id = %2$s.credit_note_id
                        AND upn.sales_return_id IS NULL AND upn.invoice_id = %3$s.id)
             AND %1$s.fulfilment_line_id = %2$s.fulfilment_line_id
             AND %4$s > 0
             AND %5$s > 0
             AND NOT %6$s)
            SQL, $rl, $cnl, $live, self::openOnReturnLine($rl), self::openOnAdjustmentLine($cnl), self::liveDistinctOnPair($rl, $cnl));
    }

    /** Return line `$rl` has an undecided pair with some adjustment line on invoice `$live`. */
    public static function hasUndecidedPair(string $rl, string $live): string
    {
        return \sprintf(<<<'SQL'
            EXISTS (SELECT 1 FROM credit_note_line hup
                     WHERE hup.tenant_id = %1$s.tenant_id AND hup.fulfilment_line_id = %1$s.fulfilment_line_id
                       AND %2$s)
            SQL, $rl, self::undecidedPair($rl, 'hup', $live));
    }

    /**
     * **`adjustment_decision_owed`** (design §5): the return is a credit's to settle against the live invoice AND some
     * line has an undecided pair. Needs `r` and `live`. Served and filtered only for an `invoice.view` holder.
     */
    public static function adjustmentDecisionOwed(): string
    {
        return \sprintf(<<<'SQL'
            (live.id IS NOT NULL AND %1$s
             AND EXISTS (SELECT 1 FROM sales_return_line orl
                          WHERE orl.tenant_id = r.tenant_id AND orl.sales_return_id = r.id AND %2$s))
            SQL, self::stampedForCredit(), self::hasUndecidedPair('orl', 'live'));
    }

    /** `billed(l)` = shipped − returned before issue (the issuer's formula; one copy, J-13). */
    public static function billed(string $fl): string
    {
        return \sprintf('(%1$s.quantity - %2$s)', $fl, self::returnedBeforeIssue($fl));
    }

    /** `creditedByReturns(l, live)`: Σ return-credit quantities on shipped line `$fl` on invoice `$live`. */
    public static function creditedByReturns(string $fl, string $live): string
    {
        return \sprintf(<<<'SQL'
            COALESCE((SELECT SUM(cbr.quantity) FROM credit_note_line cbr
                        JOIN credit_note cbrn ON cbrn.tenant_id = cbr.tenant_id AND cbrn.id = cbr.credit_note_id
                       WHERE cbr.tenant_id = %1$s.tenant_id AND cbr.fulfilment_line_id = %1$s.id
                         AND cbrn.invoice_id = %2$s.id AND cbrn.sales_return_id IS NOT NULL), 0::numeric(19,6))
            SQL, $fl, $live);
    }

    /** `sameOnLine(l, live)`: Σ live `same` quantities whose adjustment line is on `$fl` and on `$live`. */
    public static function sameOnLine(string $fl, string $live): string
    {
        return \sprintf(<<<'SQL'
            COALESCE((SELECT SUM(sol2.quantity) FROM adjustment_decision sol2
                        JOIN credit_note_line soll ON soll.tenant_id = sol2.tenant_id AND soll.id = sol2.credit_note_line_id
                        JOIN credit_note soln ON soln.tenant_id = soll.tenant_id AND soln.id = soll.credit_note_id
                       WHERE sol2.tenant_id = %1$s.tenant_id AND sol2.kind = 'same' AND soll.fulfilment_line_id = %1$s.id
                         AND soln.invoice_id = %2$s.id AND %3$s), 0::numeric(19,6))
            SQL, $fl, $live, self::live('sol2', 'sol2x'));
    }

    /**
     * **`provenOverlap(rl, cnl)`** (FR-4) = `GREATEST(creditedByReturns(l) + sameOnLine(l) + openOnReturnLine(rl) +
     * openOnAdjustmentLine(cnl) − billed(l), 0)`, `l` = shipped line `$fl`. `distinctIsImpossible` is `provenOverlap > 0`
     * (one copy: {@see self::distinctIsImpossible()}). The proof that an overlap of at least this many units exists is
     * design §4.4, given that the earlier records were true.
     */
    public static function provenOverlap(string $rl, string $cnl, string $fl, string $live): string
    {
        return \sprintf('GREATEST(%1$s + %2$s + %3$s + %4$s - %5$s, 0::numeric(19,6))',
            self::creditedByReturns($fl, $live), self::sameOnLine($fl, $live), self::openOnReturnLine($rl),
            self::openOnAdjustmentLine($cnl), self::billed($fl));
    }

    /** `distinctIsImpossible(rl, cnl)` (J-1): the arithmetic proves overlap, so a `distinct` would be false. */
    public static function distinctIsImpossible(string $rl, string $cnl, string $fl, string $live): string
    {
        return '('.self::provenOverlap($rl, $cnl, $fl, $live).' > 0)';
    }

    /**
     * **FR-9, the joint check over live `distinct`s**, on values each read through the terms above:
     * refuse when `Cr + Same + max(open(rl) + Σ_{cnl' ∈ D(rl) ∪ {cnl}} open(cnl'), open(cnl) + Σ_{rl' ∈ D(cnl) ∪ {rl}}
     * open(rl')) > billed`. Returns the joint excess (`'0.000000'` when possible). `$adjustmentSide` is
     * `open(cnl')` for every cnl' in `D(rl) ∪ {cnl}`, `$returnSide` `open(rl')` for every rl' in `D(cnl) ∪ {rl}` — each
     * keyed by id so the union is a set.
     *
     * @param array<string, string> $adjustmentSide
     * @param array<string, string> $returnSide
     */
    public static function jointExcess(string $creditedByReturns, string $sameOnLine, string $billed,
        string $openReturnLine, string $openAdjustmentLine, array $adjustmentSide, array $returnSide): string
    {
        $a = $openReturnLine;
        foreach ($adjustmentSide as $open) {
            $a = bcadd($a, $open, 6);
        }
        $b = $openAdjustmentLine;
        foreach ($returnSide as $open) {
            $b = bcadd($b, $open, 6);
        }
        $excess = bcsub(bcadd(bcadd($creditedByReturns, $sameOnLine, 6), 1 === bccomp($a, $b, 6) ? $a : $b, 6), $billed, 6);

        return 1 === bccomp($excess, '0', 6) ? $excess : '0.000000';
    }

    /**
     * **`decisionRound(rl, cnl)`** (design §2.4, final RED R-7 (b)) = `1 + count of REVERSED kind='distinct' rows on the
     * pair` — the ONE count every `distinct` writer uses for `decision_round`, read under `InvoiceRowLock`. A writer
     * counting every `distinct` instead would mint `max + 1` beside a live row (M36).
     */
    public static function decisionRound(string $rl, string $cnl): string
    {
        return \sprintf(<<<'SQL'
            (1 + (SELECT count(*) FROM adjustment_decision drd
                   WHERE drd.tenant_id = %1$s.tenant_id AND drd.sales_return_line_id = %1$s.id
                     AND drd.credit_note_line_id = %2$s.id AND drd.kind = 'distinct' AND NOT %3$s))::smallint
            SQL, $rl, $cnl, self::live('drd', 'drdx'));
    }

    /**
     * Every decision on the return lines `= ANY (?::uuid[])`, oldest first, with the adjustment's number and the recorder
     * — `adjustment_decisions[]` (design §5). One copy; `invoice.view` holders only.
     */
    public static function decisionsOnReturnLinesSql(): string
    {
        return <<<'SQL'
            SELECT ad.id, ad.sales_return_line_id, ad.kind, ad.quantity, ad.reverses_id, ad.created_at,
                   cn.id AS credit_note_id, cn.credit_note_number, u.id AS recorded_by_id, u.display_name AS recorded_by_display_name
              FROM adjustment_decision ad
              JOIN credit_note_line cnl ON cnl.tenant_id = ad.tenant_id AND cnl.id = ad.credit_note_line_id
              JOIN credit_note cn ON cn.tenant_id = cnl.tenant_id AND cn.id = cnl.credit_note_id
              JOIN app_user u ON u.tenant_id = ad.tenant_id AND u.id = ad.recorded_by_user_id
             WHERE ad.sales_return_line_id = ANY (?::uuid[])
             ORDER BY ad.created_at, ad.id
            SQL;
    }

    /**
     * Every decision on the credit-note lines `= ANY (?::uuid[])`, oldest first, with the return's number and the
     * recorder — `return_decisions[]` (design §5). One copy, used by `CreditNoteReader`; `invoice.view` holders only.
     */
    public static function decisionsOnAdjustmentLinesSql(): string
    {
        return <<<'SQL'
            SELECT ad.id, ad.credit_note_line_id, ad.sales_return_line_id, ad.kind, ad.quantity, ad.reverses_id, ad.created_at,
                   r.id AS sales_return_id, r.sales_return_number, u.id AS recorded_by_id, u.display_name AS recorded_by_display_name
              FROM adjustment_decision ad
              JOIN sales_return_line rl ON rl.tenant_id = ad.tenant_id AND rl.id = ad.sales_return_line_id
              JOIN sales_return r ON r.tenant_id = rl.tenant_id AND r.id = rl.sales_return_id
              JOIN app_user u ON u.tenant_id = ad.tenant_id AND u.id = ad.recorded_by_user_id
             WHERE ad.credit_note_line_id = ANY (?::uuid[])
             ORDER BY ad.created_at, ad.id
            SQL;
    }

    /** The numbers of the adjustments with a live `same` on return line `?` — FR-7's copy (`CreditNoteWriter`). */
    public static function adjustmentsRecordedSameOnReturnLineSql(): string
    {
        return \sprintf(<<<'SQL'
            SELECT DISTINCT cn.credit_note_number
              FROM adjustment_decision asr
              JOIN credit_note_line cnl ON cnl.tenant_id = asr.tenant_id AND cnl.id = asr.credit_note_line_id
              JOIN credit_note cn ON cn.tenant_id = cnl.tenant_id AND cn.id = cnl.credit_note_id
             WHERE asr.sales_return_line_id = ?::uuid AND asr.kind = 'same' AND %s
             ORDER BY 1
            SQL, self::live('asr', 'asrx'));
    }

    /** A return line has a live `same` (the owner's review filter, (s-iii)); `decided_since` bounds by `?` when given. */
    private static function hasLiveSame(bool $since): string
    {
        return \sprintf(<<<'SQL'
            EXISTS (SELECT 1 FROM sales_return_line hsl
                      JOIN adjustment_decision hsd ON hsd.tenant_id = hsl.tenant_id AND hsd.sales_return_line_id = hsl.id
                     WHERE hsl.tenant_id = r.tenant_id AND hsl.sales_return_id = r.id AND hsd.kind = 'same' AND %s%s)
            SQL, self::live('hsd', 'hsdx'), $since ? ' AND hsd.created_at >= ?::timestamptz' : '');
    }

    private static function linesSql(): string
    {
        return \sprintf(<<<'SQL'
        SELECT srl.id, srl.sales_return_id, srl.quantity, %1$s AS quantity_credited, %2$s AS quantity_covered_by_adjustment,
               fl.id AS fulfilment_line_id, sol.id AS sales_order_line_id, sol.line_number,
               i.id AS inventory_item_id, i.sku AS inventory_item_sku, i.name AS inventory_item_name,
               fsl.id AS shipped_from_id, fsl.code AS shipped_from_code,
               sl.id AS storage_location_id, sl.code AS storage_location_code
          FROM sales_return_line srl
          JOIN fulfilment_line fl ON fl.tenant_id = srl.tenant_id AND fl.id = srl.fulfilment_line_id
          JOIN sales_order_line sol ON sol.tenant_id = fl.tenant_id AND sol.id = fl.sales_order_line_id
          JOIN inventory_item i ON i.tenant_id = sol.tenant_id AND i.id = sol.inventory_item_id
          JOIN storage_location fsl ON fsl.tenant_id = fl.tenant_id AND fsl.id = fl.storage_location_id
          JOIN storage_location sl ON sl.tenant_id = srl.tenant_id AND sl.id = srl.storage_location_id
        SQL, self::creditedOnReturnLine('srl'), self::sameOnReturnLine('srl'));
    }

    private const LINES_ORDER = ' ORDER BY sol.line_number, fl.storage_location_id, srl.id';

    /**
     * @param array<string, mixed>                               $row
     * @param list<array<string, mixed>>                         $lines
     * @param array<string, list<AdjustmentDecisionView>>|null   $decisions null: the caller lacks `invoice.view`
     */
    private function view(array $row, array $lines, ?array $decisions): SalesReturnView
    {
        $creditable = (bool) $row['creditable'];
        $invoiceReader = null !== $decisions;

        return new SalesReturnView(
            id: (string) $row['id'],
            salesReturnNumber: (string) $row['sales_return_number'],
            reason: (string) $row['reason'],
            returnedAt: (string) SalesOrderRow::timestamp($row['returned_at']),
            fulfilment: new FulfilmentRef((string) $row['fulfilment_id'], (string) $row['fulfilment_number']),
            invoice: null === $row['invoice_id'] ? null : new InvoiceNumberRef((string) $row['invoice_id'], (string) $row['invoice_number']),
            creditTarget: $creditable ? new InvoiceNumberRef((string) $row['live_invoice_id'], (string) $row['live_invoice_number']) : null,
            deducts: (bool) $row['deducts'],
            recordedBy: new SalesActorRef((string) $row['recorded_by_id'], (string) $row['recorded_by_display_name']),
            createdAt: (string) SalesOrderRow::timestamp($row['created_at']),
            lines: array_map(static fn (array $line): SalesReturnLineView => new SalesReturnLineView(
                id: (string) $line['id'],
                fulfilmentLine: new ShippedLineRef(
                    (string) $line['fulfilment_line_id'],
                    new SalesLineRef((string) $line['sales_order_line_id'], (int) $line['line_number']),
                    new SalesItemRef((string) $line['inventory_item_id'], (string) $line['inventory_item_sku'], (string) $line['inventory_item_name']),
                    new SalesLocationRef((string) $line['shipped_from_id'], (string) $line['shipped_from_code']),
                ),
                inventoryItem: new SalesItemRef((string) $line['inventory_item_id'], (string) $line['inventory_item_sku'], (string) $line['inventory_item_name']),
                quantity: (string) $line['quantity'],
                storageLocation: new SalesLocationRef((string) $line['storage_location_id'], (string) $line['storage_location_code']),
                quantityCredited: (string) $line['quantity_credited'],
                // G-29 / G-30 part 3 (M-4): decision members are an `invoice.view` holder's alone, absent otherwise.
                quantityCoveredByAdjustment: $invoiceReader ? (string) $line['quantity_covered_by_adjustment'] : null,
                adjustmentDecisions: $invoiceReader ? ($decisions[(string) $line['id']] ?? []) : null,
            ), $lines),
            // M4-1: the field that names payment as the cause is an `invoice.view` holder's alone.
            creditBlockedByPayment: $this->identities->authenticated()->holds('invoice.view') ? (bool) $row['credit_blocked_by_payment'] : null,
            adjustmentDecisionOwed: $invoiceReader ? (bool) $row['adjustment_decision_owed'] : null,
        );
    }
}
