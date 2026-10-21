<?php

declare(strict_types=1);

namespace App\Modules\Sales\Application;

use App\Modules\AuditLog\Infrastructure\SalesOrderAuditWriter;
use App\Modules\Sales\ApiResource\SalesOrderResource;
use App\Modules\Sales\Infrastructure\Entity\SalesOrder;
use App\Platform\Http\Problem\ApiProblem;
use App\Platform\Security\RequestIdentity;
use App\Platform\Tenancy\CurrentTenant;
use App\Platform\Tenancy\TenantContext;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * **`ConfirmSalesOrder` — F-5 step 3, and the first write in `src/` ever to change a sales order that
 * already exists.**
 *
 * ===========================================================================
 * The order of the steps, and why each is where it is
 * ===========================================================================
 *   1. `sales_order.confirm` checked (E-1) — **before the row is loaded**, and outside this class
 *      entirely: `PermissionGate` is a `kernel.controller` listener, and the code that loads the row
 *      has not run when it fires. Nothing here can reorder it.
 *   2. **the row lock** (`SalesOrderRowLock`), the first statement of the transaction, before
 *      anything this method checks is read. W-5: Sales locks the order, then stock.
 *   3. row read under RLS, inside the transaction that will write it — archived ⇒
 *      `sales_order.is_archived`, absent ⇒ `not-found`.
 *   4. **status guard** — `draft` only (§8.3's matrix row, on the entity).
 *   5. **line guard** — refuse when the order has no live line
 *      (`sales_order.has_a_live_line`; V2-R-22 Half 1, which is D-C copied to the selling side).
 *   5b. **customer guard** — refuse when the order's customer is archived
 *      (`sales_order.customer_is_live`; C5 C3, owner ruling Q-2 of 2026-10-13, the sales twin of
 *      `API.md` §8.1 step 6b). Read here by statement, after the row lock, unlocked: it is the
 *      backstop, not the control — `CreateSalesOrder`/`UpdateSalesOrder` read the customer
 *      `FOR KEY SHARE` and `DeleteCustomer` (C5 C4b) refuses a customer with an open order, so an archived
 *      customer under a draft is reachable only by owner SQL or a lock regression — not by
 *      `RestoreSalesOrder` (order restores C2), which refuses to restore a draft onto an archived customer
 *      or warehouse, reading both `FOR KEY SHARE`. After the line guard, so an empty draft is still told to add a line.
 *   6. `confirm()`'s state change.
 *
 * Steps 4 to 6 are one call: `SalesOrder::confirm()` opens with its status guard and then refuses a
 * live-line count below 1. **Neither guard is re-added or restated here.** A second copy would be a
 * rule with two homes, and API-15 puts the guard on the entity so that any other caller meets it too.
 * For the line guard that binding is narrower than it sounds: the entity checks only the number it is
 * handed, so what this class owns is the **count** — the entity maps no lines, so the count is read
 * here, under the lock, inside the transaction and under RLS.
 *
 * ===========================================================================
 * There is NO DSoD rule on this path, and saying so is the point
 * ===========================================================================
 * The purchase mirror runs `PurchaseOrderDutySeparation` between the load and the status guard. **This
 * class must not.** ADR-008 is purchase-only throughout; `SEC-O-9` verified that
 * `sales_order.created_by_user_id` records *"the creator, not the confirmer"*, so a self-confirmation
 * rule could not be expressed on the columns that exist; and `IA.md` IA-O-8 states as a tested claim
 * that F-5 *"has no mirror of this gap — `sales_rep` holds create, edit and confirm … there is no
 * seam"*. Copying the purchase sequence wholesale would have shipped a control three artefacts deny.
 *
 * ===========================================================================
 * The audit event — B-4a, minted 2026-09-29 (roles slice 2, C1)
 * ===========================================================================
 *   7. `sales_order.confirmed`, one row naming the confirmer, in this transaction
 *      (`SalesOrderAuditWriter::recordConfirmed()`; `SEC` filed it business, `{}`, no IP). ⚠ Until then this
 *      section said *"No audit event, deferred by a rule"* — `API.md` §11's B-4a owned the deferral, and *"who
 *      confirmed this order"* was answerable from `ordered_at` alone. It is now answerable from the trail;
 *      `sales_order.created_by_user_id` still records the creator, not the confirmer (SEC-O-9).
 *
 * ===========================================================================
 * No reservation is written, and that is model A, ruled
 * ===========================================================================
 * V2-R-22 Half 2: `ConfirmSalesOrder` writes `status`, `ordered_at` and `updated_at` and nothing else.
 * `stock_level.qty_reserved` stays structurally zero; overselling is reachable since `CreateFulfilment`
 * (2026-09-22) and accepted on the premise renewed by owner rulings R1 (2026-09-22) and Q1 (2026-10-14,
 * stock visible per bin as on-hand only). The declaration lives in
 * `SchemaManifest`'s `stock_level` entry and the work is carried on event triggers as
 * `docs/RESIDUAL_GAPS.md` **G-7**, whose first trigger is `CreateFulfilment` — the operation that makes
 * the failure reachable. `WriteOwnershipTest::noWriteStatementUnderSrcNamesQtyReserved` is the control
 * that fires; the tripwire the design proposed does not, and that was measured (probe U-2).
 *
 * ===========================================================================
 * Where a constraint failure is caught
 * ===========================================================================
 * Nowhere: this method issues no statement a constraint can refuse that it has not already checked.
 * `SalesOrderWriter`'s rule — catch outside `TenantContext::run()`, never inside it — is unchanged and
 * simply has no subject here.
 */
final readonly class SalesOrderConfirmation
{
    public function __construct(
        private TenantContext $tenants,
        private CurrentTenant $tenant,
        private RequestIdentity $identities,
        private SalesOrderAuditWriter $audit,
    ) {
    }

    public function confirm(string $id): SalesOrderResource
    {
        if (!Uuid::isValid($id)) {
            // §5.3 clause 2: an id that is not a uuid names no row in any tenant, and its answer must
            // be the one another tenant's id gets.
            throw ApiProblem::notFound();
        }

        $identity = $this->identities->authenticated();

        return $this->tenants->run(
            $this->tenant->tenantId(),
            function (EntityManagerInterface $em) use ($id, $identity): SalesOrderResource {
                // Step 2: the order's row lock, before anything below is read. A concurrent write to
                // this order either committed before this, and every read below sees it, or waits for
                // this transaction.
                SalesOrderRowLock::holdInside($em, $id);

                // Step 3, read with DBAL rather than `$em->find()`, and that is §5.2a rather than a
                // preference: `SoftDeleteFilter` puts `deleted_at IS NULL` on every ORM query against
                // this table, so `find()` answers **null** for an archived order — and §5.2a requires a
                // direct hit on an archived row inside the caller's own tenant to be *read*, so the
                // transition can be refused with `business-rule-violated` instead of the 404 that says
                // the order is not there.
                $row = $em->getConnection()->fetchAssociative(
                    'SELECT deleted_at FROM sales_order WHERE id = ?::uuid',
                    [$id],
                );

                if (false === $row) {
                    throw ApiProblem::notFound();
                }

                if (null !== $row['deleted_at']) {
                    throw ApiProblem::businessRuleViolated(
                        'sales_order.is_archived',
                        'This sales order is archived, so it can\'t be confirmed.',
                    );
                }

                // The live entity, for `confirm()` alone. §8.2 puts the status guard on the entity and
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
                // managed, and then `confirm()` would check a status read BEFORE the lock. Refreshing
                // re-reads the row under the lock whoever loaded it and when. Its cost is one
                // primary-key SELECT, and this project's HTTP test rig keeps one EntityManager across
                // requests, so the case is real rather than theoretical.
                $em->refresh($order);

                // The count step 5 decides on: live lines only, read in this transaction under RLS. A
                // draft whose lines were all archived is as empty as one that never had any.
                $liveLineCount = (int) $em->getConnection()->fetchOne(
                    'SELECT count(*) FROM sales_order_line WHERE sales_order_id = ?::uuid AND deleted_at IS NULL',
                    [$id],
                );

                // The fact step 5b decides on (C5 C3, owner Q-2): whether this order's customer is still
                // live — by statement, for step 3's reason (`SoftDeleteFilter` would hide an archived
                // customer behind the ORM association). Not locked: it is the backstop (see step 5b).
                $customerIsLive = false !== $em->getConnection()->fetchOne(
                    'SELECT 1 FROM customer c JOIN sales_order so ON so.customer_id = c.id '
                    .'WHERE so.id = ?::uuid AND c.deleted_at IS NULL',
                    [$id],
                );

                // Steps 4 to 6: all three guards live inside `confirm()`, and their `IllegalTransition` is
                // turned into §5.2's `business-rule-violated` by `FrameworkProblemTranslator`.
                $order->confirm(new \DateTimeImmutable(), $liveLineCount, $customerIsLive);

                $em->flush();

                // Step 7 (B-4a, roles slice 2 C1): the row naming the confirmer. Joins this transaction
                // (`TenantContext::run()` is re-entrant for the same tenant), so the confirmation and the row that
                // says who made it commit together or not at all. A repeat is refused by the status guard above, so
                // its rollback writes nothing.
                $this->audit->recordConfirmed(
                    tenantId: $identity->tenantId,
                    actorUserId: $identity->userId,
                    actorLabel: $identity->actorLabel,
                    salesOrderId: $id,
                );

                // API-21 branch 5: the resource in its **new** state, read back from the database so
                // the response carries what was actually written — `status` and `ordered_at` in
                // particular. `SalesOrderRow` builds it, so the confirm's body and `GetSalesOrder`'s
                // are the same shape by construction.
                $confirmed = $em->getConnection()->fetchAssociative(
                    'SELECT '.SalesOrderRow::COLUMNS.' '.SalesOrderRow::FROM.' WHERE so.id = ?::uuid',
                    [$id],
                );

                return false === $confirmed
                    ? throw new \LogicException('The order just confirmed is not visible to its own transaction.')
                    : SalesOrderRow::toResource($confirmed);
            },
        );
    }
}
