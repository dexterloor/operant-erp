<?php

declare(strict_types=1);

namespace App\Modules\UserManagement\Domain;

/**
 * ADR-008 §2's SoD table, committed and runtime-readable — `V2-R-2` / **D2**.
 *
 * ---------------------------------------------------------------------------
 * The row convention, and it is load-bearing rather than decorative
 * ---------------------------------------------------------------------------
 * ADR-008 §2: **every row is written `preparing act / consummating act`, and the
 * right-hand side is the *controlled half*.** PC-14 derives `X(admin)` from that
 * side, so a row written the other way round silently inverts the admin charter.
 * The two halves are therefore named `preparing` and `controlled` here rather
 * than `a`/`b` or `left`/`right`: a row cannot be typed in this class without
 * the author stating which side is which.
 *
 * ADR-008's own violation clause — *"a row whose right-hand side is not the
 * consummating act"* — is not mechanically checkable (it is a claim about the
 * world, not about the data), and this class does not pretend otherwise. What
 * **is** checkable, and is checked, is that every consumer of the controlled
 * half reads it from here.
 *
 * ---------------------------------------------------------------------------
 * Why the table is typed HERE and nowhere else
 * ---------------------------------------------------------------------------
 * `V2-R-2` *fails if* `X(admin)` or an SoD row is typed as a constant. That
 * clause is about **second copies**: ADR-008 A2.6 files it as *"`X(admin)` typed
 * as a list instead of derived from §2's table"*, and PC-13's own clause files
 * the same defect as *"a seeder or a voter carrying this pair list as a hardcoded
 * constant"*. `docs/` is gitignored, so the table has to be typed once somewhere
 * to exist at runtime at all. **This class is that once.**
 *
 * So: `X(admin)` (`AdministrativeCharter`), `admin`'s and `owner`'s permission
 * sets (`DefaultRoles`), PC-13's pair table and PC-4's fires are all **computed**
 * from `ROWS`. None of them is typed. A second copy of a row anywhere under
 * `backend/src/` is the violation, not this one.
 *
 * ---------------------------------------------------------------------------
 * Amendment 2 is ruled and applied in halves (ADR-008 A2.7)
 * ---------------------------------------------------------------------------
 * **Amendment 2 is ruled and applied in halves (ADR-008 A2.7). A2.1a is HERE since
 * 'Let an invoice issued in error be voided, and take voiding off admin' (2026-09-29):
 * `invoice.void` is on SoD-3's controlled half. A2.1b and SoD-5 are HERE since
 * sales step 7 C1 ('Let finance credit a returned shipment…', 2026-10-01):**
 * `credit_note.issue` is on SoD-3's controlled half, and SoD-5 pairs it with
 * `sales_return.create` (which entered at R2, one commit ahead of its row, as A2.7
 * allows the preparing side to). A2.1c (`credit_note.adjust`) enters with
 * `IssueAdjustmentCreditNote` (C1b), never before A2.1b.
 *
 * *(Until 2026-09-29 this heading read "Amendment 2 is ruled and deliberately NOT
 * here", and SoD-3's controlled half was `invoice.pay` alone. A2.1a moved
 * `invoice.void` onto it in the commit that built `VoidInvoice`, together with
 * ADR-008 §2's SoD-3 row and `SECURITY_MODEL.md` §4's `admin` (54 → 53, by PC-14's
 * derivation — `DefaultRoles` and `AdministrativeCharter` changed no line).)*
 */
final class SeparationOfDuty
{
    /**
     * ADR-008 §2's five rows — SoD-3 with A2.1a and A2.1b applied, and SoD-5 (A2.7).
     *
     * @var array<string, array{preparing: list<string>, controlled: list<string>}>
     */
    private const ROWS = [
        'SoD-1' => [
            'preparing' => ['purchase_order.create'],
            'controlled' => ['purchase_order.approve', 'purchase_order.cancel'],
        ],
        'SoD-2' => [
            'preparing' => ['purchase_order.create', 'purchase_order.approve'],
            'controlled' => ['goods_receipt.create'],
        ],
        'SoD-3' => [
            'preparing' => ['invoice.issue'],
            // `invoice.void` since A2.1a (ADR-008 A2.7, 2026-09-29); `credit_note.issue` since A2.1b (sales step 7 C1,
            // 2026-10-01, with `IssueCreditNote`).
            'controlled' => ['invoice.pay', 'invoice.void', 'credit_note.issue'],
        ],
        'SoD-4' => [
            'preparing' => ['sales_order.confirm', 'invoice.issue'],
            'controlled' => ['fulfilment.create'],
        ],
        // ADR-008 A2.2, entering with its SECOND code (A2.7): the person who records a customer return does not also issue
        // the credit note that reduces the invoice for it (sales step 7 C1, 2026-10-01).
        'SoD-5' => [
            'preparing' => ['sales_return.create'],
            'controlled' => ['credit_note.issue'],
        ],
    ];

