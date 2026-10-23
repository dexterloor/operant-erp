<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Platform\Security\PermissionRequirement;
use App\Tests\Support\GeneratedSpec;
use App\Tests\Support\PhpSourceScan;
use App\Tests\Support\ResidualGapsDocument;
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

    // *(The pin `OPERATIONS_OWING_THE_RESTORE_COLLISIONS` — `operationId => table`, V2-R-12b's order-level restore
    // collisions plus `<table>.restored`, red the moment an order restore entered the spec — was DELETED at order restores
    // C3 (2026-10-22, final RED L-3) once both order restores had shipped with every owed item; its method
    // `noOperationOwingTheRestoreCollisionsHasBeenBuilt` went with it. The obligations are carried by the implication
    // below, whose carriers are the named tests; it is never emptied.)*

    /**
     * **`operationId` => the tests that carry V2-R-12b's order-restore collisions and the restore's other owed items, once
     * built** (order restores C2, 2026-10-21; `order-restores-design.md` R1.6, final RED L-3/L-6). The house implication
     * (`OPERATIONS_OWING_THE_RETURNED_DEDUCTION`'s shape): while the restore is in the spec, every carrier must exist. A
     * pin emptied would hold over nothing, and a deleted carrier would take its obligation with it silently. It detects a
     * removed or renamed carrier, not a weakened one. `RestorePurchaseOrder` joined at C3 (2026-10-22), and this map is never emptied.
     *
     * @var array<string, list<array{0: class-string, 1: string}>>
     */
    private const OPERATIONS_CARRYING_THE_RESTORE_COLLISIONS = [
        'RestoreSalesOrder' => [
            // 1. the order key — reachable, translated, reached alone.
            [SalesOrderRestoreTest::class, 'anOrderWhoseRetryKeyALiveOrderNowHoldsIsRefusedOnTheOrderKey'],
            // 2. the line key across orders — reachable, raised in the trigger (T-1), reached alone.
            [SalesOrderRestoreTest::class, 'anOrderWhoseLineKeyALineOnAnotherOrderNowHoldsIsRefusedOnTheLineKey'],
            // 3. the order number — unreachable since Q4, NOT translated; the proof.
            [SalesOrderRestoreTest::class, 'aDeletedOrdersNumberIsNeverReissuedSoItsRestoreCannotCollideOnIt'],
            // 4. the line number — unreachable through the API, MAPPED anyway (R1.2), owner-seeded.
            [SalesOrderRestoreTest::class, 'twoDeletedLinesSharingANumberAreRefusedOnTheLineNumber'],
            // Already done at the database (Q3, `Version20260921000200`), on both parents.
            [\App\Tests\Tenancy\DeletionPolicyTest::class, 'restoringAnOrderWhoseReplacementLineHoldsTheArchivedLinesNumberAndKeySucceeds'],
            // Q3 through the API, and the total-equals-live-lines assertion (R1.3: no recompute).
            [SalesOrderRestoreTest::class, 'aDeletedDraftComesBackWithTheLinesDeletedWithItAndATotalThatIsTheirSum'],
            // The audit event, minted in the commit.
            [SalesOrderRestoreTest::class, 'aRestoreWritesOneRestoredRowAndARepeatIsRefusedWithoutASecond'],
            // C5's invariant and API-O-34's, and the refusal order (R1.4, final RED M-1).
            [SalesOrderRestoreTest::class, 'aDraftIsNotRestoredOntoAnArchivedCustomer'],
            [SalesOrderRestoreTest::class, 'aDraftIsNotRestoredOntoAnArchivedWarehouse'],
            [SalesOrderRestoreTest::class, 'whenBothParentsAreArchivedTheWarehouseIsNamed'],
            // The parent locks, delete first (R1.7 R-1d / R-2d).
            [SalesOrderRestoreTest::class, 'aRestoreRacingADeleteOfItsCustomerWaitsAndIsRefused'],
            [SalesOrderRestoreTest::class, 'aRestoreRacingADeleteOfItsWarehouseWaitsAndIsRefused'],
            // E9-3's twin, bidirectional.
            [SalesOrderCreateTest::class, 'theArchivedIdProblemIsWhyNoOrderRestoreShips'],
        ],
        // Order restores C3 (2026-10-22): the purchase twin, moved from the deleted pin with its carriers (R1.6).
        'RestorePurchaseOrder' => [
            // 1. the order key — reachable for keyed orders, translated, reached alone.
            [PurchaseOrderRestoreTest::class, 'anOrderWhoseRetryKeyALiveOrderNowHoldsIsRefusedOnTheOrderKey'],
            // 2. the line key across orders — reachable, raised in the trigger, reached alone.
            [PurchaseOrderRestoreTest::class, 'anOrderWhoseLineKeyALineOnAnotherOrderNowHoldsIsRefusedOnTheLineKey'],
            // 3. the order number — LEGACY-reachable (pre-Q4), translated, owner-seeded.
            [PurchaseOrderRestoreTest::class, 'aLegacyOrderWhoseNumberALiveOrderWasGivenIsRefusedOnTheNumber'],
            // 4. the line number — LEGACY-reachable (pre-`3292c4a` re-stamp, RED H-1), translated, fixture (a).
            [PurchaseOrderRestoreTest::class, 'legacyFixtureA_twoRevivedLinesSharingANumberAreRefusedOnTheLineNumber'],
            // 5. the total — recomputed (R1.3), fixture (b); an overflow is a 409, never a 500, fixture (c).
            [PurchaseOrderRestoreTest::class, 'legacyFixtureB_aRevivedLegacyLineIsCountedInTheRecomputedTotal'],
            [PurchaseOrderRestoreTest::class, 'legacyFixtureC_revivedLinesWhoseSumOverflowsAreRefusedNotA500'],
            // Already done at the database (Q3, `Version20260921000200`), on both parents.
            [\App\Tests\Tenancy\DeletionPolicyTest::class, 'restoringAnOrderWhoseReplacementLineHoldsTheArchivedLinesNumberAndKeySucceeds'],
            // Q3 through the API, and the total-equals-live-lines assertion.
            [PurchaseOrderRestoreTest::class, 'aDeletedDraftComesBackWithTheLinesDeletedWithItAndATotalThatIsTheirSum'],
            // The audit event, minted in the commit.
            [PurchaseOrderRestoreTest::class, 'aRestoreWritesOneRestoredRowAndARepeatIsRefusedWithoutASecond'],
            // C5's invariant and API-O-34's, and the refusal order (warehouse first; the spar, binding).
            [PurchaseOrderRestoreTest::class, 'aDraftIsNotRestoredOntoAnArchivedSupplier'],
            [PurchaseOrderRestoreTest::class, 'aDraftIsNotRestoredOntoAnArchivedWarehouse'],
            [PurchaseOrderRestoreTest::class, 'whenBothParentsAreArchivedTheWarehouseIsNamed'],
            // The parent locks, delete first (R1.7 R-1d / R-2d).
            [PurchaseOrderRestoreTest::class, 'aRestoreRacingADeleteOfItsSupplierWaitsAndIsRefused'],
            [PurchaseOrderRestoreTest::class, 'aRestoreRacingADeleteOfItsWarehouseWaitsAndIsRefused'],
            // E9-3's twin, bidirectional, with its `status` control (C3).
            [PurchaseOrderOperationContractTest::class, 'theArchivedIdProblemIsWhyNoRestoreShips'],
        ],
    ];

    /**
     * The order-restore implication's positive control (the deleted pin's, until C3): a BUILT restore, so the spec is
     * known to carry `Restore*` operationIds the way the implication reads them. `RestoreSalesOrderLine` is the nearest twin — it owns and translates
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
     * **What roles slice 3 hands forward, mechanised** (`docs/session13/roles-s3-design.md` §11; C1, 2026-10-04). E-15e's
     * proposals now exist, and five operations (unbuilt then) owed something to them — `UpdateRole` (C2) and `DeleteRole` (C3)
     * have left, three remain. Each key is an `operationId` (the pick-read
     * precedent, `1076ba3`); each value is the obligation the failure message carries — the design §11 table, condensed;
     * the design and `SECURITY_MODEL.md` §5.2 are the authority. **`GetUser` is not keyed: Q-1 was ruled "withdraw"** (its
     * §15.1 cell records it). Clearing an entry is a deliberate edit in the commit that does the work.
     *
     * @var array<string, string>
     */
    /**
     * ⚖ **G-35's IMPLICATION** (audit reads C1, 2026-10-21; `docs/session13/audit-reads-owner-q-ruling.md` §2.6 / §5 C1, judge
     * ruling under the owner's delegation; final RED L-6) — **converted, key by key, from the pin map
     * `OPERATIONS_OWING_THE_G35_RERATING`, never emptied by hand** (the {@see self::OPERATIONS_OWING_THE_SUPPLIER_TRAIL_DECISION}
     * precedent). That map was keyed on the two audit reads (customers slice 1 C2, judge FINAL item 10: the customer trail
     * attributed a payment-terms change that only owner SQL could read):
     *  - `'ListAuditLogEntries' => 'the whole-trail read'` went red when C1 built it; C1 re-rated G-35 **Low** on three
     *    conditions, the first of which is that the trail serves every payment-terms value set, filterable by `event_type`;
     *  - `'ListAuditRecords' => 'the record-history read … must resolve customer through PC-9 (customer.view), never
     *    "{subject_type}.view"'` went red when **C2** built it (2026-10-21) and converted here: its carrier is
     *    `RecordHistorySubjectsTest::aCustomerAndItsChildrenAreReadOnCustomerView` (final RED M-1). With both keys converted
     *    the pin map was empty, so it was removed with its pin half rather than left asserting over nothing.
     * Now: neither read is in the spec without its carrier test ({@see self::noAuditReadShipsWithoutReRatingG35()}) — and
     * `RESIDUAL_GAPS.md` G-35 still carries the Low ruling line ({@see self::g35CarriesItsLowRulingWhileTheTrailIsReadable()}).
     * Removing the trail's carrier returns G-35 to Medium; removing the History's unpins the PC-9 clause the pin carried.
     *
     * **The supplier twin is not mirrored** (G-19 decision, ruling §2.5): the CUSTOMER trail records the terms VALUE since
     * C0a; the supplier trail still records names only (`SupplierWriteTest::theUpdatedRowCarriesNamesNeverValues`, below),
     * because supplier terms are credit the tenant receives — a long one favours the tenant.
     *
     * @var array<string, list<array{0: class-string, 1: string}>>
     */
    private const OPERATIONS_CARRYING_THE_G35_LOW_RATING = [
        'ListAuditLogEntries' => [
            [AuditLogReadTest::class, 'theTrailShowsEveryPaymentTermsValueSet'],
        ],
        // Audit reads C2: converted from the pin's last key (its PC-9 clause).
        'ListAuditRecords' => [
            [RecordHistorySubjectsTest::class, 'aCustomerAndItsChildrenAreReadOnCustomerView'],
        ],
    ];

    /** The line G-35 must carry while {@see self::OPERATIONS_CARRYING_THE_G35_LOW_RATING} holds (ruling §2.6, verbatim). */
    private const G35_LOW_RULING_LINE = '**Low** (`SEC`, judge ruling 2026-10-20';

    /**
     * **The supplier twin of G-35's trail, framed as a decision** (customers slice 1 C2, 2026-10-09; judge C-5, C-7).
     * `UpdateSupplier` accepts the same five fields as `UpdateCustomer`; G-19's asymmetry rule says the two edits must not
     * diverge silently. Keyed by `operationId`.
     *
     * ⚖ **CONVERTED 2026-10-12 (suppliers slice 1 C2) from a pin into an IMPLICATION — not emptied** (the
     * {@see self::OPERATIONS_OWING_THE_RETURNED_DEDUCTION} precedent). It read `'UpdateSupplier' => 'mint supplier.updated
     * … or record a twin gap'` and went red the moment `UpdateSupplier` was built; that commit took the first branch (owner
     * Q-S5: `supplier.created` / `supplier.updated`, field names only, row-locked diff — and ONLY those two, final RED
     * M-A). Now: `UpdateSupplier` is never in the spec without the tests that carry the trail.
     *
     * **Diverged deliberately since 2026-10-20 (G-35 (g), C0a; G-19 decision, `audit-reads-owner-q-ruling.md` §2.5):** the
     * CUSTOMER trail records the payment-terms VALUE; the supplier trail is not mirrored and still records names only —
     * supplier terms are credit the tenant receives, and a long one favours the tenant.
     *
     * @var array<string, list<array{0: class-string, 1: string}>>
     */
    private const OPERATIONS_OWING_THE_SUPPLIER_TRAIL_DECISION = [
        'UpdateSupplier' => [
            [SupplierWriteTest::class, 'aCreatedSupplierWritesExactlyOneSupplierCreatedRow'],
            [SupplierWriteTest::class, 'anUpdateWritesOneRowNamingOnlyTheChangedFields'],
            [SupplierWriteTest::class, 'aNoOpUpdateWritesNoRowAndNoStatement'],
            [SupplierWriteTest::class, 'theUpdatedRowCarriesNamesNeverValues'],
            [SupplierWriteTest::class, 'aTermsResetRacingATermsChangeIsAttributedToTheResetter'],
            [SupplierWriteTest::class, 'onlyTheSupplierEventsWithAWriterAreMinted'],
        ],
    ];

    /**
     * **The customer address trail** (suppliers slice 1 C2b, 2026-10-12; owner ruling F-12 / S4 "Record address changes",
     * `docs/session13/suppliers-s1-ruling.md`) — an implication from its first day, the supplier map's shape: while either
     * address write is in the spec, the tests that carry its trail must exist. Until C2b the shipped `CreateCustomerAddress`
     * / `UpdateCustomerAddress` wrote no row (customers C3 deviation D-1), and nothing would have gone red had the trail
     * been dropped again.
     *
     * @var array<string, list<array{0: class-string, 1: string}>>
     */
    private const OPERATIONS_OWING_THE_ADDRESS_TRAIL = [
        'CreateCustomerAddress' => [
            [CustomerAddressWriteTest::class, 'aCreatedAddressWritesExactlyOneCustomerAddressCreatedRow'],
            [CustomerAddressWriteTest::class, 'aReplayedOrRefusedCreateWritesNoRow'],
        ],
        'UpdateCustomerAddress' => [
            [CustomerAddressWriteTest::class, 'anAddressUpdateWritesOneRowNamingOnlyTheChangedFields'],
            [CustomerAddressWriteTest::class, 'aNoOpAddressUpdateWritesNoRowAndNoStatement'],
            [CustomerAddressWriteTest::class, 'aRefusedAddressUpdateWritesNoRow'],
            [CustomerAddressWriteTest::class, 'theAddressUpdatedRowCarriesNamesNeverValues'],
            [CustomerAddressWriteTest::class, 'aDiversionRacingACorrectionIsAttributedToTheCorrector'],
        ],
        // Customers slice 2 C3: one `.deleted {"customer_id"}` per removed row (the key pinned literally); none on a 404,
        // a refusal or a lost double delete.
        'DeleteCustomerAddress' => [
            [CustomerAddressWriteTest::class, 'aDeletedAddressWritesOneRowCarryingItsCustomer'],
            [CustomerAddressWriteTest::class, 'aRefusedOrEmptyDeleteWritesNoRow'],
            [CustomerAddressWriteTest::class, 'aSecondConcurrentAddressDeleteWaitsOnTheAddressAndIsA404WritingNoSecondRow'],
        ],
    ];

    // RETIRED 2026-10-22 with its work (G7b `UpdateSettings`): `OPERATIONS_OWING_THE_SETTINGS_NAME_MOVE` held RO-S1's source
    // move (G7a, 2026-10-21; `cursor-g7a-red.md` M-3) keyed on `UpdateSettings`. The commit that built `UpdateSettings` added
    // `tenant_settings.display_name` backfilled from `tenant.name` (`Version20261022020000`), moved `SettingsReader` to it,
    // granted `erp_app` UPDATE (display_name, updated_at) only — never on `tenant` — edited `SchemaManifest`, split
    // `TenantSettingsStorageTest`'s write pin, built `SettingsContractTest::everyDeclaredSettingsFieldHasAColumn` and gave the
    // notice. The map and `noSettingsWriteShipsWithoutMovingTheNameItReads` were removed in that commit, not hand-edited green.

    // **RETIRED 2026-10-23 (G5-C3): `OPERATIONS_OWING_THE_DERIVED_ARM_CARRIES` and its arrival pin
    // `noDerivedOperationShipsWithoutTheDerivedArmsCarries`** (G5-D0, 2026-10-22). Its last key, `CreateBulkRestoration`, LEFT
    // with every carry built: (1) `CreateBulkRestorationProcessor` acts only through `resolvedFor()`
    // (`BulkRestorationTest::aBodyReadTwoWaysIsAServerErrorNotARestoration` plants the mismatch); (2)
    // `PermissionEnforcementContractTest::PINNED_PERMISSIONS`/`PINNED_RESOLVERS`, §4.2's `*(derived)*` cell,
    // `BulkRestorationPermission` (a `DerivedPermission`); (3) `ErrorContractTest`'s derived arm and a `CreateBulkRestoration`
    // recipe in `OperationSuccessRecipes`; (4) `MarginSourcesDerivationTest::derivedArm()` asserts its response serves no
    // money. With no derived operation left unbuilt the map held over nothing, so it was removed, not hand-edited green.

    // **RETIRED 2026-10-22 (G3-C1): `OPERATIONS_OWING_THE_CATALOGUE_TRAIL` and its arrival pin
    // `noCatalogueCostReachesASuggestionWithoutItsTrail`** (suppliers slice 1 C3, 2026-10-12; reworded at G3-C0). The pin was
    // `RESIDUAL_GAPS.md` G-41's hard trigger, keyed on `ListReorderSuggestions`. Its first obligation — the catalogue trail —
    // was built at G3-C0 (`24158cc`, G-41 CLOSED); its last — *never suggest ordering from an archived preferred supplier* —
    // left in this commit with its work: `ReorderSuggestionTest::anArchivedPreferredSupplierIsNeverSuggested` proves it on the
    // built operation, so the map would be empty, and an empty arrival pin asserts nothing (its own anti-vacuity line said
    // so). Deleted with its method, not hand-edited green (design §1.5, §2.8; the `OPERATIONS_OWING_THE_PARENT_LOCK` precedent).

    // **RETIRED 2026-10-19 (customers slice 2 C3): `OPERATIONS_OWING_THE_PARENT_LOCK` and its arrival pin
    // `noCustomerChildWriteShipsWithoutTheParentLock`** (C5 C4b, 2026-10-14). The pin named the unbuilt customer child writes
    // and went red the moment one arrived without the parent lock. Its last entry, `DeleteCustomerAddress`, left in this
    // commit with its work, so the map is empty — and an empty arrival pin asserts nothing (its own anti-vacuity line said
    // so). Its job passed to {@see self::CUSTOMER_CHILD_WRITES_CARRY_THE_PARENT_LOCK} (customers slice 2 C1), which names the
    // race tests that PROVE each built child write's parent lock and derives its completeness from the spec. Deleted, not
    // hand-edited green (design R1.10; `ARCH` A-2 condition 1).

    /**
     * **Every BUILT customer child write names the tests that prove its parent lock** (customers slice 2 C1, 2026-10-19;
     * `docs/session13/customers-s2-design.md` Revision 2 R2.4, final RED F-M2, `ARCH` A-2). operationId ⇒ its carriers
     * (`[testClass, method]`): the archive race for every child write and, for the contact writes (owner Q-1 (a)), the
     * two-primaries race — an archive race passes with `FOR KEY SHARE` too, so it cannot evidence the stronger lock (A-2
     * condition 2). **The map proves the carriers exist; the races prove the locks; removing a lock is caught by the race,
     * removing a race by the map; a child write under another prefix is not seen** (A-2 condition 3). Completeness is
     * DERIVED: every built non-GET operation at `/api/v1/customers/{id}/<segment>…` (segment not in
     * {@see self::PARTY_ACTION_SEGMENTS}) must be a key.
     *
     * @var array<string, list<array{0: class-string, 1: string}>>
     */
    private const CUSTOMER_CHILD_WRITES_CARRY_THE_PARENT_LOCK = [
        'CreateCustomerAddress' => [
            [CustomerDeleteTest::class, 'anAddressAddedWhileTheCustomerIsBeingArchivedIsRefused'],
        ],
        'UpdateCustomerAddress' => [
            [CustomerDeleteTest::class, 'anAddressChangedWhileTheCustomerIsBeingArchivedIsRefused'],
        ],
        'CreateCustomerContact' => [
            [CustomerContactWriteTest::class, 'aContactAddedWhileTheCustomerIsBeingArchivedIsRefused'],
            [CustomerContactWriteTest::class, 'twoRequestsMakingContactsPrimaryAtOnceLeaveExactlyOne'],
        ],
        'UpdateCustomerContact' => [
            [CustomerContactWriteTest::class, 'aContactChangedWhileTheCustomerIsBeingArchivedIsRefused'],
            [CustomerContactWriteTest::class, 'aContactMarkedPrimaryWhileAnotherIsBeingAddedAsPrimaryWaitsThenMovesTheMark'],
        ],
        // C2: the archive race, and the double delete (the second waits on the customer — the parent lock, Q-1 (a)).
        'DeleteCustomerContact' => [
            [CustomerContactDeleteTest::class, 'aContactRemovedWhileTheCustomerIsBeingArchivedIsRefused'],
            [CustomerContactDeleteTest::class, 'aSecondConcurrentDeleteWaitsOnTheCustomerAndIsA404WritingNoSecondRow'],
        ],
        // C3: the archive race (the parent `FOR KEY SHARE` waits on `DeleteCustomer`'s `FOR UPDATE`).
        'DeleteCustomerAddress' => [
            [CustomerAddressWriteTest::class, 'anAddressRemovedWhileTheCustomerIsBeingArchivedIsRefused'],
        ],
    ];

    /** The `/customers/{id}/<segment>` actions on the PARTY itself, not a child (`RestoreCustomer`); excluded from completeness. */
    private const PARTY_ACTION_SEGMENTS = ['restoration'];

    /**
     * **The customer contact trail** (customers slice 2 C1, 2026-10-19; owner Q-2 "yes, record contact changes",
     * `docs/session13/s35-ruling.md`) — {@see self::OPERATIONS_OWING_THE_ADDRESS_TRAIL}'s shape: while either contact write is
     * in the spec, the tests that carry its trail must exist.
     *
     * @var array<string, list<array{0: class-string, 1: string}>>
     */
    private const OPERATIONS_OWING_THE_CONTACT_TRAIL = [
        'CreateCustomerContact' => [
            [CustomerContactWriteTest::class, 'aCreatedContactWritesExactlyOneCustomerContactCreatedRow'],
            [CustomerContactWriteTest::class, 'aReplayedOrRefusedContactCreateWritesNoRow'],
            [CustomerContactWriteTest::class, 'aDemotedContactGetsItsOwnUpdatedRow'],
        ],
        'UpdateCustomerContact' => [
            [CustomerContactWriteTest::class, 'aContactUpdateWritesOneRowNamingOnlyTheChangedFields'],
            [CustomerContactWriteTest::class, 'aNoOpContactUpdateWritesNoRowAndNoStatement'],
            [CustomerContactWriteTest::class, 'theContactUpdatedRowCarriesNamesNeverValues'],
            [CustomerContactWriteTest::class, 'aDemotedContactGetsItsOwnUpdatedRow'],
        ],
        // C2: one `.deleted {"customer_id"}` per removed row; none on a 404 or a refusal.
        'DeleteCustomerContact' => [
            [CustomerContactDeleteTest::class, 'aRemovedContactWritesExactlyOneDeletedRowNamingItsCustomer'],
            [CustomerContactDeleteTest::class, 'aRefusedOrRepeatedDeleteWritesNoRow'],
        ],
    ];

    // **RETIRED 2026-10-23 (G5-C3): `OPERATIONS_OWING_THE_ARCHIVE_RULES` and its arrival pin
    // `noArchiveOfAWarehouseOrLocationShipsWithoutItsRules`** (warehouses-s1 §4.7; bulk parity `stock-transfer-sec.md` §5).
    // Its last key, `CreateBulkRestoration`, LEFT with its work done: each member runs exactly its single `Restore*` writer
    // method under a savepoint (`BulkArchiveMember::restore()`), so every liveness rule and collision translation is
    // inherited; a refused member is not applied with no per-id reason (E-14); one `<table>.restored` row per applied
    // member (`BulkRestorationTest`'s seven parity cases and `aCollidingMemberIsNotAppliedAndTheRestStand`). G-50 CLOSED.

    // The G-44 release pin (OPERATIONS_THAT_CAN_RELEASE_HELD_STOCK / THE_RELEASE_RULE) was DELETED at CreateStockTransfer's C1
    // (2026-10-20; SEC docs/session13/stock-transfer-sec.md §4): both stock_movement.adjust operations apply the rule
    // (StockAdjustmentReleaseTest, StockTransferReleaseTest), no unbuilt operation moves stock, and the rule now rides on
    // WriteOwnershipTest::everyStockLevelWriterHasARecordedReleaseDecision (SEC stock-transfer-sec-2.md §2) and on that
    // file's stock-movement registry message.

    /**
     * **G-7's renewed premise, as data** (stock reads C1, 2026-10-14; design §4.6 + R-M1): owner ruling Q1 renewed model A
     * on *"stock visible, on-hand only"* — so no response may serve either name. A differently named field (`available`,
     * `atp`) is NOT seen: that residual is stated in the method's docblock, not hidden.
     *
     * @var list<string>
     */
    private const STOCK_FIGURES_NEVER_SERVED = ['qty_available', 'qty_reserved'];

    /**
     * The anchors that prove the walk reaches a real stock row: `operationId` => members that MUST be reachable from its
     * 200 response. `ListStockLevels`' item schema is published INLINE under its envelope's component, so a walker that
     * stops at a `$ref` or skips `items` misses it (mutation M10b). `ListStockMovements` (C2) reaches its row through a `$ref`.
     *
     * @var array<string, list<string>>
     */
    private const STOCK_FIGURE_ANCHORS = [
        'ListStockLevels' => ['qty_on_hand', 'version'],
        // Stock reads C2 (design R-M1): the movement list's items are a `$ref` to the `StockMovement` component.
        'ListStockMovements' => ['quantity'],
    ];

    /** G-7's closing work, carried verbatim into the failure message (design §4.6). */
    private const G7_CLOSING_WORK = 'Owner ruling Q1 of 2026-10-14 (docs/session13/stock-reads-ruling.md) renewed model A (RESIDUAL_GAPS.md G-7) on the '
        .'premise "stock is visible per bin as on-hand; no available-to-promise or reserved figure is shown anywhere", and that '
        .'renewal ENDS when this goes red. stock_level.qty_reserved is written by nothing, so an available figure equals on-hand '
        .'and presents stock confirmed orders may already have claimed as promisable. The figure the owner chose is model D at '
        .'WAREHOUSE grain (owner ruling R4 of 2026-09-22), on its own read with its own §4.4.3 row; promisable = live, '
        .'non-quarantine stock only (R2, confirmed 2026-10-14). Nobody may relax or drop stock_level_reserved_sane: it is the '
        .'only rule refusing negative stock. Bring it to the owner, or build model D with its rule, in THIS commit. Do not hand-edit '
        .'this pin to green.';

    // The double-credit pin (G-28 / G-32) was DELETED at the stock count's C1 (2026-10-16, `SEC`
    // `docs/session13/stock-adjustment-sec.md` §1): its last entry, `CreateStockAdjustment`, left with path (iii) tested, and
    // no unbuilt operation composes a role through the API (`RestoreRole`, built at G2, revives no grant: it refuses any
    // holder and any pending proposal, and an archived role cannot be granted).
    //
    // The proposal-handling pin (roles slice 3's hand-forward, C1 2026-10-04) was DELETED at G4-C2 (2026-10-23): its last two
    // entries, `CreateBulkRoleAssignment` (G5-C1) and `DeleteUser` (G4-C2), left with their work done, the same day, so the map
    // would hold over nothing (the double-credit pin's precedent above). Every entry's record, kept:
        // `UpdateUser` LEFT 2026-10-23 (G4-C1), its work done: the role lock is its first statement, unconditionally (positional
        // case `PurchaseOrderRowLockTest::theRoleAdministrationLockIsTheFirstStatementOfTheWritingTransaction` 'UpdateUser');
        // a deactivation lapses every pending proposal FOR the user (`subject_removed`) and BY the user (`proposer_removed`),
        // one `role.assignment_lapsed` each, in bulk statements carrying `AND status = 'pending'` (`UserLiveness::lapse()`;
        // `UserUpdateTest::aDeactivationLapsesEveryPendingProposalForAndByThePerson`); `UPDATE (is_active, display_name,
        // sessions_valid_after)` granted with its caller (`Version20261023000100`, G-12 reach recorded); and E-15g's strip
        // removes every `user_role` row, so `LiveGrantReader::holderRowCount` stops counting a switched-off person
        // (`UserUpdateTest::aDeactivationStripsEveryRoleAndRecordsEachOne`).
        // `DeleteUser` LEFT 2026-10-23 (G4-C2), its work done: the role lock first (positional case 'DeleteUser'); every
        // pending proposal for and by the person lapses (`UserLiveness::lapse()`); `UPDATE (deleted_at)` granted with its
        // caller (`Version20261023000300`); the strip removes every `user_role` row, so `LiveGrantReader::holderRowCount` stops
        // counting a deleted person and `DeleteRole` of their former role succeeds (`UserDeleteTest`).
        // `CreateBulkRoleAssignment` LEFT 2026-10-23 (G5-C1), its work done: it never proposes — E-15e routing, E-15b's
        // marker, E-15c and an archived role are judged once, before any member id is read
        // (`BulkRoleAssignmentTest::aNonOwnersBulkOfAnXAdminRoleIsRefusedBeforeAnyMemberIsRead`,
        // `::theRoleIsJudgedOnceBeforeAnyMemberIsRead`); an owner's bulk counts a member with a pending same-pair proposal as
        // not applied (R-21, `::anOwnersBulkSkipsAMemberWithAPendingSamePairProposal`); per member it re-runs GrantRules'
        // phase verdicts (V2-R-6, E-15a, C12, PC-13 over LIVE roles) and writes exactly AssignRole's `INSERT` and one
        // `role.assigned` per (actor, subject, role), proven differentially (`::everyMemberVerdictMatchesTheSingleAssignRole`);
        // the response carries the role's `margin_exposure` (G-28's response-contract trigger fired; clause 3 served, clause 4
        // cannot be on a bulk body). No seat is added that a single AssignRole cannot add (G-32's R-1, SEC).
        // `DeleteRole` LEFT 2026-10-07 (roles slice 4 C3), its work done: E-15h's pending refusal counts every `pending` row on
        // the role, stale ones included (`LiveGrantReader::pendingProposalCount`, a `status = 'pending'` partial index), and names
        // the count — `RoleDeleteTest::aRoleWithOpenRequestsIsRefusedCountingExpiredOnes`. The OPEN SEC question is answered by
        // `roles-s4-sec-data.md` D-7.2 (owner D-6 option (1)): every API-composed `role.delete` seat holds `role.assign`, and a
        // caller whose LOCK-time codes lack it is refused `role.delete_needs_role_assign` before the role is read, one body for
        // every id (`…::aDeleterWithoutRoleAssignIsRefusedAloneTheSameWayForEveryId`; the lock-time read in
        // `PurchaseOrderRowLockTest::aDeleterDemotedWhileWaitingMeetsTheBackstopOnItsLockTimeCodes`); PC-16's sentence written.
        // Q-5 (a) re-checked by running it: `…::aStaleRequestIsClearedByAnOwnerAndTheRoleCanThenBeDeleted`.
        // `UpdateRole` LEFT 2026-10-07 (roles slice 4 C2), its work done: pending proposals are not written to and a
        // confirmation after the edit is refused `role_changed`, and a role that LOSES its X(admin) code leaves its pending
        // proposal for a non-owner to meet at AssignRole's gap B (`proposal_pending`) — both in
        // `RoleUpdateTest::aRoleThatLosesAnAdminExcludedCodeLeavesItsPendingProposalUntouched`; G-10 arm D shipped one commit
        // EARLIER, at C1 (`0bd4a97`, design §7's recommendation: the first writer of `role_permission`), with a fixed
        // cut-off date (C1 deviation 2), and C2's code-set edits write the `role.permissions_changed` rows it folds.

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
            // P-0: read through the one parser; "alone" is exactly ALL_OF of the one code (a list adding a second code, or a
            // derived requirement, is mis-gated too).
            $permission = isset($registry[$pickRead]) ? PermissionRequirement::of($registry[$pickRead])->describe() : 'not registered';
            if (PermissionRequirement::fromDeclaration(self::THE_PICK_READS_PERMISSION)->describe() !== $permission) {
                $defects[] = \sprintf('%s is gated on %s, not on %s alone.', $pickRead, $permission, self::THE_PICK_READS_PERMISSION);
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
     * **The order-restore implication** (order restores C2, 2026-10-21; R1.6, final RED L-3): while an order restore is in the
     * spec, every test named in {@see self::OPERATIONS_CARRYING_THE_RESTORE_COLLISIONS} must exist. Until C3 (2026-10-22) a
     * pin method beside it held the unbuilt `RestorePurchaseOrder`; it was deleted when that restore shipped (final RED
     * L-3), and this method now holds both. Detects a removed or renamed carrier, not a weakened one. **V2-R-12b's history**
     * (the order restore's second trigger, named only in docblocks until 2026-09-21, then pinned): `docs/session12/
     * output9-sales-5-design.md` §5; the owed list as the pin printed it: `docs/session13/order-restores-design.md` R1.2,
     * R1.3 and Revision 1 FINAL C2/C3.
     */
    #[Test]
    public function noOrderRestoreIsBuiltWithoutTheTestsThatCarryItsCollisions(): void
    {
        $built = $this->builtOperationIds();

        self::assertArrayHasKey(self::A_RESTORE_KNOWN_TO_BE_BUILT, $built,
            'Positive control: the generated spec no longer carries a built restore\'s `operationId` the way this reads it.');
        self::assertNotSame([], self::OPERATIONS_CARRYING_THE_RESTORE_COLLISIONS, 'The order-restore implication has no antecedent, so it asserts nothing.');

        $missing = [];
        $antecedents = 0;
        foreach (self::OPERATIONS_CARRYING_THE_RESTORE_COLLISIONS as $operation => $carriers) {
            if (!isset($built[$operation])) {
                continue;
            }
            ++$antecedents;
            foreach ($carriers as [$class, $method]) {
                if (!method_exists($class, $method)) {
                    $missing[] = $operation.' is built, and '.$class.'::'.$method.' no longer exists';
                }
            }
        }
        self::assertGreaterThan(0, $antecedents, 'No carried order restore is in the spec, so this implication holds over nothing.');

        self::assertSame([], $missing, \sprintf(
            "%s\n\nAn ORDER restore owes V2-R-12b's collisions, each translated by constraint name on the restore path only\n"
            ."and each reached ALONE; the number proof; the line-number mapping; Q3 and the total; `<table>.restored`; the\n"
            ."party and warehouse refusals and their locks; and E9-3's twin. These tests carry them. Record:\n"
            ."`docs/session13/order-restores-design.md` §5, §6 and Revision 1 FINAL C2/C3.",
            implode("\n", $missing),
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
     * **`RESIDUAL_GAPS.md` G-35's hard trigger, now wholly its IMPLICATION** (customers slice 1 C2; judge FINAL item 10;
     * converted at audit reads C1 and C2, ruling §5): while each audit read is in the spec, every test named for it in
     * {@see self::OPERATIONS_CARRYING_THE_G35_LOW_RATING} must exist. It detects a removed or renamed carrier, not a weakened
     * one (the supplier-trail implication's limit). Mutation M19: remove a carrier test → red naming it. *(Until C2 it also
     * held a pin half, red the moment a key of the pin map was in the spec; C2 built the last key and converted it, so the
     * pin half went with the empty map.)*
     *
     * The name is kept through the conversion: `DELIVERY_LEDGER.md`'s G-35 row runs it by name. **Controls, same test:** the
     * positive control every sibling uses; a non-empty antecedent; and every antecedent must be BUILT (else the implication
     * holds over nothing). **M12 (review check, not evidence):** deleting the implication's carrier list defeats this test by
     * exactly that edit.
     */
    #[Test]
    public function noAuditReadShipsWithoutReRatingG35(): void
    {
        $built = $this->builtOperationIds();

        self::assertArrayHasKey(self::AN_OPERATION_ID_KNOWN_TO_BE_BUILT, $built,
            'Positive control: the generated spec no longer carries `operationId` the way this pin reads it.');
        // The implication (C1, C2): every antecedent is built, and every carrier exists.
        self::assertNotSame([], self::OPERATIONS_CARRYING_THE_G35_LOW_RATING, 'The G-35 implication has no antecedent, so it asserts nothing.');
        self::assertSame(array_keys(self::OPERATIONS_CARRYING_THE_G35_LOW_RATING),
            array_keys(array_intersect_key(self::OPERATIONS_CARRYING_THE_G35_LOW_RATING, $built)),
            'A G-35 implication\'s antecedent is not in the spec, so it holds over nothing: was an audit read removed?');
        $missing = [];
        foreach (self::OPERATIONS_CARRYING_THE_G35_LOW_RATING as $operation => $carriers) {
            foreach ($carriers as [$class, $method]) {
                if (!method_exists($class, $method)) {
                    $missing[] = $operation.' is built, and '.$class.'::'.$method.' no longer exists';
                }
            }
        }
        self::assertSame([], $missing, \sprintf(
            "%s\n\nRESIDUAL_GAPS.md G-35 is rated Low (judge ruling 2026-10-20 under the owner's delegation) on the condition that the\n"
            ."audit trail serves every payment-terms value a person sets — `customer.created` {payment_terms_days} and\n"
            ."`customer.updated` {fields, payment_terms_days: {from, to}} — readable by the owner through ListAuditLogEntries and\n"
            ."filterable by event_type; the trail's carrier holds that condition, and removing it returns G-35 to Medium. The\n"
            ."History's carrier holds the clause G-35's pin carried until C2: a customer, and its contacts and addresses, are read\n"
            ."through PC-9 on customer.view. Restore the test, or move its obligation to another named test here.\n"
            ."Record: docs/session13/audit-reads-owner-q-ruling.md §2.6, §5 C1/C2.",
            implode("\n", $missing),
        ));
    }

    /**
     * **The implication's document half** (audit reads C1; final RED L-6: pin both the control test AND the ruling line).
     * While `ListAuditLogEntries` is in the spec, `RESIDUAL_GAPS.md` G-35 carries {@see self::G35_LOW_RULING_LINE}. A rating
     * edited away from Low without the implication moving — or the entry renumbered — is red here. Skipped without `docs/`,
     * like every other reader; the method-existence half above runs everywhere.
     */
    #[Test]
    public function g35CarriesItsLowRulingWhileTheTrailIsReadable(): void
    {
        if (!ResidualGapsDocument::isAvailable()) {
            self::markTestSkipped('docs/RESIDUAL_GAPS.md is gitignored and absent from this checkout; the carrier half runs in noAuditReadShipsWithoutReRatingG35.');
        }

        self::assertArrayHasKey('ListAuditLogEntries', $this->builtOperationIds(), 'The antecedent is not built, so the ruling line need not hold.');
        $entry = ResidualGapsDocument::entry('G-35');
        self::assertNotSame('', $entry, 'RESIDUAL_GAPS.md has no G-35 entry: deleted or renumbered, so the Low rating is unanchored.');
        self::assertStringContainsString('## G-35 ', $entry, 'Control: the reader did not return the G-35 entry.');
        self::assertStringContainsString(self::G35_LOW_RULING_LINE, $entry,
            'RESIDUAL_GAPS.md G-35 no longer carries its Low ruling line while ListAuditLogEntries is built. Either the rating '
            .'moved (then move the implication with it, deliberately) or the line was edited away.');
    }


    /**
     * **Every built customer child write names its parent-lock carriers** (customers slice 2 C1; see
     * {@see self::CUSTOMER_CHILD_WRITES_CARRY_THE_PARENT_LOCK}). Asserts: the map is non-empty; every key is built; every
     * carrier exists; and — DERIVED from the generated spec — every built non-GET operation whose path is
     * `/api/v1/customers/{id}/<segment>…` with `<segment>` not a party action is a key. **Controls, same test:** the positive
     * control every sibling uses; the two shipped address writes are derived as child writes; `RestoreCustomer` exists at
     * `…/restoration` (else the exclusion is stale and must go); and the pure check reports a planted key whose carrier is
     * missing and a planted child path absent from the map (mutations M1-17, M1-18 at unit level).
     */
    #[Test]
    public function everyBuiltCustomerChildWriteNamesItsParentLockCarriers(): void
    {
        $built = $this->builtOperationIds();
        self::assertArrayHasKey(self::AN_OPERATION_ID_KNOWN_TO_BE_BUILT, $built,
            'Positive control: the generated spec no longer carries `operationId` the way this pin reads it.');
        self::assertNotSame([], self::CUSTOMER_CHILD_WRITES_CARRY_THE_PARENT_LOCK, 'The child-write map is empty, so it holds over nothing.');

        $spec = GeneratedSpec::regenerate(self::$kernel ?? throw new \LogicException('The kernel is booted in setUp().'));
        $operations = [];
        foreach (GeneratedSpec::operations($spec) as $data) {
            $operations[] = ['method' => strtoupper($data['method']), 'path' => $data['path'], 'id' => (string) ($data['operation']['operationId'] ?? '')];
        }

        $derived = self::customerChildWrites($operations);
        self::assertContains('CreateCustomerAddress', $derived, 'Control: the shipped address create is not derived as a customer child write.');
        self::assertContains('UpdateCustomerAddress', $derived, 'Control: the shipped address update is not derived as a customer child write.');
        self::assertContains(['method' => 'POST', 'path' => '/api/v1/customers/{id}/restoration', 'id' => 'RestoreCustomer'], $operations,
            'RestoreCustomer is no longer at /customers/{id}/restoration, so PARTY_ACTION_SEGMENTS\' exclusion is stale: remove it.');

        // Planted controls on the pure check: a key whose carrier is missing (M1-17's shape), a child path absent from the map
        // (M1-18's shape).
        $plantedMap = ['CreateCustomerAddress' => [[CustomerDeleteTest::class, 'noSuchCarrier']]];
        self::assertSame(['CreateCustomerAddress: '.CustomerDeleteTest::class.'::noSuchCarrier does not exist'],
            self::parentLockDefects($plantedMap, ['CreateCustomerAddress'], ['CreateCustomerAddress' => true]),
            'Control: a key whose carrier is missing was not reported.');
        $plantedOperations = [['method' => 'DELETE', 'path' => '/api/v1/customers/{id}/x/{xId}', 'id' => 'PlantedChildWrite']];
        self::assertSame(['PlantedChildWrite (DELETE /api/v1/customers/{id}/x/{xId}) is a customer child write with no carriers named'],
            self::parentLockDefects([], self::customerChildWrites($plantedOperations), ['PlantedChildWrite' => true], $plantedOperations),
            'Control: a child write absent from the map was not reported.');

        self::assertSame([], self::parentLockDefects(self::CUSTOMER_CHILD_WRITES_CARRY_THE_PARENT_LOCK, $derived, $built, $operations), \sprintf(
            "A customer child write is built without the tests that prove its parent lock, or a named carrier is gone.\n"
            ."Every write under /customers/{id}/<child> must read the customer first (address writes FOR KEY SHARE; contact writes\n"
            ."CustomerContactWriter::PARENT_LOCK, FOR NO KEY UPDATE, owner Q-1 (a)) and name its archive race (and, for contacts,\n"
            ."its two-primaries race) here. Record: docs/session13/customers-s2-design.md Revision 2 R2.4; ARCHITECTURE.md W-5.",
        ));
    }

    /**
     * The operationIds of every non-GET operation at `/api/v1/customers/{id}/<segment>…`, `<segment>` not a party action.
     *
     * @param list<array{method: string, path: string, id: string}> $operations
     *
     * @return list<string>
     */
    private static function customerChildWrites(array $operations): array
    {
        $writes = [];
        foreach ($operations as $o) {
            if ('GET' !== $o['method'] && 1 === preg_match('#^/api/v1/customers/\{id\}/([^/{]+)#', $o['path'], $m)
                && !\in_array($m[1], self::PARTY_ACTION_SEGMENTS, true)) {
                $writes[] = $o['id'];
            }
        }
        sort($writes);

        return $writes;
    }

    /**
     * @param array<string, list<array{0: class-string, 1: string}>> $map
     * @param list<string>                                         $derived
     * @param array<string, true>                                  $built
     * @param list<array{method: string, path: string, id: string}> $operations
     *
     * @return list<string>
     */
    private static function parentLockDefects(array $map, array $derived, array $built, array $operations = []): array
    {
        $defects = [];
        foreach ($map as $operation => $carriers) {
            if (!isset($built[$operation])) {
                $defects[] = $operation.' is in the map but not built: the map names a write that does not exist';
            }
            foreach ($carriers as [$class, $method]) {
                if (!method_exists($class, $method)) {
                    $defects[] = $operation.': '.$class.'::'.$method.' does not exist';
                }
            }
        }
        $paths = [];
        foreach ($operations as $o) {
            $paths[$o['id']] = $o['method'].' '.$o['path'];
        }
        foreach ($derived as $operation) {
            if (!\array_key_exists($operation, $map)) {
                $defects[] = $operation.' ('.($paths[$operation] ?? '?').') is a customer child write with no carriers named';
            }
        }

        return $defects;
    }

    /**
     * **The customer contact trail** (customers slice 2 C1) — {@see self::noCustomerAddressWriteShipsWithoutItsTrail()}'s
     * shape over {@see self::OPERATIONS_OWING_THE_CONTACT_TRAIL}. Mutation: rename a carrier → red naming it.
     */
    #[Test]
    public function noCustomerContactWriteShipsWithoutItsTrail(): void
    {
        $built = $this->builtOperationIds();

        self::assertArrayHasKey(self::AN_OPERATION_ID_KNOWN_TO_BE_BUILT, $built,
            'Positive control: the generated spec no longer carries `operationId` the way this pin reads it.');
        self::assertNotSame([], self::OPERATIONS_OWING_THE_CONTACT_TRAIL, 'The contact-trail implication has no antecedent, so it asserts nothing.');
        self::assertSame(array_keys(self::OPERATIONS_OWING_THE_CONTACT_TRAIL),
            array_keys(array_intersect_key(self::OPERATIONS_OWING_THE_CONTACT_TRAIL, $built)),
            'A contact-trail antecedent is not in the spec, so the implication holds over nothing: was a contact write removed?');

        $missing = [];
        foreach (self::OPERATIONS_OWING_THE_CONTACT_TRAIL as $operation => $carriers) {
            foreach ($carriers as [$class, $method]) {
                if (!method_exists($class, $method)) {
                    $missing[] = $operation.' is built, and '.$class.'::'.$method.' no longer exists';
                }
            }
        }

        self::assertSame([], $missing, \sprintf(
            "%s\n\nThe owner ruled \"record contact changes\" (2026-10-19, Q-2): `customer_contact.created` `{}` per INSERT (never on\n"
            ."a replay or a lost race) and `customer_contact.updated` `{fields}` per CHANGING update and per demoted sibling, names\n"
            ."never values, no row on a no-op or a refusal. Restore the test, or move its obligation to another named test here,\n"
            ."before removing it. Record: docs/session13/customers-s2-build-progress.md C1.",
            implode("\n", $missing),
        ));
    }

    /**
     * **G-7's renewed premise gets a mechanism** (stock reads C1, 2026-10-14; design §4.6, R-M1). Owner ruling Q1 renewed the
     * overselling acceptance on *"stock visible, on-hand only"*; a prose sentence could not stop the next read — model D's own,
     * a report, an export — from serving an available or reserved figure with nothing going red. This walks EVERY response
     * schema the generated spec declares (each operation's responses, `$ref`s resolved, `items` and nested objects followed,
     * and every component besides) and refuses either name anywhere.
     *
     * **Controls, same test:** a planted schema carrying `qty_available` behind a `$ref` is flagged; each anchor's members
     * (`ListStockLevels` → `qty_on_hand`, `version`) are reachable from its own 200 response, found by `operationId` — so a
     * walker that stops at `$ref` or `items` is red here, naming the anchor (mutation M10b); and more than 50 responses were
     * walked. **Residual, stated:** a differently named field (`available`, `atp`, `free_stock`) is not seen.
     */
    #[Test]
    public function noResponseServesAnAvailableOrReservedStockFigure(): void
    {
        $planted = ['components' => ['schemas' => [
            'PlantedEnvelope' => ['type' => 'object', 'properties' => ['items' => ['type' => 'array', 'items' => ['$ref' => '#/components/schemas/PlantedRow']]]],
            'PlantedRow' => ['type' => 'object', 'properties' => ['qty_on_hand' => ['type' => 'string'], 'qty_available' => ['type' => 'string']]],
        ]]];
        self::assertSame(['qty_available'], array_values(array_intersect(self::STOCK_FIGURES_NEVER_SERVED,
            self::reachableProperties($planted, ['$ref' => '#/components/schemas/PlantedEnvelope']))),
            'Positive control: a planted response carrying `qty_available` behind a `$ref` and an `items` was not flagged, so the walk is blind.');

        $spec = GeneratedSpec::regenerate(self::$kernel ?? throw new \LogicException('The kernel is booted in setUp().'));

        $found = [];
        $walked = 0;
        $anchors = [];
        foreach (GeneratedSpec::operations($spec) as $key => $data) {
            $operationId = (string) ($data['operation']['operationId'] ?? $key);
            foreach ($data['operation']['responses'] ?? [] as $status => $response) {
                foreach ((\is_array($response) ? $response['content'] ?? [] : []) as $content) {
                    ++$walked;
                    $names = self::reachableProperties($spec, $content['schema'] ?? []);
                    foreach (array_intersect(self::STOCK_FIGURES_NEVER_SERVED, $names) as $name) {
                        $found[] = $operationId.' '.$status.' serves `'.$name.'`';
                    }
                    if ('200' === (string) $status && isset(self::STOCK_FIGURE_ANCHORS[$operationId])) {
                        $anchors[$operationId] = array_values(array_intersect(self::STOCK_FIGURE_ANCHORS[$operationId], $names));
                    }
                }
            }
        }
        foreach ($spec['components']['schemas'] ?? [] as $component => $schema) {
            foreach (array_intersect(self::STOCK_FIGURES_NEVER_SERVED, self::reachableProperties($spec, \is_array($schema) ? $schema : [])) as $name) {
                $found[] = 'component '.$component.' declares `'.$name.'`';
            }
        }

        self::assertGreaterThan(50, $walked, 'Fewer than fifty responses were walked, so the spec this reads is not the built surface.');
        foreach (self::STOCK_FIGURE_ANCHORS as $operationId => $members) {
            self::assertSame($members, $anchors[$operationId] ?? null, \sprintf(
                'Anti-vacuity: %s\'s own 200 response does not reach %s through the walk, so the walk cannot see a stock row.',
                $operationId, implode(', ', $members)));
        }

        self::assertSame([], array_values(array_unique($found)), "A response serves an available or reserved stock figure.\n  "
            .implode("\n  ", array_unique($found))."\n\n".self::G7_CLOSING_WORK);
    }

    /**
     * Every property name reachable from a schema: `$ref`s resolved within the document, `properties`, `items`,
     * `allOf`/`anyOf`/`oneOf` and `additionalProperties` followed, each component visited once.
     *
     * @param array<string, mixed> $document
     * @param array<string, mixed> $schema
     * @param array<string, true>  $visited
     *
     * @return list<string>
     */
    private static function reachableProperties(array $document, array $schema, array &$visited = []): array
    {
        $names = [];
        if (isset($schema['$ref']) && \is_string($schema['$ref'])) {
            if (isset($visited[$schema['$ref']])) {
                return [];
            }
            $visited[$schema['$ref']] = true;
            $target = $document;
            foreach (\array_slice(explode('/', $schema['$ref']), 1) as $segment) {
                $target = \is_array($target) ? ($target[$segment] ?? []) : [];
            }

            return \is_array($target) ? self::reachableProperties($document, $target, $visited) : [];
        }
        foreach ((array) ($schema['properties'] ?? []) as $name => $property) {
            $names[] = (string) $name;
            if (\is_array($property)) {
                $names = [...$names, ...self::reachableProperties($document, $property, $visited)];
            }
        }
        foreach (['items', 'additionalProperties'] as $key) {
            if (isset($schema[$key]) && \is_array($schema[$key])) {
                $names = [...$names, ...self::reachableProperties($document, $schema[$key], $visited)];
            }
        }
        foreach (['allOf', 'anyOf', 'oneOf'] as $key) {
            foreach ((array) ($schema[$key] ?? []) as $part) {
                if (\is_array($part)) {
                    $names = [...$names, ...self::reachableProperties($document, $part, $visited)];
                }
            }
        }

        return $names;
    }

    /**
     * **The supplier trail decision** (customers slice 1 C2; judge FINAL item 10, C-5/C-7) — **an implication since
     * 2026-10-12** (suppliers slice 1 C2): while `UpdateSupplier` is in the spec, every test named in
     * {@see self::OPERATIONS_OWING_THE_SUPPLIER_TRAIL_DECISION} must exist. Until that commit it was a pin that went red
     * the moment `UpdateSupplier` was built. It detects a removed or renamed carrier, not a weakened one. **Controls, same
     * test:** the positive control; a non-empty antecedent; and the antecedent must be BUILT (else the implication holds
     * over nothing). Mutation: rename a carrier → red naming it.
     */
    #[Test]
    public function noSupplierEditShipsWithoutDecidingItsTrail(): void
    {
        $built = $this->builtOperationIds();

        self::assertArrayHasKey(self::AN_OPERATION_ID_KNOWN_TO_BE_BUILT, $built,
            'Positive control: the generated spec no longer carries `operationId` the way this pin reads it.');
        self::assertNotSame([], self::OPERATIONS_OWING_THE_SUPPLIER_TRAIL_DECISION,
            'The supplier-trail implication has no antecedent, so it asserts nothing.');
        self::assertSame(array_keys(self::OPERATIONS_OWING_THE_SUPPLIER_TRAIL_DECISION),
            array_keys(array_intersect_key(self::OPERATIONS_OWING_THE_SUPPLIER_TRAIL_DECISION, $built)),
            'The supplier-trail antecedent is not in the spec, so the implication holds over nothing: was UpdateSupplier removed?');

        $missing = [];
        foreach (self::OPERATIONS_OWING_THE_SUPPLIER_TRAIL_DECISION as $operation => $carriers) {
            foreach ($carriers as [$class, $method]) {
                if (!method_exists($class, $method)) {
                    $missing[] = $operation.' is built, and '.$class.'::'.$method.' no longer exists';
                }
            }
        }

        self::assertSame([], $missing, \sprintf(
            "%s\n\nUpdateSupplier accepts the same five fields as UpdateCustomer, and the supplier trail is carried by these tests\n"
            ."(owner Q-S5, 2026-10-12): `supplier.created` `{}` per create and `supplier.updated` `{fields}` per CHANGING update,\n"
            ."names never values, diffed under `FOR NO KEY UPDATE`, no row on a no-op or a refusal; ONLY those two supplier events\n"
            ."are minted (`.deleted`/`.restored` with their writers, final RED M-A). Restore the test, or move its obligation to\n"
            ."another named test here, before removing it. Record: docs/session13/suppliers-s1-build-progress.md C2.",
            implode("\n", $missing),
        ));
    }

    /**
     * **The customer address trail** (suppliers slice 1 C2b) — an implication: while `CreateCustomerAddress` or
     * `UpdateCustomerAddress` is in the spec, every test named in {@see self::OPERATIONS_OWING_THE_ADDRESS_TRAIL} must exist.
     * It detects a removed or renamed carrier, not a weakened one. **Controls, same test:** the positive control; a
     * non-empty antecedent; and every antecedent must be BUILT (else the implication holds over nothing). Mutation: rename a
     * carrier → red naming it.
     */
    #[Test]
    public function noCustomerAddressWriteShipsWithoutItsTrail(): void
    {
        $built = $this->builtOperationIds();

        self::assertArrayHasKey(self::AN_OPERATION_ID_KNOWN_TO_BE_BUILT, $built,
            'Positive control: the generated spec no longer carries `operationId` the way this pin reads it.');
        self::assertNotSame([], self::OPERATIONS_OWING_THE_ADDRESS_TRAIL,
            'The address-trail implication has no antecedent, so it asserts nothing.');
        self::assertSame(array_keys(self::OPERATIONS_OWING_THE_ADDRESS_TRAIL),
            array_keys(array_intersect_key(self::OPERATIONS_OWING_THE_ADDRESS_TRAIL, $built)),
            'An address-trail antecedent is not in the spec, so the implication holds over nothing: was an address write removed?');

        $missing = [];
        foreach (self::OPERATIONS_OWING_THE_ADDRESS_TRAIL as $operation => $carriers) {
            foreach ($carriers as [$class, $method]) {
                if (!method_exists($class, $method)) {
                    $missing[] = $operation.' is built, and '.$class.'::'.$method.' no longer exists';
                }
            }
        }

        self::assertSame([], $missing, \sprintf(
            "%s\n\nThe owner ruled \"Record address changes\" (2026-10-12, F-12 / S4): `customer_address.created` `{}` per INSERT\n"
            ."(never on a replay) and `customer_address.updated` `{fields}` per CHANGING update, names never values, diffed under\n"
            ."`FOR NO KEY UPDATE`, no row on a no-op or a refusal. Restore the test, or move its obligation to another named test\n"
            ."here, before removing it. Record: docs/session13/suppliers-s1-build-progress.md C2b.",
            implode("\n", $missing),
        ));
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
