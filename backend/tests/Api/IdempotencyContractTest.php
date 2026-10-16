<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Platform\Http\Idempotency\IdempotencyKeyGuard;
use App\Platform\Tenancy\SchemaManifest;
use App\Tests\Support\ContractDocument;
use App\Tests\Support\DeliveryLedgerDocument;
use App\Tests\Support\StatusMachines;
use App\Tests\Tenancy\TenancyTestCase;
use Doctrine\ORM\Mapping\Table;
use PHPUnit\Framework\Attributes\Test;

/**
 * **API-14 as amended — §4.2's `Idem` column, reconciled against the database
 * and the code. The test `IdempotencyKeyGuard`'s docblock has claimed existed
 * since the day that class was written, and which did not exist until now.**
 *
 * ---------------------------------------------------------------------------
 * What this exists to stop, stated first because it is not hypothetical
 * ---------------------------------------------------------------------------
 * The `Idem` column was a **binary aspiration**. It said *"a repeat should not
 * produce a second effect"* and **nothing anywhere reconciled that against what
 * actually refuses the repeat.** The guard's docblock asserted that this class
 * performed that reconciliation — the twentieth recorded fake-green on this
 * project, and the purest form of the species: a claim about a check standing in
 * for the check.
 *
 * The cost was exact. Four operations sat in the contract looking protected while
 * nothing refused their retry, `CreatePurchaseOrder` among them — its only unique
 * is on a **server-generated** `order_number` the caller never sends, so a client
 * that retried a timed-out create got a second purchase order, through a green
 * suite. Writing this test found **five more**, of which one was a live defect
 * rather than a known gap: `IssueInvoice` was inside §6.1's own *"safe on a
 * precondition"* list and has no precondition at all.
 *
 * ---------------------------------------------------------------------------
 * What is pinned and what is derived, because the difference is the whole test
 * ---------------------------------------------------------------------------
 * `docs/` is gitignored, so §4.2 is not in the repository and a check whose only
 * domain is the document has no subject on a clean checkout. `PINNED_MECHANISMS`
 * is therefore a **pin** in the §12.3 sense — the 43 cells, transcribed once —
 * and it is checked in both directions against the document wherever the document
 * is present.
 *
 * **Everything the pin is checked AGAINST is derived from a live source, never
 * restated:**
 *
 *   - `unique:<index>` → `pg_indexes`, read from the running catalogue: the index
 *     exists, it is `UNIQUE`, its live-row partiality matches its table's
 *     declared deletion class, and **its key columns are ones the client sends**;
 *   - `guard:<entity>.status` → `StatusMachines`, by reflection over `src/`;
 *   - `guard:<entity>.deleted_at` → `SchemaManifest`'s deletion class;
 *   - `store` → the operation registry (and the replay path, which is unbuilt);
 *   - `⛔ none` → `DELIVERY_LEDGER.md`'s E9-2 gate, **by operation name**.
 *
 * **An exhaustive check that restates a constant is not a check.** This project
 * has measured that repeatedly — the privilege list that was a constant and was
 * missing `MAINTAIN`; the transition matrix whose covered set included the gap it
 * was meant to find. So every method below asserts its own subject is non-empty
 * before asserting anything about it.
 *
 * ---------------------------------------------------------------------------
 * Why `⛔ none` is self-declaring, which is the design decision worth defending
 * ---------------------------------------------------------------------------
 * A fifth cell value that merely said *"unprotected"* would be a richer note, and
 * a note is what the binary column already was. `everyBlockedOperationIsNamedByTheGate`
 * is what makes it a control: an operation cannot be marked `⛔ none` unless a
 * gate names it as blocked, and the gate cannot quietly stop naming one. **An
 * unprotected operation that nothing blocks is impossible to leave in the table
 * quietly** — either E9-2 names it, or this file is red.
 *
 * ---------------------------------------------------------------------------
 * Mutations — every one below was RUN, and each docblock states the mutation
 * that was actually applied rather than the one it would have been tidier to
 * describe
 * ---------------------------------------------------------------------------
 * The load-bearing one is §6's own: **drop `inventory_item_tenant_sku_live` and
 * this goes red naming `CreateItem`** — not naming the index, naming the
 * operation whose contract the index is the whole of. Run against the live
 * catalogue, then re-created from the migration's exact definition; green again.
 *
 * ---------------------------------------------------------------------------
 * ⚠ Step 5, 2026-09-11 — the first cell to LEAVE `⛔ none`, and what it cost
 * this file
 * ---------------------------------------------------------------------------
 * `Version20260911000100` gave `purchase_order` a nullable, live-row-unique,
 * **client-supplied** `idempotency_key` column, so `CreatePurchaseOrder`'s cell
 * moved from `⛔ none` to `unique:purchase_order_tenant_idem_live` and the
 * blocked set went from nine to **eight**.
 *
 * **It required this file to learn a second legitimate source for a `unique:`
 * key, and that is the part worth reading.** Every other `unique:` operation
 * satisfies *"the client supplies the indexed value"* out of §4.2's
 * accepted-field cell — a cell that describes the **body**. This one's key is
 * the `Idempotency-Key` **header**. Widening the reconciliation to *"or it comes
 * from somewhere else"* would have re-admitted the exact defect step 4 closed,
 * so the source is pinned instead: see `PINNED_HEADER_KEY`, whose four clauses
 * are each independently red-able, and which admits exactly one column —
 * `IdempotencyKeyGuard::COLUMN`, derived from the class that enforces the header.
 *
 * **`theServerGeneratedDocumentNumbersAreNotClientSuppliedKeys` was rewritten
 * rather than deleted.** It asserted both document-number operations were
 * `⛔ none`, which made it a test of the blocked *state* — so it failed on the one
 * transition it should have survived. What it was always about survives the fix:
 * a server-minted document number is never a retry key, and
 * `CreatePurchaseOrder` is now `unique:` on the client-supplied column and still
 * emphatically not on `purchase_order_tenant_number_live`.
 *
 * **What this file still cannot see, stated because it is the live residual:**
 * nothing writes the column. `PurchaseOrder` exposes no setter and
 * `CreatePurchaseOrder` does not exist — step 6 owns both — so the index refuses
 * nothing today. `noBlockedOperationShips` no longer covers this operation (it is
 * not blocked), and what holds the line instead is
 * `theHeaderSourcedKeysAreExactlyTheHeaderColumnAndDemandTheHeader`'s registry
 * clause: the day it ships without demanding the header, this is red. **A column
 * that exists is not a column that deduplicates**, one table over from the
 * lesson's first recording.
 *
 * ---------------------------------------------------------------------------
 * ⚠ Step 8-i, 2026-09-11 — the second header-sourced key, on a table that
 * never deletes
 * ---------------------------------------------------------------------------
 * `Version20260911000400` gave `goods_receipt` the same key, so
 * `CreateGoodsReceipt` moved to `unique:goods_receipt_idem_unique` and the
 * blocked set went from eight to **seven**. Nothing in this file had to be
 * loosened for it. The unique is **total**, because `goods_receipt` is class
 * `none` and has no `deleted_at`, and
 * `everyUniqueIndexIsLiveRowPartialExactlyWhenItsTableSoftDeletes` already
 * demanded exactly that of a non-soft table.
 *
 * **⚠ This paragraph used to end *"the partiality check is honest here as
 * written: 'not soft' means 'total', and `none` is not soft"*, and that was the
 * inference the check did not make.** It read partiality as
 * `str_contains($def, 'deleted_at IS NULL')`, so *"not partial on that one
 * predicate"* was recorded as *"total"* and **any other predicate passed**. A
 * `goods_receipt_idem_unique` written `WHERE received_at > …` would have read as
 * a total unique while constraining a subset, and a backdated retry would have
 * escaped the index this operation's cell names as its whole mechanism.
 * **Measured on 2026-09-12, not argued:** that index was re-created with exactly
 * that predicate against the live catalogue and the check stayed **green**. It
 * is now written in both directions and is red on it.
 *
 * ⚠ **One mutation in this set silently failed to apply, and it is recorded
 * because it is the lesson rather than a mishap.** The first attempt at
 * `everyBlockedOperationIsNamedByTheGate`'s mutation removed `IssueInvoice` from
 * **one** of the two places E9-2's row names it, and the check stayed green —
 * correctly, because the row still named the operation. The probe that was
 * supposed to confirm the mutation had landed counted matching *rows* rather
 * than *occurrences*, so it reported success. **A green result under a mutation
 * that did not apply is indistinguishable from a check that does not work**, and
 * the only thing that separated them was re-reading the file. Every probe here
 * now counts the thing it claims to count.
 */
