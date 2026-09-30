<?php

declare(strict_types=1);

namespace App\Modules\UserManagement\Application;

use App\Modules\UserManagement\Application\Contract\OwnerPredicate;
use App\Modules\UserManagement\Domain\AdministrativeCharter;

/**
 * **E-15e's routing: does this grant need the owner's confirmation?** (roles slice 2, C3; `docs/session13/roles-s2-design.md`
 * §5.2 "`GrantConfirmationPolicy`"; `SECURITY_MODEL.md` §5.2.3.)
 *
 * ```
 * needsConfirmation(actor, role) = !OwnerPredicate::holdsMarker(actor) && role ∩ X(admin) ≠ ∅
 * ```
 *
 * An owner grants anything directly. Anyone else may grant a role only while it meets no `X(admin)` code; a role that does
 * needs the owner's confirmation — a route that does not exist until the proposal slice (slice 3), so until then
 * `AssignRole` refuses with `user_role.needs_owner_confirmation` and names no route.
 *
 * **No typed code list** (T4; V2-R-2 fails if `X(admin)` is typed): `X(admin)` is `AdministrativeCharter::excludedCodes()`,
 * derived from `SeparationOfDuty`, so A2.1a's `invoice.void` is already inside it and A2.1b's `credit_note.issue` will be
 * with no edit here. Keyed on the actor's CODES through the one owner predicate — never on `role.assign` (mutation M2) and
 * never on a role's name (M2b).
 *
 * **Why `Application`, not `Domain` as the design placed it (deviation, recorded):** the owner test is `OwnerPredicate`, in
 * `UserManagement\Application\Contract`, and `UserManagementDomain` may depend on `SharedKernel` only (deptrac). A second
 * owner test in `Domain` is what `OwnerPredicateSingleSourceTest` forbids. The function is still pure.
 *
 * Callers pass the actor's codes **re-derived under the role-administration lock** (`LiveGrantReader`), never the request
 * `Identity`'s (S-6).
 */
final class GrantConfirmationPolicy
{
    /**
     * @param list<string> $actorCodes the actor's live codes, under the lock
     * @param list<string> $roleCodes  the codes of the role being granted
     */
    public static function needsConfirmation(array $actorCodes, array $roleCodes): bool
    {
        return !OwnerPredicate::holdsMarker($actorCodes)
            && [] !== array_intersect($roleCodes, AdministrativeCharter::excludedCodes());
    }

    /** Not instantiable: one pure function. */
    private function __construct()
    {
    }
}
