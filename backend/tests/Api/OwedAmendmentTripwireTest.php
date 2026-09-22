<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Platform\Security\PermissionGate;
use App\Tests\Support\GeneratedSpec;
use PHPUnit\Framework\Attributes\Test;

/**
 * An owner-ruled amendment that is recorded but NOT YET APPLIED is owed at the
 * moment the operation it governs is built — and until this class existed, the
 * only thing standing between that build and a permission set the ruling
 * forbids was a sentence in `docs/API.md` §15.1 that somebody had to read.
 *
 * **Why this class is a test and not a register entry.** ADR-008's own D2 rule
 * is that work stays owed *"ON THE SEEDER … not on a date, and not on someone
 * remembering"*, and `docs/RESIDUAL_GAPS.md` CD-2 was carried the same way —
 * its pointer lived in `WriteOwnershipTest`'s failure message, where the person
 * is already standing. *(⚠ That pointer sat on the cross-module ownership
 * assertion, which a correct Inventory writer never trips — measured by `RED`
 * and the spar on 2026-09-22 — and CD-2 then CLOSED whole with `DepleteStock`,
 * on `WriteOwnershipTest::everyStockMovementReasonIsRegisteredToItsWriterAndPermission`:
 * a pointer is only as good as the assertion it hangs on.)* This class applies
 * that pattern to ADR-008 **Amendment 2.1**. A prose reminder cannot fail; this can.
 *
 * **The domain is the GENERATED SPEC, deliberately, not the documents.** Every
 * doc-parsing test in this suite SKIPS where `docs/` is absent (it is gitignored
 * — ROADMAP ruling B), so a tripwire that read `docs/API.md` would be silent in
 * exactly the environment that has no human reading the row either. The spec is
 * built from the running application, so this check has the same force
 * everywhere.
 *
 * **What it does NOT claim.** It does not detect whether A2.1 has been applied —
 * nothing mechanical can, because §4's role table is prose in a gitignored
 * document and `SecurityModelDocument` parses §3 only. It detects the *trigger*
 * and refuses to let it pass silently. Clearing the tripwire is a deliberate
 * edit to {@see self::OPERATIONS_OWING_AN_UNAPPLIED_AMENDMENT}, made in the same
 * commit that applies the amendment — the `PINNED_ALLOWLIST` mechanism this
 * suite uses elsewhere.
 */
final class OwedAmendmentTripwireTest extends ApiTestCase
{
    /**
     * `METHOD path` (as {@see GeneratedSpec::operations()} keys them) => the
     * amendment that must be applied in the same commit that builds it.
     *
     * Paths are the ones `docs/API.md` §4.2 gives these rows; all three are
     * `unbuilt` in §15.1 at `e87d731`. **Keyed by path rather than operationId
     * because the path is what the spec exposes** — an operation renamed on its
     * way to being built would slip an operationId pin.
     */
    private const OPERATIONS_OWING_AN_UNAPPLIED_AMENDMENT = [
        'POST /api/v1/invoices/{id}/void' => 'VoidInvoice',
        'POST /api/v1/invoices/{id}/credit-notes' => 'IssueCreditNote',
        'POST /api/v1/fulfilments/{id}/returns' => 'CreateSalesReturn',
    ];

    /**
     * The positive control, and it is not decoration. **The first version of this
     * class was FAKE-GREEN**: it pinned the paths `docs/API.md` §4.2 prints
     * (`/invoices/{id}/void`) while the generated spec prefixes every path with
     * `/api/v1`, so the intersection was vacuously empty and a planted BUILT
     * operation did not turn it red. This is the empty-set trap this suite has
     * recorded before — an exhaustive check whose domain silently does not
     * overlap what it is checking.
     *
     * Held here so the prefix assumption cannot rot again: if the spec's path
     * shape moves, THIS fails, rather than the tripwire going quietly blind.
     */
    private const A_PATH_KNOWN_TO_BE_BUILT = 'POST /api/v1/purchase-orders';

    /**
     * `METHOD path` => the operation, for the sales line reads that owe V2-C-14(b)'s
     * decision.
     *
     * **⚠ CLEARED 2026-09-21, IN THE COMMIT THAT APPLIED THE DECISION.** It held
     * `'GET /api/v1/sales-orders/{id}/lines' => 'ListSalesOrderLines'`; that
     * operation shipped with `SalesOrderLineRow`'s `CASE` derivation and with
     * {@see self::REPLACEMENT_ASSERTION} driving it, which is step 2 of the failure
     * message below. Moving the entry is the deliberate act that says the decision
     * was applied — it was not moved to get green, and the diff that clears it is the
     * diff that adds the derivation and its test.
     *
     * Prefixed `/api/v1` for the measured reason recorded on the sibling constant.
     *
     * @var array<string, string>
     */
    private const OPERATIONS_OWING_THE_REMAINDER_DECISION = [];

