<?php

declare(strict_types=1);

namespace App\Modules\Purchasing\Application;

use App\Modules\UserManagement\Application\Contract\OwnerPredicate;
use App\Platform\Http\Problem\ApiProblem;
use App\Platform\Security\Identity;

/**
 * **DSoD-1, in the one place it is written — for both of SoD-1's controlled
 * codes.**
 *
 * ===========================================================================
 * Why this class exists, and why it is an extraction rather than a new rule
 * ===========================================================================
 * `PurchaseOrderApproval::refuseSelfApproval()` was written on 2026-09-10 with a
 * `$permissionCode` parameter that had exactly one argument, and its docblock
 * said what the parameter was for:
 *
 * > `refuseSelfApproval()` is written so the cancellation half is a second
 * > caller rather than a second copy — see the `$permissionCode` parameter,
 * > which exists only to make that true and today has exactly one argument.
 *
 * This is that second caller arriving. The predicate is **moved, not rewritten**:
 * the three clauses, their order and the rule-code mapping are the same code, and
 * what changed is that the class it lives in is no longer named after one of its
 * two callers. *(Its third clause's owner test changed on 2026-09-28 — see below.)* E-19 is explicit that the failure it is
 * guarding against is structural — *"the static half moved and the dynamic half
 * did not"* — so a cancellation path holding its own copy of this comparison
 * would be the same defect one layer down.
 *
 * ===========================================================================
 * The predicate, exactly
 * ===========================================================================
 * `ADR-008` §2 and its **Amendment 1** (2026-09-10), cited through
 * `SECURITY_MODEL.md` E-19 rather than restated as a fourth copy:
 *
 *   - **refuse when `created_by_user_id` = the caller's `app_user.id`**, on
 *     `purchase_order.approve` **and** on `purchase_order.cancel`;
 *   - **the owner is exempt — and "the owner" is the owner MARKER, not a role's
 *     name** (V2-R-19 + V2-S-5, `SEC`'s Q8 ruling; sales step 6 C6a, 2026-09-28;
 *     the owner acknowledged the notice). The caller is exempt when the permission
 *     set E-1 enforced for this request contains `tenant_settings.edit`
 *     (`OwnerMarker::CODE`), decided by the ONE function `OwnerPredicate::holdsMarker()`
 *     over `Identity::$permissionCodes` — no SQL of its own, one snapshot for the
 *     gate and the exemption. On a one-person demo tenant the owner raises the
 *     order and is the only seat that can place it or withdraw it, and holds the
 *     marker. **`purchasing_manager` is still refused**, as A1.1 says in terms:
 *     A1.1 refused exempting by SoD-1's CONTROLLED codes (which it holds); the
 *     marker is on no SoD row and it does not hold it.
 *     ⚠ **Until C6a this read "`owner` is exempt by name"** and the code looked up
 *     `role.name = 'owner'` in `user_role`: a role merely NAMED `owner` with zero
 *     codes was exempt, and a co-owner the owner composed under another name was
 *     not. **Changed behaviour, deliberately:** both are now the other way round
 *     (`PurchaseOrderOwnerExemptionTest`). Neither was reachable in production —
 *     no path creates or grants a role yet;
 *   - **a NULL creator does NOT refuse.** *"A reorder-agent order was raised by
 *     no person, so there is nobody for the canceller to be, and the rule returns
 *     true."* This is the rule returning **true**, not an exemption — F-4's
 *     orders stay approvable and cancellable by any holder of the code, and a
 *     refusal on NULL would make seeded data untouchable;
 *   - **the refusal renders as `business-rule-violated`, not 403 and not 404.**
 *     §5.3 clause 4 says it in terms and ST-4e is its screen. The caller holds
 *     the code and is looking at the row, so it is a rule about this row —
 *     E-2's second case.
 *
 * ===========================================================================
 * What is deliberately NOT here
 * ===========================================================================
 * **The ordering.** E-19 fixes it — the code checked (E-1) → row loaded →
 * DSoD-1 → `place()` or `cancel()` — and an ordering is a property of the
 * sequence, not of the comparison. It is enforced by the two callers, each of
 * which runs this method after its own row load and inside its own transaction.
 * Hoisting it ahead of the permission check is a **named violation**: it reads
 * the row, so hoisting rebuilds E-1's intra-tenant existence oracle for a caller
 * holding neither code.
 *
 * @see docs/adr/ADR-008-separation-of-duty-and-role-expansion.md §2, Amendment 1 (A1.1, R-11)
 * @see docs/SECURITY_MODEL.md §5 E-19
 * @see docs/API.md §8.1
 */
