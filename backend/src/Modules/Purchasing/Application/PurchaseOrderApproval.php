<?php

declare(strict_types=1);

namespace App\Modules\Purchasing\Application;

use App\Modules\AuditLog\Infrastructure\PurchaseOrderAuditWriter;
use App\Modules\Purchasing\ApiResource\ApprovePurchaseOrderInput;
use App\Modules\Purchasing\ApiResource\PurchaseOrderResource;
use App\Modules\Purchasing\Infrastructure\Entity\PurchaseOrder;
use App\Platform\Http\Problem\ApiProblem;
use App\Platform\Http\Rfc3339Timestamp;
use App\Platform\Security\RequestIdentity;
use App\Platform\Tenancy\CurrentTenant;
use App\Platform\Tenancy\TenantContext;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * **`ApprovePurchaseOrder` — E-19 / DSoD-1, and the sequence §8.1 fixes.**
 *
 * ===========================================================================
 * The order of the seven steps, and why each is where it is
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
 *   5. **line guard** — refuse when the order has no live line
 *      (`purchase_order.has_a_live_line`; step 6a, product-owner ruling
 *      2026-09-11).
 *   6. **warehouse guard** — refuse when the order's warehouse is archived
 *      (`purchase_order.warehouse_is_live`; five-calls follow-up, owner ruling
 *      2026-09-13). `CreatePurchaseOrder` refuses a non-live warehouse; this is
 *      the same fact re-checked at the moment the order is committed to a
 *      supplier, so a draft whose site closed in between is not placed and then
 *      refused at the dock.
 *   7. `place()`'s state change.
 *
 * Steps 4 to 7 are one call: `PurchaseOrder::place()` opens with
 * `refuseUnlessStatusIsOneOf(__FUNCTION__, [self::STATUS_DRAFT])` (added by
 * `6c3ed6e`), which is §8.3's matrix row for this method, then refuses a live-line
 * count below 1, then a warehouse that is not live. **None of the guards is
 * re-added or restated here.** A second copy in
 * this class would be a rule with two homes, and API-15 puts the guard on the
 * entity so that any other caller of `place()` meets it too. **No other caller
 * exists today**: there is no reorder agent and no console command that places an
 * order. And for the line guard that binding is narrower than it sounds. The
 * entity checks only the number it is handed: a caller must *state* a count, and
 * a count below 1 is refused, but the entity cannot tell a true count from a
 * false one. What this class owns is therefore the **count**: the entity maps no
 * lines, so the count is read here, inside the transaction and under RLS, and
 * passed in. The warehouse guard is built the same way and for the same reason —
 * the entity takes a stated `$warehouseIsLive`, and this class reads it.
 *
 * **Named, not built: the race with a concurrent warehouse archive.** The order
 * row is locked, so its `warehouse_id` cannot change under the read; the
 * WAREHOUSE row is not locked, so an archive committing after that read and
 * before this transaction commits still ends with an ordered order against a
 * closed site. No operation archives a warehouse today, so the race has no
 * second party. Ordering it — the archive locking the warehouse row, or this
 * read taking `FOR SHARE` — belongs to API-O-34 with `DeleteWarehouse`, which
 * must refuse to archive a site with open orders in the first place.
 *
 * **The race with every other write to this order: CLOSED by step 6c.** Until
 * then nothing in `src/` took a row lock and `PurchaseOrder` has no
 * `#[ORM\Version]`, so under READ COMMITTED whichever `UPDATE` on
 * `purchase_order` came second waited for the first to commit and then went
 * ahead without re-reading the status or the lines. An approval could place an
 * order whose last line had just been archived; a line could be added to or
 * repriced on an order just placed (next to a DSoD-1 bypass); and cancel and
 * approve could overwrite each other's status.
 *
 * Now this class, `PurchaseOrderCancellation` and every write in
 * `PurchaseOrderWriter` take `PurchaseOrderRowLock` as the **first statement**
 * of their transaction, before anything they check is read. A concurrent
 * write either committed before the lock was granted, and every read below
 * sees it, or it waits for this transaction. `PurchaseOrderRowLockTest` races
 * each shape with a second connection. A version column was the other option
 * and was not taken: it turns the loser into a retry the client must make,
 * where the lock makes the loser wait and then give the answer that is true.
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
 * The predicate is NOT here, and as of 2026-09-11 that is the point
 * ===========================================================================
 * It is `PurchaseOrderDutySeparation`'s, called below with
 * `purchase_order.approve`. Read that class for the three clauses, the owner
 * exemption (the owner MARKER, `OwnerPredicate`, since sales step 6 C6a), the NULL ruling and the `business-rule-violated` rendering — all
 * four are `ADR-008` §2 and its Amendment 1, and none of them is restated here.
 *
 * **The extraction was anticipated by the code it came out of.** This class held
 * `refuseSelfApproval(..., string $permissionCode)` with one argument and a
 * docblock saying the parameter existed *"so the cancellation half is a second
 * caller rather than a second copy"*. `CancelPurchaseOrder` is that second
 * caller, and the predicate moved to a class named after the rule rather than
 * after one of its two users. Nothing about the approval path's behaviour
 * changed in the move.
 *
 * ===========================================================================
 * What is deliberately NOT here
 * ===========================================================================
 * **The `.cancel` half of E-19**, which is `PurchaseOrderCancellation`'s: a
 * different row load, a different transition method and a different audit event,
 * sharing exactly one thing with this class — the predicate both call.
 *
 * **The `created_by_user_id` write**, which is `PurchaseOrderWriter::create()`'s.
 * §8.1's last clause — *"`CreatePurchaseOrder` sets it server-side from the JWT
 * subject on every user-initiated create"* — is now built, so the NULL branch
 * this class relies on is no longer the branch every HTTP-created order takes.
 * E-19 names the consequence of getting that write wrong precisely: *"a single
 * missing write disarms two checks rather than one"*, and
 * `PurchaseOrderApprovalTest::everyHttpCreatedOrderRecordsItsCreator` is §8.1's
 * fifth check, which could not be written while the operation did not exist.
 */