    /**
     * The assertion that carries V2-C-14(b) now that the pin above is empty.
     *
     * It is named here, not merely mentioned in prose, so that
     * {@see self::noOperationOwingTheCancelledRemainderDecisionHasBeenBuilt} has
     * something left to fail on: delete or rename that test and this class goes red
     * naming it, rather than the obligation evaporating quietly.
     */
    private const REPLACEMENT_ASSERTION = [
        \App\Tests\Api\SalesOrderLineReadTest::class,
        'aCancelledOrdersLinesOweNothingAndALiveOrdersOweTheirRemainder',
    ];

    /**
     * **`operationId` => the pick read it may never ship without** (converted 2026-09-22, sales step 5 C3 —
     * it read `'CreateFulfilment' => 'the V2-S-1b pick read'` while the read was unbuilt). The map is an
     * implication now, not a pin to be emptied: an empty pin is the empty-set trap this class already records.
     *
     * *(History:)* `METHOD path` => the operation that owes **V2-S-1b**, the money-free pick read.
     *
     * §4.2 declares `CreateFulfilment` at `POST /sales-orders/{id}/fulfilments`;
     * the spec prefixes `/api/v1`, for the measured reason recorded on
     * {@see self::A_PATH_KNOWN_TO_BE_BUILT}.
     *
     * **Why only this row, when the carry is recorded on `ListSalesOrders` too.**
     * The owner re-anchored the gate on 2026-09-20 onto `CreateFulfilment`
     * alone, so `ListSalesOrders` no longer owes it and pinning that row would
     * redden on an operation the ruling released.
     *
     * @var array<string, string>
     */
    private const OPERATIONS_OWING_THE_PICK_READ = [
        'CreateFulfilment' => 'GetPickList',
    ];

    /** The pick read's gate: V2-S-1b clause 3, `fulfilment.view` ALONE, read from the registry (owner ruling R7). */
    private const THE_PICK_READS_PERMISSION = 'fulfilment.view';

    /**
     * The positive control for the pick-read pin, which is keyed by
     * **`operationId`** and not by path.
     *
     * **Why this replaced a nested-PATH control on 2026-09-20, hours after that
     * control was added.** The pin used to be the path
     * `POST /api/v1/sales-orders/{id}/fulfilments`, and that path was a
     * **guess** — nothing built it yet. Measured against the live spec the same
     * day, this codebase spells action sub-resources with a **singular** noun
     * (`.../{id}/cancellation` is `CancelSalesOrder`, `.../{id}/confirmation` is
     * `ConfirmSalesOrder`) and document creates with a **plural** one
     * (`.../{id}/goods-receipts` is `CreateGoodsReceipt`). `CreateFulfilment` is
     * a document create, so `fulfilments` was plausible — **and a coin flip.**
     * A pin that loses that flip does not fail; it **silently never fires**, and
     * the gate is gone with nothing to show for it.
     *
     * `operationId` has no such freedom: `API.md` §12.3 fixes it as
     * *Verb ++ Resource*, it **is** the §4.2 row name, and the generated spec
     * carries one on **all 26** built operations (measured 2026-09-20). Keying
     * on it removes the guess instead of documenting it.
     *
     * `CreateGoodsReceipt` is the structural twin — a built nested document
     * create — so if the spec ever stops exposing `operationId`, or spells it
     * some other way, this control fails **loudly** and the pin below is not
     * silently compared against an empty set.
     */
    private const AN_OPERATION_ID_KNOWN_TO_BE_BUILT = 'CreateGoodsReceipt';

    /**
     * `operationId` => what it owes: **V2-R-12b's order-level restore collisions**, which no restore of an ORDER
     * may ship without.
     *
     * Keyed by `operationId` for `1076ba3`'s reason (a path pin that guesses wrong never fires). Both order restores
     * are named, not only the sales one: the purchase twin has the same four uniques and the same reissue history.
     *
     * @var array<string, string>
     */
    private const OPERATIONS_OWING_THE_RESTORE_COLLISIONS = [
        'RestoreSalesOrder' => 'sales_order',
        'RestorePurchaseOrder' => 'purchase_order',
    ];

    /**
     * The restore-collision pin's positive control: a BUILT restore, so the spec is known to carry `Restore*`
     * operationIds the way the pin reads them. `RestoreSalesOrderLine` is the nearest twin — it owns and translates
     * the line-level half of the same hazard.
     */
    private const A_RESTORE_KNOWN_TO_BE_BUILT = 'RestoreSalesOrderLine';