    /** Not instantiable: a table, not a service. */
    private function __construct()
    {
    }

    /**
     * The row identifiers, in §2's order.
     *
     * @return list<string>
     */
    public static function rowIds(): array
    {
        return array_keys(self::ROWS);
    }

    /**
     * One row, both halves.
     *
     * @return array{preparing: list<string>, controlled: list<string>}
     */
    public static function row(string $id): array
    {
        return self::ROWS[$id] ?? ['preparing' => [], 'controlled' => []];
    }

    /**
     * The preparing half of one row.
     *
     * @return list<string>
     */
    public static function preparingHalf(string $id): array
    {
        return self::row($id)['preparing'];
    }

    /**
     * The controlled half of one row — the consummating act.
     *
     * @return list<string>
     */
    public static function controlledHalf(string $id): array
    {
        return self::row($id)['controlled'];
    }

    /**
     * **The union of every row's controlled half.** This is the whole of what
     * PC-14 derives `X(admin)` from — the SoD-derived part of it.
     *
     * Its size is not written here: it moves whenever a row does (A2.1a added one).
     *
     * @return list<string>
     */
    public static function controlledCodes(): array
    {
        $codes = [];

        foreach (self::ROWS as $row) {
            foreach ($row['controlled'] as $code) {
                if (!\in_array($code, $codes, true)) {
                    $codes[] = $code;
                }
            }
        }

        return $codes;
    }

    /**
     * Every code named on either half of any row.
     *
     * @return list<string>
     */
    public static function governedCodes(): array
    {
        $codes = [];

        foreach (self::ROWS as $row) {
            foreach ([...$row['preparing'], ...$row['controlled']] as $code) {
                if (!\in_array($code, $codes, true)) {
                    $codes[] = $code;
                }
            }
        }

        return $codes;
    }

    /**
     * **PC-4's predicate, as a function of a permission set rather than of a
     * role.** The rows a holder of `$codes` spans — that is, the rows where it
     * holds at least one code from *each* half.
     *
     * Taking a set rather than a role name is what lets the same function answer
     * PC-4 (one role's set), PC-13 (the union of two) and E-15f's re-run over a
     * composed role, with no second implementation of the predicate. ADR-008 §2:
     * *"any role — default or composed"*.
     *
     * @param list<string> $codes
     *
     * @return list<string> the row ids spanned, in §2's order
     */
    public static function rowsSpannedBy(array $codes): array
    {
        $spanned = [];

        foreach (self::ROWS as $id => $row) {
            $holdsPreparing = [] !== array_intersect($row['preparing'], $codes);
            $holdsControlled = [] !== array_intersect($row['controlled'], $codes);

            if ($holdsPreparing && $holdsControlled) {
                $spanned[] = $id;
            }
        }

        return $spanned;
    }

    /**
     * The conflict graph E-15b/E-15c's near-owner ceiling is a vertex cover of:
     * one undirected edge per (preparing code, controlled code) pair, per row.
     *
     * ADR-008 §2 states the method explicitly and states why the obvious
     * arithmetic is wrong: *"the rule for recomputing it is a minimum vertex
     * cover over the conflict graph, not `60 −` one per split"*, because splits
     * **share codes**, so a fifth split can cost 0 or 2 rather than 1.
     *
     * @return list<array{0: string, 1: string}> each edge sorted, de-duplicated
     */
    public static function conflictEdges(): array
    {
        $edges = [];

        foreach (self::ROWS as $row) {
            foreach ($row['preparing'] as $preparing) {
                foreach ($row['controlled'] as $controlled) {
                    $edge = [$preparing, $controlled];
                    sort($edge);

                    if (!\in_array($edge, $edges, true)) {
                        $edges[] = $edge;
                    }
                }
            }
        }

        return $edges;
    }
}
