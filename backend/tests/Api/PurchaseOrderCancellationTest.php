<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Modules\Purchasing\Application\PurchaseOrderDutySeparation;
use App\Modules\Purchasing\Infrastructure\Entity\PurchaseOrder;
use App\Platform\Domain\IllegalTransition;
use App\SharedKernel\ValueObject\AuditEvent;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Uid\Uuid;

/**
 * **`CancelPurchaseOrder` — E-19's second half, and X9-1's subject.**
 *
 * ---------------------------------------------------------------------------
 * What was owed here, and by whom
 * ---------------------------------------------------------------------------
 * `ADR-008` **Amendment 1** extended DSoD-1 to cancellation on 2026-09-10 and
 * `SECURITY_MODEL.md` E-19 was rewritten to enforce it on *both* codes of SoD-1's
 * controlled half. Nothing enforced it, because `PurchaseOrder::cancel()` did
 * not exist — E-19's own closing sentence says so: *"`PurchaseOrder` has no
 * cancellation method at all today, which is what X9-1 and §8.3's reachability
 * clause are there to find."*
 *
 * So this file is four separate obligations that happen to arrive together, and
 * each can fail without the others:
 *
 *   1. the **transition** — §8.3's matrix row, guarded on the entity (API-15);
 *   2. **DSoD-1 on `.cancel`** — the same predicate as approval, a different
 *      code, a different rule identifier;
 *   3. the **ordering** — E-1's, with the hoist it names as a violation;
 *   4. the **audit row**, carrying the `reason` §4.2 accepts and
 *      `purchase_order` has no column for.
 *
 * ---------------------------------------------------------------------------
 * The `owner` exemption is pinned as a literal here, independently
 * ---------------------------------------------------------------------------
 * Same reasoning as `PurchaseOrderApprovalTest`'s, and the copy is deliberate
 * rather than duplicated-by-accident: a test that granted
 * `PurchaseOrderDutySeparation::OWNER_ROLE_NAME` and then asserted the exemption
 * fired would compare the constant to itself.
 */
final class PurchaseOrderCancellationTest extends PurchaseOrderTestCase
{
    /** `SECURITY_MODEL.md` §4's first role, spelled independently of the code under test. */
    private const PINNED_OWNER_ROLE = 'owner';

    /** `ADR-008` A1.1's own words — and deliberately NOT the approval sentence. */
    private const PINNED_REFUSAL_MESSAGE = 'You raised this order. It needs a different hand to cancel it.';

    /** @param array<string, mixed> $body */
    private function cancel(string $orderId, array $body = []): void
    {
        $this->send('POST', '/purchase-orders/'.$orderId.'/cancellation', $body);
    }

    private function placedOrder(?string $createdBy = null, string $number = 'PO-000001'): string
    {
        return $this->seedOrder(
            self::TENANT_A,
            $number,
            $this->seedSupplier(self::TENANT_A, 'Acme '.$number),
            $this->seedWarehouse(self::TENANT_A, 'WH '.$number),
            $createdBy,
            PurchaseOrder::STATUS_ORDERED,
        );
    }

    // -----------------------------------------------------------------------
    // The transition
    // -----------------------------------------------------------------------