    /**
     * ADR-008 Amendment 2.1 widens SoD-3's controlled half to
     * `invoice.issue` / `invoice.pay` **or** `invoice.void` **or**
     * `credit_note.issue`. It is owner-ruled (OI-2, 2026-09-14) and **unapplied**:
     * ADR-008 §2's SoD-3 row still reads `invoice.issue` / `invoice.pay` alone,
     * and `SECURITY_MODEL.md` §4.1 says so in terms — *"Nothing in this
     * subsection is in §3's catalogue or §4's role table yet, and that is
     * deliberate."*
     *
     * **Mutation proof (run 2026-09-18, Move 217) — and it caught a real defect
     * on the first attempt, which is why it is recorded rather than summarised.**
     * Planting `'POST /purchase-orders' => 'CreatePurchaseOrder'` — a BUILT
     * operation — left this **GREEN**, because the pin used `docs/API.md` §4.2's
     * unprefixed paths while the spec prefixes `/api/v1`. The check was comparing
     * disjoint sets. Fixed by prefixing the pin and adding
     * {@see self::A_PATH_KNOWN_TO_BE_BUILT} as a positive control; re-planting
     * `'POST /api/v1/purchase-orders'` then turned it **RED**, naming that path
     * and printing the whole message below, and removing it returned it to GREEN
     * on the three unbuilt rows. **A pin nobody has seen fail is not evidence,
     * and this one was not evidence until the second run.**
     */
    #[Test]
    public function noOperationOwingAnUnappliedAmendmentHasBeenBuilt(): void
    {
        $spec = GeneratedSpec::regenerate(self::$kernel ?? throw new \LogicException('The kernel is booted in setUp().'));
        $built = array_keys(GeneratedSpec::operations($spec));

        self::assertContains(self::A_PATH_KNOWN_TO_BE_BUILT, $built,
            'Positive control: the spec no longer spells built paths the way this pin does, so the '
            .'tripwire below would compare two disjoint sets and pass on everything. Re-derive the '
            .'path shape from the spec before trusting any result from this class.');

        $arrived = array_intersect_key(
            self::OPERATIONS_OWING_AN_UNAPPLIED_AMENDMENT,
            array_fill_keys($built, true),
        );

        self::assertSame([], $arrived, $this->pointer($arrived));
    }