final class IdempotencyContractTest extends TenancyTestCase
{
    /**
     * §4.2's 43 marked cells, transcribed once. The 72 `no` rows are not listed:
     * they are asserted as *"everything else"*, so a row that loses its mechanism
     * fails rather than silently joining them.
     *
     * @var array<string, string>
     */
    private const PINNED_MECHANISMS = [
        // unique:<index> — a CLIENT-SUPPLIED value collides on a named unique.
        'CreateItem' => 'unique:inventory_item_tenant_sku_live',
        'CreateWarehouse' => 'unique:warehouse_tenant_code_live',
        'CreateStorageLocation' => 'unique:storage_location_tenant_warehouse_code_live',
        'CreateSupplier' => 'unique:supplier_tenant_code_live',
        'CreateCustomer' => 'unique:customer_tenant_code_live',
        'CreateUser' => 'unique:app_user_tenant_email_live',
        'CreateRole' => 'unique:role_tenant_name_live',
        'CreateSupplierProduct' => 'unique:supplier_product_unique',
        'AssignRole' => 'unique:user_role_unique',
        // Roles slice 3 C1, 2026-10-04: the natural pair on a STATUS-PARTIAL unique (one pending proposal per
        // (user, role)), allowed by name in LIFECYCLE_PARTIALS. The header is demanded and unused, as `AssignRole`.
        'CreateRoleGrantProposal' => 'unique:role_grant_proposal_pending_unique',
        // ⚠ **Step 5, 2026-09-11.** The only `unique:` whose client-supplied
        // value arrives as the `Idempotency-Key` HEADER rather than in the body.
        // `Version20260911000100` laid the column; see PINNED_HEADER_KEY.
        'CreatePurchaseOrder' => 'unique:purchase_order_tenant_idem_live',
        // ⚠ **Step 8, 2026-09-11.** The second header-sourced key, and the
        // first on a table with no `deleted_at`: `goods_receipt` is class
        // `none`, so its unique is TOTAL, and
        // `everyUniqueIndexIsLiveRowPartialExactlyWhenItsTableSoftDeletes`
        // demands exactly that. `Version20260911000400` laid it.
        'CreateGoodsReceipt' => 'unique:goods_receipt_idem_unique',
        // ⚠ **V2-R-12, 2026-09-16 (V4 B3a).** Was `no`, and a retry wrote a
        // second line. The third header-sourced key; tenant-wide like the
        // order's, with the path's order id in the request digest.
        // `Version20260916000200` laid it.
        'CreatePurchaseOrderLine' => 'unique:purchase_order_line_tenant_idem_live',
        // ⚠ **V2-R-11, 2026-09-19 (Output 9, sales slice 1, commit 1).** Was
        // `⛔ none`, and a retried timeout minted a second sales order — P-5's
        // defect on the revenue side. The fourth header-sourced key, and the
        // first whose columns are `NOT NULL` on a **soft-deleting** table: the
        // unique is partial like the purchase order's, the columns are non-null
        // like the goods receipt's, because nothing in `src/` has ever written a
        // `sales_order` row. `Version20260919000100` laid it.
        'CreateSalesOrder' => 'unique:sales_order_tenant_idem_live',
        // ⚠ **V2-R-12, 2026-09-21 (Output 9, sales slice 4, commit 1).** Was
        // `⛔ none` since 2026-09-16. The fifth header-sourced key, and the
        // one that inverts `CreateSalesOrder`'s most distinctive decision: these
        // columns are **NULLABLE**, not `NOT NULL`, and that is forced rather
        // than preferred. `SalesOrderWriter::insertLineInside()` has written
        // `sales_order_line` rows since `c761ba8` as `CreateSalesOrder`'s nested
        // `lines[]`, and it always will — N lines of one request share ONE
        // `Idempotency-Key`, so `NOT NULL` plus `(tenant_id, idempotency_key)`
        // would make the second line of every multi-line create collide with the
        // first. `purchase_order_line` is the worked example, not `sales_order`.
        // `Version20260921000100` laid it.
        'CreateSalesOrderLine' => 'unique:sales_order_line_tenant_idem_live',
        // ⚠ **Sales step 5, commit 2, 2026-09-22.** Was `⛔ none`: a retried
        // timeout would have posted a second fulfilment and taken the stock out
        // twice. The sixth header-sourced key, and `CreateGoodsReceipt`'s shape
        // exactly -- `NOT NULL` (no fulfilment predates it; one row per request)
        // on a TOTAL unique (the table has no `deleted_at`). The path's order id
        // is digested, so the same key on another order is a conflict.
        // `Version20260922000100` laid it.
        'CreateFulfilment' => 'unique:fulfilment_idem_unique',
        // ⚠ **Sales step 6, commit C2, 2026-09-23.** Was `⛔ none` since the
        // D-A redesign. The first `unique:` whose client-supplied key is bound
        // by the PATH alone (`/fulfilments/{id}/invoices` → `fulfilment_id`),
        // and the first whose index is partial on a LIFECYCLE predicate rather
        // than the deletion marker: `invoice` is class `none`, and a voided
        // invoice must release the shipment for a corrected re-issue. Allowed by
        // name in LIFECYCLE_PARTIALS. **Refused, never replayed** — the header is
        // demanded (API-14) and unused. `Version20260923000100` laid it.
        'IssueInvoice' => 'unique:invoice_tenant_fulfilment_not_void',
        // ⚠ **Sales step 7, R1 + R2, 2026-10-01.** Was `⛔ none` since OI-2.
        // `fulfilment`'s shape exactly: a header-sourced key, `NOT NULL`, on a
        // TOTAL unique (class `none`); the path's fulfilment is digested.
        // `Version20261001000100` laid it; R2 shipped the writer.
        'CreateSalesReturn' => 'unique:sales_return_idem_unique',
        // ⚠ **Sales step 7, C0 + C1, 2026-10-01.** Was `⛔ none` since OI-2. The
        // return's shape exactly: a header-sourced key, `NOT NULL`, on a TOTAL
        // unique; the path's invoice is digested. `Version20261001000500` laid it.
        'IssueCreditNote' => 'unique:credit_note_idem_unique',
        // Sales step 7 C1b, 2026-10-01. SHARES `IssueCreditNote`'s index (design §14 item 2): each writer's request
        // digest binds its own operation name, so one key reused across the two is a conflict, never a replay.
        'IssueAdjustmentCreditNote' => 'unique:credit_note_idem_unique',
        // G-29 / G-30 part 3 C1, 2026-10-05: a header-sourced key on NULLABLE columns with a both-or-neither CHECK (the
        // V2-R-12 form): the adjust path's `distinct` rows (C2) ride the credit note's key, several to a request. The
        // unique is TOTAL (class none; NULLs never collide). The path's return and every declared field are digested.
        'CreateAdjustmentDecision' => 'unique:adjustment_decision_idem_unique',
        // ⚠ **Customers slice 1, C0, 2026-10-09.** Was `⛔ none`: `customer_address` carried no unique beyond its
        // surrogate, so a retried timeout wrote a second address. `fulfilment`'s shape exactly: a header-sourced key,
        // `NOT NULL` (nothing has ever written an address; one row per request), on a TOTAL unique (class `hard`, no
        // `deleted_at`); the path's customer is digested. `Version20261009000200` laid it; C3 ships the writer.
        'CreateCustomerAddress' => 'unique:customer_address_idem_unique',
        // ⚠ **Stock count C0, 2026-10-16.** Was `⛔ none` since 2026-09-11: `stock_movement` carried no unique beyond its
        // surrogate. `adjustment_decision`'s shape (design R1.5): a header-sourced key on NULLABLE columns with a
        // both-or-neither CHECK — the table already holds every receipt, shipment and return, none of which carries a key —
        // on a TOTAL unique (class `none`; NULLs never collide). `Version20261016000100` laid it; C1 ships the writer.
        'CreateStockAdjustment' => 'unique:stock_movement_idem_unique',

        // guard:<entity>.status — a repeat hits a §8.3 status guard.
        'ApprovePurchaseOrder' => 'guard:purchase_order.status',
        'CancelPurchaseOrder' => 'guard:purchase_order.status',
        'ConfirmSalesOrder' => 'guard:sales_order.status',
        'CancelSalesOrder' => 'guard:sales_order.status',
        // Roles slice 3 C2, 2026-10-05: a repeat finds the proposal no longer pending and `confirm()`'s guard refuses it.
        'ConfirmRoleGrantProposal' => 'guard:role_grant_proposal.status',
        // Roles slice 3 C3: a repeat meets a proposal no longer pending; `reject()` / `cancel()` refuse it.
        'RejectRoleGrantProposal' => 'guard:role_grant_proposal.status',
        'CancelRoleGrantProposal' => 'guard:role_grant_proposal.status',
        'PayInvoice' => 'guard:invoice.status',
        'VoidInvoice' => 'guard:invoice.status',
        // ⚠ **2026-09-14, owner ruling (OI-2).** Unbuilt. `paid → issued` is a
        // §8.3 transition, so a repeat finds the invoice already `issued` and
        // is refused by the status guard — no key needed.
        'ReverseInvoicePayment' => 'guard:invoice.status',

        // guard:<entity>.deleted_at — a repeat hits a LIVE row. NOT a status
        // guard: §8.3 excludes the soft-delete methods from API-15 by name.
        'RestorePurchaseOrder' => 'guard:purchase_order.deleted_at',
        'RestorePurchaseOrderLine' => 'guard:purchase_order_line.deleted_at',
        'RestoreSalesOrder' => 'guard:sales_order.deleted_at',
        'RestoreSalesOrderLine' => 'guard:sales_order_line.deleted_at',
        'RestoreItem' => 'guard:inventory_item.deleted_at',
        'RestoreWarehouse' => 'guard:warehouse.deleted_at',
        'RestoreStorageLocation' => 'guard:storage_location.deleted_at',
        'RestoreSupplier' => 'guard:supplier.deleted_at',
        'RestoreCustomer' => 'guard:customer.deleted_at',
        'RestoreUser' => 'guard:app_user.deleted_at',
        'RestoreRole' => 'guard:role.deleted_at',

        // store — the replay is recognised in `idempotency_key`. Scoped to the
        // three bulk operations: their response is known BEFORE commit.
        'CreateBulkArchival' => 'store',
        'CreateBulkRestoration' => 'store',
        'CreateBulkRoleAssignment' => 'store',

        // ⛔ none — declared idempotent, NO mechanism, blocked from shipping.
        //
        // ⚠ **THIS COUNT READ "EIGHT" AND WAS FALSE FROM 2026-09-16 UNTIL
        // 2026-09-21, AND COMMIT 1 WOULD HAVE MADE IT ACCIDENTALLY TRUE.**
        // `CreateSalesOrderLine` joined this block on 2026-09-16 under V2-R-12,
        // taking it to **nine**, and the sentence above it was never re-read.
        // ⚠ **Count the ARRAY ENTRIES, not the file's matches.** The obvious
        // `grep -c` for the cell value over this file answers one too many,
        // because this comment would then contain the literal it is counting —
        // a measurement that corrupts itself, which is why the spelling is
        // avoided here. Filter comment lines, or count the keys. Today
        // `CreateSalesOrderLine` LEAVES the block, so the number becomes eight
        // — **for a different reason than the one the old sentence gave**. It is
        // corrected here rather than allowed to heal into invisibility, because a
        // wrong count that silently becomes right is a wrong count nobody can
        // ever find. The same false EIGHT was live in shipped `src/`
        // (`IdempotencyKeyGuard`'s class docblock) and is corrected in the same
        // commit. **Derive the number — do not restate this line.**
        //
        // EIGHT today: `CreatePurchaseOrder` left on 2026-09-11 (step 5) and
        // `CreateGoodsReceipt` the same day (step 8); `CreateSalesReturn` and
        // `IssueCreditNote` joined on 2026-09-14 (owner ruling on OI-2 — neither
        // table exists, so nothing can refuse a retry); `CreateSalesOrder` left
        // on 2026-09-19 (V2-R-11); `CreateSalesOrderLine` joined on 2026-09-16
        // and left on 2026-09-21 (V2-R-12, sales slice 4 commit 1). `IssueInvoice`
        // was redesigned per shipment (D-A as amended) and stays here until
        // `invoice_tenant_fulfilment_not_void` exists -- **it left on 2026-09-23**
        // (sales step 6 C2), when that index was laid. **`CreateFulfilment` left on
        // 2026-09-22** (sales step 5 C2), so the block is one shorter again --
        // and **`CreateSalesReturn` left on 2026-10-01** (sales step 7 R2), and
        // **`IssueCreditNote` left the same day** (sales step 7 C1) --
        // and **`CreateCustomerAddress` left on 2026-10-09** (customers slice 1
        // C0, when `customer_address_idem_unique` was laid) --
        // and **`CreateStockAdjustment` left on 2026-10-16** (stock count C0,
        // when `stock_movement_idem_unique` was laid) --
        // count the entries below; do not read a number here.
        'CreateCustomerContact' => '⛔ none',
        'CreateStockTransfer' => '⛔ none',
    ];

