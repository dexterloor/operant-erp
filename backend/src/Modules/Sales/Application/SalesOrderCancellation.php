<?php

declare(strict_types=1);

namespace App\Modules\Sales\Application;

use App\Modules\AuditLog\Infrastructure\SalesOrderAuditWriter;
use App\Modules\Sales\ApiResource\CancelSalesOrderInput;
use App\Modules\Sales\ApiResource\SalesOrderResource;
use App\Modules\Sales\Infrastructure\Entity\SalesOrder;
use App\Platform\Http\Problem\ApiProblem;
use App\Platform\Security\RequestIdentity;
use App\Platform\Tenancy\CurrentTenant;
use App\Platform\Tenancy\TenantContext;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * **`CancelSalesOrder` — F-5 step 6, and the last of API-O-17's four missing
 * transition methods to be written.**
 *
 * ===========================================================================
 * The order of the steps
 * ===========================================================================
 *   1. `sales_order.cancel` checked (E-1) — **before the row is loaded**, and
 *      outside this class entirely: `PermissionGate` is a `kernel.controller`
 *      listener, and the code that loads the row has not run when it fires.
 *      Nothing here can reorder it.
 *   2. **the row lock** (`SalesOrderRowLock`), the first statement of the
 *      transaction, before anything this method reads. W-5: Sales locks the
 *      order, then stock.
 *   3. row read under RLS, inside the transaction that will write it — archived ⇒
 *      `sales_order.is_archived`, absent ⇒ `not-found`.
 *   4. **status guard** — `draft`, `confirmed` or `partially_fulfilled` (§8.3's
 *      matrix row, on the entity).
 *   5. `cancel()`'s state change.
 *   6. **the audit row**, joining this transaction rather than opening one.
 *
 * ===========================================================================
 * ⚠ There is NO duty-separation step, and step 3 of the purchase mirror is
 * absent BY RULING rather than by omission
 * ===========================================================================
 * `PurchaseOrderCancellation` runs `PurchaseOrderDutySeparation` between the load
 * and the status guard. **This class must not, and the difference is ADR-008's own
 * decision** — §2:
 *
 * > *"The sales side is deliberately asymmetric, and the asymmetry is stated
 * > rather than left looking like an oversight. `sales_rep` holds **both**
 * > `sales_order.confirm` and `sales_order.cancel`, where the buying side splits
 * > the equivalent pair. … placing a purchase order commits the tenant's money to
 * > an outside party, while confirming a sales order commits *the customer* and
 * > moves nothing out. **There is no outbound commitment for a cancellation to
 * > repudiate.**"*
 *
 * ADR-008 **Amendment 1** — *"DSoD-1 extends to cancellation"* — is
 * **purchase-only**, and its predicate names purchase columns: *"`purchase_order.cancel`
 * is refused when `purchase_order.created_by_user_id` equals the caller's
 * `app_user.id`."* `sales_order` has no `placed_by`-equivalent column, and SEC-O-9
 * verified that `created_by_user_id` records **the creator, not the confirmer**, so
 * the comparison has no second hand to name even if the rule were wanted. IA-O-8
 * states as a tested claim that F-5 *"has no mirror of this gap — `sales_rep` holds
 * create, edit and confirm … there is no seam"*.
 *
 * **Copying the purchase sequence wholesale would have shipped a control three
 * artefacts deny**, which is the error `b1n2-ruling.md` records being caught twice
 * in one day — once inside `SECURITY_MODEL.md` itself.
 *
 * ===========================================================================
 * Which is exactly WHY step 6 exists — the audit row is the control here
 * ===========================================================================
 * ADR-008 finishes that paragraph by naming the compensation: the weaker control
 * is accepted *"and the compensating control is `audit_log`, not a second pair of
 * hands."* So this operation writes `sales_order.cancelled` **in this commit**,
 * where `ConfirmSalesOrder` deferred its event to B-4a. The owner's OI-2 named the
 * event, `SEC` had already filed it `BUSINESS` under PC-11 (4a), and §4.2 accepts a
 * `reason` that has no other destination — `sales_order` has no free-text column.
 * `SalesOrderAuditWriter`'s docblock carries the full asymmetry.
 *
 * **A cancellation that committed without its row would be the unwitnessed act
 * ADR-008 accepted only on the strength of the trail**, so the write is not caught
 * and its failure fails the request.
 *
 * ===========================================================================
 * OI-2's remainder rule needs no code, and that is what the rule says
 * ===========================================================================
 * §8.3: cancelling a `partially_fulfilled` order cancels the remainder, which is
 * *"per line, `quantity − quantity_fulfilled` … **derived and never written into
 * `quantity`**"*, and **no new status is added**. So there is no line statement to
 * issue — which is why `erp_app` holds no `DELETE` on either sales table and none
 * is asked for. The `partially_fulfilled` source is also **unreachable today**
 * (nothing writes `quantity_fulfilled`; `RESIDUAL_GAPS.md` G-7), so that half of
 * the rule is a forward declaration with `CreateFulfilment` as its hard trigger —
 * stated in `SalesOrder::cancel()`'s docblock rather than carried as prose.
 *
 * **V2-C-14(b) is the decision this commit was forced to take** and it is recorded
 * in `docs/API.md` §15.1 with a mechanism rather than a sentence:
 * `quantity_outstanding` reads **0** on a cancelled order, and
 * `OwedAmendmentTripwireTest::noOperationOwingTheCancelledRemainderDecisionHasBeenBuilt`
 * goes red the moment a line read is built without it.
 *
 * ===========================================================================
 * No idempotency key, and no `Idem` mechanism beyond the status guard
 * ===========================================================================
 * §4.2's cell is `guard:sales_order.status` and API-14 as amended demands the
 * header for `store` and `unique:` only. A replayed cancellation hits an order
 * that is already `cancelled`, `cancel()` refuses it, and the caller is told
 * `business-rule-violated` — which is how it learns the first attempt committed.
 * Demanding a header first, and 428-ing a caller who omitted one, would add a
 * refusal that protects nothing. `PurchaseOrderCancellation` reasons identically.
 *
 * ===========================================================================
 * Where a constraint failure is caught
 * ===========================================================================
 * Nowhere. The audit `INSERT` is the only statement here a constraint could
 * refuse, and every predicate on it is satisfied by construction — the event is a
 * catalogue member (`Version20260919000400`), its subject pair arrives together
 * off `SUBJECT_TYPES`, `ip_address` is structurally null on a business event, and
 * `permission_code` is not written. `SalesOrderWriter`'s rule — catch outside
 * `TenantContext::run()`, never inside it — is unchanged and has no subject here.
 *
 * @see docs/API.md §4.2, §8.3, §9.2, §15.1
 * @see docs/adr/ADR-008-separation-of-duties.md §2 and Amendment 1
 */