    /**
     * **V2-C-14(b)'s decision, carried by a mechanism rather than by a sentence.**
     *
     * The carry read: *"`quantity_outstanding` reads 0 on a cancelled order, or a
     * `quantity_cancelled` column is added — one decision, taken by whichever of
     * `ListSalesOrderLines` and `CancelSalesOrder` is built first. Both rows carry
     * the trigger so neither can ship without meeting it."*
     *
     * **`CancelSalesOrder` shipped first (2026-09-19) and took it: `quantity_outstanding`
     * reads 0 on a cancelled order, as a DERIVATION, with no new column.** Ground:
     * OI-2 defines the cancelled remainder as *"derived and never written into
     * `quantity`"*, and a `quantity_cancelled` column would write it.
     *
     * **But taking the decision built nothing, which is exactly the shape this
     * project keeps getting caught by.** Nothing in `src/` computes
     * `quantity_outstanding` for a sales order — no sales line read is built — so
     * the decision had nowhere to live except prose. `PurchaseOrderLineRow:66`
     * computes the purchase analogue as a bare subtraction with no status clause,
     * so the obvious implementation of the sales one is the wrong one, and it would
     * pass every existing test.
     *
     * So the decision is pinned here instead, in the class whose whole charter is
     * *"an obligation that is recorded but not yet applied is owed at the moment the
     * operation it governs is built"*. It reddens when a sales line read appears in
     * the generated spec and prints the decision at the point of use.
     *
     * **Positive control:** the same {@see self::A_PATH_KNOWN_TO_BE_BUILT} assertion
     * the sibling test uses, for the same measured reason — the first version of
     * that pin compared disjoint sets and a planted built operation left it green.
     *
     * ---------------------------------------------------------------------------
     * ⚠ 2026-09-21 — THE DECISION HAS BEEN APPLIED, AND THIS PIN IS NOW EMPTY
     * ---------------------------------------------------------------------------
     * `ListSalesOrderLines` shipped with the derivation in
     * `SalesOrderLineRow::COLUMNS` — `CASE WHEN so.status = ? THEN 0::numeric(19,6)
     * ELSE sol.quantity - sol.quantity_fulfilled END`, the status **bound** from
     * `SalesOrder::STATUS_CANCELLED` and no `quantity_cancelled` column — and with
     * the assertion step 2 demands, a cancelled order's lines and a live order's
     * driven **in one test** so neither an always-zero `CASE` nor an empty result set
     * can pass. §15.1's note moved on both rows in the same commit.
     *
     * **So the intersection below is now vacuous, and that is a real cost, not
     * nothing.** `array_intersect_key([], …)` is `[]` for every input — the empty-set
     * trap this very class records having fallen into once. After this commit
     * V2-C-14(b) is carried by **one HTTP test against one operation**, and a second
     * consumer — `GetSalesOrderLine` (undeclared), `CreateFulfilment`'s line
     * projection, the V2-S-1b pick read — could reintroduce the bare subtraction with
     * nothing at the spec level to catch it. The constant's own former docblock said
     * the list read was the only declared consumer *"today"*.
     *
     * **Whether to delete this method and its constant outright, or keep an empty pin,
     * is the OWNER'S call and is NOT taken here.** `BE-LEAD` recommended deletion and
     * `RED` agreed with the recommendation while dissenting from its stated cost of
     * *"none"*; deleting a tripwire is irreversible, so it is reported rather than
     * done. Until it is ruled, the method keeps a job: it fails if the replacement
     * assertion is renamed or removed.
     */
    #[Test]
    public function noOperationOwingTheCancelledRemainderDecisionHasBeenBuilt(): void
    {
        $spec = GeneratedSpec::regenerate(self::$kernel ?? throw new \LogicException('The kernel is booted in setUp().'));
        $built = array_keys(GeneratedSpec::operations($spec));

        self::assertContains(self::A_PATH_KNOWN_TO_BE_BUILT, $built,
            'Positive control: the spec no longer spells built paths the way this pin does, so the check '
            .'below would compare two disjoint sets and pass on everything.');

        // The pin is empty since 2026-09-21, so the intersection below can no longer
        // fail. What can is this: the obligation moved to a named assertion, and if
        // that assertion goes, nothing in the suite carries V2-C-14(b) at all.
        [$replacementClass, $replacementMethod] = self::REPLACEMENT_ASSERTION;
        self::assertTrue(method_exists($replacementClass, $replacementMethod), \sprintf(
            "%s::%s no longer exists, and this pin is empty, so NOTHING now carries V2-C-14(b).\n\n"
            ."The decision: `sales_order_line.quantity_outstanding` reads 0 when the parent order's status\n"
            ."is `cancelled` - DERIVED in the read's SQL, with no `quantity_cancelled` column. It is applied\n"
            ."in `SalesOrderLineRow::COLUMNS`. Restore the assertion, or put the obligation back on the pin\n"
            .'above, before removing this.', $replacementClass, $replacementMethod));

        $arrived = array_intersect_key(self::OPERATIONS_OWING_THE_REMAINDER_DECISION, array_fill_keys($built, true));

        self::assertSame([], $arrived, \sprintf(
            "%s has been built, and V2-C-14(b)'s decision has NOT been applied.\n\n"
            ."THE DECISION, taken 2026-09-19 when `CancelSalesOrder` shipped first:\n"
            ."  `sales_order_line.quantity_outstanding` reads **0** when the parent order's status is\n"
            ."  `cancelled`. It is a DERIVATION in the read's SQL. There is NO `quantity_cancelled`\n"
            ."  column and none may be added to satisfy this — `API.md` §8.3's OI-2 block defines the\n"
            ."  cancelled remainder as \"derived and never written into `quantity`\", and a column would\n"
            ."  write it.\n\n"
            ."WHY THIS IS NOT THE PURCHASE ANALOGUE, which is the trap:\n"
            ."  `PurchaseOrderLineRow::COLUMNS` computes `quantity_ordered - quantity_received` with no\n"
            ."  status clause, because a cancelled purchase order's lines are not read for outstanding\n"
            ."  work. Copying that expression to sales ships the defect: a cancelled order's lines would\n"
            ."  keep reporting `quantity - quantity_fulfilled` as work owed, which F-5 step 4 pre-fills\n"
            ."  from and a reader would take as a commitment the tenant still has.\n\n"
            ."DO THIS IN THIS COMMIT, or move this pin deliberately and say why:\n"
            ."  1. Derive `quantity_outstanding` as 0 for a `cancelled` parent, in the read's SQL.\n"
            ."  2. Drive a cancelled order's lines through the read and assert 0 — with a live order in\n"
            ."     the same test as the positive control, or the assertion proves only that the query\n"
            ."     returns nothing.\n"
            ."  3. Clear this entry and §15.1's V2-C-14(b) note on BOTH rows.\n\n"
            ."Note `quantity_fulfilled` is written by nothing today (`RESIDUAL_GAPS.md` G-7), so a\n"
            ."cancelled order's lines currently report their FULL quantity as outstanding — the defect is\n"
            ."at its widest, not its narrowest.\n\n"
            ."Full entry: `docs/API.md` §15.1, rows `CancelSalesOrder` and `ListSalesOrderLines`.",
            implode(', ', $arrived),
        ));
    }

