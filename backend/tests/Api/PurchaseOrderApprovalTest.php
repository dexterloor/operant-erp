<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Modules\Purchasing\Application\PurchaseOrderApproval;
use App\Modules\Purchasing\Infrastructure\Entity\PurchaseOrder;
use App\Platform\Domain\IllegalTransition;
use App\SharedKernel\ValueObject\AuditEvent;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Uid\Uuid;

/**
 * **E-19 / DSoD-1, and §8.1's five-step order.**
 *
 * §8.1 names five checks by name and this file is those five plus the audit row
 * F-2 step 8 requires:
 *
 *   `theCreatorCannotApproveItsOwnOrder`
 *   `anOwnerIsExemptByName`
 *   `aNullCreatorDoesNotRefuse`
 *   `theRefusalIsTheBusinessRuleProblemType`
 *   `everyHttpCreatedOrderRecordsItsCreator`   ← **NOT HERE. See below.**
 *
 * ⚠ **The fifth is `CreatePurchaseOrder`'s and that operation is not in this
 * package**, so it is not written here as a test that would pass over a fixture.
 * §8.1's clause is *"an HTTP-created `purchase_order` has a NULL
 * `created_by_user_id`"*, and there is no HTTP-created purchase order in the tree
 * — a test asserting it would be asserting something about
 * `PurchaseOrderTestCase::seedOrder()`, which sets the column because the test
 * told it to. **A check that can only pass is not a check.** It is reported as
 * owed by `CreatePurchaseOrder` rather than written green.
 *
 * ---------------------------------------------------------------------------
 * The `owner` exemption is pinned as a literal here
 * ---------------------------------------------------------------------------
 * `PurchaseOrderApproval::OWNER_ROLE_NAME` is the production constant and
 * `PINNED_OWNER_ROLE` below is an independent copy, on
 * `AuditWriterTest::PINNED_IP_BEARING_EVENTS`' reasoning: a test that granted
 * `PurchaseOrderApproval::OWNER_ROLE_NAME` and then asserted the exemption fired
 * would compare the constant to itself and stay green if somebody renamed the
 * seat to `admin`.
 */
final class PurchaseOrderApprovalTest extends PurchaseOrderTestCase
{
    /** `SECURITY_MODEL.md` §4's first role, spelled independently of the code under test. */
    private const PINNED_OWNER_ROLE = 'owner';

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
        $draft = $this->seedDraft(self::TENANT_A, 'PO-1', $buyer);

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
        $draft = $this->seedDraft(self::TENANT_A, 'PO-1', (string) $this->actorId);

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
     * **`owner` is exempt by name**, and by *name* rather than by permission set:
     * on a one-person demo tenant `owner` raises the order and is the only seat
     * that can place it. `ADR-008` A1.1 refuses the permission-set reading in
     * terms — *"`purchasing_manager` is not exempt"* — and `purchasing_manager`
     * holds `.approve` too, so a code test would exempt it as well.
     *
     * *Mutation, run:* `holdsOwnerRole()` changed to `return false;`. Red here
     * and green everywhere else, which is what makes this the exemption's only
     * subject.
     */
    #[Test]
    public function anOwnerIsExemptByName(): void
    {
        $draft = $this->seedDraft(self::TENANT_A, 'PO-1', (string) $this->actorId);
        $this->grantRoleNamed(self::TENANT_A, (string) $this->actorId, self::PINNED_OWNER_ROLE);

        $this->approve($draft['order']);

        self::assertSame(200, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        self::assertSame(PurchaseOrder::STATUS_ORDERED, $this->json()['status']);
    }

    /**
     * The other half of the exemption, and the one that makes it *by name*: a
     * seat that is not `owner` gets no exemption however much it holds. The role
     * granted here carries the same permission codes the rig's default principal
     * already has, so the only thing that differs from the test above is the
     * **name**.
     */
    #[Test]
    public function aSeatThatIsNotNamedOwnerIsNotExempt(): void
    {
        $draft = $this->seedDraft(self::TENANT_A, 'PO-1', (string) $this->actorId);
        $this->grantRoleNamed(self::TENANT_A, (string) $this->actorId, 'purchasing_manager');

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
        $draft = $this->seedDraft(self::TENANT_A, 'PO-1');

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
        $draft = $this->seedDraft(self::TENANT_A, 'PO-1', (string) $this->actorId);

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
        $draftInA = $this->seedDraft(self::TENANT_A, 'PO-1', (string) $this->actorId);
        $orderInA = $draftInA['order'];

        $inB = $this->seedPrincipal(self::TENANT_B, []);
        $supplierB = $this->seedSupplier(self::TENANT_B, 'Other');
        $warehouseB = $this->seedWarehouse(self::TENANT_B, 'Other');
        $orderInB = $this->seedOrder(self::TENANT_B, 'PO-B', $supplierB, $warehouseB, $inB);

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
        $draft = $this->seedDraft(self::TENANT_A, 'PO-1');

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
        $order = $this->seedOrder(self::TENANT_A, 'PO-1', $supplier, $warehouse, archived: true);

        $this->approve($order);

        self::assertSame(409, $this->httpStatus());
        self::assertSame('purchase_order.is_archived', $this->json()['rule']);
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
        $draft = $this->seedDraft(self::TENANT_A, 'PO-1', $buyer);

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
        $draft = $this->seedDraft(self::TENANT_A, 'PO-1', (string) $this->actorId);

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
        $order = $this->seedOrder(self::TENANT_A, 'PO-1', $supplier, $warehouse, expectedAt: '2026-03-01T00:00:00+00:00');

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

        $set = $this->seedOrder(self::TENANT_A, 'PO-1', $supplier, $warehouse, expectedAt: '2026-03-01T00:00:00+00:00');
        $this->approve($set, ['expected_at' => '2026-04-02T00:00:00+00:00']);
        self::assertSame(200, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        self::assertSame('2026-04-02T00:00:00.000+00:00', $this->json()['expected_at']);

        $cleared = $this->seedOrder(self::TENANT_A, 'PO-2', $supplier, $warehouse, expectedAt: '2026-03-01T00:00:00+00:00');
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
        $draft = $this->seedDraft(self::TENANT_A, 'PO-1');

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
        $draft = $this->seedDraft(self::TENANT_A, 'PO-1');

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
        $draft = $this->seedDraft(self::TENANT_A, 'PO-1');

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
        $orderInB = $this->seedOrder(self::TENANT_B, 'PO-B', $supplierB, $warehouseB, $inB);

        $this->approve($orderInB);
        $foreign = (string) $this->client()->getResponse()->getContent();
        self::assertSame(404, $this->httpStatus());

        $this->approve(Uuid::v4()->toRfc4122());
        self::assertSame(404, $this->httpStatus());
        self::assertSame($foreign, (string) $this->client()->getResponse()->getContent(),
            'ST-5: another tenant\'s row and a row that never existed must be the same refusal.');
    }

    /**
     * The production constant and the pin above are compared once, so a rename of
     * the seat is a red test rather than a silently disarmed exemption.
     */
    #[Test]
    public function theExemptSeatIsTheOnePinnedHere(): void
    {
        self::assertSame(self::PINNED_OWNER_ROLE, PurchaseOrderApproval::OWNER_ROLE_NAME);
    }

    /** @param array<string, mixed> $body */
    private function approve(string $orderId, array $body = []): void
    {
        $this->send('POST', '/purchase-orders/'.$orderId.'/approval', $body, [
            'Idempotency-Key' => Uuid::v4()->toRfc4122(),
        ]);
    }
}