final readonly class SalesOrderCancellation
{
    public function __construct(
        private TenantContext $tenants,
        private CurrentTenant $tenant,
        private RequestIdentity $identities,
        private SalesOrderAuditWriter $audit,
    ) {
    }

    public function cancel(string $id, CancelSalesOrderInput $input): SalesOrderResource
    {
        if (!Uuid::isValid($id)) {
            // §5.3 clause 2: an id that is not a uuid names no row in any tenant, and its answer must
            // be the one another tenant's id gets.
            throw ApiProblem::notFound();
        }

        $reason = self::reason($input);
        $identity = $this->identities->authenticated();

        return $this->tenants->run(
            $this->tenant->tenantId(),
            function (EntityManagerInterface $em) use ($id, $identity, $reason): SalesOrderResource {
                // Step 2: the order's row lock, before anything below is read. Doctrine's `UPDATE`
                // carries no status predicate, so without it a cancellation that read `confirmed`
                // would overwrite whatever a concurrent confirmation or cancellation committed
                // meanwhile. See `SalesOrderRowLock`.
                SalesOrderRowLock::holdInside($em, $id);

                // Step 3, read with DBAL rather than `$em->find()`, and that is §5.2a rather than a
                // preference: `SoftDeleteFilter` puts `deleted_at IS NULL` on every ORM query against
                // this table, so `find()` answers **null** for an archived order — and §5.2a requires a
                // direct hit on an archived row inside the caller's own tenant to be *read*, so the
                // transition can be refused with `business-rule-violated` instead of the 404 that says
                // the order is not there.
                //
                // `created_by_user_id` is deliberately NOT selected. The purchase mirror reads it for
                // DSoD-1; there is no DSoD rule here (see the docblock), so selecting it would be a
                // column read with no consumer — and the next reader would take its presence as
                // evidence that a duty rule was intended.
                $row = $em->getConnection()->fetchAssociative(
                    'SELECT deleted_at FROM sales_order WHERE id = ?::uuid',
                    [$id],
                );

                if (false === $row) {
                    throw ApiProblem::notFound();
                }

                // ST-9 / §5.2a: an archived order is read-only, and the refusal is the business-rule
                // branch because the caller can see the row.
                if (null !== $row['deleted_at']) {
                    throw ApiProblem::businessRuleViolated(
                        'sales_order.is_archived',
                        'This sales order is archived. Restore it before changing it.',
                    );
                }

                // The live entity, for `cancel()` alone. §8.2 puts the status guard on the entity and
                // API-15 requires it to stay there, so the transition is made by the domain object even
                // though every other read in this method is a statement.
                $order = $em->find(SalesOrder::class, Uuid::fromString($id));

                if (!$order instanceof SalesOrder) {
                    throw new \LogicException(
                        'A live sales_order row was read by statement and is invisible to the ORM in the '
                        .'same transaction. The only predicate that differs is SoftDeleteFilter\'s, and the '
                        .'row was just checked for it.'
                    );
                }

                // `find()` answers from the identity map, without a query, when the entity is already
                // managed, and then `cancel()` would check a status read BEFORE the lock. Refreshing
                // re-reads the row under the lock whoever loaded it and when. `SalesOrderConfirmation`
                // says why this is a refresh and not an assertion.
                $em->refresh($order);

                // Steps 4 and 5: the guard lives inside `cancel()` (§8.3's matrix row) and its
                // `IllegalTransition` is turned into §5.2's `business-rule-violated` by
                // `FrameworkProblemTranslator`.
                $order->cancel();

                $em->flush();

                // Step 6. Joins this transaction rather than opening one: `TenantContext::run()` is
                // re-entrant for the same tenant, so the status change and the row that says who made
                // it commit together or not at all. On this operation that row is the control ADR-008
                // §2 substitutes for the duty split it declines, so it is not optional decoration.
                $this->audit->recordCancelled(
                    tenantId: $identity->tenantId,
                    actorUserId: $identity->userId,
                    actorLabel: $identity->actorLabel,
                    salesOrderId: $id,
                    reason: $reason,
                );

                // API-21 branch 5: the resource in its **new** state, read back from the database so
                // the response carries what was actually written rather than what this method believes
                // it wrote. `SalesOrderRow` builds it, so this body and `GetSalesOrder`'s are the same
                // shape by construction.
                $cancelled = $em->getConnection()->fetchAssociative(
                    'SELECT '.SalesOrderRow::COLUMNS.' '.SalesOrderRow::FROM.' WHERE so.id = ?::uuid',
                    [$id],
                );

                return false === $cancelled
                    ? throw new \LogicException('The order just cancelled is not visible to its own transaction.')
                    : SalesOrderRow::toResource($cancelled);
            },
        );
    }

    /**
     * The operator's words, or null when none were given.
     *
     * An all-whitespace reason is **not** a reason: `metadata` is written once on
     * an append-only table no role can `UPDATE`, so a blank string stored there is
     * a permanent record that says nothing while looking like a record that says
     * something. Absent, null and blank therefore collapse to the same answer,
     * which is the one case the empty `metadata` object already means.
     *
     * `PurchaseOrderCancellation::reason()` is the same three lines, and the
     * duplication is deliberate rather than extracted: the two live in different
     * modules, and a shared helper would be a `Modules\Sales` → `Modules\Purchasing`
     * edge (or a new `SharedKernel` member) bought for a `trim()`.
     */
    private static function reason(CancelSalesOrderInput $input): ?string
    {
        $reason = trim((string) $input->reason);

        return '' === $reason ? null : $reason;
    }
}