    /**
     * **V2-S-1b's gate, carried by a mechanism rather than by prose — which is
     * the whole reason this method exists.**
     *
     * ---------------------------------------------------------------------------
     * ⚖ CONVERTED 2026-09-22 (sales step 5, commit 3) — from a pin into an IMPLICATION
     * ---------------------------------------------------------------------------
     * `GetPickList` shipped one commit ahead of `CreateFulfilment`, so the pin was
     * **not emptied** — an empty pin holds over nothing, the trap this class records —
     * but turned into *"`CreateFulfilment` is never in the spec without `GetPickList`,
     * and `GetPickList` is gated on `fulfilment.view` alone"*. The consequent is
     * asserted **unconditionally** (it is shipped), and its permission is read from
     * the **registry** (`TransactionBoundaryTest::contractOperations()`), because the
     * spec carries none. V2-S-1b **ships in part** (owner ruling R8): clauses 1, 2, 3,
     * 6 and clause 5's backend half are met, clause 4 is re-carried to `SEC` and clause
     * 5's frontend half to `FE` + `UX` (see the sibling method), and the obligation is
     * not called discharged anywhere.
     *
     * **Mutations run for the conversion (2026-09-22):** see the progress note
     * `docs/session12/output9-sales-6-progress.md`, C3.
     *
     * *(What follows is the pin's history, kept.)*
     *
     * **The carry, recorded 2026-09-16 (V4 batch B8b), owner `BE-LEAD` + `SEC` +
     * `UX`:** the money-free pick read exists as its own resource, with an
     * additions row, gated on `fulfilment.view`, conferring no `sales_order`
     * table under PC-8, on IA's N-3/N-8 route.
     *
     * **⚖ RE-ANCHORED BY OWNER RULING, 2026-09-20.** The gate read *"before the
     * first sales read"* and was **crossed on 2026-09-19 when `GetSalesOrder`
     * shipped** — a sales read, gated on `sales_order.view` — unruled and
     * unrecorded, and it stood crossed for nine days because the only thing
     * holding it was a sentence in five documents. The owner moved the trigger
     * onto `CreateFulfilment`, which is reachable and scheduled.
     *
     * **It is a RESCHEDULE, NOT A DISCHARGE, and nothing here may be read as a
     * reduction.** The obligation is unchanged in scope. `SECURITY_MODEL.md`'s
     * *"until then no operation projects those fields"* stays true, which is why
     * the re-anchor costs nothing in exposure — and is also why this is not the
     * `SEC-O-1` shape, where a gate was keyed to a state that had been ruled
     * impossible. This trigger is reachable, scheduled and now mechanical.
     *
     * **Keyed by `operationId`, not by path (changed 2026-09-20, same day).**
     * The original pin was the path `POST /api/v1/sales-orders/{id}/fulfilments`,
     * which nothing has built and which was therefore a guess; measured against
     * the live spec, this codebase spells action sub-resources singular
     * (`cancellation`, `confirmation`) and document creates plural
     * (`goods-receipts`), so the guess was a coin flip whose losing side is
     * **silent**: a path pin that does not match simply never fires.
     * {@see self::AN_OPERATION_ID_KNOWN_TO_BE_BUILT} for the measurement.
     *
     * **What it did NOT claim, while it was a pin.** It could not detect whether the
     * pick read had been built — no resource for it existed to name. *(False from
     * 2026-09-22: the resource exists and the converted check names it.)*
     * It detects the *trigger* and refuses to let it pass silently. Clearing it
     * is a deliberate edit to {@see self::OPERATIONS_OWING_THE_PICK_READ}, made
     * in the same commit that builds the pick read — the `PINNED_ALLOWLIST`
     * mechanism this suite uses elsewhere, and the same residual the two sibling
     * methods carry.
     *
     * **Mutation proof (re-run 2026-09-20 against the `operationId` keying, not
     * carried over from the path version).** Planting `'CreateGoodsReceipt'` —
     * a BUILT operation — turns this **RED**, naming it and printing the whole
     * message below; removing it returns it to **GREEN**. The planted line was
     * grepped back out of the file before the run, because a green after a
     * mutation that never applied is this project's recorded trap. A pin nobody
     * has seen fail is not evidence — and a mutation proof written for a
     * previous keying is not evidence for this one.
     */
    #[Test]
    public function noOperationOwingThePickReadHasBeenBuilt(): void
    {
        $spec = GeneratedSpec::regenerate(self::$kernel ?? throw new \LogicException('The kernel is booted in setUp().'));

        // Keyed by `operationId`, never by path: the path this row will be built
        // at is a guess, and a pin that guesses wrong does not fail -- it stops
        // firing, silently. `operationId` is the §4.2 row name (§12.3).
        $built = [];
        foreach (GeneratedSpec::operations($spec) as $data) {
            $operationId = $data['operation']['operationId'] ?? null;
            if (\is_string($operationId) && '' !== $operationId) {
                $built[$operationId] = true;
            }
        }

        self::assertArrayHasKey(self::AN_OPERATION_ID_KNOWN_TO_BE_BUILT, $built,
            'Positive control: the generated spec no longer carries `operationId` the way this pin reads it, '
            .'so the check below would compare the pin against an empty or differently-spelled set and pass '
            .'on everything. Re-derive the key from the spec before trusting any result from this method.');

        // Anti-vacuity: the implication has an antecedent. Emptying the map is the
        // empty-set trap -- every implication over nothing holds.
        self::assertNotSame([], self::OPERATIONS_OWING_THE_PICK_READ,
            'The pick-read implication has no antecedent, so it asserts nothing. It was converted from a pin on '
            .'2026-09-22 precisely so it could never be cleared; restore CreateFulfilment => GetPickList.');

        // The permission is read from the REGISTRY: the spec carries none, and
        // reading §4.2 instead would SKIP wherever docs/ is absent.
        $registry = TransactionBoundaryTest::contractOperations();

        $defects = [];
        foreach (self::OPERATIONS_OWING_THE_PICK_READ as $operation => $pickRead) {
            // The consequent is shipped, so it is asserted whether or not the
            // antecedent is: a pick read that disappears is red today, not only on
            // the day CreateFulfilment ships.
            if (!isset($built[$pickRead])) {
                $defects[] = \sprintf('%s is not in the generated spec%s.', $pickRead,
                    isset($built[$operation]) ? ', and '.$operation.' IS' : '');
                continue;
            }
            $permission = isset($registry[$pickRead])
                ? ($registry[$pickRead]->getExtraProperties()[PermissionGate::OPERATION_PERMISSION] ?? null)
                : null;
            if (self::THE_PICK_READS_PERMISSION !== $permission) {
                $defects[] = \sprintf('%s is gated on %s, not on %s alone.', $pickRead, var_export($permission, true), self::THE_PICK_READS_PERMISSION);
            }
        }

        self::assertSame([], $defects, \sprintf(
            "V2-S-1b's pick read is missing or mis-gated:\n  %s\n\n"
            ."THE OBLIGATION (V2-S-1b, owner re-anchor 2026-09-20; shipped IN PART on 2026-09-22 by owner ruling R8 --\n"
            ."it is NOT closed, and nothing here may be read as a reduction):\n"
            ."  1. The money-free PICK READ exists as ITS OWN RESOURCE -- `GetPickList`, not a projection of a\n"
            ."     sales-order read and not a field set toggled by permission. Money-free is held by\n"
            ."     PickListReadTest::thePickReadCarriesNoMoneyColumn (server-derived).\n"
            ."  2. It has its own rows in `docs/API.md` 4.2 / 4.4.3 / 15.1.\n"
            ."  3. It is gated on `fulfilment.view` ALONE -- warehouse_operator holds no `sales_order.view` since\n"
            ."     the owner's cost-exposure ruling of 2026-09-15. THIS is what this method checks.\n"
            ."  4. It confers NO `sales_order` table under PC-8 -- RE-CARRIED to SEC on AiToolDtoTest's pin (the\n"
            ."     first class under src/Modules/Ai), not met by anything here.\n"
            ."  5. IA N-3/N-8: it lives on `/sales-orders/[id]/fulfil`. The BACKEND half is this read; the\n"
            ."     FRONTEND half + B1N-12's screen are RE-CARRIED to FE + UX on noFrontendShipsWithoutThePickRouteCarry.\n"
            ."  6. PC-9's SECOND DELIBERATE EXCEPTION names its columns -- held by\n"
            ."     PickListReadTest::pc9sSecondExceptionNamesExactlyTheColumnsThisReadProjects.\n\n"
            ."CreateFulfilment may never be in the spec without the pick read, gated as above. Do not clear the map\n"
            ."to get green: it is an implication, and an empty one holds over nothing.\n"
            ."Copies of V2-S-1b: derive them with `grep -rn V2-S-1b docs backend` -- never by a count written here\n"
            ."(this message said FIVE until 2026-09-22; there were ten in five files).\n"
            ."Full entry: `docs/API.md` 15.1, rows `GetPickList` and `CreateFulfilment`; rulings at\n"
            ."`docs/session12/v2s1b-reanchor-ruling.md` and `docs/session12/output9-sales-6-ruling.md` (R7, R8).",
            implode("\n  ", $defects),
        ));
    }

