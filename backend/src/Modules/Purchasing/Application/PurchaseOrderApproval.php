<?php

declare(strict_types=1);

namespace App\Modules\Purchasing\Application;

use App\Modules\AuditLog\Infrastructure\PurchaseOrderAuditWriter;
use App\Modules\Purchasing\ApiResource\ApprovePurchaseOrderInput;
use App\Modules\Purchasing\ApiResource\PurchaseOrderResource;
use App\Modules\Purchasing\Infrastructure\Entity\PurchaseOrder;
use App\Platform\Http\Problem\ApiProblem;
use App\Platform\Security\RequestIdentity;
use App\Platform\Tenancy\CurrentTenant;
use App\Platform\Tenancy\TenantContext;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * **`ApprovePurchaseOrder` — E-19 / DSoD-1, and the sequence §8.1 fixes.**
 *
 * ===========================================================================
 * The order of the five steps, and why each is where it is
 * ===========================================================================
 * §8.1: *"The order on `ApprovePurchaseOrder` is fixed and is **not** negotiable
 * at implementation."*
 *
 *   1. `purchase_order.approve` checked (E-1) — **before the row is loaded**.
 *      That happens outside this class entirely: `PermissionGate` is a
 *      `kernel.controller` listener at priority 24, and the code that loads the
 *      row has not run when it fires. Nothing here can reorder it.
 *   2. row loaded under RLS — not visible ⇒ `not-found`.
 *   3. **DSoD-1** — refuse when `purchase_order.created_by_user_id` equals the
 *      caller's `app_user.id`.
 *   4. **status guard** — refuse unless `status = 'draft'`.
 *   5. `place()`.
 *
 * Steps 4 and 5 are one call: `PurchaseOrder::place()` opens with
 * `refuseUnlessStatusIsOneOf(__FUNCTION__, [self::STATUS_DRAFT])`
 * (`PurchaseOrder.php:190`, added by `6c3ed6e`), which is §8.3's matrix row for
 * this method. **That guard is not re-added or restated here.** A second copy in
 * this class would be a rule with two homes, and API-15 requires the guard to sit
 * on the entity precisely so that the reorder agent and a console command are
 * bound by it too.
 *
 * ===========================================================================
 * Why DSoD-1 runs AFTER the load, and why hoisting it is a named violation
 * ===========================================================================
 * §8.1: *"DSoD-1 runs after the load and that does not contradict E-1: E-1 orders
 * the **permission** check before the load, and DSoD-1 is not a permission
 * check — the caller holds `.approve` — it is a rule about *this row*, which is
 * E-2's second case. **Hoisting DSoD-1 ahead of the permission check to "fail
 * fast" is a named violation**: it reads the row, so hoisting rebuilds E-1's
 * intra-tenant existence oracle for a caller holding no `.approve` at all."*
 *
 * ===========================================================================
 * The predicate, exactly
 * ===========================================================================
 * `ADR-008` §2 and its **Amendment 1** (2026-09-10), cited through
 * `SECURITY_MODEL.md` E-19 rather than restated as a second copy:
 *
 *   - **refuse when `created_by_user_id` = the caller's `app_user.id`**;
 *   - **`owner` is exempt by name.** By *name*, not by permission set: on a
 *     one-person demo tenant `owner` raises the order and is the only seat that
 *     can place it. `SECURITY_MODEL.md` §4 seeds the eight default roles per
 *     tenant and `role.name` is what carries the seat, so the exemption is a
 *     lookup on `role.name` and not on a code — a code test would exempt
 *     `purchasing_manager` too, which A1.1 explicitly refuses:
 *     *"`purchasing_manager` is not exempt."*
 *   - **a NULL creator does NOT refuse.** *"A reorder-agent order was raised by
 *     no person, so there is nobody for the canceller to be, and the rule returns
 *     true."* This is the rule returning true, not an exemption — F-4's orders
 *     stay approvable by any `.approve` holder, and a refusal on NULL would make
 *     seeded data unapprovable.
 *   - **the refusal renders as `business-rule-violated`, not 403 and not 404.**
 *     §5.3 clause 4 says it in terms, and ST-3/ST-4e is its screen. The caller
 *     holds `.approve` and is looking at the row, so it is a rule about this row.
 *     `ApiProblem::businessRuleViolated()` is a 409 and carries `rule` and
 *     `message`; the message is `STATES.md` ST-4e's own words.
 *
 * ===========================================================================
 * What is deliberately NOT here
 * ===========================================================================
 * **The `.cancel` half of E-19.** Amendment 1 extends DSoD-1 to
 * `purchase_order.cancel` with the same predicate and the same three clauses, and
 * `CancelPurchaseOrder` is the next package: `PurchaseOrder` has no `cancel()`
 * method, X9-1 and §8.3's reachability clause are what find that, and adding one
 * here would turn clause (b) green with the operation still unbuilt.
 * `refuseSelfApproval()` is written so the cancellation half is a second caller
 * rather than a second copy — see the `$permissionCode` parameter, which exists
 * only to make that true and today has exactly one argument.
 *
 * **The `created_by_user_id` write.** §8.1's last clause — *"`CreatePurchaseOrder`
 * sets it server-side from the JWT subject on every user-initiated create"* — is
 * `CreatePurchaseOrder`'s, and that operation is blocked on §6.2. E-19 names the
 * consequence precisely: *"a single missing write disarms two checks rather than
 * one"*. Until it ships, every order over HTTP is seeded, and this class's NULL
 * branch is the one all seeded data takes.
 */