    /**
     * §4.2's own totals, so a parser that silently stopped finding rows fails.
     *
     * **2026-09-14:** 114 − 2 withdrawn `no` rows (`RefreshToken`,
     * `RevokeSession`, D-14 / owner ruling C) + 3 added marked rows
     * (`ReverseInvoicePayment`, `CreateSalesReturn`, `IssueCreditNote`) = 115;
     * 76 − 2 = 74 unmarked.
     *
     * **2026-09-16 (V2-R-12):** `CreatePurchaseOrderLine` → `unique:` and
     * `CreateSalesOrderLine` → `⛔ none`, so 74 − 2 = 72 unmarked; 43 marked.
     *
     * **2026-09-22 (sales step 5 C3, owner ruling R7):** `GetPickList` joins §4.2 as the 116th row, `Idem`
     * `no`, so 115 + 1 = 116 rows and 72 + 1 = 73 unmarked; 43 marked, unchanged. Every row addition to
     * date has been an owner ruling, and this one is.
     *
     * **2026-10-01 (sales step 7 R2):** `GetSalesReturn` joins as the 117th row (`no`), and `CreateSalesReturn`
     * moves from `⛔ none` to `unique:` (marked to marked): 117 rows, 74 unmarked, 43 marked. **R3:** `ListSalesReturns`
     * (`no`): 118 rows, 75 unmarked.
     *
     * **2026-10-01 (sales step 7 C0):** `ListInvoiceLines` (`no`): 119 rows, 76 unmarked, 43 marked.
     *
     * **2026-10-01 (sales step 7 C1):** `GetCreditNote` (`no`): 120 rows, 77 unmarked; `IssueCreditNote` moves from
     * `⛔ none` to `unique:` (marked to marked): 43 marked.
     *
     * **2026-10-01 (sales step 7 C1b):** `IssueAdjustmentCreditNote` joins as a marked row
     * (`unique:credit_note_idem_unique`, shared with `IssueCreditNote`): 121 rows, 77 unmarked, 44 marked.
     *
     * **2026-10-02 (sales step 7 C2):** `ListCreditNotes` (`no`): 122 rows, 78 unmarked, 44 marked.
     *
     * **2026-10-04 (roles slice 3 C1):** `ListRoleGrantProposals` and `GetRoleGrantProposal` (`no`), and
     * `CreateRoleGrantProposal` (`unique:role_grant_proposal_pending_unique`): 125 rows, 80 unmarked, 45 marked.
     *
     * **2026-10-05 (roles slice 3 C2):** `ConfirmRoleGrantProposal` (`guard:role_grant_proposal.status`): 126 rows, 80
     * unmarked, 46 marked.
     *
     * **2026-10-05 (roles slice 3 C3):** `RejectRoleGrantProposal` and `CancelRoleGrantProposal`
     * (`guard:role_grant_proposal.status`): 128 rows, 80 unmarked, 48 marked.
     *
     * **2026-10-05 (G-29 / G-30 part 3 C1):** `CreateAdjustmentDecision` (`unique:adjustment_decision_idem_unique`):
     * 129 rows, 80 unmarked, 49 marked.
     *
     * **2026-10-08 (users slice 1 C3):** `ListOfferedRoles` (`no`; K-3, owner ruling Q-U2): 130 rows, 81 unmarked, 49 marked.
     *
     * **2026-10-16 (stock count C0):** `CreateStockAdjustment` `⛔ none` → `unique:stock_movement_idem_unique` — marked to
     * marked, so neither count moves.
     */
    private const PINNED_ROW_COUNT = 130;

    private const PINNED_UNMARKED_COUNT = 81;

    /**
     * For each `unique:` operation, the index's key columns **less `tenant_id`** —
     * which is to say the part of the key the *client* has to supply for a retry
     * to collide at all.
     *
     * This is the pin that expresses the finding the old column could not.
     *
     * ⚠ **This docblock read *"`CreatePurchaseOrder` is absent from this table
     * and always will be: its index's key column is `order_number`, which the
     * server mints"* until 2026-09-19, and the array three lines below it has
     * listed that operation since step 5 (2026-09-11).** The sentence was true
     * when written and was never re-read when the header source was added: the
     * operation is here, keyed on `IdempotencyKeyGuard::COLUMN`, and its index
     * is `purchase_order_tenant_idem_live`, not the document-number unique.
     * **What survives of it is the part that matters and it is asserted rather
     * than narrated:** a server-minted document number is never a client key,
     * which is `PINNED_SERVER_MINTED_KEYS` and
     * `theServerGeneratedDocumentNumbersAreNotClientSuppliedKeys`. Corrected
     * under standing rule 1 while adding the fourth header-sourced entry,
     * because leaving a sentence that its own array contradicts is this
     * project's characteristic defect rather than a nit.
     *
     * @var array<string, list<string>>
     */
    private const PINNED_CLIENT_KEY = [
        'CreateItem' => ['sku'],
        'CreateWarehouse' => ['code'],
        'CreateStorageLocation' => ['warehouse_id', 'code'],
        'CreateSupplier' => ['code'],
        'CreateCustomer' => ['code'],
        'CreateUser' => ['email'],
        'CreateRole' => ['name'],
        'CreateSupplierProduct' => ['supplier_id', 'inventory_item_id'],
        'AssignRole' => ['user_id', 'role_id'],
        // Roles slice 3 C1: `user_id` from the path's `/users/{id}` (pathBoundColumns, response R-7), `role_id` from the body.
        'CreateRoleGrantProposal' => ['user_id', 'role_id'],
        // The header-sourced one. See PINNED_HEADER_KEY for why that is a
        // legitimate source and why it cannot be stretched into a general one.
        'CreatePurchaseOrder' => [IdempotencyKeyGuard::COLUMN],
        'CreateGoodsReceipt' => [IdempotencyKeyGuard::COLUMN],
        'CreatePurchaseOrderLine' => [IdempotencyKeyGuard::COLUMN],
        'CreateSalesOrder' => [IdempotencyKeyGuard::COLUMN],
        'CreateSalesOrderLine' => [IdempotencyKeyGuard::COLUMN],
        'CreateFulfilment' => [IdempotencyKeyGuard::COLUMN],
        'CreateSalesReturn' => [IdempotencyKeyGuard::COLUMN],
        'IssueCreditNote' => [IdempotencyKeyGuard::COLUMN],
        'IssueAdjustmentCreditNote' => [IdempotencyKeyGuard::COLUMN],
        'CreateAdjustmentDecision' => [IdempotencyKeyGuard::COLUMN],
        'CreateCustomerAddress' => [IdempotencyKeyGuard::COLUMN],
        'CreateStockAdjustment' => [IdempotencyKeyGuard::COLUMN],
        // Sales step 6 C2: bound by the PATH (`/fulfilments/{id}/invoices`), so
        // the client supplies it by addressing the shipment it bills.
        'IssueInvoice' => ['fulfilment_id'],
    ];