    /**
     * **V2-S-1b clause 5's FRONTEND half and B1N-12's screen, re-carried to `FE` + `UX` by owner ruling R8
     * (2026-09-22) — carried by a mechanism, keyed on the directory existing, not on a guessed path.**
     *
     * The backend half shipped with `GetPickList`. The route (`/sales-orders/[id]/fulfil`, IA N-8a) and the
     * screen that shows the resulting order status (B1N-12) belong to Output 6, and there is no frontend in
     * this repository (`ls` at the root: `backend docs infra`). This goes red the moment a `frontend/`
     * directory appears beside `backend/`, whatever it contains.
     *
     * **Deliberately NOT keyed on a frontend file path.** Guessing `frontend/src/app/sales-orders/…` would
     * repeat the coin-flip-path defect `1076ba3` removed: a guess that loses never fires. The cost is
     * coarseness — it fires on the first frontend commit, whatever that commit is — and that is the
     * owner-visible trade (design §4 C3, OQ-B).
     *
     * **Positive control:** `backend/` is resolved by the same path arithmetic, so a wrong root fails loudly
     * instead of reporting a `frontend/` that is simply looked for in the wrong place.
     */
    #[Test]
    public function noFrontendShipsWithoutThePickRouteCarry(): void
    {
        $root = \dirname(__DIR__, 3);

        self::assertDirectoryExists($root.'/backend',
            'Positive control: the repository root was not resolved (no backend/ beside where frontend/ is looked for), '
            .'so the check below would pass by looking in the wrong place.');

        self::assertDirectoryDoesNotExist($root.'/frontend',
            "A frontend/ directory exists, and V2-S-1b clause 5's FRONTEND half is owed with it (owner ruling R8, "
            ."2026-09-22; owner FE + UX).\n\n"
            ."  - The pick screen lives on `/sales-orders/[id]/fulfil` -- the action on the parent order (IA N-3/N-8,\n"
            ."    N-8a), not a standalone `/new` route -- and consumes `GET /api/v1/sales-orders/{id}/pick-list`.\n"
            ."  - B1N-12 (UX): after a CreateFulfilment 201 the screen shows the order's resulting status (re-read the\n"
            ."    pick list: `status` is on it), or FLOWS F-5 step 6 is rewritten.\n"
            ."  - docs/ux/FLOWS.md F-5 (UX's) still owes its open note and step 6 wording.\n"
            ."  - The pick read shows on-hand per candidate bin, never an available figure (owner ruling R1).\n"
            ."Do this in the commit that builds the screen, then move this test deliberately -- key it on the route's\n"
            ."real file once it exists. Until then V2-S-1b is shipped IN PART and must not be called discharged.");
    }

