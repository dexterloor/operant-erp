<?php

declare(strict_types=1);

namespace App\Modules\UserManagement\Domain;

/**
 * `SECURITY_MODEL.md` **PC-14** — the `admin` charter, executed.
 *
 * > *"`admin` holds every code in §3 except `X(admin)`, where `X(admin)` is the
 * > **controlled half** of every row in PC-4's SoD table, plus
 * > `tenant_settings.edit`."*
 *
 * ---------------------------------------------------------------------------
 * The whole of this class is that `X(admin)` is a DERIVATION
 * ---------------------------------------------------------------------------
 * PC-14: *"The exclusion set is derived from PC-4's table. It is never a list. …
 * Nobody should type that list again. The derivation is the rule; the six are
 * its output on today's table."* And `V2-R-2` *fails if* `X(admin)` is typed as
 * a constant.
 *
 * So `excludedCodes()` reads `SeparationOfDuty` and `OwnerMarker` and types
 * nothing. Add a fifth split tomorrow and `X(admin)` grows by that split's
 * controlled half **with no edit to this file** — which is the property PC-14
 * asks for by name, and the reason `docs/adr/sod_check.py`'s six-literal
 * `ADMIN_EXCL` is recorded in its own docstring as a defect rather than as a
 * check.
 *
 * ---------------------------------------------------------------------------
 * It runs in both directions, and the inverse is the half that can fire
 * ---------------------------------------------------------------------------
 * PC-14's violation clause:
 *
 *  - **Forward — the charter is breached:** `admin`'s set contains any member of
 *    `X(admin)`. Structurally impossible here, because `DefaultRoles` computes
 *    `admin` as the catalogue *minus* this set. That is the intent: *"`admin` is
 *    clean on every split by construction, present and future"*.
 *  - **Inverse — the charter is exceeded:** `admin`'s set omits a §3 code that is
 *    **not** a member of `X(admin)`. Also structurally impossible while `admin`
 *    is computed as a complement — which is why the inverse clause is checked in
 *    the test against the *document*, where a hand-written §4 row can drift.
 *
 * **The stopping rule is PC-14's and is not implemented here, because it cannot
 * be:** `admin` loses a code by one route only — that code appearing on a
 * controlled half. An argument of the form *"nothing about administering
 * requires X"* is unbounded (PC-14 enumerates the twelve further codes it would
 * license) and is refused by review, not by a function.
 */
final class AdministrativeCharter
{
    /** Not instantiable: one derivation. */
    private function __construct()
    {
    }

    /**
     * **`X(admin)`.** The controlled half of every SoD row, plus E-15b's marker.
     *
     * Two sources, joined here and nowhere else. Neither is typed in this file.
     *
     * @return list<string>
     */
    public static function excludedCodes(): array
    {
        $excluded = SeparationOfDuty::controlledCodes();

        if (!\in_array(OwnerMarker::CODE, $excluded, true)) {
            $excluded[] = OwnerMarker::CODE;
        }

        return $excluded;
    }

    /**
     * The part of `X(admin)` that separation of duty supplies.
     *
     * Published separately so that the two provenances stay distinguishable at
     * runtime: a caller that wants *"the codes a second pair of hands is
     * required for"* wants this, and a caller implementing E-15b wants
     * `OwnerMarker::CODE`. Folding them into one list is what
     * `sod_check.py`'s `ADMIN_EXCL` does, and it is why that constant cannot say
     * whether `admin` should hold 54 codes or 55.
     *
     * @return list<string>
     */
    public static function separationOfDutyExclusions(): array
    {
        return SeparationOfDuty::controlledCodes();
    }
}