final readonly class PurchaseOrderApproval
{
    /**
     * The seat DSoD-1 exempts, as `role.name` holds it.
     *
     * A literal, and it is one on purpose: `SECURITY_MODEL.md` §4's role table is
     * a document, `role` is a tenant-writable table, and there is no constant in
     * this codebase that names the eight defaults. Writing it here — once, beside
     * the argument for it — is better than deriving it from a permission set,
     * which is the derivation A1.1 refuses. `PurchaseOrderApprovalTest` pins the
     * same string independently, so the exemption cannot be renamed on one side.
     */
    public const OWNER_ROLE_NAME = 'owner';

    public function __construct(
        private TenantContext $tenants,
        private CurrentTenant $tenant,
        private RequestIdentity $identities,
        private PurchaseOrderAuditWriter $audit,
    ) {
    }

    public function approve(string $id, ApprovePurchaseOrderInput $input): PurchaseOrderResource
    {
        // Shape first, because it needs no database and a malformed timestamp
        // should not open a transaction.
        $submitted = $input->submitted();
        $expectedAtSubmitted = \array_key_exists('expectedAt', $submitted);
        $expectedAt = null;

        if ($expectedAtSubmitted && null !== $submitted['expectedAt']) {
            $expectedAt = self::parseTimestamp((string) $submitted['expectedAt'])
                ?? throw ApiProblem::validationFailed([[
                    'pointer' => '/expected_at',
                    'code' => 'not_a_timestamp',
                    'message' => 'This field must be an RFC 3339 timestamp.',
                ]]);
        }

        if (!Uuid::isValid($id)) {
            // §5.3 clause 2: an id that is not a uuid names no row in any
            // tenant, and its answer must be the one another tenant's id gets.
            throw ApiProblem::notFound();
        }

        $identity = $this->identities->authenticated();

        return $this->tenants->run(
            $this->tenant->tenantId(),
            function (EntityManagerInterface $em) use ($id, $identity, $expectedAtSubmitted, $expectedAt): PurchaseOrderResource {
                // ---- step 2: the row, under RLS, inside the transaction that
                // will write it. It is loaded here rather than by a provider
                // because steps 2 to 5 are one atomic sequence: a row read
                // before the transaction opened is a row whose status and
                // creator may both have moved by the time `place()` runs.
                //
                // **Read with DBAL rather than with `$em->find()`, and that is
                // §5.2a rather than a preference.** `SoftDeleteFilter` puts
                // `deleted_at IS NULL` on every ORM query against this table, so
                // `find()` answers **null** for an archived order — and §5.2a
                // requires a direct hit on an archived row inside the caller's
                // own tenant to be *read*, so the transition can be refused with
                // `business-rule-violated` instead of the 404 that says the
                // order is not there. Measured: with `find()` first, the archived
                // case answered 404 and `PurchaseOrderApprovalTest::
                // anArchivedOrderCannotBeApproved` was red.
                //
                // The same statement carries the creator DSoD-1 compares and the
                // `expected_at` `place()` would otherwise erase, so the row this
                // sequence decides on is read exactly once.
                $row = $em->getConnection()->fetchAssociative(
                    'SELECT deleted_at, created_by_user_id, expected_at FROM purchase_order WHERE id = ?::uuid',
                    [$id],
                );

                if (false === $row) {
                    throw ApiProblem::notFound();
                }

                // ST-9 / §5.2a: *"a PATCH, DELETE or transition on a
                // soft-deleted row succeeds"* is a stated failure. An archived
                // order is read-only, and the refusal is the business-rule
                // branch because the caller can see the row.
                if (null !== $row['deleted_at']) {
                    throw ApiProblem::businessRuleViolated(
                        'purchase_order.is_archived',
                        'This purchase order is archived. Restore it before changing it.',
                    );
                }

                // ---- step 3: DSoD-1.
                $this->refuseSelfApproval(
                    $em,
                    null === $row['created_by_user_id'] ? null : (string) $row['created_by_user_id'],
                    $identity->userId,
                    'purchase_order.approve',
                );

                // The live entity, for `place()` alone. §8.2 puts the status
                // guard on the entity and API-15 requires it to stay there, so
                // the transition is made by the domain object even though every
                // other read in this method is a statement.
                $order = $em->find(PurchaseOrder::class, Uuid::fromString($id));

                if (!$order instanceof PurchaseOrder) {
                    throw new \LogicException(
                        'A live purchase_order row was read by statement and is invisible to the ORM in the '
                        .'same transaction. The only predicate that differs is SoftDeleteFilter\'s, and the '
                        .'row was just checked for it.'
                    );
                }

                // ---- steps 4 and 5: the status guard lives inside `place()`
                // (§8.3's matrix row), and its `IllegalTransition` is turned into
                // §5.2's `business-rule-violated` by
                // `FrameworkProblemTranslator`.
                //
                // `place()` assigns `expectedAt` unconditionally, so an approval
                // that named no date would erase the one F-2 step 5 recorded.
                // The absent case passes the order's current value straight back.
                $order->place(
                    new \DateTimeImmutable(),
                    $expectedAtSubmitted
                        ? $expectedAt
                        : (null === $row['expected_at'] ? null : new \DateTimeImmutable((string) $row['expected_at'])),
                );

                $em->flush();

                // F-2 step 8, and it joins this transaction rather than opening
                // one: `TenantContext::run()` is re-entrant for the same tenant,
                // so the status change and the row that names the approver
                // commit together or not at all.
                $this->audit->recordPlaced(
                    tenantId: $identity->tenantId,
                    actorUserId: $identity->userId,
                    actorLabel: $identity->actorLabel,
                    purchaseOrderId: $id,
                );

                // API-21 branch 5: the resource in its **new** state, read back
                // from the database so the response carries what was actually
                // written -- `ordered_at` in particular is now non-null and
                // `purchase_order_ordered_at_set` is what made that mandatory.
                $row = $em->getConnection()->fetchAssociative(
                    'SELECT '.PurchaseOrderRow::COLUMNS.' '.PurchaseOrderRow::FROM.' WHERE po.id = ?::uuid',
                    [$id],
                );

                return false === $row
                    ? throw new \LogicException('The order just placed is not visible to its own transaction.')
                    : PurchaseOrderRow::toResource($row);
            },
        );
    }

    /**
     * DSoD-1's three clauses, in the order that makes the cheapest case cheapest
     * without changing the answer.
     *
     * 1. **NULL creator ⇒ allow.** No query. R-6: there is nobody for the
     *    approver to be.
     * 2. **creator ≠ caller ⇒ allow.** No query. This is the ordinary path and
     *    the two-person case DSoD-1 exists to permit.
     * 3. **creator = caller ⇒ refuse, unless the caller holds `owner`.** The
     *    role lookup runs only here, which is why it costs nothing on the path
     *    every other approval takes.
     *
     * The creator arrives as an **id**, read by the statement in `approve()`
     * rather than through `PurchaseOrder::getCreatedBy()`. Two reasons, and the
     * second is the one that matters: the association would lazy-load an
     * `AppUser` — a `UserManagement` **entity**, which is a boundary
     * `deptrac.yaml` does not admit into `PurchasingApplication` — and the
     * comparison is between two **ids**, so hydrating a principal to read its id
     * is work with a cross-module dependency attached.
     *
     * `$permissionCode` is not used in the predicate and is not decoration: E-19
     * enforces DSoD-1 on *both* codes of SoD-1's controlled half, and Amendment 1
     * makes `purchase_order.cancel` the second caller. Carrying the code makes
     * the cancellation half a call rather than a copy, which is the whole of what
     * E-19 says went wrong when *"the static half moved and the dynamic half did
     * not"*. It is surfaced in the refusal's `rule` member for the same reason.
     */
    private function refuseSelfApproval(EntityManagerInterface $em, ?string $creator, string $callerUserId, string $permissionCode): void
    {
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
            // `inventory_item.is_archived` follows. The code the rule is enforced
            // on is part of the identifier because E-19 enforces the same
            // predicate on two of them, and a client that saw one identifier for
            // both could not tell an approval refusal from a cancellation one.
            'purchase_order.'.self::ruleSuffix($permissionCode),
            // ST-4e's own words. `ADR-008` §2 prints the same sentence.
            'You raised this order. It needs a different approver.',
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

    /** `purchase_order.approve` => `approver_is_not_the_creator`. */
    private static function ruleSuffix(string $permissionCode): string
    {
        return match ($permissionCode) {
            'purchase_order.approve' => 'approver_is_not_the_creator',
            'purchase_order.cancel' => 'canceller_is_not_the_creator',
            default => throw new \LogicException(\sprintf(
                'DSoD-1 is enforced on the two codes of SoD-1\'s controlled half and "%s" is neither. '
                .'ADR-008 Amendment 1 A1.1 is the authority; extending it is an amendment, not a call site.',
                $permissionCode,
            )),
        };
    }

    private static function parseTimestamp(string $value): ?\DateTimeImmutable
    {
        foreach ([\DateTimeInterface::RFC3339_EXTENDED, \DateTimeInterface::RFC3339] as $format) {
            $parsed = \DateTimeImmutable::createFromFormat($format, $value);
            if (false !== $parsed) {
                return $parsed;
            }
        }

        return null;
    }
}