    /**
     * **V2-R-12b's second trigger — the ORDER restore — carried by a mechanism rather than by a handover.**
     *
     * Until 2026-09-21 this obligation was named in a migration docblock, §15.1 and a processor's docblock, and
     * **nothing in `backend/` went red about it**. `Version20260921000200` removed the same-order half at the database
     * (the cascade now revives only the lines archived with the order, the owner's Q3 ruling), and
     * `DeletionPolicyTest` proves that on both parents. What remains is what the trigger cannot see, and it is owed
     * by whichever commit ships an order restore. This method goes red the moment either one appears in the spec.
     *
     * **It detects the trigger, not the translations** — clearing the pin without building them defeats it, the
     * residual every sibling method here carries. Mutation proof (run 2026-09-21): planting the positive control's
     * own id, `RestoreSalesOrderLine`, into the pin turns this RED naming it; renaming the control to a non-existent
     * id turns it RED loudly; both restored byte-identical.
     */
    #[Test]
    public function noOperationOwingTheRestoreCollisionsHasBeenBuilt(): void
    {
        $spec = GeneratedSpec::regenerate(self::$kernel ?? throw new \LogicException('The kernel is booted in setUp().'));

        $built = [];
        foreach (GeneratedSpec::operations($spec) as $data) {
            $operationId = $data['operation']['operationId'] ?? null;
            if (\is_string($operationId) && '' !== $operationId) {
                $built[$operationId] = true;
            }
        }

        self::assertArrayHasKey(self::A_RESTORE_KNOWN_TO_BE_BUILT, $built,
            'Positive control: the generated spec does not carry a built restore\'s `operationId` the way this pin '
            .'reads it, so the check below would compare the pin against nothing and pass on everything.');

        $arrived = array_intersect_key(self::OPERATIONS_OWING_THE_RESTORE_COLLISIONS, $built);

        self::assertSame([], $arrived, \sprintf(
            "%s has been built. An ORDER restore owes V2-R-12b's collisions, IN THIS COMMIT.\n\n"
            ."ALREADY DONE AT THE DATABASE (do not redo): `Version20260921000200` revives only the lines archived\n"
            ."WITH the order (owner ruling Q3, 2026-09-21), so a replacement line on the SAME order cannot collide.\n"
            ."`DeletionPolicyTest::restoringAnOrderWhoseReplacementLineHoldsTheArchivedLinesNumberAndKeySucceeds`\n"
            ."proves it on both parents.\n\n"
            ."STILL OWED, each translated BY CONSTRAINT NAME on the restore path only, each with a test that reaches\n"
            ."its branch ALONE (sales slice 4's lesson: the unpredicted collision fired first):\n"
            ."  1. `<order>_tenant_idem_live` -- REACHABLE: a client that retries a create after the order was\n"
            ."     deleted gets a NEW order holding the same key (the key lookup is live-only). Refuse in domain\n"
            ."     terms: an order created since holds this order's retry key.\n"
            ."  2. `<order>_line_tenant_idem_live` -- REACHABLE ACROSS ORDERS: the line key is tenant-wide, so a key\n"
            ."     on an archived order's line may be reused on ANOTHER order's line, and the restore then collides\n"
            ."     inside the cascade trigger's statement (SQLSTATE 23505, the message still names the constraint).\n"
            ."  3. `<order>_tenant_number_live` -- UNREACHABLE in BOTH modules since the owner's Q4 ruling (sales,\n"
            ."     then extended to purchasing, 2026-09-21: a deleted order's number is never reissued): PROVE it\n"
            ."     with a test, do not translate it. ⚠ A PURCHASE order deleted BEFORE that fix may already share its\n"
            ."     number with a live one; the restore must refuse that case in domain terms, not 500.\n"
            ."  4. `<order>_line_unique` -- unreachable under the new cascade; prove it, do not translate it.\n\n"
            ."ALSO DUE WITH THE RESTORE: E9-3 (`theArchivedIdProblemIsWhyNo*RestoreShips`) must have gone green on\n"
            ."`archived` arriving; `RESIDUAL_GAPS.md` G-8 closes and G-9's `*.restored` event is owed to B-4a;\n"
            ."`RequestContractTest::archiveTheRowARestoreBinds()` must know the order's collection; and §8.3a --\n"
            ."under `sales_order_archived_only_while_draft` a restore needs no draft guard (the CHECK is the guard).\n\n"
            ."Then clear this entry deliberately. Record: `docs/session12/output9-sales-5-design.md` §5.",
            implode(', ', array_keys($arrived)),
        ));
    }