    /**
     * **The operations whose client-supplied key arrives as the
     * `Idempotency-Key` HEADER rather than as a request body field.**
     *
     * ---------------------------------------------------------------------
     * Why a second source was needed at all
     * ---------------------------------------------------------------------
     * `unique:` means *"a client-supplied value collides on a named live-row
     * unique"*, and `everyPinnedClientKeyColumnIsAcceptedOrBoundByThePath`
     * reconciles that against §4.2's **accepted-field** cell — a cell that
     * describes the **body**. Every other `unique:` operation satisfies it that
     * way: `CreateItem` sends `sku`.
     *
     * `CreatePurchaseOrder` cannot, and not because of an oversight in the
     * contract. Its retry key **is** the idempotency key, and that value is
     * carried by the header P-5 already requires. Adding it to the accepted-field
     * cell instead would put a second spelling of the same value in the body,
     * where a client could send one value in the header and a different one in
     * the body and the two mechanisms would disagree about what identifies the
     * retry.
     *
     * ---------------------------------------------------------------------
     * Why this is NOT "anything goes", which is the whole risk of adding a source
     * ---------------------------------------------------------------------
     * A source that merely said *"or it comes from somewhere else"* would
     * re-admit exactly the class of defect step 4 closed: an index the caller
     * cannot collide with, excused by an unfalsifiable claim. Four clauses stop
     * that, and every one of them can fail:
     *
     *   1. the column must be **`IdempotencyKeyGuard::COLUMN`** — derived from
     *      the class that enforces the header, not restated here, so there is
     *      exactly one column a header key may land in;
     *   2. it must be the **whole** key less `tenant_id`, read from
     *      `pg_indexes` — so the header can never excuse a *missing* body field
     *      on a composite key such as `storage_location`'s `[warehouse_id, code]`;
     *   3. the operation's cell must genuinely demand the header (`unique:`
     *      does, under API-14 as amended) — a `guard:` or `no` operation cannot
     *      claim a header source for anything;
     *   4. the column must be **absent** from §4.2's accepted fields — one
     *      value, one source.
     *
     * A wrong cell therefore still fails. Point a header-sourced operation at a
     * business column and clause 1 is red; claim the header covers half a
     * composite key and clause 2 is red; claim it for an operation that does not
     * demand the header and clause 3 is red.
     *
     * @var list<string>
     */
    private const PINNED_HEADER_KEY = [
        'CreatePurchaseOrder',
        'CreateGoodsReceipt',
        'CreatePurchaseOrderLine',
        // V2-R-11, 2026-09-19. Same ground as `CreatePurchaseOrder`'s: the
        // order's own unique is on the server-minted `order_number`, so the
        // client-supplied value is the header and putting a second spelling of
        // it in the body is what clause 4 forbids.
        'CreateSalesOrder',
        // V2-R-12, 2026-09-21. Same ground again, one level down: the line
        // table's other unique is on the server-minted `line_number`, so the
        // only client-supplied value is the header. The path's order id goes
        // into the request DIGEST, not into the index key — which is how the
        // same key sent to a different order is refused rather than replayed.
        'CreateSalesOrderLine',
        // Sales step 5 C2, 2026-09-22. `CreateGoodsReceipt`'s ground: the
        // table's other unique is on the server-minted `fulfilment_number`.
        'CreateFulfilment',
        // Sales step 7 R2, 2026-10-01. The fulfilment's ground exactly.
        'CreateSalesReturn',
        // Sales step 7 C1, 2026-10-01. The return's ground: the other unique is on the server-minted `credit_note_number`.
        'IssueCreditNote',
        // Sales step 7 C1b, 2026-10-01. The same table and index as `IssueCreditNote`, the same ground.
        'IssueAdjustmentCreditNote',
        // G-29 / G-30 part 3 C1, 2026-10-05. A decision row has no client-supplied business key at all.
        'CreateAdjustmentDecision',
        // Customers slice 1 C0, 2026-10-09. `CreateGoodsReceipt`'s ground: the table's only other key is the
        // surrogate; one row per request.
        'CreateCustomerAddress',
        // Stock count C0, 2026-10-16. `CreateGoodsReceipt`'s ground: the table's only other key is the surrogate; one
        // movement per request.
        'CreateStockAdjustment',
        // NOT `IssueInvoice` (sales step 6 C2): its key is the path's fulfilment,
        // not the header, so it is not header-sourced.
    ];

    /**
     * **The phrases a blocking clause may not use, because no check can compute
     * who is inside them.**
     *
     * Both were E9-2's own wording and both have now failed in *both* directions.
     * *"Create-a-child or bulk"* did not reach `CreatePurchaseOrder`, which is
     * neither, so an unprotected operation sat outside the gate; and it did still
     * reach `CreateGoodsReceipt` after step 8-i gave that operation a real
     * mechanism, so a protected operation shipped past a gate that formally
     * blocked it. The category is not a shorthand for the set — **it is a second,
     * unmaintained copy of the set**, and the two drift.
     *
     * Kept deliberately short. The list is not an attempt to enumerate every noun
     * a category could be phrased with; it is the two this project has actually
     * been bitten by, pinned so they cannot come back. A fresh category would
     * escape it, and `noGateBlocksByCategoryOrBlocksAnOperationThatNowHasAMechanism`'s
     * second clause is what catches the consequence rather than the wording.
     *
     * @var list<string>
     */
    /**
     * The one predicate a live-row unique may carry, as `pg_indexes` normalises
     * it. See `everyUniqueIndexIsLiveRowPartialExactlyWhenItsTableSoftDeletes`.
     */
    private const LIVE_ROW_PREDICATE = '(deleted_at IS NULL)';

    /**
     * **The named-predicate allowance: a `unique:` index on a deletion-class
     * `none` table that is partial on a LIFECYCLE predicate, pinned by name AND by
     * exact predicate** (sales step 6 C2, 2026-09-23). Its one member is D-A's
     * one-live-invoice-per-shipment rule — a voided invoice releases the shipment.
     * The spelling is the catalogue's normalisation, measured with `indexdef` and
     * `pg_get_expr(indpred, indrelid)` after the migration (M-5): both read
     * `(status <> 'void'::text)`. `SchemaManifestTest` carries its **own literal**
     * of the same fact on purpose (one shared constant would be one source).
     *
     * @var array<string, string>
     */
    private const LIFECYCLE_PARTIALS = [
        'invoice_tenant_fulfilment_not_void' => "(status <> 'void'::text)",
        // Roles slice 3 C1 (design F-10, R-7's by-name exception; `DATA` §1.4): one PENDING proposal per (user, role) on
        // the class-`none` `role_grant_proposal`. The predicate is the catalogue's normalised spelling (M-3, measured).
        'role_grant_proposal_pending_unique' => "(status = 'pending'::text)",
    ];

    private const UNDECIDABLE_CATEGORIES = [
        'create-a-child',
        'bulk operations',
    ];

    /**
     * The two document-number uniques that look like retry keys and are not.
     * `theServerGeneratedDocumentNumbersAreNotClientSuppliedKeys` is the
     * assertion; this is the subject.
     *
     * @var array<string, string>
     */
    private const PINNED_SERVER_MINTED_KEYS = [
        'CreatePurchaseOrder' => ['column' => 'order_number', 'index' => 'purchase_order_tenant_number_live'],
        'CreateSalesOrder' => ['column' => 'order_number', 'index' => 'sales_order_tenant_number_live'],
    ];

    // -----------------------------------------------------------------------
    // The pin, against the document.
    // -----------------------------------------------------------------------

    /**
     * Both directions, over the whole table: the marked rows are exactly the pin,
     * and everything else is `no`.
     *
     * **Mutation, run:** `CreatePurchaseOrder`'s §4.2 cell changed from
     * `**⛔ none**` to `no`. Red — *"Failed asserting that 77 is identical to
     * 76"*, the unmarked count. **It does not name the operation, and that is
     * worth stating rather than glossing:** this method's job is the shape of the
     * whole column, so it reports a total; the methods that care about a
     * particular operation are the ones below, and they name it.
     */
    #[Test]
    public function theContractsMarkedRowsAreExactlyThePinnedMechanisms(): void
    {
        $this->requireContract();

        $rows = ContractDocument::operationRows();

        self::assertCount(self::PINNED_ROW_COUNT, $rows,
            '§4.2 no longer has '.self::PINNED_ROW_COUNT.' rows, so every comparison below has moved under it.');

        $marked = [];
        $unmarked = 0;

        foreach ($rows as $operationId => $row) {
            if ('no' === $row['idem']) {
                ++$unmarked;

                continue;
            }
            $marked[$operationId] = $row['idem'];
        }

        self::assertSame(self::PINNED_UNMARKED_COUNT, $unmarked, 'The `no` count moved.');

        ksort($marked);
        $pinned = self::PINNED_MECHANISMS;
        ksort($pinned);

        self::assertSame($pinned, $marked);
    }

