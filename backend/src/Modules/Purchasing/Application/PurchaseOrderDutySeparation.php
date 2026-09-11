<?php

declare(strict_types=1);

namespace App\Modules\Purchasing\Application;

use App\Platform\Http\Problem\ApiProblem;
use Doctrine\ORM\EntityManagerInterface;

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
 * the three clauses, their order, the `owner` lookup and the rule-code mapping
 * are the same code, and what changed is that the class it lives in is no longer
 * named after one of its two callers. E-19 is explicit that the failure it is
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
 *   - **`owner` is exempt by name.** By *name*, not by permission set: on a
 *     one-person demo tenant `owner` raises the order and is the only seat that
 *     can place it or withdraw it. A1.1 refuses the permission-set reading in
 *     terms — *"`purchasing_manager` is not exempt"* — and `purchasing_manager`
 *     holds both controlled codes, so a code test would exempt it too;
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
     * The seat DSoD-1 exempts, as `role.name` holds it.
     *
     * A literal, and it is one on purpose: `SECURITY_MODEL.md` §4's role table is
     * a document, `role` is a tenant-writable table, and there is no constant in
     * this codebase that names the eight defaults. Writing it here — once, beside
     * the argument for it — is better than deriving it from a permission set,
     * which is the derivation A1.1 refuses. `PurchaseOrderApprovalTest` and
     * `PurchaseOrderCancellationTest` each pin the same string independently, so
     * the exemption cannot be renamed on one side.
     */
    public const OWNER_ROLE_NAME = 'owner';

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
     * 3. **creator = caller ⇒ refuse, unless the caller holds `owner`.** The
     *    role lookup runs only here, which is why it costs nothing on the path
     *    every other approval and cancellation takes.
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
        EntityManagerInterface $em,
        ?string $creator,
        string $callerUserId,
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
        if ($creator !== $callerUserId) {
            return;
        }

        // Clause 3 -- and only here does a query run.
        if ($this->holdsOwnerRole($em, $callerUserId)) {
            return;
        }

        throw ApiProblem::businessRuleViolated(
            // `<table>.<rule>`, the shape `IllegalTransition::rule()` derives and
            // `inventory_item.is_archived` follows.
            'purchase_order.'.$suffix,
            self::MESSAGES[$permissionCode],
        );
    }

    /**
     * The `owner` exemption, resolved against `role.name` under RLS.
     *
     * `role.deleted_at IS NULL` is the same predicate `IdentityReader` applies
     * when it resolves the caller's permission codes — a soft-deleted role grants
     * nothing, and it must not exempt anything either, or revoking `owner` would
     * leave the exemption behind.
     */
    private function holdsOwnerRole(EntityManagerInterface $em, string $userId): bool
    {
        return (bool) $em->getConnection()->fetchOne(
            <<<'SQL'
            SELECT EXISTS (
                SELECT 1
                  FROM user_role ur
                  JOIN role r ON r.tenant_id = ur.tenant_id AND r.id = ur.role_id
                 WHERE ur.user_id = ?::uuid
                   AND r.name = ?
                   AND r.deleted_at IS NULL
            )
            SQL,
            [$userId, self::OWNER_ROLE_NAME],
        );
    }
}
