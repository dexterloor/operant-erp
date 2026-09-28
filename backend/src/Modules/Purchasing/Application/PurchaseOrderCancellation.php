<?php

declare(strict_types=1);

namespace App\Modules\Purchasing\Application;

use App\Modules\AuditLog\Infrastructure\PurchaseOrderAuditWriter;
use App\Modules\Purchasing\ApiResource\CancelPurchaseOrderInput;
use App\Modules\Purchasing\ApiResource\PurchaseOrderResource;
use App\Modules\Purchasing\Infrastructure\Entity\PurchaseOrder;
use App\Platform\Http\Problem\ApiProblem;
use App\Platform\Security\RequestIdentity;
use App\Platform\Tenancy\CurrentTenant;
use App\Platform\Tenancy\TenantContext;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * **`CancelPurchaseOrder` — E-19's second half, and the operation X9-1 has been
 * naming since 2026-09-10.**
 *
 * ===========================================================================
 * The order of the four steps, and why it is `ApprovePurchaseOrder`'s
 * ===========================================================================
 * `SECURITY_MODEL.md` E-19, and `ADR-008` Amendment 1 A1.1 spells it for this
 * code specifically: *"The ordering is E-1's: `.cancel` checked → row loaded →
 * DSoD-1 → `cancel()`."*
 *
 *   1. `purchase_order.cancel` checked (E-1) — **before the row is loaded**.
 *      That happens outside this class entirely: `PermissionGate` is a
 *      `kernel.controller` listener at priority 24, and the code that loads the
 *      row has not run when it fires. Nothing here can reorder it.
 *   2. row loaded under RLS — not visible ⇒ `not-found`.
 *   3. **DSoD-1** — `PurchaseOrderDutySeparation`, on the `.cancel` code.
 *   4. `cancel()`, whose own status guard is §8.3's matrix row.
 *
 * **Hoisting DSoD-1 ahead of the permission check to "fail fast" is a named
 * violation**: it reads the row, so hoisting rebuilds E-1's intra-tenant
 * existence oracle for a caller holding no `.cancel` at all.
 *
 * ===========================================================================
 * What this class does NOT contain, and each absence is a rule
 * ===========================================================================
 * **The DSoD-1 predicate.** It is `PurchaseOrderDutySeparation`'s, called with
 * `purchase_order.cancel`. E-19's own diagnosis of what went wrong before
 * Amendment 1 was that *"the static half moved and the dynamic half did not"*,
 * so a second copy of the comparison here would reproduce that failure inside
 * one module.
 *
 * **The status guard.** `PurchaseOrder::cancel()` opens with
 * `refuseUnlessStatusIsOneOf()` over §8.3's three legal sources, and API-15
 * requires that guard to sit on the entity precisely so the reorder agent and a
 * console command are bound by it too. Its `IllegalTransition` becomes §5.2's
 * `business-rule-violated` in `FrameworkProblemTranslator`.
 *
 * **An idempotency key.** §4.2's cell is `guard:purchase_order.status` and
 * API-14 as amended demands the header for `store` and `unique:` only. A
 * replayed cancellation hits an order that is already `cancelled`, `cancel()`
 * refuses it, and the caller is told `business-rule-violated` — which is how it
 * learns the first attempt committed. Demanding a header first, and 428-ing a
 * caller who omitted one, would add a refusal that protects nothing.
 *
 * ===========================================================================
 * The audit row carries the reason, and that is this slice's charter
 * ===========================================================================
 * §4.2 accepts a `reason` on this operation and `purchase_order` has **no
 * free-text column at all**, so the operator's words land in
 * `audit_log.metadata` on `STOCK_MOVEMENT_ADJUSTED`'s precedent —
 * `AuditEvent::PURCHASE_ORDER_CANCELLED`'s docblock rules that in advance, *"so
 * the operation's author inherits it rather than deciding it"*. Dropping the
 * field would be the silent shape this project keeps finding: a documented field
 * the server accepts and discards.
 *
 * @see docs/API.md §8.1, §8.3, §9.2
 * @see docs/SECURITY_MODEL.md §5 E-19
 */