final readonly class PurchaseOrderApproval
{
    public function __construct(
        private TenantContext $tenants,
        private CurrentTenant $tenant,
        private RequestIdentity $identities,
        private PurchaseOrderDutySeparation $dutySeparation,
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
                // Step 6c: the order's row lock, before anything below is read.
                // A concurrent write to this order either committed before
                // this, and every read below sees it, or waits for this
                // transaction. See `PurchaseOrderRowLock`.
                PurchaseOrderRowLock::holdInside($em, $id);

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

                // ---- step 3: DSoD-1, on the `.approve` code.
                $this->dutySeparation->refuseWhenTheActorIsTheCreator(
                    null === $row['created_by_user_id'] ? null : (string) $row['created_by_user_id'],
                    $identity,
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

                // Step 6c follow-up: `find()` answers from the identity map,
                // without a query, when the entity is already managed, and then
                // `place()` would check a status read BEFORE the lock. Refreshing
                // re-reads the row under the lock whoever loaded it and when.
                // Refresh rather than asserting "not managed": the assertion
                // would refuse a legitimate caller (a long-lived worker, or this
                // project's HTTP test rig, which keeps one EntityManager across
                // requests), where the refresh is simply correct. Its cost is
                // one primary-key SELECT.
                $em->refresh($order);

                // The count step 5 decides on: live lines only, read in this
                // transaction under RLS. A draft whose lines were all archived
                // is as empty as one that never had any.
                $liveLineCount = (int) $em->getConnection()->fetchOne(
                    'SELECT count(*) FROM purchase_order_line WHERE purchase_order_id = ?::uuid AND deleted_at IS NULL',
                    [$id],
                );

                // The fact step 6 decides on: whether this order's warehouse is
                // still live, read in this transaction under RLS. By statement,
                // not through `$order->getWarehouse()` — `SoftDeleteFilter`
                // hides an archived warehouse from the ORM, and a warehouse
                // already in the identity map is not re-read by the refresh
                // above. The order row is locked, so its `warehouse_id` cannot
                // move under this read; the warehouse row is NOT locked — see
                // the class docblock's race note (API-O-34's to order).
                $warehouseIsLive = false !== $em->getConnection()->fetchOne(
                    'SELECT 1 FROM warehouse w JOIN purchase_order po ON po.warehouse_id = w.id '
                    .'WHERE po.id = ?::uuid AND w.deleted_at IS NULL',
                    [$id],
                );

                // The fact step 6b decides on (C5 C1, owner Q-S2): whether this
                // order's supplier is still live — by statement, for the
                // warehouse read's reason. Not locked: `DeleteSupplier` refuses
                // a supplier with an open order (a draft is open), so an
                // archived supplier under a draft is reachable only by owner
                // SQL or a lock regression; this is the backstop.
                $supplierIsLive = false !== $em->getConnection()->fetchOne(
                    'SELECT 1 FROM supplier s JOIN purchase_order po ON po.supplier_id = s.id '
                    .'WHERE po.id = ?::uuid AND s.deleted_at IS NULL',
                    [$id],
                );

                // ---- steps 4 to 7: all four guards live inside `place()`
                // (the status guard is §8.3's matrix row), and their
                // `IllegalTransition` is turned into §5.2's
                // `business-rule-violated` by `FrameworkProblemTranslator`.
                //
                // `place()` assigns `expectedAt` unconditionally, so an approval
                // that named no date would erase the one F-2 step 5 recorded.
                // The absent case passes the order's current value straight back.
                $order->place(
                    new \DateTimeImmutable(),
                    $liveLineCount,
                    $warehouseIsLive,
                    $supplierIsLive,
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
     * `Rfc3339Timestamp`'s, the one parser the create and update paths and the
     * request digest already share. Until step 6c this was a third copy
     * without its warning check, so `2026-02-30` was placed as 2 March, and
     * `T24:00` and `:60` rolled to the next instant, all as a 200.
     */
    private static function parseTimestamp(string $value): ?\DateTimeImmutable
    {
        return Rfc3339Timestamp::parse($value);
    }
}
