<?php

declare(strict_types=1);

namespace App\Modules\UserManagement\Domain;

/**
 * **PC-13 / E-18 at `AssignRole`: the separation-of-duty rows a grant would CREATE** (roles slice 2, C3;
 * `docs/session13/roles-s2-design.md` §5.2 step 9 as revised after `RED` R2-7; spar P8).
 *
 * ```
 * new = rowsSpannedBy(held ∪ role) − rowsSpannedBy(held) − rowsSpannedBy(role)
 * ```
 *
 *  - **`− rowsSpannedBy(held)`** — a split the subject ALREADY spans (reachable only by owner-process seeding, or later by a
 *    role edit before E-15f's re-run) is reconciliation's business, not a reason to block an unrelated grant. The first draft
 *    subtracted each held role alone and so refused ANY further grant to such a subject — `viewer` included, which E-18
 *    names as a violation (mutation A2).
 *  - **`− rowsSpannedBy(role)`** — a single role that spans a row on its own is E-18's deliberate permission (a split is
 *    refused only if no single member spans it): `purchasing_manager` spans SoD-1 alone and is grantable to a `buyer`
 *    (mutation A2b).
 *
 * The spar searched every ordered three-grant sequence of default roles and found no admitted sequence that ends spanning a
 * row no single held role spans; for composed roles, the step at which a row is first spanned has it in `spans(held∪role)`
 * and not in `spans(held)`, so it is refused unless the role spans it alone.
 *
 * Pure, over code sets. `held` is the union of the subject's LIVE roles' codes, re-derived under the role-administration
 * lock (`LiveGrantReader`), never a pair list: the rows come from `SeparationOfDuty`, the one committed copy of ADR-008 §2.
 */
final class GrantConflict
{
    /**
     * @param list<string> $heldCodes the union of the subject's live roles' codes
     * @param list<string> $roleCodes the codes of the role being granted
     *
     * @return list<string> the SoD row ids the grant would newly span, in §2's order; empty = no conflict
     */
    public static function newRows(array $heldCodes, array $roleCodes): array
    {
        $together = SeparationOfDuty::rowsSpannedBy(array_values(array_unique([...$heldCodes, ...$roleCodes])));

        return array_values(array_diff($together, SeparationOfDuty::rowsSpannedBy($heldCodes), SeparationOfDuty::rowsSpannedBy($roleCodes)));
    }

    /** Not instantiable: one pure function. */
    private function __construct()
    {
    }
}
