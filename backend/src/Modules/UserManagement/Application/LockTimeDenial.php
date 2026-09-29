<?php

declare(strict_types=1);

namespace App\Modules\UserManagement\Application;

/**
 * **What a role-administration transaction returns when the actor no longer holds the permission under the lock** —
 * `SEC`'s S-2 ruling (`docs/session13/roles-s2-sec.md` §2.1, and its *Acknowledgement of the spar* A.2–A.3, which governs;
 * mechanism `roles-s2-spar.md` P13).
 *
 * The refusal is a VALUE, never an exception thrown across `TenantContext::run()`: an exception there makes Doctrine
 * close the shared entity manager and roll back, so a denial row written inside the transaction would be lost, and a
 * denial written in a second `run()` afterwards would fail with `EntityManagerClosed` — a 500 with no row, E-3 not holding
 * (the spar measured it; mutation S2-M1′). Instead the transaction writes the `authz.permission.denied` row, RETURNS this,
 * and commits having written nothing else; the caller then throws `ApiProblem::permissionDenied()` outside `run()`,
 * byte-identical to the gate's 403.
 *
 * **A dedicated type on purpose** (`SEC` A.3): not `null` and not `false`, so a later caller cannot mistake a refusal for
 * a success and go on to write. Because the refusal path COMMITS, its ordering is an integrity property: the refusal is
 * returned before any statement or ORM mutation that writes (the re-check is the first decision after the lock), and
 * `RoleRevokeTest`'s demotion tests assert the pre-state row for row (mutation S2-M4).
 */
final readonly class LockTimeDenial
{
    public function __construct(public string $permissionCode)
    {
    }
}
