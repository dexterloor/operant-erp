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
 * - **Granting `tenant_settings.edit` is appointing a co-owner** for every exemption this function decides (the owner was
 *   told so, `-ruling.md` (c)). **The complete list of callers, re-derived by `git grep -nE "holdsMarker\("` over `src/`
 *   (2026-10-05, G-29 / G-30 part 3) and pinned by `OwnerPredicateSingleSourceTest::HOLDS_MARKER_CALLERS`:**
 *   - `PurchaseOrderDutySeparation` — DSoD-1 on `purchase_order.approve` / `.cancel` (A1.1);
 *   - `InvoicePaymentReversal` — A2.3's reverser ≠ recorder (`ReverseInvoicePayment`);
 *   - `InvoiceVoiding` — B′: voiding an invoice whose payment was ever reversed (`VoidInvoice`, sales step 6 C7,
 *     `invoice.is_not_payment_reversed`);
 *   - `CreditNoteWriter` — B′: crediting such an invoice without a return (`IssueAdjustmentCreditNote`, sales step 7 C1b,
 *     design §4.4b (ii), the same code reused); **and, from G-29 / G-30 part 3 C2, naming a pending return on the adjust
 *     path** (`acknowledged_return_ids`, step 11): a `distinct` assertion that the returned goods are different from the
 *     units adjusted. The refusal this exempts from is UNCONDITIONAL — `adjustment_decision.distinct_needs_the_owner` —
 *     so an adjuster without the marker cannot adjust past a pending return at all (design §4.2);
 *   - `SalesReturnWriter` — Q-L (a′): who may record a pre-invoice return that `deducts`;
 *   - `GrantConfirmationPolicy` — E-15e: an owner's grant of an `X(admin)` code needs no proposal (roles slice 3);
 *   - `RoleGrantProposalConfirmation`, `RoleGrantProposalRejection` — E-15e: only an owner confirms or rejects a
 *     proposal (roles slice 3 C2/C3; lock-time codes);
 *   - `AdjustmentDecisionWriter` — **G-29 / G-30 part 3:** recording that returned goods are DIFFERENT from units an
 *     adjustment already credited (`distinct`, owner ruling D1 (a)), and undoing any decision (the reversal, D2 (b));
 *   - `RoleUpdate` — **roles slice 4 C2, Q-A** (`roles-s4-c2-decisions.md` FINAL): only a marker holder (lock-time codes)
 *     may change a role whose stored or resulting codes confer the marker; the predicate is also applied to a ROLE's
 *     codes there (the stored set, the resulting set, and the removed codes for API-20's floor);
 *   - `StorageLocationWriter` — **warehouses slice 1 C3, owner Q-W1 (d)** (`warehouses-s1-design.md` §4.3, adopted under
 *     delegation 2026-10-13): taking a storage location OUT of `quarantine` while it holds stock (a release of held goods
 *     to picking). The refusal it exempts from is UNCONDITIONAL — `storage_location.release_needs_owner` — so no holder
 *     of `storage_location.edit` without the marker (`admin`, `warehouse_operator`) can release stocked held goods at all
 *     (`SEC` S-2; `RESIDUAL_GAPS.md` G-44, whose closing work is a dedicated release code). A hold is never gated;
 *   - *(`GrantRules`, `RoleRevocation` apply the predicate to a ROLE's codes — E-15b, "is this role an owner-grade
 *     role" — not to the caller's exemption.)*
 *   **For `distinct` and the reversal the refusal this predicate exempts from is UNCONDITIONAL** (`SEC` S-2, R-5): no
 *   holder of `credit_note.issue` without the marker can ever perform them, so the marker is the only route — a
 *   capability no catalogue entry names, invisible to anyone composing a role from `ListPermissions`.
 *
 * **A second copy of this predicate is a violation in every track**, and `OwnerPredicateSingleSourceTest` makes it
 * impossible rather than merely forbidden: no `OWNER_ROLE_NAME`, no SQL or PHP comparison of a role name to `'owner'`,
 * and `OwnerMarker::CODE` read only by the classes it allows. **It grants nothing on its own: E-1's code still gates
 * every act it decides. For some acts (recording `distinct`, naming a pending return on the adjust path, and reversing a
 * decision, from G-29/G-30 part 3) the refusal it exempts from is unconditional, so the marker is the only route.**
 * (`SEC` S-2, R-5, 2026-10-05: the earlier sentence "it only decides an exemption" stayed true only by the letter.)
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
