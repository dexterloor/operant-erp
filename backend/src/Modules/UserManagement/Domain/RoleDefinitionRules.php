<?php

declare(strict_types=1);

namespace App\Modules\UserManagement\Domain;

/**
 * **The pure rules a role definition is judged by** — roles slice 4 (design §4.1, §4.5, §4.7). No I/O: the callers
 * (`CreateRole`, `UpdateRole`, from C1/C2) read the sets under `RoleAdministrationLock` and pass them in. Nothing calls this
 * class at C0 except its tests.
 *
 *  - **The E-15f ceiling** — *"no actor adds to a role a permission code it does not itself hold"* (owner, 2026-09-14). It
 *    binds every code a definition ADDS, held role or not (D-3 is already ruled, reading A: the role-edit adjudication record).
 *    Whether `CreateRole` has it is `SEC`'s V2-R-7 (D-4); this function is the ceiling either way, and a caller that skips
 *    it is the place that ruling lands.
 *  - **PC-4 on the result** — a composed role spanning any SoD row is refused, for every actor including an owner: the
 *    exemption is a DEFAULT role's (`owner`, `purchasing_manager`), never a person's (ADR-008 §2; design §4.5).
 *  - **E-15f's PC-13 re-run over each holder** at edge grain (design §4.7): the edit is refused when it creates a
 *    conflicting PAIR the holder did not already carry —
 *    `edges(H ∪ N) − edges(H) − edges(N) − edges(H ∪ O)`, H the holder's other live roles' codes, O the old set, N the new.
 *    `edges(N)` is subtracted because a pair inside the new set alone is PC-4's to refuse; `edges(H ∪ O)` because a span the
 *    holder already carried is tolerated (new-conflict-only, the shape PC-13/E-18 already ship). Stricter than shipped
 *    `GrantConflict::newRows` (row grain) for new pairs, equal for pre-existing spans. `SEC` confirms the mechanics (D-7).
 */
final class RoleDefinitionRules
{
    /** Not instantiable: pure functions. */
    private function __construct()
    {
    }

    /**
     * The codes being added that the actor does not hold — the E-15f ceiling's refusal set. Empty = within the ceiling.
     *
     * @param list<string> $addedCodes the codes the definition adds (all of them, for a creation)
     * @param list<string> $actorCodes the actor's lock-time codes
     *
     * @return list<string> in `$addedCodes`' order
     */
    public static function beyondCeiling(array $addedCodes, array $actorCodes): array
    {
        return array_values(array_diff($addedCodes, $actorCodes));
    }

    /**
     * PC-4 at composition: the SoD rows the resulting role would span on its own. Non-empty = refused, whoever acts.
     *
     * @param list<string> $resultCodes the role's code set after the write
     *
     * @return list<string> row ids, in §2's order
     */
    public static function separationOfDutyRowsSpanned(array $resultCodes): array
    {
        return SeparationOfDuty::rowsSpannedBy($resultCodes);
    }

    /**
     * The conflicting pairs an edit would newly give ONE holder of the role.
     *
     * @param list<string> $heldOther the union of the holder's OTHER live roles' codes
     * @param list<string> $oldCodes  the role's codes before the edit
     * @param list<string> $newCodes  the role's codes after the edit
     *
     * @return list<array{0: string, 1: string}> the new edges, each sorted; empty = no new conflict for this holder
     */
    public static function newConflictEdgesForHolder(array $heldOther, array $oldCodes, array $newCodes): array
    {
        $before = [
            ...SeparationOfDuty::edgesSpannedBy($heldOther),
            ...SeparationOfDuty::edgesSpannedBy($newCodes),
            ...SeparationOfDuty::edgesSpannedBy(self::union($heldOther, $oldCodes)),
        ];

        return array_values(array_filter(
            SeparationOfDuty::edgesSpannedBy(self::union($heldOther, $newCodes)),
            static fn (array $edge): bool => !\in_array($edge, $before, true),
        ));
    }

    /**
     * @param list<string> $a
     * @param list<string> $b
     *
     * @return list<string>
     */
    private static function union(array $a, array $b): array
    {
        return array_values(array_unique([...$a, ...$b]));
    }
}
