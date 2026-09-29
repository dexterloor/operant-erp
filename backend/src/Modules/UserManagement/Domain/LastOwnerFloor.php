<?php

declare(strict_types=1);

namespace App\Modules\UserManagement\Domain;

/**
 * **API-20's floor — the one home of "a workspace may not be left with no owner"** (`API.md` API-20, `SECURITY_MODEL.md`
 * E-15b / E-15g; roles slice 2, C2, as amended by `docs/session13/roles-s2-spar.md` P1 / §2 item 3).
 *
 * `refuses($before, $after)` = **`$before > 0 && $after === 0`**, both counts being the workspace's **active marker
 * holders** — users that are not deleted, are `is_active`, and hold `tenant_settings.edit` (the owner marker,
 * {@see OwnerMarker}) through a live role — read under the role-administration lock by `LiveGrantReader`: `$before` as it
 * stands, `$after` as it would stand once this act's whole effect is applied.
 *
 * **Why the before/after form, and not a count alone or three booleans:**
 *  - `after === 0` alone (mutation R9) refuses every revoke in a workspace ALREADY without an active owner (SEC-O-19's
 *    state, reachable today only by owner-process writes) — including an `admin` removing `finance` from a compromised
 *    account — and would call it "last owner". An act that cannot reduce the number of active owners (`after === before`)
 *    has nothing to answer to this floor.
 *  - The design's first form, `(roleConfersMarker, subjectIsActiveMarkerHolder, excludingThisGrant)`, is equivalent for one
 *    revoke (spar P1 showed it) but leaves both booleans undefined for a bulk assignment over several users or for E-15g's
 *    deactivation, which removes a user's liveness rather than a role — exactly where a later hand re-derives the rule.
 *    Counts are defined for every caller: **a bulk operation or an E-15g strip computes `$after` over its WHOLE effect,
 *    once**, never per member, or two members each passing alone could remove the last two owners together.
 *
 * **Unreachable through `RevokeRole` in a correct build** (design §5.1, stated honestly): the actor must hold the marker to
 * revoke a marker role (E-15b), is not the subject (E-15a), and is re-derived live and active under the lock, so after any
 * single revoke the actor is still an active marker holder. It is the last line against ONE upstream defect — the actor's
 * codes taken from the request instead of under the lock (mutation A5) — and the paired mutation A5+R5 is the evidence that
 * it is not dead code: it reaches zero owners over HTTP.
 */
final class LastOwnerFloor
{
    public static function refuses(int $activeMarkerHoldersBefore, int $activeMarkerHoldersAfter): bool
    {
        return $activeMarkerHoldersBefore > 0 && 0 === $activeMarkerHoldersAfter;
    }

    /** Not instantiable: one pure function. */
    private function __construct()
    {
    }
}
