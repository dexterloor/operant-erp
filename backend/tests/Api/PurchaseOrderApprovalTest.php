<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Modules\Purchasing\Infrastructure\Entity\PurchaseOrder;
use App\Modules\UserManagement\Domain\DefaultRoles;
use App\Modules\UserManagement\Domain\OwnerMarker;
use App\Platform\Domain\IllegalTransition;
use App\Platform\Http\Idempotency\IdempotencyKeyGuard;
use App\SharedKernel\ValueObject\AuditEvent;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Uid\Uuid;

/**
 * **E-19 / DSoD-1, and §8.1's five-step order.**
 *
 * §8.1 names five checks by name and this file is those five plus the audit row
 * F-2 step 8 requires:
 *
 *   `theCreatorCannotApproveItsOwnOrder`
 *   `anOwnerMarkerHolderIsExempt`   (was `anOwnerIsExemptByName` until sales step 6 C6a)
 *   `aNullCreatorDoesNotRefuse`
 *   `theRefusalIsTheBusinessRuleProblemType`
 *   `everyHttpCreatedOrderRecordsItsCreator`   ← **owed since 2026-09-10, paid
 *                                                 2026-09-11.**
 *
 * ⚠ **The fifth check could not be written until `CreatePurchaseOrder` existed,
 * and this docblock said so rather than writing it green.** The reasoning is
 * kept because it is the reason the check is worth anything now: §8.1's clause
 * is *"an HTTP-created `purchase_order` has a NULL `created_by_user_id`"*, and
 * while no operation created one over HTTP, any test of it would have been
 * asserting something about `PurchaseOrderTestCase::seedOrder()` — which sets
 * the column because the test told it to. **A check that can only pass is not a
 * check.** Step 6 shipped the operation, so the check now has the subject it was
 * always about, and it reads the column **out of the database** rather than out
 * of the response, because the response is built by the same method that did the
 * write.
 *
 * ---------------------------------------------------------------------------
 * The owner exemption is the owner MARKER, pinned as a literal here
 * ---------------------------------------------------------------------------
 * Since sales step 6 C6a (2026-09-28; V2-R-19 + V2-S-5, `SEC`'s Q8 ruling, the
 * owner acknowledged the notice) the caller is exempt when the permission set
 * E-1 enforced holds `tenant_settings.edit` — `OwnerPredicate::holdsMarker()` —
 * never by a role's name. `PINNED_OWNER_MARKER` below is an independent copy of
 * `OwnerMarker::CODE`, on `AuditWriterTest::PINNED_IP_BEARING_EVENTS`' reasoning.
 * ⚠ **Until C6a this block described `PINNED_OWNER_ROLE`, a pin on the NAME
 * `owner` compared to `PurchaseOrderDutySeparation::OWNER_ROLE_NAME`**; the
 * constant was deleted with the name test and the pin retired with it. The five
 * shapes that separate the two predicates are `PurchaseOrderOwnerExemptionTest`.
 */
final class PurchaseOrderApprovalTest extends PurchaseOrderTestCase
{
    /**
     * **The code DSoD-1's exemption is keyed on, spelled independently of the code under test** (`SECURITY_MODEL.md`
     * E-15b's marker). ⚠ **Until 2026-09-28 this file pinned `PINNED_OWNER_ROLE = 'owner'`** — the role NAME the
     * exemption was keyed on, compared once to `PurchaseOrderDutySeparation::OWNER_ROLE_NAME`. Sales step 6 C6a
     * (V2-R-19 + V2-S-5, `SEC`'s Q8 ruling, owner-acknowledged) replaced the name test with the owner marker and deleted
     * that constant, so the name pin retired with it; this pins the marker instead, so a change of the code the
     * exemption reads is a red test rather than a silently moved exemption.
     */
    private const PINNED_OWNER_MARKER = 'tenant_settings.edit';

    /** ST-4e's own words, and `ADR-008` §2 prints the same sentence. */
    private const PINNED_REFUSAL_MESSAGE = 'You raised this order. It needs a different approver.';

