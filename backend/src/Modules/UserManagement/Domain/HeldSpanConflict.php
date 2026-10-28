<?php

declare(strict_types=1);

namespace App\Modules\UserManagement\Domain;

/**
 * **PC-13 / E-18 for someone who ALREADY combines two separated duties** (`RESIDUAL_GAPS.md` G-91; `SEC`
 * `docs/design/sec-held-conflict.md` Revision 2, R2.1 and R2.4; BK-11).
 *
 * {@see GrantConflict::newRows()} works at row grain and subtracts the rows the subject already spans, so a person who
 * already spans a row (through a database edit, owner-process seeding, or a later commit that changed the duty table or a
 * default role) could be given a NEW combination on that same row. This class is the held-violator clause, and it works
 * per row at edge grain:
 *
 * ```
 * refuse R for P on row r  ⇔  r ∉ spans(R)  and  some pair (p, c), p on r's preparing half, c on r's controlled half,
 *                               has both ends in held ∪ R, not both in held, and not both in R
 * ```
 *
 * `held` is the flattened live codes, as `GrantConflict::newRows()` takes them. **`r ∉ spans(R)` is the guard** that keeps
 * E-18's deliberate permission: a role that spans a row on its own (`purchasing_manager` on SoD-1) is not refused on that
 * row. For a person who spans rows only through single roles holding every code of those rows (every default role today,
 * `HeldSpanConflictTest`'s invariant), no new pair can fall on such a row, so this clause agrees with `newRows`; it adds
 * refusals only for held violators.
 *
 * Every row is read from {@see SeparationOfDuty::rowIds()} and {@see SeparationOfDuty::row()}, never from
 * {@see SeparationOfDuty::conflictEdges()}, which carries no row, and never from a pair list (V2-R-2).
 *
 * The two report functions serve `app:roles:reconcile` arm H, which lists people and revokes nothing:
 *  - {@see self::violatedRows()} — PC-13's predicate: rows the union spans that no single live role spans alone;
 *  - {@see self::maskedRows()} — R2.4: rows the union spans that are spanned alone only by roles not needed for the union
 *    to span them (granting `owner` to a violator hides the violation from PC-13's predicate until it is revoked).
 */
final class HeldSpanConflict
{
    /**
     * The rows a grant of `$roleCodes` to a holder of `$heldCodes` would refuse under the held-violator clause.
     *
     * @param list<string> $heldCodes the union of the subject's live roles' codes
     * @param list<string> $roleCodes the codes of the role being granted
     *
     * @return list<string> SoD row ids, in §2's order; empty = this clause refuses nothing
     */
    public static function newEdgeRows(array $heldCodes, array $roleCodes): array
    {
        $together = array_values(array_unique([...$heldCodes, ...$roleCodes]));
        $spannedByRole = SeparationOfDuty::rowsSpannedBy($roleCodes);
        $rows = [];

        foreach (SeparationOfDuty::rowIds() as $id) {
            if (\in_array($id, $spannedByRole, true)) {
                continue;
            }
            $row = SeparationOfDuty::row($id);
            foreach ($row['preparing'] as $preparing) {
                foreach ($row['controlled'] as $controlled) {
                    if (!\in_array($preparing, $together, true) || !\in_array($controlled, $together, true)) {
                        continue;
                    }
                    $inHeld = \in_array($preparing, $heldCodes, true) && \in_array($controlled, $heldCodes, true);
                    $inRole = \in_array($preparing, $roleCodes, true) && \in_array($controlled, $roleCodes, true);
                    if (!$inHeld && !$inRole) {
                        $rows[] = $id;
                        continue 3;
                    }
                }
            }
        }

        return $rows;
    }

    /**
     * PC-13's predicate over one person's live roles: the rows their union spans that no single role spans alone.
     *
     * @param array<string, list<string>> $liveRoles role id => that role's codes
     *
     * @return list<string> SoD row ids, in §2's order
     */
    public static function violatedRows(array $liveRoles): array
    {
        $violated = [];
        foreach (SeparationOfDuty::rowsSpannedBy(self::union($liveRoles)) as $id) {
            if ([] === self::rolesSpanningAlone($liveRoles, $id)) {
                $violated[] = $id;
            }
        }

        return $violated;
    }

    /**
     * R2.4: per masking role, the rows the union spans that are spanned alone only by roles not needed for the union to
     * span them — i.e. the roles that do NOT span the row alone still span it together.
     *
     * @param array<string, list<string>> $liveRoles role id => that role's codes
     *
     * @return array<string, list<string>> masking role id => SoD row ids in §2's order; ordered by role id
     */
    public static function maskedRows(array $liveRoles): array
    {
        $masked = [];
        foreach (SeparationOfDuty::rowsSpannedBy(self::union($liveRoles)) as $id) {
            $alone = self::rolesSpanningAlone($liveRoles, $id);
            if ([] === $alone) {
                continue;
            }
            $others = array_diff_key($liveRoles, array_flip($alone));
            if (!\in_array($id, SeparationOfDuty::rowsSpannedBy(self::union($others)), true)) {
                continue;
            }
            foreach ($alone as $roleId) {
                $masked[$roleId][] = $id;
            }
        }
        ksort($masked);

        return $masked;
    }

    /**
     * @param array<string, list<string>> $liveRoles
     *
     * @return list<string>
     */
    private static function union(array $liveRoles): array
    {
        return array_values(array_unique(array_merge([], ...array_values($liveRoles))));
    }

    /**
     * @param array<string, list<string>> $liveRoles
     *
     * @return list<string> the role ids that span `$rowId` alone
     */
    private static function rolesSpanningAlone(array $liveRoles, string $rowId): array
    {
        $alone = [];
        foreach ($liveRoles as $roleId => $codes) {
            if (\in_array($rowId, SeparationOfDuty::rowsSpannedBy($codes), true)) {
                $alone[] = (string) $roleId;
            }
        }

        return $alone;
    }

    /** Not instantiable: pure functions. */
    private function __construct()
    {
    }
}