    /**
     * Every cell is one of the five declared forms, and **all five are in use** —
     * the second half being what stops this turning into a check over four
     * vocabularies plus a dead one.
     *
     * **Mutation, run:** `CreateItem`'s pinned mechanism set to a bare `unique:`
     * with no index named. Red. *(The pin was mutated rather than the document
     * cell, because the pin is what this method reads — mutating the cell would
     * have tested `theContractsMarkedRowsAreExactlyThePinnedMechanisms` instead.)*
     */
    #[Test]
    public function everyMechanismUsesTheDeclaredVocabulary(): void
    {
        $forms = [
            'unique' => '/^unique:[a-z0-9_]+$/',
            'guard' => '/^guard:[a-z_]+\.[a-z_]+$/',
            'store' => '/^store$/',
            'blocked' => '/^⛔ none$/',
        ];

        $seen = [];

        foreach (self::PINNED_MECHANISMS as $operationId => $mechanism) {
            $matched = null;
            foreach ($forms as $name => $pattern) {
                if (1 === preg_match($pattern, $mechanism)) {
                    $matched = $name;
                    break;
                }
            }

            self::assertNotNull($matched, $operationId.' has a mechanism outside API-14\'s vocabulary: '.$mechanism);
            $seen[$matched] = true;
        }

        self::assertSame(array_keys($forms), array_keys($seen),
            'A declared form is unused, so this check covers a smaller vocabulary than the contract declares.');
    }

    // -----------------------------------------------------------------------
    // `unique:` — against the live catalogue.
    // -----------------------------------------------------------------------

    /**
     * **THE load-bearing check, and §6's own mutation is the proof it works.**
     *
     * Every `unique:<index>` names an index that exists in `pg_indexes` and is
     * actually `UNIQUE`. The failure message names the **operation**, not the
     * index: an index is a schema object somebody may drop for good reasons, and
     * what this test is about is the operation whose entire retry contract that
     * index is.
     *
     * **Mutation, RUN:** `DROP INDEX inventory_item_tenant_sku_live` and re-run —
     * red, naming `CreateItem`. Re-created; green again.
     */
    #[Test]
    public function everyUniqueMechanismNamesAUniqueIndexInTheLiveCatalogue(): void
    {
        $catalogue = $this->indexCatalogue();
        $missing = [];

        foreach ($this->mechanismsOfKind('unique:') as $operationId => $indexName) {
            if (!isset($catalogue[$indexName])) {
                $missing[] = $operationId.' → '.$indexName.' (no such index)';

                continue;
            }
            if (!str_contains(strtoupper($catalogue[$indexName]['def']), 'CREATE UNIQUE INDEX')) {
                $missing[] = $operationId.' → '.$indexName.' (exists but is not UNIQUE)';
            }
        }

        self::assertSame([], $missing,
            'An operation\'s §4.2 cell names a unique index the database does not have, so its retry contract '
            .'is a claim about nothing. A repeat of that operation writes a second row.');
    }

    /**
     * Live-row partiality is not a style rule — it is *"which rows does this
     * unique actually constrain"*, and the right answer is decided by the table's
     * deletion class, not by convention.
     *
     * A `soft` table's unique **must** be partial on `deleted_at IS NULL`, or an
     * archived row keeps blocking a legitimate re-create. A `hard` table's unique
     * must **not** be, because there is no `deleted_at` to be partial on and every
     * row is live — which is why `supplier_product_unique` and `user_role_unique`
     * are total and still correct.
     *
     * ---------------------------------------------------------------------------
     * ⚠ It asked the wrong question until 2026-09-12, and called itself honest
     * ---------------------------------------------------------------------------
     * Partiality was read as `str_contains($def, 'deleted_at IS NULL')`, so
     * *"partial"* meant *"partial on that one predicate"* and **every other
     * predicate read as total**. On a non-soft table — `goods_receipt`, class
     * `none` — a unique written `WHERE received_at > '2026-01-01'`, or
     * `WHERE status <> 'void'`, or anything else, would have passed this check as
     * a total unique while constraining a subset of the rows. **A backdated retry
     * would then escape the index the contract names as its whole mechanism.**
     * This file's step 8-i note said the check was *"honest here as written"* on
     * the ground that *"not soft" means "total"*; that is the inference the check
     * does not actually make.
     *
     * **It now asks what it means to ask, in both directions:**
     *
     *   - a **non-soft** table's unique must have **no `WHERE` at all** — total is
     *     total, not "total except for the predicate I did not think of";
     *   - a **soft** table's predicate must be **exactly `(deleted_at IS NULL)`**,
     *     not merely contain it, so `WHERE deleted_at IS NULL AND status = 'x'`
     *     is red rather than mistaken for a live-row unique.
     *
     * The predicate is taken from the catalogue's own `indexdef`, which Postgres
     * normalises and parenthesises, so the comparison is against what the server
     * holds rather than against the migration's spelling.
     *
     * **This is where `IssueInvoice` arrived when D-A's schema was built (sales
     * step 6 C2, 2026-09-23)**: its ruled index is partial on `status <> 'void'`
     * and `invoice` is class `none`, so it taught this check that predicate BY
     * NAME, in {@see self::LIFECYCLE_PARTIALS} — exact, not "contains", so a
     * narrower lifecycle predicate (`status = 'issued'`) is still red.
     *
     * **Mutations, run** (against the live catalogue, each restored from the
     * migration's exact definition): **(a)** `goods_receipt_idem_unique` dropped
     * and re-created with `WHERE received_at > '2000-01-01'::timestamptz` — the
     * escape the old check could not see. Red now, **green before**. **(b)**
     * `inventory_item_tenant_sku_live` re-created without its
     * `WHERE deleted_at IS NULL`, the original mutation, still red. **(c)** the
     * same index re-created with `WHERE deleted_at IS NULL AND sku <> ''` — red
     * now, **green before**.
     */
    #[Test]
    public function everyUniqueIndexIsLiveRowPartialExactlyWhenItsTableSoftDeletes(): void
    {
        $catalogue = $this->indexCatalogue();
        $checked = 0;

        foreach ($this->mechanismsOfKind('unique:') as $operationId => $indexName) {
            self::assertArrayHasKey($indexName, $catalogue, $operationId.' names a missing index.');

            $table = $catalogue[$indexName]['table'];
            self::assertArrayHasKey($table, SchemaManifest::TABLES, $table.' is not in the manifest.');

            $softDeletes = SchemaManifest::SOFT === SchemaManifest::TABLES[$table]['deletion'];
            $predicate = self::predicateOf($catalogue[$indexName]['def']);

            $expected = $softDeletes ? self::LIVE_ROW_PREDICATE : (self::LIFECYCLE_PARTIALS[$indexName] ?? null);

            self::assertSame($expected, $predicate,
                $operationId.'\'s unique '.$indexName.' on '.$table.' is '
                .(null === $predicate ? 'total' : 'partial on '.$predicate)
                .' while the manifest classes the table '.SchemaManifest::TABLES[$table]['deletion'].'. '
                .'A soft-deleting table needs a live-row partial and nothing else in the predicate, or an '
                .'archived row blocks a re-create or some other subset escapes it; a non-soft one must have '
                .'no WHERE at all, because any predicate leaves rows the unique does not constrain and the '
                .'contract names this index as the whole mechanism.');
            ++$checked;
        }

        self::assertGreaterThan(0, $checked, 'No unique mechanism was examined.');
    }

    /**
     * An index's `WHERE` predicate as the catalogue spells it, or `null` when it
     * has none.
     *
     * `pg_indexes.indexdef` is the server's own normalisation — it parenthesises
     * the predicate and re-prints the expression — so `WHERE deleted_at IS NULL`
     * comes back as `WHERE (deleted_at IS NULL)` however the migration wrote it.
     * Matching on that normalised form is the point: it compares against what the
     * database enforces, not against a spelling.
     */
    private static function predicateOf(string $def): ?string
    {
        return 1 === preg_match('/\bWHERE\s+(.+)$/', $def, $m) ? trim($m[1]) : null;
    }

    /**
     * **`unique:` requires the client to send the value the index is built on**,
     * and this is the check that distinguishes `CreateItem` from
     * `CreatePurchaseOrder` — the distinction the binary column had no way to
     * express and which is the whole finding.
     *
     * The index's key columns are read out of the **live catalogue**; the client's
     * contribution is the pin. A column counts as client-supplied if the caller
     * sends it in the body, or if the **path** binds it — `/warehouses/{id}/locations`
     * supplies `warehouse_id` as surely as the body supplies `code`.
     *
     * **Mutation, run:** `PINNED_CLIENT_KEY['CreateItem']` changed from `['sku']`
     * to `['order_number']`. Red against the **catalogue** — *"is keyed on [sku],
     * which is not the client-supplied set this contract pins"* — which is the
     * direction that matters: the pin cannot drift away from the real index.
     */
    #[Test]
    public function everyUniqueIndexsKeyColumnsAreSuppliedByTheClient(): void
    {
        $catalogue = $this->indexCatalogue();

        foreach ($this->mechanismsOfKind('unique:') as $operationId => $indexName) {
            self::assertArrayHasKey($indexName, $catalogue, $operationId.' names a missing index.');
            self::assertArrayHasKey($operationId, self::PINNED_CLIENT_KEY,
                $operationId.' is `unique:` and declares no client-supplied key, which is the '
                .'`CreatePurchaseOrder` shape: an index the caller cannot collide with.');

            $keyColumns = array_values(array_diff($catalogue[$indexName]['columns'], ['tenant_id']));

            self::assertSame(self::PINNED_CLIENT_KEY[$operationId], $keyColumns,
                $operationId.'\'s index '.$indexName.' is keyed on ['.implode(', ', $keyColumns)
                .'], which is not the client-supplied set this contract pins.');
        }
    }

