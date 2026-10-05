<?php

declare(strict_types=1);

namespace App\Modules\UserManagement\Application\Contract;

use App\Modules\UserManagement\Domain\OwnerMarker;

/**
 * **"The caller is the owner" — the ONE answer to that question in this codebase** (V2-R-19 + V2-S-5; `SEC`'s Q8
 * ruling, `docs/session13/output9-sales-7-sec.md` §3; built by sales step 6, C6a, 2026-09-28; the owner acknowledged
 * the notice, `-ruling.md` (c)).
 *
 * > **The caller is the owner when the permission set E-1 enforced for this request contains `OwnerMarker::CODE`
 * > (`tenant_settings.edit`).** Never by a role's name, never by `is_system`, never by holding a controlled code.
 *
 * - **Pure.** Its input is `$identity->permissionCodes` — the set `IdentityReader` derived for this request from live
 *   `app_user`, live `role`, `user_role` and `role_permission` — so it runs **no SQL**, and the request is authorised
 *   (E-1) and exempted from ONE snapshot. A marker revoked mid-request still exempts that one request, exactly as the
 *   permission that authorised it still counts; nothing serialises a revocation against an approval in either version.
 *   **Callers inside the role-administration lock pass `RoleAdministrationActor`'s lock-time codes instead (S-6)** —
 *   `AssignRole`, `RevokeRole`, `ConfirmRoleGrantProposal` — so there a marker revoked while the request waited does NOT
 *   exempt it; other callers pass the request's set (roles slice 3 C2; design §21 item 23, RED-5 L-3).
 * - **Why the marker and not a name:** a name is tenant-writable text. The name test failed both ways — a role merely
 *   NAMED `owner` with no codes was exempt, and a co-owner the owner composed under another name was not. Who can hold
 *   the marker is governed by rules written for exactly that (E-15b, §5.2.9, E-15f).
 * - **Why the marker is not A1.1's refused "permission-set reading":** A1.1 refused exempting by SoD-1's controlled codes,
 *   which `purchasing_manager` holds. The marker is on no SoD row and `purchasing_manager` does not hold it, so it stays
 *   refused.
 * - **Granting `tenant_settings.edit` is appointing a co-owner** for every exemption this function decides: DSoD-1 on
 *   `purchase_order.approve` / `.cancel` (A1.1), A2.3's reverser ≠ recorder (`ReverseInvoicePayment`), and — when built —
 *   E-15b, E-15e and E-15g. The owner was told so (`-ruling.md` (c)). **Two more since, both B′-shaped (an invoice
 *   whose payment was ever reversed):** voiding it (`VoidInvoice`, sales step 6 C7, `invoice.is_not_payment_reversed`)
 *   and crediting it without a return (`IssueAdjustmentCreditNote`, sales step 7 C1b, design §4.4b (ii), the same
 *   code reused) — an owner-marker holder passes both.
 *
 * **A second copy of this predicate is a violation in every track**, and `OwnerPredicateSingleSourceTest` makes it
 * impossible rather than merely forbidden: no `OWNER_ROLE_NAME`, no SQL or PHP comparison of a role name to `'owner'`,
 * and `OwnerMarker::CODE` read only by the classes it allows. It grants nothing; it only decides an exemption.
 */
final class OwnerPredicate
{
    /**
     * @param list<string> $liveCodes callers inside the role-administration lock pass `RoleAdministrationActor`'s lock-time
     *                                codes (S-6); other callers pass the request's set (`Identity::$permissionCodes`)
     */
    public static function holdsMarker(array $liveCodes): bool
    {
        return \in_array(OwnerMarker::CODE, $liveCodes, true);
    }

    /** Not instantiable: one pure function. */
    private function __construct()
    {
    }
}