    /**
     * The pointer goes in the failure message, not in a list somebody has to
     * remember to read. Owner ruling 2026-09-18: the project's read-before list
     * is load-bearing *because* it is short, so a new obligation earns its place
     * at the point of use instead.
     *
     * @param array<string, string> $arrived
     */
    private function pointer(array $arrived): string
    {
        return \sprintf(
            "%s has been built, and ADR-008 Amendment 2.1 has NOT been applied.\n\n"
            ."A2.1 is OWNER-RULED (OI-2, 2026-09-14) and widens SoD-3's controlled half to\n"
            ."`invoice.issue` / `invoice.pay` OR `invoice.void` OR `credit_note.issue`. Applying it\n"
            ."takes `invoice.void` off `admin`. Until it is applied, whoever issues an invoice can\n"
            ."also cancel it, which is the separation this ruling exists to create.\n\n"
            ."DO THIS IN THIS COMMIT, or move this pin deliberately and say why:\n"
            ."  1. ADR-008 §2's SoD-3 row — widen the controlled half. A2.6 requires §3's new codes,\n"
            ."     §4's `admin` change, PC-13's pair table and A2.5's run IN THE SAME PASS.\n"
            ."  2. `SECURITY_MODEL.md` §4.1's *Becomes false when built* list — TEN sentences, not\n"
            ."     nine: `IA.md:425`'s L-9 reversal-register row was added 2026-09-18 and is owned by\n"
            ."     no other register.\n"
            ."  3. SEC-O-18 decides WHO may void once `admin` cannot. It is DEFERRED, its deadline is\n"
            ."     this build, and `admin` = 53 is invariant across both of its live outcomes — so its\n"
            ."     answer does not block §4's `admin` edit.\n"
            ."  4. Re-run `python3 docs/adr/sod_check.py` — but DO NOT paste its `admin` count as\n"
            ."     evidence: `ADMIN_EXCL` is typed, not derived, so that assertion validates a number\n"
            ."     the same edit chose. Its check-back is a SUBSET test and CANNOT see A2.1 entering\n"
            ."     §2. Prefer ADR-008 D2's committed SoD port (V2-R-2) over editing that constant.\n\n"
            ."Full entry: `docs/DELIVERY_LEDGER.md` risk register, row R-39 (scheduled Output 9 / M2, on this build).\n"
            ."Whether A2.1 may be applied in halves (A2.1a `invoice.void` / A2.1b `credit_note.issue`)\n"
            ."is the OWNER's call and was still open at Move 217 — do not split it unasked.",
            implode(', ', $arrived),
        );
    }
}