    /**
     * The document half of the clause above: each pinned column is one §4.2 says
     * the caller sends.
     *
     * **Mutation, run:** `AssignRole`'s §4.2 accepted-field cell emptied of
     * `role_id`. Red — *"§4.2 shows the caller supplying neither that field []
     * nor a path binding it [user_id]"*, which also demonstrates the path-binding
     * derivation working on the half of the key it does cover.
     */
    #[Test]
    public function everyPinnedClientKeyColumnIsAcceptedOrBoundByThePath(): void
    {
        $this->requireContract();

        $rows = ContractDocument::operationRows();
        $checked = 0;

        foreach (self::PINNED_CLIENT_KEY as $operationId => $columns) {
            self::assertArrayHasKey($operationId, $rows, $operationId.' has left §4.2.');

            $accepted = ContractDocument::acceptedFields($operationId);
            $bound = self::pathBoundColumns($rows[$operationId]['path']);

            // The THIRD source, and it is deliberately the narrowest of the
            // three: not "a header", but *the* header column, and only for an
            // operation pinned as header-sourced. Everything that makes that
            // admissible rather than a loophole is asserted by
            // `theHeaderSourcedKeysAreExactlyTheHeaderColumnAndDemandTheHeader`.
            $headerSourced = \in_array($operationId, self::PINNED_HEADER_KEY, true);

            foreach ($columns as $column) {
                $byHeader = $headerSourced && IdempotencyKeyGuard::COLUMN === $column;

                self::assertTrue(
                    \in_array($column, $accepted, true) || \in_array($column, $bound, true) || $byHeader,
                    $operationId.' is `unique:` on '.$column.', and §4.2 shows the caller supplying neither '
                    .'that field ['.implode(', ', $accepted).'] nor a path binding it ['.implode(', ', $bound).'] '
                    .'nor the '.IdempotencyKeyGuard::HEADER.' header. '
                    .'A unique the client cannot collide with refuses no retry.',
                );
                ++$checked;
            }
        }

        self::assertGreaterThan(0, $checked, 'No client key column was examined.');
    }

    /**
     * **The counterfactual, asserted rather than described.** `purchase_order` and
     * `sales_order` both carry a live-row unique on a document number, and both
     * were *assumed* to be covered by it. They are not, because the number is
     * server-minted.
     *
     * ⚠ **Rewritten at step 5's arrival, 2026-09-11, and the rewrite is the point
     * rather than a repair.** This method used to assert that both operations
     * were `⛔ none`, which made it a test of the *blocked* state — so it went red
     * the moment `CreatePurchaseOrder` gained a mechanism, which is the one
     * transition it should have survived. **The content it was always about is
     * narrower and outlives the fix: a server-minted document number is never a
     * retry key.** `CreatePurchaseOrder` is now `unique:` — on
     * `purchase_order_tenant_idem_live`, the **client-supplied** column
     * `Version20260911000100` laid — and this method still says the thing that
     * matters, which is that it is emphatically **not** `unique:` on
     * `purchase_order_tenant_number_live`.
     *
     * The second clause stays red-able forever: the day `order_number` becomes an
     * accepted field, the operation's key would be client-supplied and this goes
     * red.
     *
     * **Mutation, RUN:** `PINNED_MECHANISMS['CreatePurchaseOrder']` repointed to
     * `unique:purchase_order_tenant_number_live` — the exact wrong fix step 5
     * could have shipped. Red: *"CreatePurchaseOrder's mechanism is the
     * server-minted document number index."*
     */
    #[Test]
    public function theServerGeneratedDocumentNumbersAreNotClientSuppliedKeys(): void
    {
        $this->requireContract();

        $checked = 0;

        foreach (self::PINNED_SERVER_MINTED_KEYS as $operationId => $pin) {
            self::assertArrayHasKey($operationId, self::PINNED_MECHANISMS,
                $operationId.' has left the mechanism pin, so this counterfactual has no subject.');

            self::assertNotSame('unique:'.$pin['index'], self::PINNED_MECHANISMS[$operationId],
                $operationId.'\'s mechanism is the server-minted document number index '.$pin['index'].'. '
                .$pin['column'].' is not in its accepted-field set, so the caller sends nothing that index '
                .'can collide on and a retry mints a new number and a second document.');

            self::assertNotContains($pin['column'], ContractDocument::acceptedFields($operationId),
                $operationId.' now accepts '.$pin['column'].', so the document number is client-supplied '
                .'and this counterfactual is stale.');
            ++$checked;
        }

        self::assertGreaterThan(0, $checked, 'No server-minted key was examined.');
    }

    // -----------------------------------------------------------------------
    // The second legitimate source for a `unique:` key — the header.
    // -----------------------------------------------------------------------

    /**
     * **The clause that keeps the header source from becoming a loophole**, and
     * the reason `everyPinnedClientKeyColumnIsAcceptedOrBoundByThePath` could
     * learn a third source without being loosened into *"anything goes"*.
     *
     * Read `PINNED_HEADER_KEY`'s docblock for the argument. This method is
     * clauses 1, 2 and 3 of it, against the **live catalogue** and the
     * **registry**; the document half is the method below.
     *
     * The column name is taken from `IdempotencyKeyGuard::COLUMN` rather than
     * written here, so there is one definition of *"the column a header key lands
     * in"* and a rename cannot leave the test pointing at the old one.
     *
     * **Mutation, RUN:** `PINNED_CLIENT_KEY['CreatePurchaseOrder']` changed from
     * `[IdempotencyKeyGuard::COLUMN]` to `['order_number']` — the header excusing
     * a server-minted column, which is the precise defect this source could
     * otherwise re-admit. Red on clause 1, naming the operation.
     */
    #[Test]
    public function theHeaderSourcedKeysAreExactlyTheHeaderColumnAndDemandTheHeader(): void
    {
        self::assertNotSame([], self::PINNED_HEADER_KEY, 'No operation is header-sourced, so this clause has no subject.');

        $catalogue = $this->indexCatalogue();
        $shipped = array_keys(TransactionBoundaryTest::contractOperations());
        self::assertNotSame([], $shipped, 'The registry produced no operations.');

        foreach (self::PINNED_HEADER_KEY as $operationId) {
            $mechanism = self::PINNED_MECHANISMS[$operationId] ?? 'no';

            // 3. Only a mechanism that actually demands the header may claim to
            //    be honoured by it. `guard:`, `store` and `no` cannot.
            self::assertStringStartsWith('unique:', $mechanism,
                $operationId.' is pinned as header-sourced and its mechanism is `'.$mechanism.'`. '
                .'Only a `unique:` cell demands the '.IdempotencyKeyGuard::HEADER.' header under API-14 as amended, '
                .'so nothing else can be honoured by it.');

            // 1. Exactly one column, and it is the guard's own.
            self::assertSame([IdempotencyKeyGuard::COLUMN], self::PINNED_CLIENT_KEY[$operationId] ?? [],
                $operationId.' is header-sourced and its pinned client key is not exactly ['
                .IdempotencyKeyGuard::COLUMN.']. A header key may land in that column and no other, or the '
                .'source becomes an excuse for any index the caller cannot collide with.');

            // 2. The whole key less tenant_id -- never part of a composite one.
            $indexName = substr($mechanism, \strlen('unique:'));
            self::assertArrayHasKey($indexName, $catalogue, $operationId.' names a missing index '.$indexName.'.');

            $keyColumns = array_values(array_diff($catalogue[$indexName]['columns'], ['tenant_id']));
            self::assertSame([IdempotencyKeyGuard::COLUMN], $keyColumns,
                $operationId.'\'s index '.$indexName.' is keyed on ['.implode(', ', $keyColumns).']. '
                .'A header supplies one value, so it can only honour an index whose entire key is that column -- '
                .'it must never excuse a body field missing from a composite key.');

            // The registry half: if it ships, it must really demand the header.
            // `theHeaderIsRequiredExactlyWhereTheMechanismNeedsIt` asserts this
            // over every shipped operation; repeated here because THIS is the
            // operation whose whole mechanism is the header.
            if (\in_array($operationId, $shipped, true)) {
                $operation = TransactionBoundaryTest::contractOperations()[$operationId];
                self::assertTrue(
                    true === ($operation->getExtraProperties()[IdempotencyKeyGuard::OPERATION_FLAG] ?? null),
                    $operationId.' is shipped, its retry key is the '.IdempotencyKeyGuard::HEADER.' header, and it '
                    .'does not demand that header. The column it collides on would be NULL on every request.',
                );
            }
        }
    }

