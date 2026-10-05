<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Platform\Security\PermissionGate;
use App\Tests\Support\GeneratedSpec;
use App\Tests\Support\PhpSourceScan;
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
     * **`operationId` => the §4.2 path the row declares (for the reader; the
     * check compares keys only)** — the operations that owe an amendment that
     * must be applied in the same commit that builds them.
     *
     * ---------------------------------------------------------------------------
     * ⚖ RE-KEYED BY `operationId` 2026-09-23 (sales step 6, commit C1; design F-1)
     * ---------------------------------------------------------------------------
     * This map was keyed by `METHOD path`, and its docblock argued for that:
     * *"the path is what the spec exposes — an operation renamed on its way to
     * being built would slip an operationId pin."* **The argument was backwards,
     * and `1076ba3` had already measured why for the sibling pick-read pin.**
     * `API.md` §12.3 fixes the `operationId` as *Verb ++ Resource*, and it **is**
     * the §4.2 row name, so it cannot drift without the contract row moving with
     * it. A path is a spelling the builder chooses — and `/void` is the one
     * sub-resource §4.2 itself calls an exception — so a builder who wrote
     * `/invoices/{id}/voidance` would get a GREEN tripwire and A2.1 would ship
     * unapplied with nothing red. **A path pin that guesses wrong does not fail;
     * it stops firing, silently.** The positive control moved with the key: it is
     * {@see self::AN_OPERATION_ID_KNOWN_TO_BE_BUILT}, the one the sibling methods
     * already use, and the path control this map used to carry
     * (`'POST /api/v1/purchase-orders'`) is deleted rather than left, because a
     * control whose domain no longer overlaps what the check compares is the
     * exact hole it was written to prevent.
     *
     * **Mutations re-run against THIS keying (a proof written for the path keying
     * is not evidence for this one):** planting `CreateGoodsReceipt` into the map
     * → RED naming it; breaking the control to `CreateGoodsReceiptX` → RED,
     * loudly. Recorded in `docs/session13/output9-sales-7-progress.md`, C1.
     *
     * @var array<string, string>
     */
    private const OPERATIONS_OWING_AN_UNAPPLIED_AMENDMENT = [
        // `VoidInvoice` LEFT this map on 2026-09-29 (sales step 6, C7, 'Let an invoice issued in error be voided, and
        // take voiding off admin'), which built it AND applied A2.1a in the same commit — ADR-008 A2.7 (owner ruling
        // 2026-09-23, Q3: "Amend: land in halves"): `invoice.void` joined SoD-3's controlled half in ADR-008 §2 and
        // `SeparationOfDuty::ROWS`, `admin` lost it by PC-14's derivation (54 -> 53), SEC-O-18 closed, and the live-holder
        // listing was run and reported. This test was RED naming `VoidInvoice` until then (pasted in
        // `docs/session13/output9-sales-7-progress.md`, C7). The two entries left owe A2.1b and SoD-5 — see `pointer()`.
        // `IssueCreditNote` LEFT this map on 2026-10-01 (sales step 7 C1, 'Let finance credit a returned shipment, and
        // keep a credited invoice from being voided or paid'), which built it AND applied pointer items 1–3 in the same
        // commit: `credit_note.issue` entered SECURITY_MODEL.md §3, PermissionCatalogue and a `permission`-row migration
        // TOGETHER with its SoD-3 entry (A2.1b) and SoD-5's row in ADR-008 §2 and SeparationOfDuty::ROWS; `admin` does not
        // hold it (PC-14); `sod_check.py` edited and re-run (cover 5). Holder listing not owed (item 4: the row entered with
        // its SoD entry). This test was RED naming `IssueCreditNote` until then. **The map is now EMPTY**: the method keeps
        // its positive control and a planted-map control, so it is not vacuous (`INVOICE_CORRECTIONS_NOT_YET_BUILT`'s shape).
        // `CreateSalesReturn` LEFT this map on 2026-10-01 (sales step 7 R2, 'Let a customer's returned goods be taken
        // back, and come off the bill only when a second person records them'), which built it AND applied pointer item
        // 2 in the same commit: `sales_return.create` (and `sales_return.view`, owner ruling Q-B) entered
        // SECURITY_MODEL.md §3, PermissionCatalogue and a `permission`-row migration; `admin` holds it by PC-14's inverse
        // clause. SoD-5 did NOT enter: its second code, `credit_note.issue`, is absent, and A2.7 puts SoD-5 in whichever
        // lands SECOND -- IssueCreditNote, whose entry stays. This test was RED naming `CreateSalesReturn` until then.
    ];

    /**
     * `operationId` => its §4.2 path, for the sales line reads that owe V2-C-14(b)'s
     * decision. *(Keyed by `METHOD path` until 2026-09-23; re-keyed with the A2.1
     * map above so that no method in this class compares against a path. The map
     * was already empty, so no behaviour moved.)*
     *
     * **⚠ CLEARED 2026-09-21, IN THE COMMIT THAT APPLIED THE DECISION.** It held
     * `'GET /api/v1/sales-orders/{id}/lines' => 'ListSalesOrderLines'`; that
     * operation shipped with `SalesOrderLineRow`'s `CASE` derivation and with
     * {@see self::REPLACEMENT_ASSERTION} driving it, which is step 2 of the failure
     * message below. Moving the entry is the deliberate act that says the decision
     * was applied — it was not moved to get green, and the diff that clears it is the
     * diff that adds the derivation and its test.
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
     * the spec prefixes `/api/v1` (the history of the path control this class
     * carried until 2026-09-23 is on the A2.1 map's docblock).
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
     * The positive control for **every** pin in this class — each is keyed by
     * **`operationId`** and not by path (the A2.1 map and the remainder map
     * joined the pick-read pin on 2026-09-23).
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
     * may ship without — **and, since B-4a (roles slice 2 C1, 2026-09-29), the `<table>.restored` audit event**, filed
     * business by `SEC` and minted with its writer in the restore's commit. That obligation is carried in THIS map's
     * failure message rather than a second map on the same two keys (spar P9: a second copy of one set rots).
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
     * **`operationId` => the tests that carry Q1's returned-before-issue deduction, as refined by Q-L (a′)** (sales step
     * 6, C3, 2026-09-28; owner ruling Q1 *"What shipped"*; refined 2026-09-30, `output9-sales-8-ruling.md` Q-L).
     *
     * ⚖ **CONVERTED 2026-10-01 (sales step 7 R2) from a pin into an IMPLICATION — not emptied.** It read
     * `'CreateSalesReturn' => 'IssueInvoice bills shipped - returned-before-issue'` while the return was unbuilt, and
     * went red the moment it was built. The commit that built it applied all four owed items and turned the entry into
     * *"CreateSalesReturn is never in the spec without these named tests"*, as `REPLACEMENT_ASSERTION` did for
     * V2-C-14(b): an emptied pin would hold over nothing (`:646`'s own guard), and a deleted test would take the
     * obligation with it silently. Keyed by `operationId` for `1076ba3`'s reason.
     *
     * @var array<string, list<array{0: class-string, 1: string}>>
     */
    private const OPERATIONS_OWING_THE_RETURNED_DEDUCTION = [
        'CreateSalesReturn' => [
            [SalesReturnCreateTest::class, 'anInvoiceBillsWhatShippedLessOnlyTheReturnsThatDeduct'],
            [SalesReturnCreateTest::class, 'aShipmentWhollyReturnedBeforeBillingIsNotInvoicedAndAFreeOneStillIs'],
            [SalesReturnCreateTest::class, 'aShipmentAlreadyInvoicedCannotBeRecordedInError'],
            [PurchaseOrderRowLockTest::class, 'aReturnAndAnIssueOfOneShipmentSerialiseOnTheOrderLock'],
        ],
    ];

    /**
     * **`operationId` => what it owes: V2-R-17's enforcement and the invoice's credit fields** (sales step 6, C3).
     * The failure message is `SEC`'s text (`output9-sales-7-sec.md` §7.3), verbatim, plus item 6 as `SEC` signed it
     * on 2026-09-23 (§16).
     *
     * @var array<string, list<array{0: class-string, 1: string}>>
     */
    private const OPERATIONS_OWING_THE_INVOICE_CREDIT_FIELDS = [
        // ⚖ CONVERTED 2026-10-01 (sales step 7 C1) from a pin into an IMPLICATION — not emptied (the method's own guard,
        // design §9 RED M-1): it read `'IssueCreditNote' => 'V2-R-17 + V2-C-10 + V2-R-16 + A2.1b + V2-S-9'` and went red
        // the moment the credit note was built. The commit that built it applied all six owed items; the entry now names
        // the tests that carry them, so a removed carrier is red. Items 2 and 3 (V2-C-10, V2-R-16) were met early, at C0.
        'IssueCreditNote' => [
            [PurchaseOrderRowLockTest::class, 'aVoidAndACreditOfOneInvoiceRaceAndExactlyOneSucceeds'],      // 1. V2-R-17, both orders
            [CreditNoteIssueTest::class, 'creditingAnInvoiceToZeroMovesItToCreditedAndShutsPaymentAndVoid'], // 1, 3. V2-R-17 / V2-R-16
            [InvoiceCreditedSegmentTest::class, 'aPartlyCreditedInvoiceThatWasPaidReadsPaid'],             // 2. V2-C-10 (C0)
            [InvoiceCreditedSegmentTest::class, 'everyInvoiceIsInExactlyOneSegment'],                      // 3. V2-R-16 (C0)
            [CreditNoteIssueTest::class, 'eachLineIsAShippedLineTheReturnTookBack'],                       // 5. V2-S-9
            [CreditNoteIssueTest::class, 'moreCannotBeCreditedThanReturnedOrBilled'],                      // 5. V2-S-9
            [CreditNoteIssueTest::class, 'aCreditedInvoiceIsRefusedVoidingBeforeBPrimeIsAsked'],           // 6. B′ precedence
        ],
    ];

    /**
     * **The D-1 residual (design §19 C3; owner ruling Q6 *"Four chunks"*): `IssueInvoice` ships in chunk 2, before
     * the operations that correct an issued invoice.** While `IssueInvoice` is in the spec, each correction must be in
     * the spec too — or named here, against the `RESIDUAL_GAPS.md` row that carries its absence. Both directions are
     * held: a correction that is built but still listed here is red too, so its half of G-14 is closed in the same
     * commit that builds it. C7 empties the map and G-14 closes.
     *
     * @var array<string, string> correction operationId => the residual row carrying its absence
     */
    private const INVOICE_CORRECTIONS_NOT_YET_BUILT = [
        // `ReverseInvoicePayment` LEFT this map on 2026-09-28 (sales step 6, C6), which shipped it and closed G-14's
        // payment half in the same commit — this test was red until both were done, as its docblock says.
        // `VoidInvoice` LEFT it on 2026-09-29 (sales step 6, C7), which shipped it and closed G-14's void half — and so
        // G-14 — in the same commit. The map is now EMPTY: the implication holds literally, and the planted controls
        // below (literal maps, never this one) keep the check from being vacuous.
    ];

    /**
     * The built operations that touch invoices or credits, each a ruling that it cannot lower a credit or raise a total
     * ({@see self::noCreditOrInvoiceOperationShipsOffTheAllowList()}). Sales step 7 C0, 2026-10-01.
     */
    private const CREDIT_AND_INVOICE_OPERATIONS = [
        'IssueInvoice', 'GetInvoice', 'ListInvoices', 'PayInvoice', 'ReverseInvoicePayment', 'VoidInvoice', 'ListInvoiceLines',
        // Sales step 7 C1 (2026-10-01): a credit note only RAISES an invoice's credited sum (`credit_note_total_positive`),
        // and its read lowers nothing.
        'IssueCreditNote', 'GetCreditNote',
        // Sales step 7 C1b (2026-10-01): an adjustment is a `credit_note` row like any other — it only RAISES the
        // invoice's credited sum (`credit_note_total_positive`) and writes nothing to `invoice`. A ruling made by the
        // person moving this list (the pin cannot judge it): it cannot lower a credit or raise a total.
        'IssueAdjustmentCreditNote',
        // Sales step 7 C2 (2026-10-02): the credit-note register is a read — it writes nothing, so it can neither lower a
        // credit nor raise a total. A ruling made by the person moving this list.
        'ListCreditNotes',
    ];

    /**
     * **`operationId` => the obligation group it fires: the operations that WIDEN the double credit an adjustment allows**
     * (`RESIDUAL_GAPS.md` G-29 and G-30, ruled 2026-10-02; part 1 of their sequencing, owner: *"Go ahead with part 1"*).
     *
     * Neither gap is reachable by anyone but an owner today: `credit_note.adjust` is held by the owner alone among the
     * default roles, and `CreditNoteAdjustTest::aRoleWithoutCreditNoteAdjustIsRefused` goes red if a default role gains
     * it. What that test cannot see is a role a TENANT composes — so `CreateRole` / `UpdateRole` (G-28's trigger, the same
     * event, and G-29's and G-30's) are pinned here. `CreateStockAdjustment` is pinned because G-30 names its build commit
     * as a hard trigger that must test path (iii), and G-21's phantom-stock sentence names it as unbuilt.
     *
     * Keyed by `operationId`, never a path (`1076ba3`). **None of the three is built** — this is a PIN that goes red on
     * arrival, not an implication. Clearing an entry is a deliberate edit made in the commit that does what the failure
     * message lists; the residual every sibling method carries applies (clearing without doing it defeats the pin).
     * **Disclosed limit (G-29/G-30's own text):** a grant written by SQL (G-10's shape) composes a role with no operation,
     * and nothing here sees it.
     *
     * @var array<string, 'role composition'|'stock count'>
     */
    private const OPERATIONS_THAT_WIDEN_THE_DOUBLE_CREDIT = [
        'CreateRole' => 'role composition',
        'UpdateRole' => 'role composition',
        'CreateStockAdjustment' => 'stock count',
    ];

    /**
     * **What roles slice 3 hands forward, mechanised** (`docs/session13/roles-s3-design.md` §11; C1, 2026-10-04). E-15e's
     * proposals now exist, and five unbuilt operations owe something to them. Each key is an `operationId` (the pick-read
     * precedent, `1076ba3`); each value is the obligation the failure message carries — the design §11 table, condensed;
     * the design and `SECURITY_MODEL.md` §5.2 are the authority. **`GetUser` is not keyed: Q-1 was ruled "withdraw"** (its
     * §15.1 cell records it). Clearing an entry is a deliberate edit in the commit that does the work.
     *
     * @var array<string, string>
     */
    private const OPERATIONS_OWING_PROPOSAL_HANDLING = [
        'UpdateUser' => 'E-15g: deactivation lapses every pending proposal FOR the user (`subject_removed`) and BY the user '
            .'(`proposer_removed`), one `role.assignment_lapsed` each, under the role lock taken FIRST and unconditionally '
            .'(SECURITY_MODEL.md §5.2.5); plus `UPDATE (is_active)` on app_user with its caller (G-12). The lapse is a bulk '
            .'UPDATE that MUST carry `AND status = \'pending\'` — the decided-is-final trigger refuses any other (DATA §3.1).',
        'DeleteUser' => 'E-15g, the same, for `deleted_at`; and GrantFootprintTest\'s app_user deleted_at probe moves (C0).',
        'DeleteRole' => 'E-15h: refuse while any `status = \'pending\'` row exists on the role (stale ones included — fails '
            .'closed), naming the count, read via `role_grant_proposal_pending_by_role`. OPEN, for SEC at this build: the refusal '
            .'may not reveal pending existence beyond role.assign (PC-16 condition 1) and DeleteRole is role.delete — serve the '
            .'count only to a role.assign holder, or refuse without it; decide how a role.delete-only seat is told what to do. '
            .'Every such proposal must be clearable by someone the tenant still has — re-check Q-5 (a) before building.',
        'CreateBulkRoleAssignment' => 'Bulk never proposes (refused wholesale before any member id is read, keeping E-14\'s '
            .'no-oracle property); an owner\'s bulk counts a member with a pending same-pair proposal as NOT applied (R-21).',
        'UpdateRole' => 'E-15f: pending proposals on the role are not written to (C14 refuses their confirmation as '
            .'`role_changed`); a role that LOSES its X(admin) codes leaves pending proposals non-owners now meet at AssignRole\'s '
            .'gap B (`proposal_pending`, design §5 change 2, RED L-8); G-10 arm D is owed in the same commit.',
    ];

    /** The corrections an issued invoice owes (OI-2: *"nothing issued is edited"*). */
    private const THE_INVOICE_CORRECTIONS = ['ReverseInvoicePayment', 'VoidInvoice'];

    /**
     * ADR-008 Amendment 2.1 widens SoD-3's controlled half to
     * `invoice.issue` / `invoice.pay` **or** `invoice.void` **or**
     * `credit_note.issue`. It is owner-ruled (OI-2, 2026-09-14) and, since the
     * owner's 2026-09-23 ruling (ADR-008 A2.7), lands in two halves. **A2.1a
     * (`invoice.void`) was applied on 2026-09-29 with `VoidInvoice`** (sales step
     * 6, C7), which left the map; **A2.1b (`credit_note.issue`) is unapplied**,
     * and this method stays red-on-arrival for `IssueCreditNote` and
     * `CreateSalesReturn`. *(Until 2026-09-29 this paragraph said the whole of
     * A2.1 was unapplied and ADR-008 §2's SoD-3 row read the pair alone.)*
     *
     * **Mutation proof (run 2026-09-18, Move 217) — and it caught a real defect
     * on the first attempt, which is why it is recorded rather than summarised.**
     * Planting `'POST /purchase-orders' => 'CreatePurchaseOrder'` — a BUILT
     * operation — left this **GREEN**, because the pin used `docs/API.md` §4.2's
     * unprefixed paths while the spec prefixes `/api/v1`. The check was comparing
     * disjoint sets. Fixed then by prefixing the pin and adding a path positive
     * control; re-planting `'POST /api/v1/purchase-orders'` then turned it
     * **RED**. **Superseded 2026-09-23 by the `operationId` keying** (see the
     * map's docblock): the path control is gone, and both mutations were re-run
     * against the new key. **A pin nobody has seen fail is not evidence, and a
     * proof written for a previous keying is not evidence for this one.**
     */
    #[Test]
    public function noOperationOwingAnUnappliedAmendmentHasBeenBuilt(): void
    {
        $built = $this->builtOperationIds();

        self::assertArrayHasKey(self::AN_OPERATION_ID_KNOWN_TO_BE_BUILT, $built,
            'Positive control: the generated spec no longer carries `operationId` the way this pin reads it, '
            .'so the tripwire below would compare the pin against an empty or differently-spelled set and pass '
            .'on everything. Re-derive the key from the spec before trusting any result from this class.');

        $arrived = array_intersect_key(self::OPERATIONS_OWING_AN_UNAPPLIED_AMENDMENT, $built);

        self::assertSame([], $arrived, $this->pointer($arrived));

        // Planted-map control (sales step 7 C1 emptied the map): the same intersection over a literal map naming a BUILT
        // operation must report it, or the check above holds over nothing.
        self::assertSame(['IssueCreditNote' => 'x'], array_intersect_key(['IssueCreditNote' => 'x', 'NoSuchOperation' => 'y'], $built),
            'A planted owing operation that IS built was not reported: the intersection is blind.');
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
     * **Positive control:** the same {@see self::AN_OPERATION_ID_KNOWN_TO_BE_BUILT}
     * assertion the sibling tests use, for the same measured reason — the first
     * version of the A2.1 pin compared disjoint sets and a planted built operation
     * left it green.
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
        $built = $this->builtOperationIds();

        self::assertArrayHasKey(self::AN_OPERATION_ID_KNOWN_TO_BE_BUILT, $built,
            'Positive control: the generated spec no longer carries `operationId` the way this pin reads it, so '
            .'the check below would compare the pin against nothing and pass on everything.');

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

        $arrived = array_intersect_key(self::OPERATIONS_OWING_THE_REMAINDER_DECISION, $built);

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
            implode(', ', array_keys($arrived)),
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
            ."`archived` arriving; `RESIDUAL_GAPS.md` G-8 closes;\n"
            ."THE AUDIT EVENT: mint `<table>.restored` with its writer IN THIS COMMIT -- BUSINESS, subject the order,\n"
            ."`{}`, no IP (`SEC` 2026-09-29, `docs/session13/roles-s2-sec.md` §3; already filed in `SECURITY_MODEL.md`\n"
            ."§4.1, so no new ruling is owed). `<table>.deleted` shipped with B-4a (roles slice 2 C1); a restore without\n"
            ."its event re-opens G-9's asymmetry. Both restores mint together if both ship together;\n"
            ."`RequestContractTest::archiveTheRowARestoreBinds()` must know the order's collection; and §8.3a --\n"
            ."under `sales_order_archived_only_while_draft` a restore needs no draft guard (the CHECK is the guard).\n\n"
            ."Then clear this entry deliberately. Record: `docs/session12/output9-sales-5-design.md` §5.",
            implode(', ', array_keys($arrived)),
        ));
    }

    /**
     * **Q1's deduction, carried by a mechanism** (design §19 C3) — **an implication since 2026-10-01** (sales step 7 R2):
     * while `CreateSalesReturn` is in the spec, every test named in {@see self::OPERATIONS_OWING_THE_RETURNED_DEDUCTION}
     * must exist. Until that commit it was a pin that went red the moment the return was built, printing the four owed
     * items; that commit applied them. It detects a removed or renamed carrier, not a weakened one.
     */
    #[Test]
    public function noOperationOwingTheReturnedDeductionHasBeenBuilt(): void
    {
        $built = $this->builtOperationIds();

        self::assertArrayHasKey(self::AN_OPERATION_ID_KNOWN_TO_BE_BUILT, $built,
            'Positive control: the generated spec no longer carries `operationId` the way this pin reads it.');
        self::assertNotSame([], self::OPERATIONS_OWING_THE_RETURNED_DEDUCTION, 'The returned-deduction implication has no antecedent, so it asserts nothing.');

        $missing = [];
        foreach (self::OPERATIONS_OWING_THE_RETURNED_DEDUCTION as $operation => $carriers) {
            if (!isset($built[$operation])) {
                continue;
            }
            foreach ($carriers as [$class, $method]) {
                if (!method_exists($class, $method)) {
                    $missing[] = $operation.' is built, and '.$class.'::'.$method.' no longer exists';
                }
            }
        }

        self::assertSame([], $missing, \sprintf(
            "%s\n\nIssueInvoice's amount rule owes Q1's deduction as refined, and these tests carry it:\n\n"
            ."  1. Q1 (owner ruling 2026-09-23, \"What shipped\") AS REFINED BY Q-L (a') (2026-09-30, docs/session13/\n"
            ."     output9-sales-8-ruling.md; appended to output9-sales-7-ruling.md): IssueInvoice bills what shipped, less\n"
            ."     returns recorded before issue that were classified as deducting (`sales_return.deducts`: recorded by a\n"
            ."     `fulfilment.create` holder who did not record the shipment, or by an owner); every other return reduces\n"
            ."     the invoice through a credit note (IssueCreditNote) -- never by editing the invoice (OI-2).\n"
            ."  2. A return `recorded_in_error` against a shipment that already has a LIVE invoice is refused.\n"
            ."  3. THE LOCK REMEDY (ARCH 6): CreateSalesReturn and IssueInvoice both take SalesOrderRowLock FIRST. No grant\n"
            ."     on `fulfilment` is added for this.\n"
            ."  4. API.md 8.3's sentence on what an invoice bills is true of the code.\n\n"
            ."Restore the test, or move its obligation to another named test here, before removing it.\n"
            ."Record: docs/session13/output9-sales-8-design.md 4.3; docs/session13/output9-sales-8-build-progress.md R2.",
            implode("\n", $missing),
        ));
    }

    /**
     * **V2-R-17 and the invoice's credit fields, carried by a mechanism** (design §19 C3; `SEC` §7.3 and §16).
     * Red the moment `IssueCreditNote` is in the spec.
     */
    #[Test]
    public function noOperationOwingTheInvoiceCreditFieldsHasBeenBuilt(): void
    {
        $built = $this->builtOperationIds();

        self::assertArrayHasKey(self::AN_OPERATION_ID_KNOWN_TO_BE_BUILT, $built,
            'Positive control: the generated spec no longer carries `operationId` the way this pin reads it.');
        self::assertNotSame([], self::OPERATIONS_OWING_THE_INVOICE_CREDIT_FIELDS, 'The credit-fields implication has no antecedent, so it asserts nothing.');

        $missing = [];
        foreach (self::OPERATIONS_OWING_THE_INVOICE_CREDIT_FIELDS as $operation => $carriers) {
            if (!isset($built[$operation])) {
                continue;
            }
            foreach ($carriers as [$class, $method]) {
                if (!method_exists($class, $method)) {
                    $missing[] = $operation.' is built, and '.$class.'::'.$method.' no longer exists';
                }
            }
        }

        self::assertSame([], $missing, \sprintf(
            "%s\n\nIssueCreditNote owes, and these tests carry (SEC 7.3 and 16, applied 2026-10-01, sales step 7 C1):\n"
            ."  1. V2-R-17 (ruled text 2026-09-23, SEC + PO, owner-approved 2026-09-23, PO co-signed 2026-09-29): \"An invoice\n"
            ."     against which any credit note has been issued cannot be voided\" -- VoidInvoice refuses it\n"
            ."     (`invoice.is_not_credited`) under InvoiceRowLock; IssueCreditNote takes InvoiceRowLock first and refuses a\n"
            ."     void invoice; the void || credit race in BOTH orders.\n"
            ."  2. V2-C-10: amount_credited / amount_due on invoice reads.\n"
            ."  3. V2-R-16: a fully credited invoice leaves collections (the Credited segment, TB-4 3).\n"
            ."  4. ADR-008 A2.1b: applied in the same commit (see the R-39 map).\n"
            ."  5. V2-S-9: credit-note lines belong to the invoice's fulfilment; credited <= returned - already credited.\n"
            ."  6. B' precedence: status guard, then V2-R-17, then B' -- no one is exempt from V2-R-17.\n"
            ."  N-1's carry (PO, v2r17-po-cosign.md 4): V2-R-17's refusal tells the user to issue a further credit note;\n"
            ."  confirm that route exists for every credited invoice, or return the message to the owner. Discharged by\n"
            ."  owner ruling Q-A = B' (IssueAdjustmentCreditNote, C1b: a non-return credit, owner-held).\n\n"
            ."Restore the test, or move its obligation to another named test here, before removing it.",
            implode("\n", $missing),
        ));
    }

    /**
     * **The D-1 residual: an issued invoice cannot yet be corrected, and that cannot be forgotten** (design §19 C3).
     * While `IssueInvoice` is in the spec, every correction in {@see self::THE_INVOICE_CORRECTIONS} is built or is
     * named in {@see self::INVOICE_CORRECTIONS_NOT_YET_BUILT} against its `RESIDUAL_GAPS.md` row; and a correction
     * that IS built may not stay listed. So C6 turns this red until the payment half is closed, and C7 until the
     * void half is — the implication holds literally only once the map is empty.
     *
     * **Planted controls, same test:** the check is a pure function of (built set, pending map); run with
     * `ReverseInvoicePayment` both built and pending → red naming it; run with `VoidInvoice` neither built nor pending
     * → red naming it.
     */
    #[Test]
    public function anIssuedInvoiceCannotShipWithoutItsCorrectionsOrTheirResidualRow(): void
    {
        $built = $this->builtOperationIds();

        self::assertArrayHasKey(self::AN_OPERATION_ID_KNOWN_TO_BE_BUILT, $built,
            'Positive control: the generated spec no longer carries `operationId` the way this pin reads it.');

        self::assertSame([], self::invoiceCorrectionDefects($built, self::INVOICE_CORRECTIONS_NOT_YET_BUILT), \sprintf(
            "IssueInvoice is built, and the operations that correct an issued invoice are not all accounted for:\n  %s\n\n"
            ."OI-2: nothing issued is edited -- an issued invoice is corrected only by ReverseInvoicePayment (a recorded payment)\n"
            ."and VoidInvoice (the invoice). Until both ship, RESIDUAL_GAPS.md G-14 carries the gap (owner ruling Q6, four\n"
            ."chunks; triggers: the first non-demo tenant, any deployment off a laptop, hosted credentials).\n"
            ."When a correction ships: remove it from INVOICE_CORRECTIONS_NOT_YET_BUILT and close its half of G-14 IN THE SAME\n"
            ."COMMIT. Never list a correction here to get green without its residual row.",
            implode("\n  ", self::invoiceCorrectionDefects($built, self::INVOICE_CORRECTIONS_NOT_YET_BUILT)),
        ));

        // Planted controls: the function is not vacuous in either direction.
        // The pending map is planted as a literal (not `INVOICE_CORRECTIONS_NOT_YET_BUILT`) since C6 emptied that map of
        // `ReverseInvoicePayment`: a control that reads the live map would stop controlling anything the day it empties.
        $plantBuilt = $built + ['IssueInvoice' => true, 'ReverseInvoicePayment' => true];
        self::assertSame(['ReverseInvoicePayment is built and still listed as not built: close its half of the residual.'],
            self::invoiceCorrectionDefects(array_diff_key($plantBuilt, ['VoidInvoice' => true]), ['ReverseInvoicePayment' => 'x', 'VoidInvoice' => 'y']),
            'A built correction still listed as pending did not fail the check.');
        self::assertSame(['VoidInvoice is neither built nor named against a residual row.'],
            self::invoiceCorrectionDefects(['IssueInvoice' => true], ['ReverseInvoicePayment' => 'x']),
            'An unbuilt correction with no residual row did not fail the check.');
    }

    /**
     * @param array<string, true>   $built
     * @param array<string, string> $pending
     *
     * @return list<string>
     */
    private static function invoiceCorrectionDefects(array $built, array $pending): array
    {
        if (!isset($built['IssueInvoice'])) {
            return [];
        }

        $defects = [];
        foreach (self::THE_INVOICE_CORRECTIONS as $correction) {
            $isBuilt = isset($built[$correction]);
            $isPending = isset($pending[$correction]);
            if ($isBuilt && $isPending) {
                $defects[] = $correction.' is built and still listed as not built: close its half of the residual.';
            } elseif (!$isBuilt && !$isPending) {
                $defects[] = $correction.' is neither built nor named against a residual row.';
            }
        }

        return $defects;
    }

    /**
     * **The `operationId` allow-list behind TB-4's Credited exception** (sales step 7 C0; `output9-sales-8-c0-amendments.md`
     * A6-b, the BACKSTOP to `CreditedSegmentLapseTest`'s live-grant pin). Every built operation whose `operationId`
     * contains `Invoice`, `Credit` or `DebitNote` must be named here. The substrings cover every verb — `Revoke`,
     * `Reject`, `Change` included — the line resources, `AdjustmentCreditNote`, a reversal shaped as a new document
     * (`IssueCreditNoteReversal`, `IssueDebitNote`) and a total-raising `UpdateInvoice` / `UpdateInvoiceLine`, without
     * guessing names (the earlier seven-name list missed most of them, amendments RED H-1). Keyed by `operationId`, never
     * a path (`1076ba3`).
     *
     * **Adding a name is a ruling** that the operation cannot lower a credit or raise a total — the pin cannot judge
     * that; only the person moving it can. Additions, each in its own commit, as a deliberate move: C1
     * `IssueCreditNote`, `GetCreditNote`; C1b `IssueAdjustmentCreditNote`; C2 `ListCreditNotes`.
     *
     * Written as a pure function with **planted controls in the same test** (`anIssuedInvoiceCannotShipWithout…`'s
     * precedent): {@see self::AN_OPERATION_ID_KNOWN_TO_BE_BUILT} proves only that the spec is read, not that the filter
     * matches anything (amendments RED-2 M-2).
     */
    #[Test]
    public function noCreditOrInvoiceOperationShipsOffTheAllowList(): void
    {
        $built = $this->builtOperationIds();
        self::assertArrayHasKey(self::AN_OPERATION_ID_KNOWN_TO_BE_BUILT, $built,
            'Positive control: the generated spec no longer carries `operationId` the way this pin reads it.');

        // Planted controls: a literal built set with the real allow-list reports exactly the three unlisted matches.
        self::assertSame(['RevokeCreditNote', 'UpdateInvoice', 'IssueDebitNote'],
            self::unlistedCreditOrInvoiceOperations(['RevokeCreditNote', 'UpdateInvoice', 'IssueDebitNote', 'CreateGoodsReceipt', 'GetInvoice'], self::CREDIT_AND_INVOICE_OPERATIONS),
            'The allow-list filter does not report an unlisted credit or invoice operation, or reports one it should not.');
        // No allow-list entry is dead: each matches the substring filter itself.
        self::assertSame([], array_values(array_filter(self::CREDIT_AND_INVOICE_OPERATIONS,
            static fn (string $name): bool => [] === self::unlistedCreditOrInvoiceOperations([$name], []))),
            'An allow-list entry does not match the substring filter, so it can never be needed and hides nothing.');

        self::assertSame([], self::unlistedCreditOrInvoiceOperations(array_keys($built), self::CREDIT_AND_INVOICE_OPERATIONS),
            \App\Tests\Support\CreditedSegment::LAPSE."\nA built operation touching invoices or credits is not on the allow-list. If it can lower a "
            .'credit or raise an invoice total, Credited is no longer monotonic: re-rule TB-4 (UX) first. If it cannot, add it to '
            .'CREDIT_AND_INVOICE_OPERATIONS in its own commit, as a ruling, never to get green.');
    }

    /**
     * @param list<string> $built
     * @param list<string> $allowed
     *
     * @return list<string> the built operations matching `Invoice`, `Credit` or `DebitNote` that are not allowed
     */
    private static function unlistedCreditOrInvoiceOperations(array $built, array $allowed): array
    {
        return array_values(array_filter($built, static fn (string $operation): bool => 1 === preg_match('/Invoice|Credit|DebitNote/', $operation)
            && !\in_array($operation, $allowed, true)));
    }

    /**
     * **G-28, G-29 and G-30's composition trigger, and G-30 (iii)'s stock-count trigger, carried by a mechanism**
     * (2026-10-02). Red the moment any key of {@see self::OPERATIONS_THAT_WIDEN_THE_DOUBLE_CREDIT} is in the generated
     * spec, printing what that commit owes. Until this method, G-29 and G-30 said *"the composition trigger has no
     * tripwire yet"*.
     *
     * **Controls, same test:** the positive control every sibling uses; a non-empty map; and the intersection over a
     * literal map naming a BUILT operation must report it. **Mutations (run 2026-10-02, pasted in
     * `docs/session13/g29-g30-part1-progress.md`):** each of the three keys planted into the built set in turn → red
     * naming it with its own group's text; restored byte-identical.
     */
    #[Test]
    public function noOperationThatWidensTheDoubleCreditHasBeenBuilt(): void
    {
        $built = $this->builtOperationIds();

        self::assertArrayHasKey(self::AN_OPERATION_ID_KNOWN_TO_BE_BUILT, $built,
            'Positive control: the generated spec no longer carries `operationId` the way this pin reads it.');
        self::assertNotSame([], self::OPERATIONS_THAT_WIDEN_THE_DOUBLE_CREDIT,
            'The double-credit pin is empty, so it holds over nothing. Its entries leave only with the work their message lists.');
        self::assertSame(['IssueAdjustmentCreditNote' => 'x'],
            array_intersect_key(['IssueAdjustmentCreditNote' => 'x', 'NoSuchOperation' => 'y'], $built),
            'A planted owing operation that IS built was not reported: the intersection is blind.');

        $arrived = array_intersect_key(self::OPERATIONS_THAT_WIDEN_THE_DOUBLE_CREDIT, $built);

        self::assertSame([], $arrived, self::doubleCreditPointer($arrived));
    }

    /**
     * **Roles slice 3's hand-forward, carried by a mechanism** (C1, 2026-10-04; design §11). Red the moment any key of
     * {@see self::OPERATIONS_OWING_PROPOSAL_HANDLING} is in the generated spec, printing each arrived operation's
     * obligation. **Controls, same test:** the positive control every sibling uses; a non-empty map; and the intersection
     * over a literal map naming a BUILT operation (`CreateRoleGrantProposal`) must report it. Mutation: plant a built
     * `operationId` as a key → red naming it.
     */
    #[Test]
    public function noOperationOwingProposalHandlingHasBeenBuilt(): void
    {
        $built = $this->builtOperationIds();

        self::assertArrayHasKey(self::AN_OPERATION_ID_KNOWN_TO_BE_BUILT, $built,
            'Positive control: the generated spec no longer carries `operationId` the way this pin reads it.');
        self::assertNotSame([], self::OPERATIONS_OWING_PROPOSAL_HANDLING,
            'The proposal-handling map is empty, so it holds over nothing. Its entries leave only with the work they list.');
        self::assertSame(['CreateRoleGrantProposal' => 'x'],
            array_intersect_key(['CreateRoleGrantProposal' => 'x', 'NoSuchOperation' => 'y'], $built),
            'A planted owing operation that IS built was not reported: the intersection is blind.');

        $arrived = array_intersect_key(self::OPERATIONS_OWING_PROPOSAL_HANDLING, $built);

        self::assertSame([], $arrived, \sprintf(
            "%s has been built, and E-15e's proposals (roles slice 3) are owed handling by it:\n\n%s\n\n"
            ."DO THE WORK IN THIS COMMIT, then clear the entry deliberately. Do not hand-edit this pin to green. "
            ."Record: docs/session13/roles-s3-design.md §11; SECURITY_MODEL.md §5.2.",
            implode(', ', array_keys($arrived)),
            implode("\n\n", array_map(static fn (string $id, string $owed): string => $id.': '.$owed, array_keys($arrived), $arrived)),
        ));
    }

    /**
     * The failure message: one block per obligation group that arrived. The text is `RESIDUAL_GAPS.md` G-28/G-29/G-30's
     * (and G-21's), condensed; the register is the authority.
     *
     * @param array<string, string> $arrived
     */
    private static function doubleCreditPointer(array $arrived): string
    {
        $blocks = [];
        if (\in_array('role composition', $arrived, true)) {
            $blocks[] = "ROLE COMPOSITION (CreateRole / UpdateRole) -- a hard trigger of G-28, G-29 AND G-30, whichever comes first.\n"
                ."Today `credit_note.adjust` is the owner's alone among the default roles; a composed role can hand it to a\n"
                ."delegate, and delegated adjusters make G-30's double credit more frequent.\n"
                ."  G-28 (Medium): PC-12's margin warning and its check are unbuilt. Build PC-12 clause 6's derived check\n"
                ."    (sources from Perm cells and read shapes, PC-12 clause 2 and PC-17) and the role picker's warning with\n"
                ."    its data (SEC + BE-LEAD + UX copy). V2-S-1c and B1N-11 are re-homed there. PC-17 is re-put to the owner\n"
                ."    at this trigger. (PermissionListTest's CreateRole/UpdateRole pin separately owes `sod_rows`, V2-C-5.)\n"
                ."  G-29 (Low) + G-30 (Medium) share ONE identity design (BE-LEAD + PO + UX, then a fresh RED). It must:\n"
                ."    (a) make the person holding the paperwork decide when a return line falls on a shipped line that\n"
                ."        already carries adjustment credits (PayInvoice's `acknowledged_return_ids` precedent; a new\n"
                ."        accepted field, so a 4.2 `Accepts`-cell edit);\n"
                ."    (b) RECORD that decision -- a link from the return line to the adjustment line that covered it -- so a\n"
                ."        return whose units were already credited leaves BOTH `?creditable=true` and the blocked-by-payment\n"
                ."        queue; state whether linked units are served as `quantity_credited` or apart. An acknowledgement\n"
                ."        alone is not enough: it records nothing when finance correctly declines;\n"
                ."    (c) settle G-29's lock question: whatever reads returns on the adjust path takes SalesOrderRowLock before\n"
                ."        InvoiceRowLock (as PayInvoice does), or restates CreditNoteWriter's \"only GROWS a bound\" premise;\n"
                ."    (d) rewrite, never delete, the current-behaviour pins: CreditNoteAdjustTest::\n"
                ."        anAdjustmentIsBoundByWhatWasBilledAndShrinksWhatAReturnCanBeCredited (G-29) and\n"
                ."        aReturnOfAdjustedUnitsIsCreditedAgainToday (G-30).\n"
                ."  A grant written by SQL (G-10's shape) is not detected by this pin.";
        }
        if (\in_array('stock count', $arrived, true)) {
            $blocks[] = "STOCK COUNT (CreateStockAdjustment) -- a hard trigger of G-30, and the operation G-21 names as unbuilt.\n"
                ."  G-30 path (iii): `short_shipped` units that were never packed and are found on the shelf. Today no count\n"
                ."    operation exists and, after invoicing, `recorded_in_error` is refused, so the only built sales document\n"
                ."    that puts them back in stock is a return under another reason -- and that return is CREDITABLE, so the\n"
                ."    units are credited twice. G-30's closing work: found units come back through F-6a's count adjustment,\n"
                ."    without a return. THIS BUILD COMMIT MUST TEST PATH (iii).\n"
                ."  G-21's phantom stock: every mistaken return posts stock that no built operation corrects (G-21 says\n"
                ."    `CreateStockAdjustment` is unbuilt, E-25) -- that sentence becomes false in this commit; re-word it. G-21's\n"
                ."    closing work is ruled reversal documents (SEC + BE-LEAD + PO); a count correction does not close it.\n"
                ."  Also owed here: WriteOwnershipTest's E-25 (a) pin (the first 'adjustment' writer).";
        }

        return \sprintf("%s has been built, and it widens the double credit an adjustment allows (RESIDUAL_GAPS.md G-29, G-30).\n\n%s\n\n"
            ."DO THE WORK ABOVE IN THIS COMMIT (or bring the trigger to the owner first), then clear the entry deliberately.\n"
            ."Do not hand-edit this pin to green. Record: docs/RESIDUAL_GAPS.md G-28, G-29, G-30, G-21;\n"
            ."docs/session13/l4-judge.md J-1, l4-final-red.md, l5-final-red.md.",
            implode(', ', array_keys($arrived)), implode("\n\n", $blocks));
    }

    /**
     * **`RESIDUAL_GAPS.md` G-18 (design §19 C4, R-C) — nothing stores `overdue`, because overdue is worked out when
     * shown** (owner ruling Q2, 2026-09-23). `Invoice::markOverdue()` and the stored value `overdue` are KEPT — the value
     * stays in `invoice_status_values` and in `invoice_tenant_due_idx`'s predicate — but nothing may reach them until the
     * owner rules G-18's closing option. A caller that stored `overdue` would split one fact in two: the wire derives
     * `overdue` from `due_at` on the database clock, and a stored copy written by a schedule would disagree with it.
     *
     * **The scan:** every `src/` file through `PhpSourceScan` (comments and docblocks are never literals, so prose
     * naming `markOverdue()` — this file's own, `InvoiceViews`' — is invisible): no method may CALL `markOverdue`, and no
     * string literal may write `overdue` into `invoice` (an `UPDATE`/`INSERT` naming `invoice` with `'overdue'`).
     * **Disclosed limit:** a write that binds `Invoice::STATUS_OVERDUE` as a parameter carries no literal and is not seen;
     * the call half catches the ORM route.
     * **Planted control, same test:** a scratch source with one call and one SQL literal must produce two violations.
     */
    #[Test]
    public function nothingStoresOverdueBecauseOverdueIsWorkedOutWhenShown(): void
    {
        $sources = [];
        foreach (PhpSourceScan::phpFilesUnder(\dirname(__DIR__, 2).'/src') as $file) {
            $sources[$file] = (string) file_get_contents($file);
        }
        self::assertGreaterThan(100, \count($sources), 'The scan found almost no source under src/, so it is blind.');

        self::assertSame([], self::overdueWriters($sources), \sprintf(
            "Something in src/ now stores `overdue` on an invoice:\n  %s\n\n"
            ."Owner ruling Q2 (2026-09-23): overdue is WORKED OUT WHEN SHOWN -- `status` reads `overdue` when an `issued`\n"
            ."invoice's `due_at` has passed on the database clock (InvoiceReader), and nothing stores it. There is no job\n"
            ."runner in this project. RESIDUAL_GAPS.md G-18 carries `markOverdue()` and the stored value; its triggers are\n"
            ."a job runner of any kind (cron + bin/console included) and the first reader of STORED `invoice.status` outside\n"
            ."the wire derivation. Closing options: (a) delete `markOverdue()` and drop `overdue` from the CHECK and the index\n"
            ."predicate, or (b) keep it and make the stored value agree with the derivation under a ruled schedule.\n"
            ."Rule G-18 first; then move this test deliberately.",
            implode("\n  ", self::overdueWriters($sources)),
        ));

        $plant = "<?php\nnamespace App\\Modules\\Nowhere;\nfinal class Nightly {\n"
            ."    public function run(object \$invoice, object \$c): void {\n"
            ."        \$invoice->markOverdue();\n"
            ."        \$c->executeStatement(\"UPDATE invoice SET status = 'overdue' WHERE due_at < now()\");\n"
            ."    }\n}\n";
        self::assertCount(2, self::overdueWriters(['plant.php' => $plant]),
            'A planted markOverdue() call and a planted SQL write of overdue did not both fail the scan, so it is not a check.');
    }

    /**
     * @param array<string, string> $sources path => contents
     *
     * @return list<string>
     */
    private static function overdueWriters(array $sources): array
    {
        $found = [];
        foreach ($sources as $path => $source) {
            foreach (PhpSourceScan::scan($source)['methods'] as $key => $method) {
                if (\in_array('markOverdue', $method['calls'], true)) {
                    $found[] = basename((string) $path).' '.$key.' calls markOverdue()';
                }
                foreach ($method['literals'] as $literal) {
                    $value = (string) $literal['value'];
                    if (1 === preg_match('/\b(UPDATE|INSERT\s+INTO)\s+invoice\b/i', $value) && str_contains($value, "'overdue'")) {
                        $found[] = basename((string) $path).' '.$key.' writes \'overdue\' in SQL';
                    }
                }
            }
        }

        return $found;
    }

    /**
     * The `operationId`s the generated spec carries, as a set. Every pin in this
     * class is keyed by `operationId` (§12.3: it is the §4.2 row name), never by a
     * path a builder chooses.
     *
     * @return array<string, true>
     */
    private function builtOperationIds(): array
    {
        $spec = GeneratedSpec::regenerate(self::$kernel ?? throw new \LogicException('The kernel is booted in setUp().'));

        $built = [];
        foreach (GeneratedSpec::operations($spec) as $data) {
            $operationId = $data['operation']['operationId'] ?? null;
            if (\is_string($operationId) && '' !== $operationId) {
                $built[$operationId] = true;
            }
        }

        return $built;
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
        // Replaced WHOLE on 2026-09-29 by sales step 6 C7 with `SEC`'s text (`output9-sales-7-sec.md` §6.3(e), owner-
        // approved with A2.7 on 2026-09-23), written for the world after A2.1a: it is shared by every entry left in the
        // map (`IssueCreditNote`, `CreateSalesReturn`). A commit cannot contain its own hash, so C7 is cited by its dated
        // subject. The text it replaced asked for A2.1 whole and said "do not split it unasked" — the owner split it.
        return \sprintf(
            "%s has been built. ADR-008 A2.1 was applied in HALF by the commit 'Let an invoice issued in error be voided,\n"
            ."and take voiding off admin' of 2026-09-29 (A2.1a: invoice.void joined SoD-3's controlled half, `admin` 54 -> 53,\n"
            ."SEC-O-18 closed). The rest is owed by the operation(s) named above, IN THIS COMMIT (ADR-008 A2.7, owner ruling\n"
            ."2026-09-23):\n\n"
            ."  1. APPLIED 2026-10-01 by IssueCreditNote's commit (A2.1b): `credit_note.issue` entered SECURITY_MODEL.md §3,\n"
            ."     PermissionCatalogue, a `permission`-row migration, ADR-008 §2's SoD-3 row and SeparationOfDuty::ROWS together.\n"
            ."  2. APPLIED 2026-10-01 by CreateSalesReturn's commit (`sales_return.create`) and IssueCreditNote's (SoD-5 entered\n"
            ."     §2 and SeparationOfDuty::ROWS with its second code).\n"
            ."  3. Re-derive, never type: SeparationOfDutyTest's pins; `python3 docs/adr/sod_check.py` (it DERIVES `admin` and\n"
            ."     checks the SoD rows by EQUALITY since 'Let an invoice issued in error be voided, and take voiding off admin';\n"
            ."     paste its output). Expected after A2.1b and SoD-5: cover 5.\n"
            ."  4. Holders: only if `credit_note.issue`'s `permission` row pre-existed its SoD-3 entry, list every LIVE role,\n"
            ."     system or not, conferring it (excluding roles conferring tenant_settings.edit), per tenant, with a planted\n"
            ."     control; system roles -> data migration, composed roles -> the owner rules revoke or stand.\n"
            ."  5. V2-R-17 and V2-C-10 are owed by IssueCreditNote too -- see OPERATIONS_OWING_THE_INVOICE_CREDIT_FIELDS.\n\n"
            ."Full entry: DELIVERY_LEDGER.md R-39. Do not hand-edit this pin to green.",
            implode(', ', array_keys($arrived)),
        );
    }
}