    /** API-21 branch 5 — 200 with the resource in its **new** state, and no `Location`. */
    #[Test]
    public function cancellingAPlacedOrderMovesItToCancelled(): void
    {
        $order = $this->placedOrder();

        $this->cancel($order);

        self::assertSame(200, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        self::assertSame(PurchaseOrder::STATUS_CANCELLED, $this->json()['status']);
        self::assertFalse($this->client()->getResponse()->headers->has('Location'),
            'A transition creates no row, so branch 5 gives it no Location.');

        // Read back independently of the response the operation composed.
        $this->send('GET', '/purchase-orders/'.$order);
        self::assertSame(PurchaseOrder::STATUS_CANCELLED, $this->json()['status']);
    }

    /**
     * **§8.3 makes `draft` a legal source, and until `Version20260911000200` the
     * database refused it.**
     *
     * `purchase_order_ordered_at_set` read `status = 'draft' OR ordered_at IS NOT
     * NULL`, so a draft moving to `cancelled` satisfied neither disjunct and
     * Postgres answered `23514` — a 500 on a transition the contract declares
     * legal. The constraint was widened to admit `cancelled`; this is the test
     * that would have found it.
     *
     * *Mutation, run:* the widened `CHECK` reverted to its original wording and
     * the volume rebuilt. Red here with a constraint violation, and green in
     * `cancellingAPlacedOrderMovesItToCancelled` — which is why the two cases are
     * separate tests rather than one.
     */
    #[Test]
    public function cancellingADraftIsLegalAndKeepsItsEmptyOrderedDate(): void
    {
        $draft = $this->seedDraft(self::TENANT_A, 'PO-000001');

        $this->cancel($draft['order']);

        self::assertSame(200, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        self::assertSame(PurchaseOrder::STATUS_CANCELLED, $this->json()['status']);
        self::assertNull($this->json()['ordered_at'],
            'An order cancelled before it was ever placed has no ordered date, and inventing one would say '
            .'it had been placed.');
    }

    /** A cancelled order keeps the date it was placed on: that is the fact the trail needs. */
    #[Test]
    public function cancellingAPlacedOrderKeepsTheDateItWasPlacedOn(): void
    {
        $order = $this->placedOrder();

        $this->cancel($order);

        self::assertSame(200, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        self::assertNotNull($this->json()['ordered_at']);
    }

    /**
     * §8.3's matrix gives `cancel()` three legal sources and `received` is not one
     * of them: a fully received order has nothing left to withdraw.
     *
     * The refusal is the **entity guard's**, identified by its rule code — a
     * second guard in the cancellation path would answer with a different one,
     * and §8.2 requires the guard to sit on the entity.
     */
    #[Test]
    public function aReceivedOrderCannotBeCancelled(): void
    {
        $order = $this->seedOrder(
            self::TENANT_A,
            'PO-000001',
            $this->seedSupplier(self::TENANT_A, 'Acme'),
            $this->seedWarehouse(self::TENANT_A, 'Main'),
            null,
            PurchaseOrder::STATUS_RECEIVED,
        );

        $this->cancel($order);

        self::assertSame(409, $this->httpStatus());
        self::assertSame('urn:erp:problem:business-rule-violated', $this->json()['type']);
        self::assertSame('purchase_order.'.IllegalTransition::RULE, $this->json()['rule']);
    }

    /**
     * §4.2's cell is `guard:purchase_order.status`, and this is that mechanism as
     * behaviour: a replayed cancellation hits an order that is already
     * `cancelled` and `cancel()` refuses it, which is how the caller learns the
     * first attempt committed.
     */
    #[Test]
    public function aReplayedCancellationIsRefusedByTheStatusGuard(): void
    {
        $order = $this->placedOrder();

        $this->cancel($order);
        self::assertSame(200, $this->httpStatus());

        $this->cancel($order);

        self::assertSame(409, $this->httpStatus());
        self::assertSame('urn:erp:problem:business-rule-violated', $this->json()['type']);
        self::assertSame('purchase_order.'.IllegalTransition::RULE, $this->json()['rule']);
    }

    /**
     * ⚠ **A `guard:` operation must NOT demand the header** — API-14 as amended.
     * `cancel()` refuses the repeat whether or not one arrives, so a 428 would
     * refuse a correct request and teach the caller nothing.
     *
     * Asserted as *"not 428"* and then as the success, so that adding the flag to
     * the operation is red rather than silent.
     */
    #[Test]
    public function cancellingWithoutAnIdempotencyKeyIsAccepted(): void
    {
        $order = $this->placedOrder();

        $this->cancel($order);

        self::assertNotSame(428, $this->httpStatus(),
            'A `guard:` operation must not 428 for a missing header: cancel() refuses the repeat either way.');
        self::assertSame(200, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
    }

    // -----------------------------------------------------------------------
    // DSoD-1 on `.cancel` — ADR-008 Amendment 1
    // -----------------------------------------------------------------------

    /** The ordinary path: somebody else raised it, so this caller may withdraw it. */
    #[Test]
    public function anOrderRaisedByAnotherPersonIsCancelled(): void
    {
        $buyer = $this->seedPrincipal(self::TENANT_A, []);

        $this->cancel($this->placedOrder($buyer));

        self::assertSame(200, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        self::assertSame(PurchaseOrder::STATUS_CANCELLED, $this->json()['status']);
    }

    /**
     * **The refusal.** A1.1: *"`purchase_order.cancel` is refused when
     * `purchase_order.created_by_user_id` equals the caller's `app_user.id`."*
     *
     * What it prevents, in Amendment 1's words: *"a single hand on both ends of a
     * commitment's lifecycle"* — money committed by one person and released by
     * the same person, with an `audit_log` trail whose two rows name one name.
     *
     * *Mutation, run:* the creator comparison in
     * `PurchaseOrderDutySeparation::refuseWhenTheActorIsTheCreator()` inverted to
     * `===`. Red here **and** in `PurchaseOrderApprovalTest`, which is the whole
     * argument for the predicate living in one class: one mutation, two red
     * files.
     */
    #[Test]
    public function theCreatorCannotCancelItsOwnOrder(): void
    {
        $order = $this->placedOrder((string) $this->actorId);

        $this->cancel($order);

        self::assertSame(409, $this->httpStatus());
        self::assertSame('purchase_order.canceller_is_not_the_creator', $this->json()['rule']);

        // And nothing moved.
        $this->send('GET', '/purchase-orders/'.$order);
        self::assertSame(PurchaseOrder::STATUS_ORDERED, $this->json()['status']);
    }

    /**
     * **The rule code is the CANCELLATION one**, not the approval one.
     *
     * E-19 enforces one predicate on two codes, and a client that saw a single
     * identifier for both could not tell an approval refusal from a cancellation
     * one. This is the assertion that keeps the shared implementation from
     * collapsing the two.
     */
    #[Test]
    public function theRefusalNamesTheCancellationRuleAndNotTheApprovalOne(): void
    {
        $this->cancel($this->placedOrder((string) $this->actorId));

        self::assertSame('purchase_order.canceller_is_not_the_creator', $this->json()['rule']);
        self::assertNotSame('purchase_order.approver_is_not_the_creator', $this->json()['rule']);
        self::assertSame(self::PINNED_REFUSAL_MESSAGE, $this->json()['message']);
    }

    /**
     * **`owner` is exempt by name**: on a one-person demo tenant `owner` raises
     * the order, places it, and is the only seat that can withdraw it.
     *
     * *Mutation, run:* `holdsOwnerRole()` changed to `return false;`. Red here
     * and in `PurchaseOrderApprovalTest::anOwnerIsExemptByName`, and green
     * everywhere else.
     */
    #[Test]
    public function anOwnerIsExemptByName(): void
    {
        $order = $this->placedOrder((string) $this->actorId);
        $this->grantRoleNamed(self::TENANT_A, (string) $this->actorId, self::PINNED_OWNER_ROLE);

        $this->cancel($order);

        self::assertSame(200, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        self::assertSame(PurchaseOrder::STATUS_CANCELLED, $this->json()['status']);
    }

    /**
     * The other half, and the one that makes the exemption *by name*: A1.1 says
     * in terms that **`purchasing_manager` is not exempt**, and it holds
     * `.cancel`. The role granted here carries the same codes the rig's default
     * principal already has, so the only difference from the test above is the
     * **name**.
     */
    #[Test]
    public function aSeatThatIsNotNamedOwnerIsNotExempt(): void
    {
        $order = $this->placedOrder((string) $this->actorId);
        $this->grantRoleNamed(self::TENANT_A, (string) $this->actorId, 'purchasing_manager');

        $this->cancel($order);

        self::assertSame(409, $this->httpStatus());
        self::assertSame('purchase_order.canceller_is_not_the_creator', $this->json()['rule']);
    }

    /**
     * **R-6 — a NULL creator does not refuse, and it is the rule returning true
     * rather than an exemption.** *"A reorder-agent order was raised by no
     * person, so there is nobody for the canceller to be."* F-4's orders stay
     * cancellable by any `.cancel` holder.
     */
    #[Test]
    public function aNullCreatorDoesNotRefuse(): void
    {
        $this->cancel($this->placedOrder(null));

        self::assertSame(200, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        self::assertSame(PurchaseOrder::STATUS_CANCELLED, $this->json()['status']);
    }

    /**
     * **§5.3 clause 4 / ST-3 / ST-4e: the refusal is `business-rule-violated`,
     * not 403 and not 404.** E-19 names both wrong renderings as failures — a 403
     * would tell the caller they lack a permission they hold, and a 404 would say
     * the order is not there.
     *
     * *Mutation, run:* `ApiProblem::businessRuleViolated(...)` in
     * `PurchaseOrderDutySeparation` replaced by
     * `ApiProblem::permissionDenied('purchase_order.cancel')`. Red on the status,
     * the type and the members. *Second mutation, run:* replaced by
     * `ApiProblem::notFound()`. Red the same way.
     */
    #[Test]
    public function theRefusalIsTheBusinessRuleProblemTypeAndNeither403Nor404(): void
    {
        $this->cancel($this->placedOrder((string) $this->actorId));

        self::assertSame(409, $this->httpStatus(), 'DSoD-1 refused with something other than §5.2\'s business-rule status.');
        self::assertNotSame(403, $this->httpStatus());
        self::assertNotSame(404, $this->httpStatus());
        self::assertSame('application/problem+json; charset=utf-8', $this->contentType());

        $body = $this->json();
        self::assertSame('urn:erp:problem:business-rule-violated', $body['type']);
        self::assertSame('purchase_order.canceller_is_not_the_creator', $body['rule']);
        self::assertSame(self::PINNED_REFUSAL_MESSAGE, $body['message']);
        self::assertArrayNotHasKey('required_permission', $body,
            'A separation-of-duty refusal carrying `required_permission` is E-2\'s authorisation branch wearing a 409.');
    }

    /**
     * **E-19's ordering, and the violation it names.** A caller holding **no**
     * `.cancel` gets byte-identical answers for an order it created in its own
     * tenant, an order in another tenant, and an id that exists nowhere. If
     * DSoD-1 ran first, the first of those would be a 409 naming a rule about a
     * row the caller has no right to know exists.
     */
    #[Test]
    public function aCallerWithoutCancelGetsTheSameRefusalForEveryId(): void
    {
        $mine = $this->placedOrder((string) $this->actorId);

        $inB = $this->seedPrincipal(self::TENANT_B, []);
        $theirs = $this->seedOrder(
            self::TENANT_B,
            'PO-103000',
            $this->seedSupplier(self::TENANT_B, 'Other'),
            $this->seedWarehouse(self::TENANT_B, 'Other'),
            $inB,
            PurchaseOrder::STATUS_ORDERED,
        );

        $this->actAs(self::TENANT_A, []);

        $statuses = [];
        $bodies = [];

        foreach ([$mine, $theirs, Uuid::v4()->toRfc4122()] as $id) {
            $this->cancel($id);
            $statuses[] = $this->httpStatus();
            $bodies[] = (string) $this->client()->getResponse()->getContent();
        }

        self::assertSame([403, 403, 403], $statuses,
            'A caller holding no purchase_order.cancel did not get the same status for every id. E-1 orders '
            .'the permission check before the row is loaded, and E-19 names hoisting DSoD-1 ahead of it as a '
            .'violation for exactly this reason.');
        self::assertSame([$bodies[0], $bodies[0], $bodies[0]], $bodies, 'The three refusals are not byte-identical.');
    }

    // -----------------------------------------------------------------------
    // The trail
    // -----------------------------------------------------------------------

    /**
     * **§9.2's row for `PURCHASE_ORDER_CANCELLED`, cell by cell**, plus the one
     * this slice was chartered to close: the `reason` lands in
     * `audit_log.metadata`.
     *
     * `AuditEvent::PURCHASE_ORDER_CANCELLED`'s docblock ruled the destination in
     * advance, on `STOCK_MOVEMENT_ADJUSTED`'s precedent, *"so the operation's
     * author inherits it rather than deciding it"* — and its closing warning was
     * *"do not read this paragraph as a claim that the metadata write is built"*.
     * This is the test that makes it built.
     *
     * *Mutation, run:* `recordCancelled()`'s `jsonb_build_object` branch replaced
     * by a literal `'{}'::jsonb`, i.e. the reason accepted and discarded. Red here
     * and nowhere else — which is exactly why the field's destination needed a
     * test rather than a docblock.
     */
    #[Test]
    public function cancellingWritesOneAuditRowCarryingTheReason(): void
    {
        $buyer = $this->seedPrincipal(self::TENANT_A, []);
        $order = $this->placedOrder($buyer);

        $this->cancel($order, ['reason' => 'Supplier cannot deliver before the quarter ends.']);
        self::assertSame(200, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());

        $rows = $this->fetchAsOwnerForTenant(self::TENANT_A, <<<'SQL'
            SELECT event_type, actor_user_id, actor_label, subject_type, subject_id,
                   host(ip_address) AS ip_address, metadata::text AS metadata
              FROM audit_log
             WHERE event_type = ?
            SQL, [AuditEvent::PURCHASE_ORDER_CANCELLED]);

        self::assertCount(1, $rows, 'One cancellation must write exactly one row.');
        $row = $rows[0];

        self::assertSame($this->actorId, $row['actor_user_id']);
        self::assertNotSame($buyer, $row['actor_user_id'], 'The trail names the buyer rather than the canceller.');
        self::assertSame('Fixture Actor', $row['actor_label']);

        self::assertSame(AuditEvent::SUBJECT_TYPES[AuditEvent::PURCHASE_ORDER_CANCELLED], $row['subject_type']);
        self::assertSame($order, $row['subject_id'],
            'L-7 queries the History section on (subject_type, subject_id), so this pair is what puts the '
            .'cancellation on the order\'s own page.');

        self::assertNull($row['ip_address'],
            'A business event carries no ip_address — API-16, and audit_log_ip_address_scope refuses it at '
            .'the database too.');

        $metadata = json_decode((string) $row['metadata'], true);
        self::assertIsArray($metadata);
        self::assertSame(['reason'], array_keys($metadata));
        self::assertSame('Supplier cannot deliver before the quarter ends.', $metadata['reason'],
            '§4.2 accepts a `reason` on this operation and purchase_order has no column for it. Accepting the '
            .'field and discarding it is the silent shape this project keeps finding.');
    }

    /** No reason given: an empty object, never a key carrying a null. */
    #[Test]
    public function aCancellationWithNoReasonWritesAnEmptyMetadataObject(): void
    {
        $this->cancel($this->placedOrder());
        self::assertSame(200, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());

        $rows = $this->fetchAsOwnerForTenant(self::TENANT_A,
            'SELECT metadata::text AS metadata FROM audit_log WHERE event_type = ?',
            [AuditEvent::PURCHASE_ORDER_CANCELLED]);

        self::assertCount(1, $rows);
        self::assertSame('{}', $rows[0]['metadata']);
    }

    /**
     * An operator's blank line is not a reason. `metadata` is written once on an
     * append-only table no role can `UPDATE`, so a stored empty string is a
     * permanent record that says nothing while looking like one that says
     * something.
     */
    #[Test]
    public function aBlankReasonIsTreatedAsNoReason(): void
    {
        $this->cancel($this->placedOrder(), ['reason' => "   \n "]);
        self::assertSame(200, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());

        $rows = $this->fetchAsOwnerForTenant(self::TENANT_A,
            'SELECT metadata::text AS metadata FROM audit_log WHERE event_type = ?',
            [AuditEvent::PURCHASE_ORDER_CANCELLED]);

        self::assertSame('{}', $rows[0]['metadata']);
    }

    /** A refused cancellation writes no trail row: the trail records what happened. */
    #[Test]
    public function aRefusedCancellationWritesNoAuditRow(): void
    {
        $this->cancel($this->placedOrder((string) $this->actorId), ['reason' => 'changed my mind']);
        self::assertSame(409, $this->httpStatus());

        self::assertSame([], $this->fetchAsOwnerForTenant(self::TENANT_A,
            'SELECT id FROM audit_log WHERE event_type = ?', [AuditEvent::PURCHASE_ORDER_CANCELLED]));
    }

    /**
     * The audit write joins the caller's transaction, so the status change and
     * the row that names who made it commit together or not at all.
     *
     * Driven from the other end: a cancellation that succeeded left exactly one
     * row, and a cancellation the status guard refused left the order untouched
     * **and** no row — so neither half can be observed without the other.
     */
    #[Test]
    public function aCancellationRefusedByTheStatusGuardLeavesNeitherAStatusChangeNorATrailRow(): void
    {
        $order = $this->seedOrder(
            self::TENANT_A,
            'PO-000001',
            $this->seedSupplier(self::TENANT_A, 'Acme'),
            $this->seedWarehouse(self::TENANT_A, 'Main'),
            null,
            PurchaseOrder::STATUS_RECEIVED,
        );

        $this->cancel($order, ['reason' => 'too late']);
        self::assertSame(409, $this->httpStatus());

        $this->send('GET', '/purchase-orders/'.$order);
        self::assertSame(PurchaseOrder::STATUS_RECEIVED, $this->json()['status']);

        self::assertSame([], $this->fetchAsOwnerForTenant(self::TENANT_A,
            'SELECT id FROM audit_log WHERE event_type = ?', [AuditEvent::PURCHASE_ORDER_CANCELLED]));
    }

    // -----------------------------------------------------------------------
    // The refusals every operation on this row shares
    // -----------------------------------------------------------------------

    /** ST-9 / §5.2a — an archived row is read-only, and the refusal is the business-rule branch. */
    #[Test]
    public function anArchivedOrderCannotBeCancelled(): void
    {
        $order = $this->seedOrder(
            self::TENANT_A,
            'PO-000001',
            $this->seedSupplier(self::TENANT_A, 'Acme'),
            $this->seedWarehouse(self::TENANT_A, 'Main'),
            null,
            PurchaseOrder::STATUS_ORDERED,
            archived: true,
        );

        $this->cancel($order);

        self::assertSame(409, $this->httpStatus());
        self::assertSame('purchase_order.is_archived', $this->json()['rule']);
    }

    /** §5.3 clause 2 — another tenant's row and an id that never existed are the same refusal. */
    #[Test]
    public function anOrderInAnotherTenantIsNotFound(): void
    {
        $inB = $this->seedPrincipal(self::TENANT_B, []);
        $theirs = $this->seedOrder(
            self::TENANT_B,
            'PO-103000',
            $this->seedSupplier(self::TENANT_B, 'Other'),
            $this->seedWarehouse(self::TENANT_B, 'Other'),
            $inB,
            PurchaseOrder::STATUS_ORDERED,
        );

        $this->cancel($theirs);
        $foreign = (string) $this->client()->getResponse()->getContent();
        self::assertSame(404, $this->httpStatus());

        $this->cancel(Uuid::v4()->toRfc4122());
        self::assertSame(404, $this->httpStatus());
        self::assertSame($foreign, (string) $this->client()->getResponse()->getContent(),
            'ST-5: another tenant\'s row and a row that never existed must be the same refusal.');
    }

    /** API-9 — an undeclared field is refused, never ignored. §4.2 accepts `reason` and nothing else. */
    #[Test]
    public function anUndeclaredFieldIsRefused(): void
    {
        $this->cancel($this->placedOrder(), ['note' => 'wrong field name']);

        self::assertSame(422, $this->httpStatus());
        self::assertSame('urn:erp:problem:validation-failed', $this->json()['type']);
    }

    /** The production constant and the pin above are compared once, in each file that relies on it. */
    #[Test]
    public function theExemptSeatIsTheOnePinnedHere(): void
    {
        self::assertSame(self::PINNED_OWNER_ROLE, PurchaseOrderDutySeparation::OWNER_ROLE_NAME);
    }
}