    /**
     * The document half: clause 4, plus the contract's own agreement that the
     * header is demanded.
     *
     * **One value, one source.** The column must be **absent** from §4.2's
     * accepted fields. If the caller could also send `idempotency_key` in the
     * body, two spellings of one value would exist and nothing would decide which
     * identifies the retry when they disagree.
     *
     * **Mutation, RUN:** `idempotency_key` added to `CreatePurchaseOrder`'s §4.2
     * accepted-field cell. Red — *"CreatePurchaseOrder accepts idempotency_key as
     * a body field."*
     */
    #[Test]
    public function everyHeaderSourcedKeyIsAbsentFromTheAcceptedFieldsAndDemandedByTheContract(): void
    {
        $this->requireContract();

        self::assertNotSame([], self::PINNED_HEADER_KEY, 'No operation is header-sourced, so this clause has no subject.');

        foreach (self::PINNED_HEADER_KEY as $operationId) {
            self::assertTrue(ContractDocument::headerIsRequiredFor($operationId),
                $operationId.'\'s §4.2 cell does not demand the '.IdempotencyKeyGuard::HEADER.' header, and its '
                .'retry key is that header. The value the index is built on would never arrive.');

            self::assertNotContains(IdempotencyKeyGuard::COLUMN, ContractDocument::acceptedFields($operationId),
                $operationId.' accepts '.IdempotencyKeyGuard::COLUMN.' as a body field while its retry key is the '
                .IdempotencyKeyGuard::HEADER.' header. One value with two sources: a caller could send different '
                .'ones and nothing decides which identifies the retry.');
        }
    }

    // -----------------------------------------------------------------------
    // `guard:` — against the entities and the manifest.
    // -----------------------------------------------------------------------

    /**
     * Every `guard:<entity>.status` names a table whose entity really is a status
     * machine — it declares `STATUS_*` constants and has a method that writes
     * `$this->status`. Derived by reflection over `src/`, never listed.
     *
     * **`cancel()` does not exist yet** (§8.3's *(no method exists)* rows,
     * API-O-17), so this asserts the machine rather than the individual method —
     * `CancelPurchaseOrder` is declared in §4.2 and is unshipped for exactly that
     * reason, and `noBlockedOrUnbuiltOperationShips` is what holds it unshipped.
     *
     * **Mutation, run:** `PayInvoice`'s pin repointed to
     * `guard:no_such_table.status`. Red, naming the operation and listing the
     * machines actually found under `src/` — *"Machines found: purchase_order,
     * invoice, sales_order, tenant"* — which is the evidence the domain is
     * derived by reflection and not restated here.
     */
    #[Test]
    public function everyStatusGuardNamesARealStatusMachine(): void
    {
        $machines = self::statusMachinesByTable();
        self::assertNotSame([], $machines, 'No status machine was found under src/, so this check has no subject.');

        $checked = 0;

        foreach ($this->mechanismsOfKind('guard:') as $operationId => $property) {
            [$table, $column] = explode('.', $property, 2);

            if ('status' !== $column) {
                continue;
            }

            self::assertArrayHasKey($table, $machines,
                $operationId.' is guarded on '.$table.'.status and no entity mapped to that table is a status '
                .'machine — it declares no STATUS_* constants, or nothing writes $this->status. '
                .'Machines found: '.implode(', ', array_keys($machines)));

            self::assertNotSame([], StatusMachines::transitionMethods($machines[$table]),
                $operationId.'\'s entity has no transition method at all.');
            ++$checked;
        }

        self::assertGreaterThan(0, $checked, 'No status guard was examined.');
    }

    /**
     * Every `guard:<entity>.deleted_at` names a table the manifest classes `soft`.
     * That is what makes the refusal real: a replayed `Restore*` hits a **live**
     * row and is answered `business-rule-violated`.
     *
     * **Mutation, run:** `inventory_item`'s manifest class flipped `SOFT` →
     * `HARD`. Red, naming `RestoreItem`.
     */
    #[Test]
    public function everyDeletedAtGuardNamesASoftDeletingTable(): void
    {
        $checked = 0;

        foreach ($this->mechanismsOfKind('guard:') as $operationId => $property) {
            [$table, $column] = explode('.', $property, 2);

            if ('deleted_at' !== $column) {
                continue;
            }

            self::assertArrayHasKey($table, SchemaManifest::TABLES, $operationId.' names an unknown table '.$table.'.');
            self::assertSame(SchemaManifest::SOFT, SchemaManifest::TABLES[$table]['deletion'],
                $operationId.' is guarded on '.$table.'.deleted_at and the manifest does not class that table '
                .'`soft`, so there is no archived state for a repeat to be refused from.');
            ++$checked;
        }

        self::assertSame(11, $checked, 'The `Restore*` set is not the size §4.2 declares.');
    }

    // -----------------------------------------------------------------------
    // `store` and `⛔ none` — against the registry and the gate.
    // -----------------------------------------------------------------------

    /**
     * `store` is scoped to the three bulk operations, and **none of them ships**,
     * because the replay path does not exist: `IdempotencyKeyGuard` has no
     * database dependency at all.
     *
     * The second half is the one that matters. A `store` cell is the only value in
     * the vocabulary that names a mechanism which is **not built**, so without
     * this assertion the cell would be exactly the unchecked wish the amendment
     * abolished — one layer further in.
     *
     * **Mutation, run:** `CreateGoodsReceipt`'s pin changed `⛔ none` → `store`,
     * making a fourth `store` operation. Red.
     */
    #[Test]
    public function everyStoreMechanismIsABulkOperationAndNoneShipsWithoutTheReplayPath(): void
    {
        $store = array_keys($this->mechanismsOfKind('store'));
        sort($store);

        self::assertSame(
            ['CreateBulkArchival', 'CreateBulkRestoration', 'CreateBulkRoleAssignment'],
            $store,
            '§6.1 scopes the envelope store to the three bulk operations: their response is known before '
            .'commit, which is what makes the no-UPDATE grant sufficient.',
        );

        $shipped = array_keys(TransactionBoundaryTest::contractOperations());

        foreach ($store as $operationId) {
            self::assertNotContains($operationId, $shipped,
                $operationId.' is shipped and its mechanism is `store`, but nothing reads or writes '
                .'`idempotency_key`. It would advertise a replay contract the system cannot honour.');
        }
    }

    /**
     * **The clause that makes `⛔ none` a control rather than a note.**
     *
     * Every operation whose cell says it has no mechanism must be named — by name
     * — in `DELIVERY_LEDGER.md`'s E9-2. E9-2 originally named a **category**
     * (*"six create-a-child or three bulk"*), and that is precisely how
     * `CreatePurchaseOrder`, which is neither, fell outside both the rule and the
     * gate while looking protected.
     *
     * **Mutation, run:** **every** occurrence of `IssueInvoice` removed from
     * E9-2's row — there are two, and removing only the one in the operation list
     * leaves the row still naming it, which is why the first attempt stayed
     * green. Red once both were gone.
     */
    #[Test]
    public function everyBlockedOperationIsNamedByTheGate(): void
    {
        $this->requireLedger();

        $row = DeliveryLedgerDocument::gateRow('E9-2');
        self::assertNotSame('', $row, 'E9-2 has left DELIVERY_LEDGER.md, so nothing gates the blocked operations.');

        $named = DeliveryLedgerDocument::operationsNamedBy('E9-2');
        self::assertNotSame([], $named, 'E9-2 names no operation, so this clause has no subject.');

        $blocked = array_keys($this->mechanismsOfKind('⛔ none'));
        sort($blocked);

        $unnamed = array_values(array_diff($blocked, $named));

        self::assertSame([], $unnamed,
            'An operation is marked `⛔ none` in §4.2 and no gate names it as blocked. That is the exact '
            .'shape that let `CreatePurchaseOrder` sit in the contract looking protected: an unprotected '
            .'operation nothing stops from being scheduled.');
    }