final readonly class PurchaseOrderCancellation
{
    public function __construct(
        private TenantContext $tenants,
        private CurrentTenant $tenant,
        private RequestIdentity $identities,
        private PurchaseOrderDutySeparation $dutySeparation,
        private PurchaseOrderAuditWriter $audit,
    ) {
    }

    public function cancel(string $id, CancelPurchaseOrderInput $input): PurchaseOrderResource
    {
        if (!Uuid::isValid($id)) {
            // §5.3 clause 2: an id that is not a uuid names no row in any
            // tenant, and its answer must be the one another tenant's id gets.
            throw ApiProblem::notFound();
        }

        $reason = self::reason($input);
        $identity = $this->identities->authenticated();

        return $this->tenants->run(
            $this->tenant->tenantId(),
            function (EntityManagerInterface $em) use ($id, $identity, $reason): PurchaseOrderResource {
                // Step 6c: the order's row lock, before anything below is read.
                // Doctrine's `UPDATE` carries no status predicate, so without it
                // a cancellation that read `draft` would overwrite whatever a
                // concurrent approval or cancellation committed meanwhile. See
                // `PurchaseOrderRowLock`.
                PurchaseOrderRowLock::holdInside($em, $id);

                // ---- step 2: the row, under RLS, inside the transaction that
                // will write it. Loaded here rather than by a provider because
                // steps 2 to 4 are one atomic sequence: a row read before the
                // transaction opened is a row whose status and creator may both
                // have moved by the time `cancel()` runs.
                //
                // Read with DBAL rather than `$em->find()`, and that is §5.2a
                // rather than a preference: `SoftDeleteFilter` puts
                // `deleted_at IS NULL` on every ORM query against this table, so
                // `find()` answers **null** for an archived order — and §5.2a
                // requires a direct hit on an archived row inside the caller's
                // own tenant to be *read*, so the transition can be refused with
                // `business-rule-violated` instead of a 404 saying the order is
                // not there.
                $row = $em->getConnection()->fetchAssociative(
                    'SELECT deleted_at, created_by_user_id FROM purchase_order WHERE id = ?::uuid',
                    [$id],
                );

                if (false === $row) {
                    throw ApiProblem::notFound();
                }

                // ST-9 / §5.2a: an archived order is read-only, and the refusal
                // is the business-rule branch because the caller can see the row.
                if (null !== $row['deleted_at']) {
                    throw ApiProblem::businessRuleViolated(
                        'purchase_order.is_archived',
                        'This purchase order is archived. Restore it before changing it.',
                    );
                }

                // ---- step 3: DSoD-1, on the `.cancel` code.
                $this->dutySeparation->refuseWhenTheActorIsTheCreator(
                    null === $row['created_by_user_id'] ? null : (string) $row['created_by_user_id'],
                    $identity,
                    'purchase_order.cancel',
                );

                // The live entity, for `cancel()` alone. §8.2 puts the status
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

                // Step 6c follow-up: re-read under the lock, whoever loaded the
                // entity and when. `PurchaseOrderApproval` says why this is a
                // refresh and not an assertion.
                $em->refresh($order);

                // ---- step 4. The guard is inside `cancel()` (§8.3's matrix
                // row) and its `IllegalTransition` is turned into §5.2's
                // `business-rule-violated` by `FrameworkProblemTranslator`.
                $order->cancel();

                $em->flush();

                // Joins this transaction rather than opening one:
                // `TenantContext::run()` is re-entrant for the same tenant, so
                // the status change and the row that says who made it commit
                // together or not at all.
                $this->audit->recordCancelled(
                    tenantId: $identity->tenantId,
                    actorUserId: $identity->userId,
                    actorLabel: $identity->actorLabel,
                    purchaseOrderId: $id,
                    reason: $reason,
                );

                // API-21 branch 5: the resource in its **new** state, read back
                // from the database so the response carries what was actually
                // written rather than what this method believes it wrote.
                $read = $em->getConnection()->fetchAssociative(
                    'SELECT '.PurchaseOrderRow::COLUMNS.' '.PurchaseOrderRow::FROM.' WHERE po.id = ?::uuid',
                    [$id],
                );

                return false === $read
                    ? throw new \LogicException('The order just cancelled is not visible to its own transaction.')
                    : PurchaseOrderRow::toResource($read);
            },
        );
    }

    /**
     * The operator's words, or null when none were given.
     *
     * An all-whitespace reason is **not** a reason: `metadata` is written once on
     * an append-only table no role can `UPDATE`, so a blank string stored there
     * is a permanent record that says nothing while looking like a record that
     * says something. Absent, null and blank therefore collapse to the same
     * answer, which is the one case the empty `metadata` object already means.
     */
    private static function reason(CancelPurchaseOrderInput $input): ?string
    {
        $reason = trim((string) $input->reason);

        return '' === $reason ? null : $reason;
    }
}