final readonly class PurchaseOrderDutySeparation
{
    /**
     * The two codes of SoD-1's controlled half, mapped to the rule identifier
     * their refusal carries.
     *
     * **The code is part of the client-visible identifier because the same
     * predicate is enforced on two operations**, and a client that saw one rule
     * code for both could not tell an approval refusal from a cancellation one.
     * A code outside this map is a `LogicException` rather than a default:
     * extending DSoD-1 to a third operation is an amendment to ADR-008, not a
     * new call site.
     *
     * @var array<string, string>
     */
    private const RULE_SUFFIXES = [
        'purchase_order.approve' => 'approver_is_not_the_creator',
        'purchase_order.cancel' => 'canceller_is_not_the_creator',
    ];

    /**
     * ST-4e's own words, per code. `ADR-008` §2 prints the approval sentence and
     * A1.1 prints the cancellation one — *"You raised this order. It needs a
     * different hand to cancel it."* — so neither is composed here.
     *
     * @var array<string, string>
     */
    private const MESSAGES = [
        'purchase_order.approve' => 'You raised this order. It needs a different approver.',
        'purchase_order.cancel' => 'You raised this order. It needs a different hand to cancel it.',
    ];

    /**
     * DSoD-1's three clauses, in the order that makes the cheapest case cheapest
     * without changing the answer.
     *
     * 1. **NULL creator ⇒ allow.** No query. R-6: there is nobody for the actor
     *    to be.
     * 2. **creator ≠ caller ⇒ allow.** No query. This is the ordinary path and
     *    the two-person case DSoD-1 exists to permit.
     * 3. **creator = caller ⇒ refuse, unless the caller is the owner** —
     *    `OwnerPredicate::holdsMarker()` over the request's own permission set.
     *    Pure; since C6a no query runs on any path through this method.
     *
     * The creator arrives as an **id**, read by the caller's own statement rather
     * than through `PurchaseOrder::getCreatedBy()`. Two reasons, and the second
     * is the one that matters: the association would lazy-load an `AppUser` — a
     * `UserManagement` **entity**, which is a boundary `deptrac.yaml` does not
     * admit into `PurchasingApplication` — and the comparison is between two
     * **ids**, so hydrating a principal to read its id is work with a
     * cross-module dependency attached.
     */
    public function refuseWhenTheActorIsTheCreator(
        ?string $creator,
        Identity $caller,
        string $permissionCode,
    ): void {
        $suffix = self::RULE_SUFFIXES[$permissionCode] ?? throw new \LogicException(\sprintf(
            'DSoD-1 is enforced on the two codes of SoD-1\'s controlled half and "%s" is neither. '
            .'ADR-008 Amendment 1 A1.1 is the authority; extending it is an amendment, not a call site.',
            $permissionCode,
        ));

        // Clause 1. Written as its own branch rather than folded into the
        // comparison below, because `null !== $creator` reading as "allow" by
        // accident is exactly the clause E-19 names as a violation when it goes
        // the other way.
        if (null === $creator) {
            return;
        }

        // Clause 2.
        if ($creator !== $caller->userId) {
            return;
        }

        // Clause 3. The owner MARKER, over the set E-1 enforced for this request -- never a role's name.
        if (OwnerPredicate::holdsMarker($caller->permissionCodes)) {
            return;
        }

        throw ApiProblem::businessRuleViolated(
            // `<table>.<rule>`, the shape `IllegalTransition::rule()` derives and
            // `inventory_item.is_archived` follows.
            'purchase_order.'.$suffix,
            self::MESSAGES[$permissionCode],
        );
    }
}
