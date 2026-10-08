<?php

declare(strict_types=1);

namespace App\Modules\UserManagement\Application;

use App\Modules\UserManagement\Domain\GrantConflict;
use App\Modules\UserManagement\Domain\MarginExposure;

/**
 * **The picker's per-role verdicts — pure, over what `OfferedRoleReader`'s one statement read** (users slice 1 C3;
 * `docs/session13/users-s1-design.md` rev 3 §2.2; `SEC` `users-s1-sec.md` §1.4, §2.2, R1-1, R1-3).
 *
 * Every rule is the server's own function, never a copy:
 *  - `refusal` is {@see GrantRules::firstVerdict()} — `AssignRole`'s phases 1–4 in their order, **omitting slot B**
 *    (`proposal_pending`: owner ruling Q-1, 2026-10-01 — the picker never says a grant is pending) and routing. Phase 3
 *    reads whether ANY grant row exists for the pair (live or archived role; `LiveGrantReader::grant()`'s set), phase 4
 *    the codes of the subject's grants on LIVE roles (`LiveGrantReader::codesHeldBy()`'s set) — two inputs, never derived
 *    from each other (RED-3 F-1). `AssignRole` re-decides everything under the lock.
 *  - `requires_owner_confirmation` is {@see GrantConfirmationPolicy::needsConfirmation()}, `false` whenever `refusal` is
 *    set (V2-C-2's E-15e notice).
 *  - `conflicts_with` is pairwise PC-13 for the screen's SELECTION: {@see GrantConflict::newRows()}`(codes(r'), codes(r))`
 *    for every OTHER live role `r'`, one entry per SoD row. It can disagree with `refusal` on a held role (RED L-4): the
 *    held refusal is `refusal`'s, the selection's is the intersection of `conflicts_with`.
 *  - `margin_exposure_with` is PC-12 clause 4's pair rule, co-signed by `SEC` (§2.2): `r'` with
 *    `¬E(r) ∧ ¬E(r') ∧ E(r ∪ r') ∧ newRows(r, r') = ∅` — a pair PC-13 refuses outright is not warned (a refusal outranks a
 *    warning). Over-warning is accepted: the pair is shown even when the subject's union is already exposed.
 *  - Under `archived=true` every item is an archived role: `refusal` is the first applicable code (`role_is_archived`
 *    for an active subject), and the pair lists are `[]`.
 *
 * `archived` and `q` select WHICH items are returned and nothing else (RED-3 F-3): every pair list is computed against
 * ALL live roles of the workspace, and the held sets are never filtered.
 */
final class OfferedRoleEvaluator
{
    /**
     * @param list<array{id: string, name: string, is_system: bool, live: bool, codes: list<string>}> $items     the roles to return, in order
     * @param list<array{id: string, name: string, codes: list<string>}>                              $liveRoles every live role, `name, id`
     * @param array{deleted: bool, is_active: bool}                                                    $subject
     * @param list<string>                                                                             $callerCodes   the caller's live codes
     * @param list<string>                                                                             $heldRoleIds   every role the subject has a grant row for (live or archived)
     * @param list<string>                                                                             $heldLiveCodes the codes of the subject's grants on live roles
     *
     * @return list<OfferedRoleView>
     */
    public static function evaluate(array $items, array $liveRoles, array $subject, string $subjectId, string $callerId, array $callerCodes, array $heldRoleIds, array $heldLiveCodes): array
    {
        $views = [];
        foreach ($items as $item) {
            $role = ['name' => $item['name'], 'live' => $item['live'], 'is_system' => $item['is_system'], 'codes' => $item['codes']];
            $verdict = GrantRules::firstVerdict($subject, $role, $subjectId, $callerId, $callerCodes,
                \in_array($item['id'], $heldRoleIds, true), $heldLiveCodes);

            $views[] = new OfferedRoleView(
                id: $item['id'],
                name: $item['name'],
                conflictsWith: $item['live'] ? self::conflictsWith($item, $liveRoles) : [],
                marginExposureWith: $item['live'] ? self::marginExposureWith($item, $liveRoles) : [],
                refusal: $verdict?->code,
                requiresOwnerConfirmation: null === $verdict && GrantConfirmationPolicy::needsConfirmation($callerCodes, $item['codes']),
            );
        }

        return $views;
    }

    /**
     * @param array{id: string, codes: list<string>}                       $role
     * @param list<array{id: string, name: string, codes: list<string>}> $liveRoles
     *
     * @return list<array{role: array{id: string, name: string}, sod_row: string}>
     */
    private static function conflictsWith(array $role, array $liveRoles): array
    {
        $entries = [];
        foreach ($liveRoles as $other) {
            if ($other['id'] === $role['id']) {
                continue;
            }
            foreach (GrantConflict::newRows($other['codes'], $role['codes']) as $row) {
                $entries[] = ['role' => ['id' => $other['id'], 'name' => $other['name']], 'sod_row' => $row];
            }
        }

        return $entries;
    }

    /**
     * @param array{id: string, codes: list<string>}                       $role
     * @param list<array{id: string, name: string, codes: list<string>}> $liveRoles
     *
     * @return list<array{id: string, name: string}>
     */
    private static function marginExposureWith(array $role, array $liveRoles): array
    {
        if (MarginExposure::of($role['codes'])) {
            return [];
        }
        $partners = [];
        foreach ($liveRoles as $other) {
            if ($other['id'] === $role['id'] || MarginExposure::of($other['codes'])) {
                continue;
            }
            $union = array_values(array_unique([...$role['codes'], ...$other['codes']]));
            if (MarginExposure::of($union) && [] === GrantConflict::newRows($role['codes'], $other['codes'])) {
                $partners[] = ['id' => $other['id'], 'name' => $other['name']];
            }
        }

        return $partners;
    }

    /** Not instantiable: pure functions. */
    private function __construct()
    {
    }
}