    /**
     * **DSoD-1's ordinary path: a second pair of eyes.** The buyer raised it, the
     * manager places it, and F-2 is a flow between two people because that is
     * what separation of duty means.
     */
    #[Test]
    public function anOrderRaisedByAnotherPersonIsApproved(): void
    {
        $buyer = $this->seedPrincipal(self::TENANT_A, []);
        $draft = $this->seedDraft(self::TENANT_A, 'PO-000001', $buyer);
        $this->seedLine(self::TENANT_A, $draft['order'], $draft['item']);

        $this->approve($draft['order']);

        self::assertSame(200, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        self::assertSame(PurchaseOrder::STATUS_ORDERED, $this->json()['status']);
        self::assertNotNull($this->json()['ordered_at'], 'purchase_order_ordered_at_set requires it and the response has to carry it.');
    }

    /**
     * **The refusal.** `purchase_order.created_by_user_id` equals the caller's
     * `app_user.id`, the caller is not `owner`, and E-19 refuses.
     *
     * *Mutation, run:* the creator comparison in
     * `PurchaseOrderApproval::refuseSelfApproval()` inverted to `===`. Red here
     * and in `anOrderRaisedByAnotherPersonIsApproved`, which is the pair that
     * proves the comparison is a comparison rather than a constant.
     */
    #[Test]
    public function theCreatorCannotApproveItsOwnOrder(): void
    {
        $draft = $this->seedDraft(self::TENANT_A, 'PO-000001', (string) $this->actorId);

        $this->approve($draft['order']);

        self::assertSame(409, $this->httpStatus());
        self::assertSame('purchase_order.approver_is_not_the_creator', $this->json()['rule']);

        // And nothing moved. A refusal that renders correctly over a row it
        // already changed is the worse half of getting this wrong.
        $this->send('GET', '/purchase-orders/'.$draft['order']);
        self::assertSame(PurchaseOrder::STATUS_DRAFT, $this->json()['status']);
        self::assertNull($this->json()['ordered_at']);
    }

    /**
     * **The owner is exempt — the holder of the owner marker**, not a role called
     * `owner`: on a one-person demo tenant the owner raises the order and is the
     * only seat that can place it. The role granted here is deliberately NOT named
     * `owner`, so a name test cannot pass it. *(Rewritten deliberately at sales step
     * 6 C6a; it was `anOwnerIsExemptByName`, granting a role named `owner` with no
     * codes — the shape that is now refused.)*
     *
     * *Mutation, run at C6a:* `OwnerPredicate::holdsMarker()` returning `false` —
     * red here and in the co-owner and all-codes cases of
     * `PurchaseOrderOwnerExemptionTest`.
     */
    #[Test]
    public function anOwnerMarkerHolderIsExempt(): void
    {
        $draft = $this->seedDraft(self::TENANT_A, 'PO-000001', (string) $this->actorId);
        $this->seedLine(self::TENANT_A, $draft['order'], $draft['item']);
        $this->grantRoleWithCodes(self::TENANT_A, (string) $this->actorId, 'Workspace principal', [self::PINNED_OWNER_MARKER]);

        $this->approve($draft['order']);

        self::assertSame(200, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        self::assertSame(PurchaseOrder::STATUS_ORDERED, $this->json()['status']);
    }

    /**
     * The other half of the exemption, intent kept: a seat that does not hold the
     * owner marker gets no exemption however much it holds — `purchasing_manager`
     * holds both of SoD-1's controlled codes, and A1.1 says in terms that it is
     * not exempt. *(It was `aSeatThatIsNotNamedOwnerIsNotExempt` until C6a, when
     * the exemption stopped being a name.)*
     */
    #[Test]
    public function aSeatWithoutTheOwnerMarkerIsNotExempt(): void
    {
        $draft = $this->seedDraft(self::TENANT_A, 'PO-000001', (string) $this->actorId);
        $this->grantRoleWithCodes(self::TENANT_A, (string) $this->actorId, DefaultRoles::PURCHASING_MANAGER,
            DefaultRoles::codesFor(DefaultRoles::PURCHASING_MANAGER));

        $this->approve($draft['order']);

        self::assertSame(409, $this->httpStatus());
        self::assertSame('purchase_order.approver_is_not_the_creator', $this->json()['rule']);
    }

    /**
     * **A NULL creator does not refuse, and it is the rule returning true rather
     * than an exemption** (R-6). A purchase order raised by the reorder agent
     * (F-4) was raised by no person, so there is nobody for the approver to be —
     * and a refusal on NULL would make every seeded order unapprovable.
     *
     * *Mutation, run:* the `null === $creator` branch in `refuseSelfApproval()`
     * deleted. `fetchOne` returns `null`, `(string) null` is `''`, the comparison
     * against a uuid is false and the order is **still approved** — so this test
     * stays green and the mutation is invisible here. The mutation that shows the
     * branch is load-bearing is the opposite one: `return;` replaced by a throw
     * on NULL, which is red here and green in every other test in this file.
     * Recorded rather than tidied, because the first form was the obvious one and
     * it proves nothing.
     */
    #[Test]
    public function aNullCreatorDoesNotRefuse(): void
    {
        $draft = $this->seedDraft(self::TENANT_A, 'PO-000001');
        $this->seedLine(self::TENANT_A, $draft['order'], $draft['item']);

        $this->approve($draft['order']);

        self::assertSame(200, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        self::assertSame(PurchaseOrder::STATUS_ORDERED, $this->json()['status']);
    }

    /**
     * **§5.3 clause 4 and E-2: the refusal is `business-rule-violated`, not 403
     * and not 404.** The caller holds `.approve` and is looking at the row, so it
     * is a rule about *this row*; a 403 would say the caller may not approve
     * anything and a 404 would say the order is not there.
     *
     * The message is ST-4e's own sentence, asserted as a literal, because
     * ST-4e's whole point is that the reason names the job split rather than a
     * code.
     *
     * *Mutation, run:* `ApiProblem::businessRuleViolated(...)` in
     * `refuseSelfApproval()` replaced by `ApiProblem::permissionDenied('purchase_order.approve')`.
     * Red on the status, the type and the members. *Second mutation, run:*
     * replaced by `ApiProblem::notFound()`. Red the same way.
     */
    #[Test]
    public function theRefusalIsTheBusinessRuleProblemType(): void
    {
        $draft = $this->seedDraft(self::TENANT_A, 'PO-000001', (string) $this->actorId);

        $this->approve($draft['order']);

        self::assertSame(409, $this->httpStatus(), 'DSoD-1 refused with something other than §5.2\'s business-rule status.');
        self::assertSame('application/problem+json; charset=utf-8', $this->contentType());

        $body = $this->json();
        self::assertSame('urn:erp:problem:business-rule-violated', $body['type']);
        self::assertSame('purchase_order.approver_is_not_the_creator', $body['rule']);
        self::assertSame(self::PINNED_REFUSAL_MESSAGE, $body['message']);

        // The two members §5.2 gives to the refusals this is NOT.
        self::assertArrayNotHasKey('required_permission', $body,
            'A separation-of-duty refusal carrying `required_permission` is E-2\'s authorisation branch wearing a 409.');
    }

    /**
     * **§8.1's ordering, and the violation it names: hoisting DSoD-1 ahead of the
     * permission check rebuilds E-1's intra-tenant existence oracle.**
     *
     * A caller holding **no** `.approve` gets byte-identical answers for an order
     * it created in its own tenant, an order in another tenant, and an id that
     * exists nowhere. If DSoD-1 ran first, the first of those three would be a
     * 409 naming a rule about a row the caller has no right to know exists.
     *
     * *Mutation, run:* `refuseSelfApproval()` called from
     * `ApprovePurchaseOrderProcessor` before delegating — i.e. hoisted ahead of
     * `PermissionGate` in effect by moving the row read forward. The in-tenant id
     * answers 409 and the other two 403; red on the byte-identity assertion.
     */
    #[Test]
    public function aCallerWithoutApproveGetsTheSameRefusalForItsOwnOrderAsForOneThatDoesNotExist(): void
    {
        // The order exists, in this tenant, and this principal created it -- the
        // exact row DSoD-1 would refuse if it ran first.
        $draftInA = $this->seedDraft(self::TENANT_A, 'PO-000001', (string) $this->actorId);
        $orderInA = $draftInA['order'];

        $inB = $this->seedPrincipal(self::TENANT_B, []);
        $supplierB = $this->seedSupplier(self::TENANT_B, 'Other');
        $warehouseB = $this->seedWarehouse(self::TENANT_B, 'Other');
        $orderInB = $this->seedOrder(self::TENANT_B, 'PO-103000', $supplierB, $warehouseB, $inB);

        // The same principal, now holding nothing.
        $this->actAs(self::TENANT_A, []);

        $bodies = [];
        $statuses = [];

        foreach ([$orderInA, $orderInB, Uuid::v4()->toRfc4122()] as $id) {
            $this->approve($id);
            $statuses[] = $this->httpStatus();
            $bodies[] = (string) $this->client()->getResponse()->getContent();
        }

        self::assertSame([403, 403, 403], $statuses,
            'A caller holding no purchase_order.approve did not get the same status for every id. '
            .'E-1 orders the permission check before the row is loaded, and §8.1 names hoisting DSoD-1 '
            .'ahead of it as a violation for exactly this reason.');
        self::assertSame([$bodies[0], $bodies[0], $bodies[0]], $bodies, 'The three refusals are not byte-identical.');
    }

    /**
     * §8.1 step 4 — the status guard, which is `PurchaseOrder::place()`'s own and
     * is **not** re-added in the approval path. §8.3's matrix gives `place()` one
     * legal source, `draft`.
     *
     * This is also §6.1's interim rule made visible: a replayed
     * `ApprovePurchaseOrder` hits an order that is no longer `draft` and is
     * answered `business-rule-violated`, which is how the caller learns the first
     * attempt committed. That is what makes the operation safe without the
     * idempotency store API-O-1 owes.
     */
    #[Test]
    public function approvingATwiceApprovedOrderIsRefusedByTheStatusGuard(): void
    {
        $draft = $this->seedDraft(self::TENANT_A, 'PO-000001');
        $this->seedLine(self::TENANT_A, $draft['order'], $draft['item']);

        $this->approve($draft['order']);
        self::assertSame(200, $this->httpStatus());

        $this->approve($draft['order']);

        self::assertSame(409, $this->httpStatus());
        self::assertSame('urn:erp:problem:business-rule-violated', $this->json()['type']);
        self::assertSame('purchase_order.status_transition_is_legal', $this->json()['rule'],
            'The refusal is not the entity guard\'s. A second guard in the approval path would answer with '
            .'a different rule code and §8.2 requires the guard to sit on the entity.');
    }

    /** ST-9 / §5.2a: *"a PATCH, DELETE or transition on a soft-deleted row succeeds"* is a stated failure. */
    #[Test]
    public function anArchivedOrderCannotBeApproved(): void
    {
        $supplier = $this->seedSupplier(self::TENANT_A, 'Acme');
        $warehouse = $this->seedWarehouse(self::TENANT_A, 'Main');
        $order = $this->seedOrder(self::TENANT_A, 'PO-000001', $supplier, $warehouse, archived: true);

        $this->approve($order);

        self::assertSame(409, $this->httpStatus());
        self::assertSame('purchase_order.is_archived', $this->json()['rule']);
    }

    /**
     * **Step 6a, product-owner ruling 2026-09-11: an order with nothing on it
     * cannot be placed with a supplier.** Until this test, `place()` checked the
     * status alone and a manager could send an empty, zero-value order.
     *
     * The creator is a second person so DSoD-1 passes and the only thing left to
     * refuse is the line guard. Three things are asserted, because a refusal that
     * renders correctly over a row it already moved is the worse half of getting
     * this wrong: the rule, the unchanged row, and the absent trail row.
     */
    #[Test]
    public function anOrderWithNoLinesCannotBeApproved(): void
    {
        $buyer = $this->seedPrincipal(self::TENANT_A, []);
        $draft = $this->seedDraft(self::TENANT_A, 'PO-000001', $buyer);

        $this->approve($draft['order']);

        self::assertSame(409, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        self::assertSame('urn:erp:problem:business-rule-violated', $this->json()['type']);
        self::assertSame('purchase_order.has_a_live_line', $this->json()['rule']);

        $this->assertStillAnUnplacedDraft($draft['order']);
    }

    /**
     * **"Live" is `deleted_at IS NULL`.** A draft whose lines were all archived
     * holds rows in `purchase_order_line` and is as empty as one that never had
     * any; a count over every row would approve it. The lines are archived
     * through the operation rather than by a fixture, so the `deleted_at` the
     * guard reads is the one the product writes.
     */
    #[Test]
    public function anOrderWhoseLinesAreAllArchivedCannotBeApproved(): void
    {
        $buyer = $this->seedPrincipal(self::TENANT_A, []);
        $draft = $this->seedDraft(self::TENANT_A, 'PO-000001', $buyer);

        foreach ([1, 2] as $number) {
            $line = $this->seedLine(self::TENANT_A, $draft['order'], $draft['item'], lineNumber: $number);
            $this->send('DELETE', '/purchase-orders/'.$draft['order'].'/lines/'.$line);
            self::assertSame(204, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        }

        $this->approve($draft['order']);

        self::assertSame(409, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        self::assertSame('purchase_order.has_a_live_line', $this->json()['rule']);

        $this->assertStillAnUnplacedDraft($draft['order']);
    }

    /**
     * The other half: one live line beside an archived one is enough. A guard
     * that refused on *any* archived line would pass both tests above.
     */
    #[Test]
    public function oneLiveLineBesideAnArchivedOneIsEnough(): void
    {
        $buyer = $this->seedPrincipal(self::TENANT_A, []);
        $draft = $this->seedDraft(self::TENANT_A, 'PO-000001', $buyer);

        $archived = $this->seedLine(self::TENANT_A, $draft['order'], $draft['item'], lineNumber: 1);
        $this->seedLine(self::TENANT_A, $draft['order'], $draft['item'], lineNumber: 2);
        $this->send('DELETE', '/purchase-orders/'.$draft['order'].'/lines/'.$archived);
        self::assertSame(204, $this->httpStatus());

        $this->approve($draft['order']);

        self::assertSame(200, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        self::assertSame(PurchaseOrder::STATUS_ORDERED, $this->json()['status']);
    }

    /**
     * **The count is of *this* order's lines.** Every other line-guard test seeds
     * one order, so a count that dropped its `purchase_order_id` predicate — and
     * counted every live line the tenant has — would leave all of them green.
     * Here the tenant holds a live line on order A, and draft B, which has none,
     * must still be refused.
     *
     * *Mutation, run:* the `purchase_order_id = ?` predicate in
     * `PurchaseOrderApproval`'s count replaced by one that is always true; red
     * here — B is approved — and green in every other test in this file.
     */
    #[Test]
    public function aLineOnAnotherOrderDoesNotCountTowardsThisOne(): void
    {
        $buyer = $this->seedPrincipal(self::TENANT_A, []);
        $withALine = $this->seedDraft(self::TENANT_A, 'PO-101000', $buyer);
        $this->seedLine(self::TENANT_A, $withALine['order'], $withALine['item']);
        $empty = $this->seedDraft(self::TENANT_A, 'PO-103000', $buyer);

        $this->approve($empty['order']);

        self::assertSame(409, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        self::assertSame('purchase_order.has_a_live_line', $this->json()['rule']);

        $this->assertStillAnUnplacedDraft($empty['order']);
    }

    /**
     * **Five-calls follow-up, owner ruling 2026-09-13: approval re-checks the
     * warehouse.** `CreatePurchaseOrder` refuses a warehouse that is not live,
     * and until this test approval never looked again — so a draft raised
     * against a live site, whose site was archived before approval, was placed
     * with the supplier, and the goods were then refused at the dock by
     * `ReceiveStock`'s `warehouse.is_live`.
     *
     * The warehouse is archived by statement as the owner because no operation
     * archives one (`DeleteWarehouse` is declared and unbuilt; API-O-34). The
     * draft has a live line and a different creator, so every earlier step of
     * §8.1 passes and the warehouse is the only thing left to refuse. The same
     * three things as the line guard's tests are asserted: the rule, the
     * unchanged row, and no trail row.
     */
    #[Test]
    public function anOrderWhoseWarehouseWasArchivedAfterItWasRaisedCannotBeApproved(): void
    {
        $buyer = $this->seedPrincipal(self::TENANT_A, []);
        $draft = $this->seedDraft(self::TENANT_A, 'PO-000001', $buyer);
        $this->seedLine(self::TENANT_A, $draft['order'], $draft['item']);

        $this->insertAsOwnerForTenant(self::TENANT_A,
            'UPDATE warehouse SET deleted_at = now() WHERE id = ?::uuid', [$draft['warehouse']]);

        $this->approve($draft['order']);

        self::assertSame(409, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        self::assertSame('urn:erp:problem:business-rule-violated', $this->json()['type']);
        self::assertSame('purchase_order.warehouse_is_live', $this->json()['rule']);

        $this->assertStillAnUnplacedDraft($draft['order']);
    }

    /**
     * **F-2 step 8 — *"writes an `audit_log` row naming the approver"*.**
     *
     * §9.2's row for `PURCHASE_ORDER_PLACED`: written by `ApprovePurchaseOrder`,
     * principal `erp_app`, subject `purchase_order`, **`ip_address` null**, no
     * cap. Every one of those is asserted, because §9.2 is a table of five cells
     * and any of them can be wrong on its own.
     *
     * *Mutation, run:* the `recordPlaced()` call deleted from
     * `PurchaseOrderApproval::approve()`. Red on the row count, and every other
     * test in this file stays green — which is why the audit is a separate check
     * from the transition.
     */
    #[Test]
    public function approvingWritesOneAuditRowNamingTheApprover(): void
    {
        $buyer = $this->seedPrincipal(self::TENANT_A, []);
        $draft = $this->seedDraft(self::TENANT_A, 'PO-000001', $buyer);
        $this->seedLine(self::TENANT_A, $draft['order'], $draft['item']);

        $this->approve($draft['order']);
        self::assertSame(200, $this->httpStatus());

        $rows = $this->fetchAsOwnerForTenant(self::TENANT_A, <<<'SQL'
            SELECT event_type, actor_user_id, actor_label, subject_type, subject_id,
                   host(ip_address) AS ip_address, metadata::text AS metadata
              FROM audit_log
             WHERE event_type = ?
            SQL, [AuditEvent::PURCHASE_ORDER_PLACED]);

        self::assertCount(1, $rows, 'One approval must write exactly one row.');
        $row = $rows[0];

        // The approver, not the creator. F-2 step 8: "recorded against this
        // manager by name", which is what makes SoD-1 auditable rather than
        // merely stated.
        self::assertSame($this->actorId, $row['actor_user_id']);
        self::assertNotSame($buyer, $row['actor_user_id'], 'The trail names the buyer rather than the approver.');
        self::assertSame('Fixture Actor', $row['actor_label']);

        self::assertSame(AuditEvent::SUBJECT_TYPES[AuditEvent::PURCHASE_ORDER_PLACED], $row['subject_type']);
        self::assertSame($draft['order'], $row['subject_id'],
            'L-7 queries the History section on (subject_type, subject_id), so this pair is what puts the '
            .'approval on the order\'s own page.');

        self::assertNull($row['ip_address'],
            '§9.2 gives this event a null ip_address and API-16 restricts the column to security events. '
            .'`audit_log_ip_address_scope` refuses it at the database too.');
        self::assertSame('{}', $row['metadata'], '§9.2 assigns this event no metadata.');
    }

    /** A refused approval writes no trail row: the trail records what happened. */
    #[Test]
    public function aRefusedApprovalWritesNoAuditRow(): void
    {
        $draft = $this->seedDraft(self::TENANT_A, 'PO-000001', (string) $this->actorId);

        $this->approve($draft['order']);
        self::assertSame(409, $this->httpStatus());

        self::assertSame([], $this->fetchAsOwnerForTenant(self::TENANT_A,
            'SELECT id FROM audit_log WHERE event_type = ?', [AuditEvent::PURCHASE_ORDER_PLACED]));
    }

    /**
     * **`place()` assigns `expectedAt` unconditionally, so an approval that names
     * no date must not erase the one the buyer entered at F-2 step 5.**
     *
     * *Mutation, run:* `$expectedAtSubmitted ? $expectedAt : self::currentExpectedAt(...)`
     * replaced by `$expectedAt`. Red here — the date comes back null — and green
     * in every other test in this file, because none of the others seeds one.
     */
    #[Test]
    public function approvingWithoutAnExpectedDateKeepsTheOneTheOrderHad(): void
    {
        $supplier = $this->seedSupplier(self::TENANT_A, 'Acme');
        $warehouse = $this->seedWarehouse(self::TENANT_A, 'Main');
        $order = $this->seedOrder(self::TENANT_A, 'PO-000001', $supplier, $warehouse, expectedAt: '2026-03-01T00:00:00+00:00');
        $this->seedLine(self::TENANT_A, $order, $this->seedItem(self::TENANT_A, 'SKU-1'));

        $this->approve($order);

        self::assertSame(200, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        self::assertSame('2026-03-01T00:00:00.000+00:00', $this->json()['expected_at']);
    }

    /** The other two of the three cases: a value replaces it, and an explicit null clears it. */
    #[Test]
    public function approvingCanSetAndCanClearTheExpectedDate(): void
    {
        $supplier = $this->seedSupplier(self::TENANT_A, 'Acme');
        $warehouse = $this->seedWarehouse(self::TENANT_A, 'Main');
        $item = $this->seedItem(self::TENANT_A, 'SKU-1');

        $set = $this->seedOrder(self::TENANT_A, 'PO-000001', $supplier, $warehouse, expectedAt: '2026-03-01T00:00:00+00:00');
        $this->seedLine(self::TENANT_A, $set, $item);
        $this->approve($set, ['expected_at' => '2026-04-02T00:00:00+00:00']);
        self::assertSame(200, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        self::assertSame('2026-04-02T00:00:00.000+00:00', $this->json()['expected_at']);

        $cleared = $this->seedOrder(self::TENANT_A, 'PO-000002', $supplier, $warehouse, expectedAt: '2026-03-01T00:00:00+00:00');
        $this->seedLine(self::TENANT_A, $cleared, $item);
        $this->approve($cleared, ['expected_at' => null]);
        self::assertSame(200, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        self::assertNull($this->json()['expected_at'],
            'An explicit null is the caller clearing the date, and it must be distinguishable from an absent field.');
    }

    /**
     * ⚠ **Approval does NOT require the header, and this test asserted the
     * opposite until 2026-09-10.**
     *
     * §4.2's cell is `guard:purchase_order.status`. API-14 as amended demands the
     * header for `store` and `unique:` only, because a `guard:` operation refuses
     * its own repeat through the state machine whether or not a header arrives —
     * so the 428 this method used to assert turned a correct request into a
     * refusal and taught the caller nothing.
     *
     * **Inverted rather than deleted.** "No 428" is the behaviour that would
     * otherwise go untested, and re-adding the flag to the operation must be red
     * rather than silent.
     */
    #[Test]
    public function approvingWithoutAnIdempotencyKeyIsAccepted(): void
    {
        // A **null** creator, as the method this replaced used. Seeding the
        // acting principal as the creator trips DSoD-1
        // (`purchase_order.approver_is_not_the_creator`) and the approval is
        // refused 409 before `place()` is ever reached -- which would make this
        // test green on the wrong refusal. `aNullCreatorDoesNotRefuse` is the
        // test that establishes a null creator is approvable.
        $draft = $this->seedDraft(self::TENANT_A, 'PO-000001');
        $this->seedLine(self::TENANT_A, $draft['order'], $draft['item']);

        $this->send('POST', '/purchase-orders/'.$draft['order'].'/approval', []);

        self::assertNotSame(428, $this->httpStatus(),
            'A `guard:` operation must not 428 for a missing header: `place()` refuses the repeat either way.');
        self::assertSame(200, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
    }

    /**
     * The mechanism the cell names, as behaviour: a replayed approval hits an
     * order that is no longer `draft` and `place()`'s guard refuses it, which is
     * how the caller learns the first attempt committed.
     */
    #[Test]
    public function aReplayedApprovalIsRefusedByTheStatusGuard(): void
    {
        // Null creator, for the reason given above: DSoD-1 would otherwise refuse
        // the FIRST approval, and the second refusal this test is about would
        // never be reached. The two refusals are both `business-rule-violated`,
        // so they are distinguished here by their `rule` member.
        $draft = $this->seedDraft(self::TENANT_A, 'PO-000001');
        $this->seedLine(self::TENANT_A, $draft['order'], $draft['item']);

        $this->send('POST', '/purchase-orders/'.$draft['order'].'/approval', []);
        self::assertSame(200, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());

        $this->send('POST', '/purchase-orders/'.$draft['order'].'/approval', []);

        self::assertSame(409, $this->httpStatus());
        self::assertSame('urn:erp:problem:business-rule-violated', $this->json()['type']);
        self::assertSame('purchase_order.'.IllegalTransition::RULE, $this->json()['rule']);
    }

    /** API-9 — an undeclared field is refused, never ignored. `notes` is not in §4.2's cell. */
    #[Test]
    public function anUndeclaredFieldIsRefused(): void
    {
        $draft = $this->seedDraft(self::TENANT_A, 'PO-000001');

        $this->approve($draft['order'], ['notes' => 'hurry']);

        self::assertSame(422, $this->httpStatus());
        self::assertSame('urn:erp:problem:validation-failed', $this->json()['type']);
    }

    /** §5.3 clause 2 — a row in another tenant is `not-found`, for a caller who holds the code. */
    #[Test]
    public function anOrderInAnotherTenantIsNotFound(): void
    {
        $inB = $this->seedPrincipal(self::TENANT_B, []);
        $supplierB = $this->seedSupplier(self::TENANT_B, 'Other');
        $warehouseB = $this->seedWarehouse(self::TENANT_B, 'Other');
        $orderInB = $this->seedOrder(self::TENANT_B, 'PO-103000', $supplierB, $warehouseB, $inB);

        $this->approve($orderInB);
        $foreign = (string) $this->client()->getResponse()->getContent();
        self::assertSame(404, $this->httpStatus());

        $this->approve(Uuid::v4()->toRfc4122());
        self::assertSame(404, $this->httpStatus());
        self::assertSame($foreign, (string) $this->client()->getResponse()->getContent(),
            'ST-5: another tenant\'s row and a row that never existed must be the same refusal.');
    }

    /**
     * The production marker and the pin above are compared once, so a change of the
     * code the exemption reads is a red test rather than a silently moved exemption.
     * *(Replaced `theExemptSeatIsTheOnePinnedHere`, the name pin, at C6a.)*
     */
    #[Test]
    public function theExemptionIsKeyedOnThePinnedMarker(): void
    {
        self::assertSame(self::PINNED_OWNER_MARKER, OwnerMarker::CODE);
    }

    /**
     * **§8.1's fifth check, owed since the rule was written and unwritable until
     * `CreatePurchaseOrder` shipped.**
     *
     * > *fails if:* … **or** an HTTP-created `purchase_order` has a NULL
     * > `created_by_user_id`.
     *
     * E-19 states the stake exactly: a NULL there on a user-initiated create
     * *"converts DSoD-1 from a control into a suggestion one `INSERT` at a
     * time"* — **and since Amendment 1 a single missing write disarms two checks
     * rather than one**, approval and cancellation. So this is not a test about a
     * column being populated; it is the test that keeps both halves of E-19 from
     * being inert.
     *
     * It reads `created_by_user_id` **out of the database**, not out of the
     * response. The response is built by the same method that performed the
     * write, so a create that returned the caller as `created_by` while writing
     * NULL would satisfy a response-level assertion — and that is precisely the
     * shape (a claim about a write, in place of the write) this project has
     * recorded finding twenty times.
     *
     * *Mutation, run:* the `$createdBy` argument in
     * `PurchaseOrderWriter::create()`'s `INSERT` replaced by a literal `null`.
     * Red here, and red in `theCreatorCannotApproveItsOwnOrder`'s cancellation
     * twin — which is the two-checks-from-one-write property stated as a test
     * result rather than as a sentence.
     */
    #[Test]
    public function everyHttpCreatedOrderRecordsItsCreator(): void
    {
        $supplier = $this->seedSupplier(self::TENANT_A, 'Acme');
        $warehouse = $this->seedWarehouse(self::TENANT_A, 'Main');

        $this->send('POST', '/purchase-orders', [
            'supplier_id' => $supplier,
            'warehouse_id' => $warehouse,
        ], [IdempotencyKeyGuard::HEADER => Uuid::v4()->toRfc4122()]);

        self::assertSame(201, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());

        $rows = $this->fetchAsOwnerForTenant(self::TENANT_A,
            'SELECT created_by_user_id FROM purchase_order WHERE id = ?::uuid', [$this->json()['id']]);

        self::assertCount(1, $rows);
        self::assertSame($this->actorId, $rows[0]['created_by_user_id'],
            'An order created over HTTP does not name its creator. §8.1: CreatePurchaseOrder sets '
            .'created_by_user_id server-side from the JWT subject on every user-initiated create, and a NULL '
            .'there disarms DSoD-1 on approval AND on cancellation.');
    }

    /**
     * The consequence of the check above, driven end to end: the creator of an
     * order raised **through the API** cannot then approve it.
     *
     * Every other DSoD-1 test in this file seeds the creator directly, which is
     * what `PurchaseOrderTestCase`'s docblock calls *"a fixture standing in for
     * an unbuilt operation"*. This one uses no fixture for the creator at all —
     * the column is written by the operation, and the refusal is produced by the
     * value it wrote.
     */
    #[Test]
    public function theCreatorOfAnApiCreatedOrderCannotApproveIt(): void
    {
        $supplier = $this->seedSupplier(self::TENANT_A, 'Acme');
        $warehouse = $this->seedWarehouse(self::TENANT_A, 'Main');

        $this->send('POST', '/purchase-orders', [
            'supplier_id' => $supplier,
            'warehouse_id' => $warehouse,
        ], [IdempotencyKeyGuard::HEADER => Uuid::v4()->toRfc4122()]);
        self::assertSame(201, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());

        $this->approve($this->json()['id']);

        self::assertSame(409, $this->httpStatus());
        self::assertSame('purchase_order.approver_is_not_the_creator', $this->json()['rule']);
    }

    /**
     * **An impossible date is refused, not rolled over.** Before 6c this class
     * had its own copy of the parser, without `Rfc3339Timestamp`'s warning
     * check, so 30 February became 2 March and the order was placed with an
     * expected date nobody wrote. The order has a live line, so the only thing
     * left to refuse is the field.
     */
    #[Test]
    #[DataProvider('impossibleInstants')]
    public function approvingWithAnImpossibleExpectedDateIsRefusedAndPlacesNothing(string $expectedAt): void
    {
        $draft = $this->seedDraft(self::TENANT_A, 'PO-000001', $this->seedPrincipal(self::TENANT_A, []));
        $this->seedLine(self::TENANT_A, $draft['order'], $draft['item']);

        $this->approve($draft['order'], ['expected_at' => $expectedAt]);

        self::assertSame(422, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        self::assertSame('urn:erp:problem:validation-failed', $this->json()['type']);
        self::assertSame('not_a_timestamp', $this->json()['errors'][0]['code']);
        self::assertSame('/expected_at', $this->json()['errors'][0]['pointer']);
        $this->assertStillAnUnplacedDraft($draft['order']);
    }

    /**
     * Each is a value PHP corrects with a warning rather than refusing, so each
     * would be stored as a different instant from the one written: 2 March,
     * midnight of 2 March, and 00:00 of 2 March.
     *
     * @return iterable<string, array{string}>
     */
    public static function impossibleInstants(): iterable
    {
        yield '30 February' => ['2026-02-30T00:00:00Z'];
        yield 'hour 24' => ['2026-03-01T24:00:00Z'];
        yield 'second 60' => ['2026-03-01T23:59:60Z'];
    }

    /**
     * A refused approval moved nothing: still `draft`, still no `ordered_at`, and
     * no `purchase_order.placed` row — the trail records what happened.
     */
    private function assertStillAnUnplacedDraft(string $orderId): void
    {
        $this->send('GET', '/purchase-orders/'.$orderId);
        self::assertSame(PurchaseOrder::STATUS_DRAFT, $this->json()['status']);
        self::assertNull($this->json()['ordered_at']);

        self::assertSame([], $this->fetchAsOwnerForTenant(self::TENANT_A,
            'SELECT id FROM audit_log WHERE event_type = ?', [AuditEvent::PURCHASE_ORDER_PLACED]),
            'A refused approval wrote a trail row.');
    }

    /** @param array<string, mixed> $body */
    private function approve(string $orderId, array $body = []): void
    {
        $this->send('POST', '/purchase-orders/'.$orderId.'/approval', $body, [
            'Idempotency-Key' => Uuid::v4()->toRfc4122(),
        ]);
    }
}