    /**
     * **The other direction, and the one that was missing: a gate must not still
     * block an operation that has since earned a mechanism — and it must say
     * *which* operations it blocks in terms something can decide.**
     *
     * `everyBlockedOperationIsNamedByTheGate` above runs `⛔ none` → *named*. It
     * cannot see the failure this stops, and did not: E9-2's condition opened with
     * a **category** — *"any of §4.2's six create-a-child or three bulk operations
     * while API-O-1 is open"* — and `CreateGoodsReceipt` is a create-a-child
     * operation. Step 8-i gave it `unique:goods_receipt_idem_unique` and edited
     * the **by-name** clause; the category clause was untouched, API-O-1 is still
     * `⟦LIVE⟧`, and step 8-ii shipped the operation straight through it. There was
     * no runtime defect — the operation is protected, by a real unique on a real
     * client-supplied column — but a gate that a shipped operation walks past is
     * not a gate, and the next one through it may not be protected.
     *
     * **A category cannot be reconciled against a set.** That is already this
     * file's own finding, recorded when `CreatePurchaseOrder` fell outside
     * *"create-a-child or bulk"* in the other direction, and the repair was
     * applied to one clause of one row rather than to the rule. So this check
     * makes the rule the rule:
     *
     *   1. **No blocking clause may name a category.** The condition cell and
     *      API-O-1's register row must both decide their subject from §4.2's
     *      `Idem` cell or from an operation name — never from *"create-a-child"*
     *      or *"bulk operations"*, whose membership no test can compute.
     *   2. **Every operation a condition names must still be `⛔ none`.** The
     *      *why* cell is deliberately exempt: it is the narrative of how the gate
     *      was widened and narrowed and it names operations that have since left,
     *      which is why the condition cell is read alone.
     *
     * **Mutation, run:** `` `CreateGoodsReceipt` `` added to E9-2's condition cell
     * (1 occurrence, counted). Red on clause 2 — *"E9-2's condition still blocks
     * `CreateGoodsReceipt`, whose §4.2 cell reads
     * `unique:goods_receipt_idem_unique`"*. Reverted, `cmp`-identical.
     */
    #[Test]
    public function noGateBlocksByCategoryOrBlocksAnOperationThatNowHasAMechanism(): void
    {
        $this->requireLedger();
        $this->requireContract();

        $condition = DeliveryLedgerDocument::gateCondition('E9-2');
        self::assertNotSame('', $condition, 'E9-2 has no condition cell, so nothing states what it blocks.');

        $register = ContractDocument::registerRow('API-O-1');
        self::assertNotSame('', $register, 'API-O-1 has left §14.1, so E9-2\'s citation of it dangles.');

        // 1. Neither clause may decide its subject by a category.
        foreach (self::UNDECIDABLE_CATEGORIES as $category) {
            self::assertStringNotContainsString($category, $condition,
                'E9-2\'s condition blocks by the category "'.$category.'". Membership of a category is not '
                .'computable from §4.2, so no check can tell whether a shipped operation is inside it — which '
                .'is how `CreateGoodsReceipt` shipped past this clause carrying a `unique:` cell. State the '
                .'condition against the `Idem` cell, or by operation name.');

            self::assertStringNotContainsString($category, $register,
                'API-O-1 blocks by the category "'.$category.'", and E9-2\'s condition cites API-O-1 being '
                .'open. The same undecidability reaches the gate through the citation.');
        }

        // 2. Nothing a condition names may have earned a mechanism since.
        $stillBlocked = [];

        foreach (DeliveryLedgerDocument::operationsNamedIn($condition) as $operationId) {
            $mechanism = self::PINNED_MECHANISMS[$operationId] ?? null;

            if (null === $mechanism || '⛔ none' === $mechanism) {
                continue;
            }

            $stillBlocked[$operationId] = $mechanism;
        }

        self::assertSame([], $stillBlocked,
            'E9-2\'s condition still blocks an operation whose §4.2 cell now names a mechanism: '
            .json_encode($stillBlocked, \JSON_THROW_ON_ERROR).'. A gate that outlives its subject either '
            .'stops a shipped operation or is walked past; both are how a gate stops being one.');
    }

    /**
     * The consequence of the gate, asserted against the registry: none of them
     * ships.
     *
     * **Mutation, run:** `CreateItem`'s pin changed to `⛔ none` while the
     * operation is shipped. Red — *"CreateItem is marked `⛔ none` and is shipped.
     * A retried timeout on it writes a second row."*
     */
    #[Test]
    public function noBlockedOperationShips(): void
    {
        $shipped = array_keys(TransactionBoundaryTest::contractOperations());
        self::assertNotSame([], $shipped, 'The registry produced no operations.');

        foreach (array_keys($this->mechanismsOfKind('⛔ none')) as $operationId) {
            self::assertNotContains($operationId, $shipped,
                $operationId.' is marked `⛔ none` and is shipped. A retried timeout on it writes a second row.');
        }
    }

    // -----------------------------------------------------------------------
    // The header requirement, which is the amendment's substantive half.
    // -----------------------------------------------------------------------

    /**
     * **`IdempotencyKeyGuard::OPERATION_FLAG` is set on exactly the shipped
     * operations whose mechanism needs the header — `store` and `unique:`.**
     *
     * This is the reconciliation the guard's docblock has always claimed and never
     * had. It is bidirectional: an operation carrying the flag with a `guard:`
     * cell is red (a refusal that protects nothing), and one omitting it with a
     * `unique:` cell is red (a retry that is not asked to identify itself).
     *
     * **Mutation, run:** `IdempotencyKeyGuard::OPERATION_FLAG` removed from
     * `CreateItem`, whose cell is `unique:` and which therefore must demand the
     * header. Red. *(The opposite direction — restoring the flag to a `guard:`
     * operation — is asserted by the same `$wrong` collection and was **not**
     * separately run, because `ApprovePurchaseOrder`'s flag removal also took the
     * class's only `IdempotencyKeyGuard` import with it.)*
     */
    #[Test]
    public function theHeaderIsRequiredExactlyWhereTheMechanismNeedsIt(): void
    {
        $operations = TransactionBoundaryTest::contractOperations();
        self::assertNotSame([], $operations, 'The registry produced no operations.');

        $demanded = [];
        $wrong = [];

        foreach ($operations as $name => $operation) {
            $mechanism = self::PINNED_MECHANISMS[$name] ?? 'no';
            $needsHeader = 'store' === $mechanism || str_starts_with($mechanism, 'unique:');
            $carriesFlag = true === ($operation->getExtraProperties()[IdempotencyKeyGuard::OPERATION_FLAG] ?? null);

            if ($carriesFlag) {
                $demanded[] = $name;
            }
            if ($needsHeader !== $carriesFlag) {
                $wrong[] = $name.' (cell `'.$mechanism.'`, '.($carriesFlag ? 'demands' : 'does not demand').' the header)';
            }
        }

        self::assertSame([], $wrong,
            'API-14 as amended demands `Idempotency-Key` for `store` and `unique:` only. A `guard:` operation '
            .'refuses its own repeat whether or not a header arrives, so 428-ing without one is a refusal '
            .'with no purpose.');

        self::assertNotSame([], $demanded,
            'No shipped operation demands the header, so the clause above is satisfied vacuously.');
    }

    // -----------------------------------------------------------------------
    // Derivations
    // -----------------------------------------------------------------------

    /**
     * The pinned mechanisms of one kind, keyed by operation, with the prefix
     * stripped from the value.
     *
     * @return array<string, string>
     */
    private function mechanismsOfKind(string $prefix): array
    {
        $found = [];

        foreach (self::PINNED_MECHANISMS as $operationId => $mechanism) {
            if (str_starts_with($mechanism, $prefix)) {
                $found[$operationId] = substr($mechanism, \strlen($prefix));
            }
        }

        return $found;
    }

    /**
     * Every index in the live schema, with the table it is on, its key columns and
     * its definition.
     *
     * **Read from `pg_indexes` rather than parsed out of the migrations**, and the
     * distinction is deliberate: the migrations are what somebody *wrote*, and
     * this check is about what the database *has*. An index created and later
     * dropped, or created only in a `down()`, reads correctly here and would read
     * wrongly from the files. The required mutation — dropping an index and
     * re-running — is only meaningful against the catalogue.
     *
     * @return array<string, array{table: string, columns: list<string>, def: string}>
     */
    private function indexCatalogue(): array
    {
        $rows = $this->owner()->fetchAllAssociative(
            "SELECT indexname, tablename, indexdef FROM pg_indexes WHERE schemaname = 'public'",
        );

        self::assertNotSame([], $rows, 'pg_indexes returned nothing, so every index check would be vacuous.');

        $catalogue = [];

        foreach ($rows as $row) {
            $def = (string) $row['indexdef'];
            $columns = [];

            // `… USING btree (tenant_id, sku) WHERE (deleted_at IS NULL)` — the
            // FIRST parenthesised group is the key; a partial predicate follows it.
            if (1 === preg_match('/\((.*?)\)/', $def, $match)) {
                foreach (explode(',', $match[1]) as $column) {
                    $columns[] = trim($column);
                }
            }

            $catalogue[(string) $row['indexname']] = [
                'table' => (string) $row['tablename'],
                'columns' => $columns,
                'def' => $def,
            ];
        }

        return $catalogue;
    }

    /**
     * Every status machine under `src/`, keyed by the table its entity maps to.
     *
     * @return array<string, class-string>
     */
    private static function statusMachinesByTable(): array
    {
        $byTable = [];

        foreach (StatusMachines::all() as $entity) {
            $attributes = (new \ReflectionClass($entity))->getAttributes(Table::class);

            if ([] === $attributes) {
                continue;
            }

            $name = $attributes[0]->newInstance()->name;

            if (\is_string($name)) {
                $byTable[$name] = $entity;
            }
        }

        return $byTable;
    }

    /**
     * The columns a path binds, as foreign keys. `/warehouses/{id}/locations`
     * binds `warehouse_id`; `/users/{id}/roles` binds `user_id`.
     *
     * Derived from the path rather than declared, so a sub-resource added
     * tomorrow is covered. The singularisation is the crude one — strip a
     * trailing `s` — and that is sufficient and checkable here because every
     * column it produces is compared against a **real index's** column list: a
     * wrong guess cannot pass, it can only fail.
     *
     * @return list<string>
     */
    private static function pathBoundColumns(string $path): array
    {
        preg_match_all('#/([a-z-]+)/\{[a-zA-Z]+\}#', $path, $matches);

        $columns = [];

        foreach ($matches[1] as $segment) {
            $columns[] = rtrim(str_replace('-', '_', $segment), 's').'_id';
        }

        return $columns;
    }

    private function requireContract(): void
    {
        if (!ContractDocument::isAvailable()) {
            self::markTestSkipped('docs/API.md is gitignored and absent from this checkout, so the pins above are checked against the code and the catalogue only.');
        }
    }

    private function requireLedger(): void
    {
        if (!DeliveryLedgerDocument::isAvailable()) {
            self::markTestSkipped('docs/DELIVERY_LEDGER.md is gitignored and absent from this checkout, so the gate cannot be read.');
        }
    }
}
