<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Modules\Purchasing\Infrastructure\Entity\PurchaseOrder;
use App\Modules\Sales\Infrastructure\Entity\SalesOrder;
use App\Modules\UserManagement\Domain\DefaultRoles;
use App\Platform\Http\Idempotency\IdempotencyKeyGuard;
use App\SharedKernel\ValueObject\AuditEvent;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Uid\Uuid;

/**
 * **`docs/API.md` §6.1's own *fails if* clause, which names this class by name:
 * *"an operation marked `Idem` is implemented with no key store and no
 * precondition that refuses the repeat — `IdempotencyTest::everyIdempotentOperationRefusesAReplay`,
 * which derives its domain from the operation registry's `Idem` flag and replays
 * each request with the same key and body, asserting the second call writes no
 * second row."***
 *
 * ---------------------------------------------------------------------------
 * Why this is a second file and not a rename of `IdempotencyContractTest`
 * ---------------------------------------------------------------------------
 * The delivery ledger's step 9 line reads *"derived guards — `TransitionGuardTest`,
 * `IdempotencyTest`, each mutated"*, and no file had that name, while
 * `IdempotencyContractTest` did. The two were checked against §6.1 rather than
 * assumed to be the same thing, and **they are not**:
 *
 *  - `IdempotencyContractTest` reconciles §4.2's `Idem` **cells** against the
 *    live catalogue, the status machines, `SchemaManifest` and the ledger's
 *    gate. It answers *"does the mechanism this table claims exist, and is it
 *    the shape it claims?"*. **It never sends a request twice.**
 *  - This file answers the other question, which is the one a client asks:
 *    *"if I send it twice, does it happen twice?"* — over HTTP, counting **rows
 *    in the database** after the second call.
 *
 * A mechanism can be perfectly declared and perfectly indexed and still be
 * bypassed by a processor that never reaches it. Nothing here reads a document.
 *
 * ---------------------------------------------------------------------------
 * Individual replay cases already existed. A domain did not.
 * ---------------------------------------------------------------------------
 * `PurchaseOrderCreateTest::aRetriedCreateYieldsOneOrderAndNotTwo`,
 * `GoodsReceiptRetryKeyStorageTest` and `ItemWriteTest` each drive their own
 * operation's replay, and each is better at its own operation than anything
 * here. **What none of them can do is fail when a NEW operation ships without
 * one** — a set of individual cases is not a domain, and *"every operation"* was
 * exactly the claim §6.1 made. {@see self::REPLAY_CASES} is reconciled against
 * the derived set below, so the seventh operation to carry a live mechanism is
 * red until somebody replays it.
 *
 * ⚠ **Measured, because it decides whether this file should exist at all.** The
 * receipt's replay read was short-circuited and the **whole suite** run: four
 * tests went red, and **three of them were `GoodsReceiptCreateTest`'s own**. So
 * the six cases below are, for the operations that already have per-operation
 * coverage, **largely redundant — and that is said here rather than left for a
 * reviewer to find.** The load-bearing member of this file is
 * `theDomainIsEveryShippedOperationWhoseMechanismCanBeExercised`, and its value
 * is exactly one thing: **nothing else in this project fails when an operation
 * ships with a declared mechanism and no replay is ever sent.** §6.1's *fails
 * if* clause has claimed that check existed since it was written, and it did
 * not — the same species as `IdempotencyKeyGuard`'s docblock, and as the
 * deptrac test cited for months before `edee43d` wrote it.
 *
 * The cases are kept rather than reduced to the registry because a coverage gate
 * whose entries are names and not executions is the *next* version of the same
 * defect; an entry here has to actually send the request twice.
 *
 * ---------------------------------------------------------------------------
 * The domain, and the two exclusions that are not holes
 * ---------------------------------------------------------------------------
 * Derived as: every operation the **registry** ships, whose §4.2 mechanism is
 * not `no`, intersected with what that mechanism can be exercised against today.
 *
 *  - **`⛔ none` is excluded because nothing marked it may ship at all**, and
 *    `IdempotencyContractTest::noBlockedOperationShips` is what enforces that.
 *    An operation that appeared in both sets would be red there first.
 *  - **`store` is excluded because the store has no replay path** (API-O-31) and
 *    the three operations that use it are unbuilt. The same
 *    `noBlockedOperationShips`-shaped guard covers that:
 *    `everyStoreMechanismIsABulkOperationAndNoneShipsWithoutTheReplayPath`.
 *    **The intersection is asserted below rather than described**, so neither
 *    exclusion can quietly start hiding a shipped operation.
 */
final class IdempotencyTest extends PurchaseOrderTestCase
{
    use InvoiceFixtures;
    use CreditNoteFixtures;

    /**
     * The replay case for each shipped operation with a live mechanism. The keys
     * are checked against the derived set; the values name a method here.
     *
     * `why` is what the second call must NOT have done, in the words the
     * mechanism makes true — `unique:` operations must not create a second row,
     * `guard:` operations must not apply a second effect to the one they have.
     *
     * @var array<string, array{method: string, why: string}>
     */
    private const REPLAY_CASES = [
        'CreateItem' => [
            'method' => 'replayCreateItem',
            'why' => 'A second inventory_item with the same SKU. Its mechanism is the live-row unique on (tenant, sku).',
        ],
        'CreateCustomer' => [
            'method' => 'replayCreateCustomer',
            'why' => 'A second customer with the same code. Its mechanism is the live-row unique on (tenant, code), refused, never '
                .'replayed (customers slice 1 C2), and a refused replay writes no second customer.created row.',
        ],
        'CreateWarehouse' => [
            'method' => 'replayCreateWarehouse',
            'why' => 'A second warehouse with the same code. Its mechanism is the live-row unique on (tenant, code), refused, never '
                .'replayed (warehouses slice 1 C2), and a refused replay writes no second warehouse.created row.',
        ],
        'CreateStorageLocation' => [
            'method' => 'replayCreateStorageLocation',
            'why' => 'A second storage location with the same code in the same warehouse. Its mechanism is the live-row unique on '
                .'(tenant, warehouse, code), refused, never replayed (warehouses slice 1 C3), and a refused replay writes no second '
                .'storage_location.created row.',
        ],
        'CreateSupplier' => [
            'method' => 'replayCreateSupplier',
            'why' => 'A second supplier with the same code. Its mechanism is the live-row unique on (tenant, code), refused, never '
                .'replayed (suppliers slice 1 C2), and a refused replay writes no second supplier.created row.',
        ],
        'CreateSupplierProduct' => [
            'method' => 'replayCreateSupplierProduct',
            'why' => 'A second supplier_product for the same (supplier, item). Its mechanism is the TOTAL unique on (tenant, '
                .'supplier, item) — the table is hard — refused, never replayed (suppliers slice 1 C3): one row.',
        ],
        'CreateCustomerAddress' => [
            'method' => 'replayCreateCustomerAddress',
            'why' => 'A second customer_address for the same request (customers slice 1 C3). Its mechanism is the header-sourced '
                .'retry key on a TOTAL unique (the table is hard): the repeat replays the stored address, same id, one row.',
        ],
        'CreatePurchaseOrder' => [
            'method' => 'replayCreatePurchaseOrder',
            'why' => 'A second purchase_order. Its mechanism is the header-sourced retry key on a live-row unique.',
        ],
        'CreateSalesOrder' => [
            'method' => 'replayCreateSalesOrder',
            'why' => 'A second sales_order, and a second copy of its lines and its total. Its mechanism is the '
                .'header-sourced retry key on a live-row unique, as CreatePurchaseOrder\'s is.',
        ],
        'CreateGoodsReceipt' => [
            'method' => 'replayCreateGoodsReceipt',
            'why' => 'A second goods_receipt, and a second helping of quantity_received on the order line.',
        ],
        'CreateFulfilment' => [
            'method' => 'replayCreateFulfilment',
            'why' => 'A second fulfilment, a second helping of quantity_fulfilled on the order line, and the stock taken '
                .'out twice (sales step 5). Its mechanism is the header-sourced retry key on a TOTAL unique.',
        ],
        'AssignRole' => [
            'method' => 'repeatAssignRole',
            'why' => 'A second user_role row for one (user, role) and a second role.assigned row (roles slice 2 C3). Its '
                .'mechanism is `unique:user_role_unique` on the natural pair, and a repeat is REFUSED 409 '
                .'user_role.already_held, never replayed: the key header is required and its value is not stored.',
        ],
        'CreateRole' => [
            'method' => 'repeatCreateRole',
            'why' => 'A second role of one name and a second role.permissions_changed row (roles slice 4 C1). Its mechanism is '
                .'`unique:role_tenant_name_live` on the natural key `name` — checked case-insensitively under the role lock '
                .'first — and a repeat is REFUSED 409 role.name_taken, never replayed: the key header is required and its '
                .'value is not stored.',
        ],
        'CreateRoleGrantProposal' => [
            'method' => 'repeatCreateRoleGrantProposal',
            'why' => 'A second pending proposal for one (user, role) and a second role.assignment_proposed row (roles slice 3 '
                .'C1). Its mechanism is `unique:role_grant_proposal_pending_unique` on the natural pair, partial on the '
                .'LIFECYCLE (one PENDING proposal), and a repeat is REFUSED 409 role_grant_proposal.already_pending, never '
                .'replayed: the key header is required and its value is not stored.',
        ],
        'IssueInvoice' => [
            'method' => 'repeatIssueInvoice',
            'why' => 'A second live invoice for one shipment, and a second invoice.issued row (sales step 6 C3). Its '
                .'mechanism is `unique:invoice_tenant_fulfilment_not_void`, partial on the LIFECYCLE, and a repeat is '
                .'REFUSED, never replayed (API.md 4.2 item 1): the key header is required and unused.',
        ],
        'CreateSalesReturn' => [
            'method' => 'replayCreateSalesReturn',
            'why' => 'A second sales_return, and the returned stock put back twice (sales step 7 R2). Its mechanism is the '
                .'header-sourced retry key on a TOTAL unique, CreateFulfilment\'s shape.',
        ],
        'IssueCreditNote' => [
            'method' => 'replayIssueCreditNote',
            'why' => 'A second credit_note, the invoice reduced twice (sales step 7 C1). Its mechanism is the header-sourced '
                .'retry key on a TOTAL unique, the return\'s shape.',
        ],
        'IssueAdjustmentCreditNote' => [
            'method' => 'replayIssueAdjustmentCreditNote',
            'why' => 'A second adjustment credit_note, the invoice written down twice (sales step 7 C1b). It shares '
                .'IssueCreditNote\'s index; its digest binds its own operation name.',
        ],
        'CreateAdjustmentDecision' => [
            'method' => 'replayCreateAdjustmentDecision',
            'why' => 'A second adjustment_decision for one return line and adjustment, and the returned units taken out of the '
                .'return\'s room twice (G-29 / G-30 part 3 C1). Its mechanism is the header-sourced retry key on nullable '
                .'both-or-neither columns under a TOTAL unique.',
        ],
        'CreatePurchaseOrderLine' => [
            'method' => 'replayCreatePurchaseOrderLine',
            'why' => 'A second purchase_order_line, and a second helping of its amount on the order total (V2-R-12). Its '
                .'mechanism is the header-sourced retry key on a live-row unique.',
        ],
        'CreateSalesOrderLine' => [
            'method' => 'replayCreateSalesOrderLine',
            'why' => 'A second sales_order_line, and a second helping of its amount on the order total (V2-R-12, the '
                .'revenue-side twin). Its mechanism is the header-sourced retry key on a live-row unique.',
        ],
        'ApprovePurchaseOrder' => [
            'method' => 'replayApprovePurchaseOrder',
            'why' => 'A second placement of an order already placed. Its mechanism is the status guard, which refuses '
                .'the repeat with no key at all — so the check is that the row is byte-identical afterwards.',
        ],
        'CancelPurchaseOrder' => [
            'method' => 'replayCancelPurchaseOrder',
            'why' => 'A second cancellation of a cancelled order. Status guard, as above.',
        ],
        'ConfirmSalesOrder' => [
            'method' => 'replayConfirmSalesOrder',
            'why' => 'A second confirmation of an order already confirmed. Its mechanism is the status guard, which '
                .'refuses the repeat with no key at all — §4.2 gives it guard:sales_order.status and no header — so the '
                .'check is that the row is byte-identical afterwards, ordered_at included.',
        ],
        'ConfirmRoleGrantProposal' => [
            'method' => 'replayConfirmRoleGrantProposal',
            'why' => 'A second confirmation of a proposal already confirmed (roles slice 3 C2): a second user_role row and a '
                .'second role.assigned row. Its mechanism is the status guard (guard:role_grant_proposal.status, no header): '
                .'the repeat meets a proposal that is no longer pending and is refused, and the row is byte-identical after.',
        ],
        'RejectRoleGrantProposal' => [
            'method' => 'replayDecideRoleGrantProposal',
            'why' => 'A second rejection of a rejected proposal (roles slice 3 C3): a second role.assignment_rejected row and '
                .'a rewritten reason. Status guard (guard:role_grant_proposal.status, no header): the repeat is refused and '
                .'the row is byte-identical after.',
        ],
        'CancelRoleGrantProposal' => [
            'method' => 'replayDecideRoleGrantProposal',
            'why' => 'A second withdrawal of a withdrawn proposal (roles slice 3 C3): a second role.assignment_cancelled row. '
                .'Status guard, as Reject.',
        ],
        'PayInvoice' => [
            'method' => 'replayPayInvoice',
            'why' => 'A second payment of a paid invoice: a second invoice.paid row, and the recorder or paid_at '
                .'overwritten (sales step 6 C5). Its mechanism is the status guard, which refuses the replay.',
        ],
        'ReverseInvoicePayment' => [
            'method' => 'replayReverseInvoicePayment',
            'why' => 'A second reversal of a reversed payment: a second invoice.payment_reversed row, or B′\'s '
                .'payment_reversed_at moved (sales step 6 C6). Its mechanism is the status guard: the invoice is issued again.',
        ],
        'VoidInvoice' => [
            'method' => 'replayVoidInvoice',
            'why' => 'A second void of a void invoice: a second invoice.voided row, or updated_at moved (sales step 6 C7). '
                .'Its mechanism is the status guard: the invoice is already void.',
        ],
        'CancelSalesOrder' => [
            'method' => 'replayCancelSalesOrder',
            'why' => 'A second cancellation of a cancelled order. Status guard, as the purchase mirror above -- and '
                .'the row read is not the whole of the check here: this operation writes an audit row, so the replay '
                .'must also leave the trail with exactly ONE sales_order.cancelled for the order. audit_log is '
                .'append-only by grant, so a duplicate would be permanent.',
        ],
        'RestoreSalesOrderLine' => [
            'method' => 'replayRestoreSalesOrderLine',
            'why' => 'A second restoration of a live line. Its mechanism is the soft-delete predicate rather than a '
                .'status, which is why §4.2 spells it guard:<table>.deleted_at -- and the order TOTAL is part of the '
                .'check here, because a restore raises it and a second one would raise it twice.',
        ],
        'RestoreItem' => [
            'method' => 'replayRestoreItem',
            'why' => 'A second restoration of a live row. Its mechanism is the soft-delete predicate rather than a '
                .'status, which is why §4.2 spells it guard:<table>.deleted_at.',
        ],
    ];

    /**
     * **The domain, derived from the registry and the mechanism table rather
     * than from the list above.**
     *
     * **Mutation:** delete any entry from `REPLAY_CASES`; red, naming the
     * operation that ships with a mechanism nothing replays. **Reverse
     * mutation:** add one for an operation that does not ship; also red.
     */
    #[Test]
    public function theDomainIsEveryShippedOperationWhoseMechanismCanBeExercised(): void
    {
        $shipped = array_keys(TransactionBoundaryTest::contractOperations());
        self::assertNotSame([], $shipped, 'The registry produced no operations, so this file has no subject.');

        $exercisable = [];
        foreach (self::mechanisms() as $operationId => $mechanism) {
            if (!\in_array($operationId, $shipped, true)) {
                continue;
            }

            if ('no' === $mechanism || '⛔ none' === $mechanism || 'store' === $mechanism) {
                // The last two are asserted unshippable by IdempotencyContractTest;
                // seeing one here would mean that guard had stopped working, so it
                // is named rather than skipped.
                self::assertSame('no', $mechanism, \sprintf(
                    '%s ships with the mechanism "%s", which IdempotencyContractTest asserts cannot ship. '
                    .'This file excluded it on that guarantee; the guarantee has failed.', $operationId, $mechanism));

                continue;
            }

            $exercisable[] = $operationId;
        }

        sort($exercisable);
        $covered = array_keys(self::REPLAY_CASES);
        sort($covered);

        self::assertNotSame([], $exercisable, 'No shipped operation carries a live mechanism, so every case below is vacuous.');
        self::assertSame($covered, $exercisable,
            'The operations that ship with a mechanism §4.2 names are not the ones replayed here. A mechanism that is '
            .'declared, indexed and never sent twice is the gap §6.1\'s *fails if* is about: the contract test says the '
            .'index exists, and nothing says the processor reaches it.');
    }

    /**
     * §6.1's clause, one operation at a time.
     *
     * **Mutations, run 2026-09-12, each landed by occurrence count and reverted
     * `cmp`-identical:** the client's key replaced by a server-minted one in
     * `PurchaseOrderWriter::create()` → red on `CreatePurchaseOrder`;
     * `PurchaseOrder::place()`'s status guard deleted → red on
     * `ApprovePurchaseOrder` (200 where 409 is owed); the receipt's replay read
     * short-circuited in `GoodsReceiptWriter::createInside()` → red on
     * `CreateGoodsReceipt`.
     *
     * ⚠ **The third one was GREEN on the first attempt and the test was wrong,
     * not the mutation.** With the replay read bypassed, the second delivery
     * loses at `goods_receipt_idem_unique` instead — so one row was still the
     * answer and a case that only counted rows stayed green over a broken replay
     * path. The index stops the second delivery; the replay read is what turns
     * the retry into an *answer*. The case now asserts the returned id, which is
     * what §6.2 requires of a `unique:` operation carrying a stored digest, and
     * the same mutation reddens it. See `PurchaseOrderRowLockTest`'s twin for the
     * same distinction at the race boundary.
     */
    #[Test]
    #[DataProvider('replayCases')]
    public function everyIdempotentOperationRefusesAReplay(string $operationId): void
    {
        $case = self::REPLAY_CASES[$operationId];

        $this->{$case['method']}($case['why']);
    }

    /** @return iterable<string, array{string}> */
    public static function replayCases(): iterable
    {
        foreach (array_keys(self::REPLAY_CASES) as $operationId) {
            yield $operationId => [$operationId];
        }
    }

    // -----------------------------------------------------------------------
    // The cases. Each sends the SAME request twice, and reads the database as
    // the owner afterwards — a response is not evidence about a row.
    // -----------------------------------------------------------------------

    private function replayCreateItem(string $why): void
    {
        $body = ['sku' => 'SKU-REPLAY', 'name' => 'Widget', 'unit_of_measure_code' => 'EA'];
        $key = [IdempotencyKeyGuard::HEADER => Uuid::v4()->toRfc4122()];

        $this->send('POST', '/items', $body, $key);
        self::assertSame(201, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());

        $this->send('POST', '/items', $body, $key);

        self::assertCount(1, $this->fetchAsOwnerForTenant(self::TENANT_A, 'SELECT id FROM inventory_item'), $why);
    }

    private function replayCreateSupplierProduct(string $why): void
    {
        $supplier = $this->seedSupplier(self::TENANT_A, 'Replayed Catalogue Supplier');
        $body = ['inventory_item_id' => $this->seedItem(self::TENANT_A, 'SKU-REPLAY-CAT'), 'supplier_sku' => 'SP-REPLAY',
            'unit_cost' => '3.5', 'lead_time_days' => 5];
        $key = [IdempotencyKeyGuard::HEADER => Uuid::v4()->toRfc4122()];

        $this->send('POST', '/suppliers/'.$supplier.'/products', $body, $key);
        self::assertSame(201, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());

        $this->send('POST', '/suppliers/'.$supplier.'/products', $body, $key);
        self::assertSame(409, $this->httpStatus(), 'A repeated CreateSupplierProduct was replayed, not refused.');
        self::assertSame('supplier_product.item_is_listed_once_per_supplier', $this->json()['rule'] ?? null, $why);

        self::assertCount(1, $this->fetchAsOwnerForTenant(self::TENANT_A, 'SELECT id FROM supplier_product WHERE supplier_id = ?::uuid', [$supplier]), $why);
    }

    private function replayCreateWarehouse(string $why): void
    {
        $body = ['code' => 'W-REPLAY', 'name' => 'Replayed Warehouse', 'country_code' => 'GB'];
        $key = [IdempotencyKeyGuard::HEADER => Uuid::v4()->toRfc4122()];
        $before = \count($this->fetchAsOwnerForTenant(self::TENANT_A, 'SELECT id FROM warehouse'));

        $this->send('POST', '/warehouses', $body, $key);
        self::assertSame(201, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());

        $this->send('POST', '/warehouses', $body, $key);
        self::assertSame(409, $this->httpStatus(), 'A repeated CreateWarehouse was replayed, not refused.');
        self::assertSame('warehouse.code_is_unique_per_workspace', $this->json()['rule'] ?? null, $why);

        self::assertCount($before + 1, $this->fetchAsOwnerForTenant(self::TENANT_A, 'SELECT id FROM warehouse'), $why);
        self::assertCount(1, $this->fetchAsOwnerForTenant(self::TENANT_A, "SELECT id FROM audit_log WHERE event_type = 'warehouse.created'"), $why);
    }

    private function replayCreateStorageLocation(string $why): void
    {
        $warehouse = $this->seedWarehouse(self::TENANT_A, 'Replayed Location Warehouse');
        $body = ['code' => 'L-REPLAY', 'kind' => 'bin'];
        $key = [IdempotencyKeyGuard::HEADER => Uuid::v4()->toRfc4122()];

        $this->send('POST', '/warehouses/'.$warehouse.'/locations', $body, $key);
        self::assertSame(201, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());

        $this->send('POST', '/warehouses/'.$warehouse.'/locations', $body, $key);
        self::assertSame(409, $this->httpStatus(), 'A repeated CreateStorageLocation was replayed, not refused.');
        self::assertSame('storage_location.code_is_unique_per_warehouse', $this->json()['rule'] ?? null, $why);

        self::assertCount(1, $this->fetchAsOwnerForTenant(self::TENANT_A, 'SELECT id FROM storage_location WHERE warehouse_id = ?::uuid', [$warehouse]), $why);
        self::assertCount(1, $this->fetchAsOwnerForTenant(self::TENANT_A, "SELECT id FROM audit_log WHERE event_type = 'storage_location.created'"), $why);
    }

    private function replayCreateSupplier(string $why): void
    {
        $body = ['code' => 'S-REPLAY', 'name' => 'Replayed Supplier', 'country_code' => 'GB', 'currency_code' => 'GBP'];
        $key = [IdempotencyKeyGuard::HEADER => Uuid::v4()->toRfc4122()];

        $this->send('POST', '/suppliers', $body, $key);
        self::assertSame(201, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());

        $this->send('POST', '/suppliers', $body, $key);
        self::assertSame(409, $this->httpStatus(), 'A repeated CreateSupplier was replayed, not refused.');
        // Both live uniques collide on an identical repeat; Postgres reports whichever index it checks first, so either rule
        // is a refusal (never a replay). Each rule alone is pinned in `SupplierWriteTest`.
        self::assertContains($this->json()['rule'] ?? null, ['supplier.code_is_unique_per_workspace', 'supplier.name_is_unique_per_workspace'], $why);

        self::assertCount(1, $this->fetchAsOwnerForTenant(self::TENANT_A, 'SELECT id FROM supplier'), $why);
        self::assertCount(1, $this->fetchAsOwnerForTenant(self::TENANT_A, "SELECT id FROM audit_log WHERE event_type = 'supplier.created'"), $why);
    }

    private function replayCreateCustomer(string $why): void
    {
        $body = ['code' => 'C-REPLAY', 'name' => 'Replayed', 'country_code' => 'GB', 'currency_code' => 'GBP'];
        $key = [IdempotencyKeyGuard::HEADER => Uuid::v4()->toRfc4122()];

        $this->send('POST', '/customers', $body, $key);
        self::assertSame(201, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());

        $this->send('POST', '/customers', $body, $key);
        self::assertSame(409, $this->httpStatus(), 'A repeated CreateCustomer was replayed, not refused.');

        self::assertCount(1, $this->fetchAsOwnerForTenant(self::TENANT_A, 'SELECT id FROM customer'), $why);
        self::assertCount(1, $this->fetchAsOwnerForTenant(self::TENANT_A, "SELECT id FROM audit_log WHERE event_type = 'customer.created'"), $why);
    }

    /**
     * The nested lines are sent because the replay this operation has to survive is not only "one order":
     * `SalesOrderWriter::create()` computes `total_amount` inside the order's own INSERT from the
     * request's own lines, so a replay that re-ran would produce a second order **and** a second total.
     * Counting both tables is what makes that visible.
     */
    private function replayCreateSalesOrder(string $why): void
    {
        $customer = $this->seedCustomer(self::TENANT_A, 'Acme Retail');
        $body = [
            'customer_id' => $customer,
            'warehouse_id' => $this->seedWarehouse(self::TENANT_A, 'Main SO'),
            'ship_to_address_id' => $this->seedCustomerAddress(self::TENANT_A, $customer),
            'lines' => [['inventory_item_id' => $this->seedItem(self::TENANT_A, 'SKU-SO-REPLAY'), 'quantity' => '2', 'unit_price' => '3']],
        ];
        $key = [IdempotencyKeyGuard::HEADER => Uuid::v4()->toRfc4122()];

        $this->send('POST', '/sales-orders', $body, $key);
        self::assertSame(201, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        $first = $this->json()['id'];

        $this->send('POST', '/sales-orders', $body, $key);

        self::assertSame($first, $this->json()['id'] ?? null, 'The replay did not return the first order.');
        self::assertSame('6.0000', $this->json()['total_amount'] ?? null, 'The replay did not return the first order\'s total.');
        self::assertCount(1, $this->fetchAsOwnerForTenant(self::TENANT_A, 'SELECT id FROM sales_order'), $why);
        self::assertCount(1, $this->fetchAsOwnerForTenant(self::TENANT_A, 'SELECT id FROM sales_order_line'), $why);
    }

    private function replayCreatePurchaseOrder(string $why): void
    {
        $body = [
            'supplier_id' => $this->seedSupplier(self::TENANT_A, 'Acme'),
            'warehouse_id' => $this->seedWarehouse(self::TENANT_A, 'Main'),
        ];
        $key = [IdempotencyKeyGuard::HEADER => Uuid::v4()->toRfc4122()];

        $this->send('POST', '/purchase-orders', $body, $key);
        self::assertSame(201, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        $first = $this->json()['id'];

        $this->send('POST', '/purchase-orders', $body, $key);

        self::assertSame($first, $this->json()['id'] ?? null, 'The replay did not return the first order.');
        self::assertCount(1, $this->fetchAsOwnerForTenant(self::TENANT_A, 'SELECT id FROM purchase_order'), $why);
    }

    private function replayCreateGoodsReceipt(string $why): void
    {
        [$order, $line, $bin] = $this->orderedOrderToReceiveHere();
        $body = ['lines' => [['purchase_order_line_id' => $line, 'quantity' => '1', 'storage_location_id' => $bin]]];
        $key = [IdempotencyKeyGuard::HEADER => Uuid::v4()->toRfc4122()];

        $this->send('POST', '/purchase-orders/'.$order.'/goods-receipts', $body, $key);
        self::assertSame(201, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        $first = $this->json()['id'];

        $this->send('POST', '/purchase-orders/'.$order.'/goods-receipts', $body, $key);

        // ⚠ The row count alone does NOT hold this operation's replay read up:
        // measured 2026-09-12 by short-circuiting `if (false !== $replay)` in
        // `GoodsReceiptWriter::createInside()` — the second INSERT then loses at
        // `goods_receipt_idem_unique` instead, so one row is still the answer and
        // the count stays green. The index prevents the second delivery; the
        // replay read is what turns the retry into an ANSWER rather than a
        // refusal, which is what §6.2 requires of a `unique:` operation carrying
        // a stored digest. Asserting the returned id is what makes that mutation
        // red, and it is the reason this assertion is here rather than only the
        // count below.
        self::assertSame($first, $this->json()['id'] ?? null,
            'The replay did not return the receipt the first call recorded. A retry that is refused rather than '
            .'answered is not idempotent; it is merely not duplicated.');

        self::assertCount(1, $this->fetchAsOwnerForTenant(self::TENANT_A, 'SELECT id FROM goods_receipt'), $why);
        self::assertSame([['quantity_received' => '1.000000']], $this->fetchAsOwnerForTenant(self::TENANT_A,
            'SELECT quantity_received FROM purchase_order_line WHERE id = ?::uuid', [$line]),
            'The replay applied the delivery a second time to the order line, which is the effect the row count alone '
            .'cannot see.');
        // B-4a (roles slice 2, C1): the audit write sits on the INSERT branch, so the replay writes no second row.
        self::assertCount(1, $this->auditRowsOf(AuditEvent::GOODS_RECEIPT_CREATED),
            'The replay wrote a second goods_receipt.created row: the audit write is on the replay branch too.');
    }

    /**
     * `replayCreateGoodsReceipt()`'s twin (sales step 5, commit 4): the retry must be ANSWERED with the first
     * fulfilment (the returned id), not merely not duplicated, and none of its three effects may apply twice —
     * the fulfilment row, the line's `quantity_fulfilled`, and the stock.
     */
    /**
     * `replayCreateFulfilment()`'s twin (sales step 7 R2): the retry is ANSWERED with the first return, and none of its
     * effects applies twice — the return row, the stock put back, the trail row.
     */
    private function replayCreateSalesReturn(string $why): void
    {
        $shipment = $this->shipped('IDEM-RET', [['5', '10.0000', '3']]);
        $this->send('GET', '/fulfilments/'.$shipment['fulfilment']);
        $line = $this->json()['lines'][0];
        $before = (string) $this->fetchAsOwnerForTenant(self::TENANT_A,
            'SELECT qty_on_hand::text AS q FROM stock_level WHERE storage_location_id = ?::uuid', [$line['storage_location']['id']])[0]['q'];
        $body = ['reason' => 'other', 'lines' => [
            ['fulfilment_line_id' => $line['id'], 'quantity' => '1', 'storage_location_id' => $line['storage_location']['id']],
        ]];
        $key = [IdempotencyKeyGuard::HEADER => Uuid::v4()->toRfc4122()];

        $this->send('POST', '/fulfilments/'.$shipment['fulfilment'].'/sales-returns', $body, $key);
        self::assertSame(201, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        $first = $this->json()['id'];

        $this->send('POST', '/fulfilments/'.$shipment['fulfilment'].'/sales-returns', $body, $key);

        self::assertSame($first, $this->json()['id'] ?? null,
            'The replay did not return the return the first call recorded. A retry that is refused rather than '
            .'answered is not idempotent; it is merely not duplicated.');
        self::assertCount(1, $this->fetchAsOwnerForTenant(self::TENANT_A, 'SELECT id FROM sales_return'), $why);
        self::assertSame([['q' => bcadd($before, '1', 6)]], $this->fetchAsOwnerForTenant(self::TENANT_A,
            'SELECT qty_on_hand::text AS q FROM stock_level WHERE storage_location_id = ?::uuid', [$line['storage_location']['id']]), $why);
        self::assertCount(1, $this->auditRowsOf(AuditEvent::SALES_RETURN_CREATED),
            'The replay wrote a second sales_return.created row: the audit write is on the replay branch too.');
    }

    /** `replayCreateSalesReturn()`'s twin (sales step 7 C1): the retry is the first note; nothing applies twice. */
    private function replayIssueCreditNote(string $why): void
    {
        $i = $this->invoicedShipment('IDEM-CN', [['4', '10.0000', '2']]);
        $this->actAsRole(\App\Modules\UserManagement\Domain\DefaultRoles::OWNER);
        $return = $this->returnOf($i, [[0, '1']]);
        $this->actAsRole(\App\Modules\UserManagement\Domain\DefaultRoles::FINANCE);
        $key = Uuid::v4()->toRfc4122();

        $this->credit($i, $return, [[0, '1']], $key);
        self::assertSame(201, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        $first = $this->json()['id'];
        $this->credit($i, $return, [[0, '1']], $key);

        self::assertSame($first, $this->json()['id'] ?? null, 'The replay did not return the credit note the first call issued.');
        self::assertCount(1, $this->fetchAsOwnerForTenant(self::TENANT_A, 'SELECT id FROM credit_note'), $why);
        self::assertCount(1, $this->auditRowsOf(AuditEvent::CREDIT_NOTE_ISSUED),
            'The replay wrote a second credit_note.issued row: the audit write is on the replay branch too.');
    }

    /** `replayIssueCreditNote()`'s twin for the adjustment (sales step 7 C1b): no return, the owner adjusts. */
    private function replayIssueAdjustmentCreditNote(string $why): void
    {
        $i = $this->invoicedShipment('IDEM-ADJ', [['4', '10.0000', '2']]);
        $this->actAsRole(\App\Modules\UserManagement\Domain\DefaultRoles::OWNER);
        $body = ['reason' => 'damaged_goods_kept', 'lines' => [['fulfilment_line_id' => $i['lines'][0], 'quantity' => '1']]];
        $key = [IdempotencyKeyGuard::HEADER => Uuid::v4()->toRfc4122()];

        $this->send('POST', '/invoices/'.$i['invoice'].'/credit-note-adjustments', $body, $key);
        self::assertSame(201, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        $first = $this->json()['id'];
        $this->send('POST', '/invoices/'.$i['invoice'].'/credit-note-adjustments', $body, $key);

        self::assertSame($first, $this->json()['id'] ?? null, 'The replay did not return the credit note the first call issued.');
        self::assertCount(1, $this->fetchAsOwnerForTenant(self::TENANT_A, 'SELECT id FROM credit_note'), $why);
        self::assertCount(1, $this->auditRowsOf(AuditEvent::CREDIT_NOTE_ISSUED),
            'The replay wrote a second credit_note.issued row: the audit write is on the replay branch too.');
    }

    private function replayCreateAdjustmentDecision(string $why): void
    {
        $i = $this->invoicedShipment('IDEM-ADD', [['4', '10.0000', '4']]);
        $this->actAsRole(\App\Modules\UserManagement\Domain\DefaultRoles::OWNER);
        $this->send('POST', '/invoices/'.$i['invoice'].'/credit-note-adjustments', ['reason' => 'damaged_goods_kept',
            'lines' => [['fulfilment_line_id' => $i['lines'][0], 'quantity' => '2']]], [IdempotencyKeyGuard::HEADER => Uuid::v4()->toRfc4122()]);
        self::assertSame(201, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        $adjustmentLine = $this->json()['lines'][0]['id'];
        $return = $this->returnOf($i, [[0, '2']]);
        $body = ['kind' => 'same', 'sales_return_line_id' => $this->json()['lines'][0]['id'], 'credit_note_line_id' => $adjustmentLine, 'quantity' => '1'];
        $key = [IdempotencyKeyGuard::HEADER => Uuid::v4()->toRfc4122()];

        $this->send('POST', '/sales-returns/'.$return.'/adjustment-decisions', $body, $key);
        self::assertSame(200, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        $first = $this->json();
        $this->send('POST', '/sales-returns/'.$return.'/adjustment-decisions', $body, $key);

        self::assertSame($first, $this->json(), 'The replay did not answer the return as the first call left it.');
        self::assertCount(1, $this->fetchAsOwnerForTenant(self::TENANT_A, 'SELECT id FROM adjustment_decision'), $why);
        self::assertCount(1, $this->auditRowsOf(AuditEvent::CREDIT_NOTE_RETURN_DECIDED),
            'The replay wrote a second credit_note.return_decided row: the audit write is on the replay branch too.');
    }

    private function replayCreateFulfilment(string $why): void
    {
        $warehouse = $this->seedWarehouse(self::TENANT_A, 'Main FUL');
        $order = $this->seedSalesOrder(self::TENANT_A, 'SO-162000', warehouseId: $warehouse, status: SalesOrder::STATUS_CONFIRMED, orderedAt: '2026-09-20T10:00:00Z');
        $item = $this->seedItem(self::TENANT_A, 'SKU-IDEM-FUL');
        $line = $this->seedSalesOrderLine(self::TENANT_A, $order, $item, quantity: '5');
        $bin = Uuid::v4()->toRfc4122();
        $this->insertAsOwnerForTenant(self::TENANT_A,
            'INSERT INTO storage_location (id, tenant_id, warehouse_id, code) VALUES (?::uuid, ?::uuid, ?::uuid, ?)',
            [$bin, self::TENANT_A, $warehouse, 'A-01']);
        $this->insertAsOwnerForTenant(self::TENANT_A,
            'INSERT INTO stock_level (id, tenant_id, inventory_item_id, storage_location_id, qty_on_hand) VALUES (?::uuid, ?::uuid, ?::uuid, ?::uuid, 10)',
            [Uuid::v4()->toRfc4122(), self::TENANT_A, $item, $bin]);
        $body = ['lines' => [['sales_order_line_id' => $line, 'quantity' => '2', 'storage_location_id' => $bin]]];
        $key = [IdempotencyKeyGuard::HEADER => Uuid::v4()->toRfc4122()];

        $this->send('POST', '/sales-orders/'.$order.'/fulfilments', $body, $key);
        self::assertSame(201, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        $first = $this->json()['id'];

        $this->send('POST', '/sales-orders/'.$order.'/fulfilments', $body, $key);

        self::assertSame($first, $this->json()['id'] ?? null,
            'The replay did not return the fulfilment the first call recorded. A retry that is refused rather than '
            .'answered is not idempotent; it is merely not duplicated.');
        self::assertCount(1, $this->fetchAsOwnerForTenant(self::TENANT_A, 'SELECT id FROM fulfilment'), $why);
        self::assertSame([['q' => '2.000000']], $this->fetchAsOwnerForTenant(self::TENANT_A,
            'SELECT quantity_fulfilled::text AS q FROM sales_order_line WHERE id = ?::uuid', [$line]), $why);
        self::assertSame([['q' => '8.000000']], $this->fetchAsOwnerForTenant(self::TENANT_A,
            'SELECT qty_on_hand::text AS q FROM stock_level WHERE storage_location_id = ?::uuid', [$bin]), $why);
        // B-4a (roles slice 2, C1): the audit write sits on the INSERT branch, so the replay writes no second row.
        self::assertCount(1, $this->auditRowsOf(AuditEvent::FULFILMENT_CREATED),
            'The replay wrote a second fulfilment.created row: the audit write is on the replay branch too.');
    }

    /**
     * **A REFUSAL case, not a replay case** (design §19 C3): `IssueInvoice`'s repeat is refused — a second call is as
     * likely a human re-issue as a network retry (§4.2 item 1) — so the same key and the same path answer 409, and the
     * one invoice and its one trail row are all that exist afterwards.
     */
    private function repeatAssignRole(string $why): void
    {
        $role = Uuid::v4()->toRfc4122();
        $this->insertAsOwnerForTenant(self::TENANT_A, 'INSERT INTO role (id, tenant_id, name) VALUES (?::uuid, ?::uuid, ?)',
            [$role, self::TENANT_A, 'Repeat Role']);
        $subject = $this->seedPrincipal(self::TENANT_A, []);
        $key = [IdempotencyKeyGuard::HEADER => Uuid::v4()->toRfc4122()];

        $this->send('POST', '/users/'.$subject.'/roles', ['role_id' => $role], $key);
        self::assertSame(201, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());

        $this->send('POST', '/users/'.$subject.'/roles', ['role_id' => $role], $key);

        self::assertSame(409, $this->httpStatus(), 'The repeat was not refused: '.(string) $this->client()->getResponse()->getContent());
        self::assertSame('user_role.already_held', $this->json()['rule']);
        self::assertCount(1, $this->fetchAsOwnerForTenant(self::TENANT_A, 'SELECT id FROM user_role WHERE user_id = ?::uuid', [$subject]), $why);
        self::assertCount(1, $this->fetchAsOwnerForTenant(self::TENANT_A,
            "SELECT id FROM audit_log WHERE event_type = 'role.assigned'"), $why);
    }

    private function repeatCreateRole(string $why): void
    {
        $key = [IdempotencyKeyGuard::HEADER => Uuid::v4()->toRfc4122()];
        $body = ['name' => 'Repeat composed', 'permission_codes' => ['role.view']];

        $this->send('POST', '/roles', $body, $key);
        self::assertSame(201, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());

        $this->send('POST', '/roles', $body, $key);

        self::assertSame(409, $this->httpStatus(), 'The repeat was not refused: '.(string) $this->client()->getResponse()->getContent());
        self::assertSame('role.name_taken', $this->json()['rule']);
        self::assertCount(1, $this->fetchAsOwnerForTenant(self::TENANT_A, "SELECT id FROM role WHERE name = 'Repeat composed'"), $why);
        self::assertCount(1, $this->fetchAsOwnerForTenant(self::TENANT_A,
            "SELECT id FROM audit_log WHERE event_type = 'role.permissions_changed'"), $why);
    }

    private function repeatCreateRoleGrantProposal(string $why): void
    {
        $role = Uuid::v4()->toRfc4122();
        $this->insertAsOwnerForTenant(self::TENANT_A, 'INSERT INTO role (id, tenant_id, name) VALUES (?::uuid, ?::uuid, ?)',
            [$role, self::TENANT_A, 'Repeat Proposal']);
        foreach (['invoice.pay', 'role.view'] as $code) {
            $this->insertAsOwnerForTenant(self::TENANT_A,
                'INSERT INTO role_permission (id, tenant_id, role_id, permission_code) VALUES (gen_random_uuid(), ?::uuid, ?::uuid, ?)',
                [self::TENANT_A, $role, $code]);
        }
        $subject = $this->seedPrincipal(self::TENANT_A, []);
        $key = [IdempotencyKeyGuard::HEADER => Uuid::v4()->toRfc4122()];

        $this->send('POST', '/users/'.$subject.'/role-grant-proposals', ['role_id' => $role], $key);
        self::assertSame(201, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());

        $this->send('POST', '/users/'.$subject.'/role-grant-proposals', ['role_id' => $role], $key);

        self::assertSame(409, $this->httpStatus(), 'The repeat was not refused: '.(string) $this->client()->getResponse()->getContent());
        self::assertSame('role_grant_proposal.already_pending', $this->json()['rule']);
        self::assertCount(1, $this->fetchAsOwnerForTenant(self::TENANT_A, 'SELECT id FROM role_grant_proposal WHERE user_id = ?::uuid', [$subject]), $why);
        self::assertCount(1, $this->fetchAsOwnerForTenant(self::TENANT_A,
            "SELECT id FROM audit_log WHERE event_type = 'role.assignment_proposed'"), $why);
    }

    private function repeatIssueInvoice(string $why): void
    {
        $warehouse = $this->seedWarehouse(self::TENANT_A, 'Main INV');
        $order = $this->seedSalesOrder(self::TENANT_A, 'SO-163000', warehouseId: $warehouse, status: SalesOrder::STATUS_CONFIRMED, orderedAt: '2026-09-20T10:00:00Z');
        $item = $this->seedItem(self::TENANT_A, 'SKU-IDEM-INV');
        $line = $this->seedSalesOrderLine(self::TENANT_A, $order, $item, quantity: '5');
        $bin = Uuid::v4()->toRfc4122();
        $this->insertAsOwnerForTenant(self::TENANT_A,
            'INSERT INTO storage_location (id, tenant_id, warehouse_id, code) VALUES (?::uuid, ?::uuid, ?::uuid, ?)',
            [$bin, self::TENANT_A, $warehouse, 'A-01']);
        $this->insertAsOwnerForTenant(self::TENANT_A,
            'INSERT INTO stock_level (id, tenant_id, inventory_item_id, storage_location_id, qty_on_hand) VALUES (?::uuid, ?::uuid, ?::uuid, ?::uuid, 10)',
            [Uuid::v4()->toRfc4122(), self::TENANT_A, $item, $bin]);
        $this->send('POST', '/sales-orders/'.$order.'/fulfilments',
            ['lines' => [['sales_order_line_id' => $line, 'quantity' => '2', 'storage_location_id' => $bin]]],
            [IdempotencyKeyGuard::HEADER => Uuid::v4()->toRfc4122()]);
        self::assertSame(201, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        $fulfilment = $this->json()['id'];
        $key = [IdempotencyKeyGuard::HEADER => Uuid::v4()->toRfc4122()];

        $this->send('POST', '/fulfilments/'.$fulfilment.'/invoices', null, $key);
        self::assertSame(201, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());

        $this->send('POST', '/fulfilments/'.$fulfilment.'/invoices', null, $key);

        self::assertSame(409, $this->httpStatus(), 'The repeat was not refused: '.(string) $this->client()->getResponse()->getContent());
        self::assertSame('invoice.fulfilment_is_not_already_invoiced', $this->json()['rule']);
        self::assertCount(1, $this->fetchAsOwnerForTenant(self::TENANT_A, 'SELECT id FROM invoice'), $why);
        self::assertCount(1, $this->fetchAsOwnerForTenant(self::TENANT_A,
            "SELECT id FROM audit_log WHERE event_type = 'invoice.issued'"), $why);
    }

    /**
     * The sales twin of {@see self::replayCreatePurchaseOrderLine()}.
     *
     * The order is raised through the API rather than seeded, so the line is added to an order whose
     * `total_amount` a writer computed — which is what makes the total assertion below meaningful. The
     * order's own line contributes `2.0000`; the retried line contributes `6.0000`; a replay that wrote a
     * second line would read `14.0000`.
     */
    /**
     * The order is raised, a line deleted and restored through the operations themselves, then restored
     * again. The second restore must be refused by the guard, and the order total must not move.
     */
    private function replayRestoreSalesOrderLine(string $why): void
    {
        $customer = $this->seedCustomer(self::TENANT_A, 'Acme Retail RSOL');
        $this->send('POST', '/sales-orders', [
            'customer_id' => $customer,
            'warehouse_id' => $this->seedWarehouse(self::TENANT_A, 'Main RSOL'),
            'ship_to_address_id' => $this->seedCustomerAddress(self::TENANT_A, $customer),
            'lines' => [['inventory_item_id' => $this->seedItem(self::TENANT_A, 'SKU-RSOL'), 'quantity' => '2', 'unit_price' => '3']],
        ], [IdempotencyKeyGuard::HEADER => Uuid::v4()->toRfc4122()]);
        self::assertSame(201, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        $order = $this->json()['id'];

        $this->send('GET', '/sales-orders/'.$order.'/lines');
        $line = $this->json()['items'][0]['id'];

        $this->send('DELETE', '/sales-orders/'.$order.'/lines/'.$line);
        self::assertSame(204, $this->httpStatus());

        $this->send('POST', '/sales-orders/'.$order.'/lines/'.$line.'/restoration');
        self::assertSame(200, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());

        $this->send('POST', '/sales-orders/'.$order.'/lines/'.$line.'/restoration');

        self::assertSame(409, $this->httpStatus(), $why);
        self::assertSame('sales_order_line.is_not_archived', $this->json()['rule'] ?? null, $why);
        self::assertSame([['total_amount' => '6.0000']], $this->fetchAsOwnerForTenant(self::TENANT_A,
            'SELECT total_amount FROM sales_order WHERE id = ?::uuid', [$order]), $why);
    }

    private function replayCreateCustomerAddress(string $why): void
    {
        $customer = $this->seedCustomer(self::TENANT_A, 'Replayed Address Customer');
        $body = ['kind' => 'shipping', 'line' => '1 Replay Road', 'city' => 'Leeds', 'postal_code' => 'LS1 4AP', 'country_code' => 'GB'];
        $key = [IdempotencyKeyGuard::HEADER => Uuid::v4()->toRfc4122()];

        $this->send('POST', '/customers/'.$customer.'/addresses', $body, $key);
        self::assertSame(201, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        $first = $this->json();

        $this->send('POST', '/customers/'.$customer.'/addresses', $body, $key);
        self::assertSame(201, $this->httpStatus(), 'A replayed CreateCustomerAddress was not answered with the stored address.');
        self::assertSame($first, $this->json(), $why);

        self::assertCount(1, $this->fetchAsOwnerForTenant(self::TENANT_A, 'SELECT id FROM customer_address WHERE customer_id = ?::uuid', [$customer]), $why);
    }

    private function replayCreateSalesOrderLine(string $why): void
    {
        $customer = $this->seedCustomer(self::TENANT_A, 'Acme Retail SOL');
        $this->send('POST', '/sales-orders', [
            'customer_id' => $customer,
            'warehouse_id' => $this->seedWarehouse(self::TENANT_A, 'Main SOL'),
            'ship_to_address_id' => $this->seedCustomerAddress(self::TENANT_A, $customer),
            'lines' => [['inventory_item_id' => $this->seedItem(self::TENANT_A, 'SKU-SOL-A'), 'quantity' => '2', 'unit_price' => '1']],
        ], [IdempotencyKeyGuard::HEADER => Uuid::v4()->toRfc4122()]);
        self::assertSame(201, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        $order = $this->json()['id'];

        $body = ['inventory_item_id' => $this->seedItem(self::TENANT_A, 'SKU-SOL-B'), 'quantity' => '2', 'unit_price' => '3'];
        $key = [IdempotencyKeyGuard::HEADER => Uuid::v4()->toRfc4122()];

        $this->send('POST', '/sales-orders/'.$order.'/lines', $body, $key);
        self::assertSame(201, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        $first = $this->json()['id'];

        $this->send('POST', '/sales-orders/'.$order.'/lines', $body, $key);

        // The id, not only the count: the replay read is what turns the retry into an answer.
        self::assertSame($first, $this->json()['id'] ?? null, 'The replay did not return the first line.');
        self::assertCount(2, $this->fetchAsOwnerForTenant(self::TENANT_A, 'SELECT id FROM sales_order_line'), $why);
        self::assertSame([['total_amount' => '8.0000']], $this->fetchAsOwnerForTenant(self::TENANT_A,
            'SELECT total_amount FROM sales_order WHERE id = ?::uuid', [$order]), $why);
    }

    private function replayCreatePurchaseOrderLine(string $why): void
    {
        $draft = $this->seedDraft(self::TENANT_A, 'PO-000001');
        $this->seedSupplierProduct(self::TENANT_A, $draft['supplier'], $draft['item']);
        $body = ['inventory_item_id' => $draft['item'], 'quantity' => '2', 'unit_cost' => '3'];
        $key = [IdempotencyKeyGuard::HEADER => Uuid::v4()->toRfc4122()];

        $this->send('POST', '/purchase-orders/'.$draft['order'].'/lines', $body, $key);
        self::assertSame(201, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        $first = $this->json()['id'];

        $this->send('POST', '/purchase-orders/'.$draft['order'].'/lines', $body, $key);

        // The id, not only the count: the replay read is what turns the retry
        // into an answer (see `replayCreateGoodsReceipt`).
        self::assertSame($first, $this->json()['id'] ?? null, 'The replay did not return the first line.');
        self::assertCount(1, $this->fetchAsOwnerForTenant(self::TENANT_A, 'SELECT id FROM purchase_order_line'), $why);
        self::assertSame([['total_amount' => '6.0000']], $this->fetchAsOwnerForTenant(self::TENANT_A,
            'SELECT total_amount FROM purchase_order WHERE id = ?::uuid', [$draft['order']]), $why);
    }

    private function replayConfirmSalesOrder(string $why): void
    {
        $order = $this->seedSalesOrder(self::TENANT_A, 'SO-000001');
        $this->seedSalesOrderLine(self::TENANT_A, $order, $this->seedItem(self::TENANT_A, 'SKU-SO-000001'));

        $this->send('POST', '/sales-orders/'.$order.'/confirmation');
        self::assertSame(200, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        $after = $this->salesRowOf($order);

        $this->send('POST', '/sales-orders/'.$order.'/confirmation');

        self::assertSame(409, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        // `ordered_at` is in the row read, so a second confirmation that re-stamped the timestamp on an
        // already-confirmed order would be red here even though the status never moved.
        self::assertSame($after, $this->salesRowOf($order), $why);
        self::assertSame(SalesOrder::STATUS_CONFIRMED, $after[0]['status']);
    }

    private function replayConfirmRoleGrantProposal(string $why): void
    {
        $role = Uuid::v4()->toRfc4122();
        $this->insertAsOwnerForTenant(self::TENANT_A, 'INSERT INTO role (id, tenant_id, name) VALUES (?::uuid, ?::uuid, ?)',
            [$role, self::TENANT_A, 'Repeat Confirmation']);
        foreach (['invoice.pay', 'role.view'] as $code) {
            $this->insertAsOwnerForTenant(self::TENANT_A,
                'INSERT INTO role_permission (id, tenant_id, role_id, permission_code) VALUES (gen_random_uuid(), ?::uuid, ?::uuid, ?)',
                [self::TENANT_A, $role, $code]);
        }
        $subject = $this->seedPrincipal(self::TENANT_A, []);
        $proposal = Uuid::v4()->toRfc4122();
        $this->insertAsOwnerForTenant(self::TENANT_A, <<<'SQL'
            INSERT INTO role_grant_proposal (id, tenant_id, user_id, role_id, permission_codes, proposed_by_user_id, proposed_at, expires_at)
            SELECT ?::uuid, ?::uuid, ?::uuid, ?::uuid, '["invoice.pay", "role.view"]'::jsonb, ?::uuid, c.t, c.t + interval '336 hours'
              FROM (SELECT clock_timestamp() AS t) c
            SQL, [$proposal, self::TENANT_A, $subject, $role, $this->seedPrincipal(self::TENANT_A, ['role.assign'])]);
        // An owner who manages roles (Q-6 (c′)), holding the role's codes (E-15c).
        $this->actAsUser(self::TENANT_A, $this->seedPrincipal(self::TENANT_A, ['role.assign', 'role.view', 'invoice.pay', 'tenant_settings.edit']));

        $this->send('POST', '/role-grant-proposals/'.$proposal.'/confirmation');
        self::assertSame(200, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        $row = 'SELECT to_jsonb(p)::text AS r FROM role_grant_proposal p WHERE id = ?::uuid';
        $after = $this->fetchAsOwnerForTenant(self::TENANT_A, $row, [$proposal]);

        $this->send('POST', '/role-grant-proposals/'.$proposal.'/confirmation');

        self::assertSame(409, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        self::assertSame('role_grant_proposal.status_transition_is_legal', $this->json()['rule']);
        self::assertSame($after, $this->fetchAsOwnerForTenant(self::TENANT_A, $row, [$proposal]), $why);
        self::assertCount(1, $this->fetchAsOwnerForTenant(self::TENANT_A, 'SELECT id FROM user_role WHERE user_id = ?::uuid', [$subject]), $why);
        self::assertCount(1, $this->fetchAsOwnerForTenant(self::TENANT_A, "SELECT id FROM audit_log WHERE event_type = 'role.assigned'"), $why);
    }

    /** Reject and Withdraw (C3): the first decision 200, the repeat 409 on the status rule, the row byte-identical, one event. */
    private function replayDecideRoleGrantProposal(string $why): void
    {
        $reject = str_contains($why, 'rejection');
        $proposer = $this->seedPrincipal(self::TENANT_A, ['role.assign', 'role.view']);
        $proposal = Uuid::v4()->toRfc4122();
        $role = Uuid::v4()->toRfc4122();
        $this->insertAsOwnerForTenant(self::TENANT_A, 'INSERT INTO role (id, tenant_id, name) VALUES (?::uuid, ?::uuid, ?)',
            [$role, self::TENANT_A, 'Repeat Decision']);
        $this->insertAsOwnerForTenant(self::TENANT_A, <<<'SQL'
            INSERT INTO role_grant_proposal (id, tenant_id, user_id, role_id, permission_codes, proposed_by_user_id, proposed_at, expires_at)
            SELECT ?::uuid, ?::uuid, ?::uuid, ?::uuid, '["role.view"]'::jsonb, ?::uuid, c.t, c.t + interval '336 hours'
              FROM (SELECT clock_timestamp() AS t) c
            SQL, [$proposal, self::TENANT_A, $this->seedPrincipal(self::TENANT_A, []), $role, $proposer]);
        $this->actAsUser(self::TENANT_A, $reject ? $this->seedPrincipal(self::TENANT_A, ['role.assign', 'tenant_settings.edit']) : $proposer);
        $path = '/role-grant-proposals/'.$proposal.($reject ? '/rejection' : '/cancellation');

        $this->send('POST', $path, $reject ? ['reason' => 'First'] : null);
        self::assertSame(200, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        $row = 'SELECT to_jsonb(p)::text AS r FROM role_grant_proposal p WHERE id = ?::uuid';
        $after = $this->fetchAsOwnerForTenant(self::TENANT_A, $row, [$proposal]);

        $this->send('POST', $path, $reject ? ['reason' => 'Second'] : null);

        self::assertSame(409, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        self::assertSame('role_grant_proposal.status_transition_is_legal', $this->json()['rule']);
        self::assertSame($after, $this->fetchAsOwnerForTenant(self::TENANT_A, $row, [$proposal]), $why);
        self::assertCount(1, $this->fetchAsOwnerForTenant(self::TENANT_A, "SELECT id FROM audit_log WHERE event_type LIKE 'role.assignment_%'"), $why);
    }

    private function replayCancelSalesOrder(string $why): void
    {
        // A seeded draft needs no line: §8.3 makes `draft` a legal source for `cancel()`.
        $order = $this->seedSalesOrder(self::TENANT_A, 'SO-138000');

        $this->send('POST', '/sales-orders/'.$order.'/cancellation', ['reason' => 'First']);
        self::assertSame(200, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        $after = $this->salesRowOf($order);

        $this->send('POST', '/sales-orders/'.$order.'/cancellation', ['reason' => 'Second']);

        self::assertSame(409, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        self::assertSame($after, $this->salesRowOf($order), $why);
        self::assertSame(SalesOrder::STATUS_CANCELLED, $after[0]['status']);

        // The half the row read cannot see: one trail row, carrying the FIRST reason. A replay that got
        // past the guard would leave two rows naming two reasons for one cancellation, permanently.
        self::assertSame([['reason' => 'First']], $this->fetchAsOwnerForTenant(self::TENANT_A,
            "SELECT metadata->>'reason' AS reason FROM audit_log WHERE event_type = ? AND subject_id = ?::uuid",
            [AuditEvent::SALES_ORDER_CANCELLED, $order]), $why);
    }

    private function replayPayInvoice(string $why): void
    {
        $i = $this->issuedInvoice('IDEM-PAY');
        $this->actAsRole(DefaultRoles::FINANCE);

        $this->send('POST', '/invoices/'.$i['invoice'].'/payment', []);
        self::assertSame(200, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        $after = $this->fetchAsOwnerForTenant(self::TENANT_A,
            'SELECT status, paid_at::text AS paid_at, paid_by_user_id::text AS paid_by, updated_at::text AS updated_at FROM invoice WHERE id = ?::uuid',
            [$i['invoice']]);

        $this->send('POST', '/invoices/'.$i['invoice'].'/payment', []);

        self::assertSame(409, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        self::assertSame($after, $this->fetchAsOwnerForTenant(self::TENANT_A,
            'SELECT status, paid_at::text AS paid_at, paid_by_user_id::text AS paid_by, updated_at::text AS updated_at FROM invoice WHERE id = ?::uuid',
            [$i['invoice']]), $why);
        self::assertSame(1, $this->countAsOwnerForTenant(self::TENANT_A,
            'SELECT count(*) FROM audit_log WHERE event_type = ? AND subject_id = ?::uuid', [AuditEvent::INVOICE_PAID, $i['invoice']]), $why);
    }

    private function replayReverseInvoicePayment(string $why): void
    {
        $i = $this->issuedInvoice('IDEM-REV');
        $this->actAsRole(DefaultRoles::FINANCE);
        $this->send('POST', '/invoices/'.$i['invoice'].'/payment', []);
        self::assertSame(200, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        $this->actAsRole(DefaultRoles::FINANCE);

        $this->send('POST', '/invoices/'.$i['invoice'].'/payment-reversal', ['reason' => 'First']);
        self::assertSame(200, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        $after = $this->fetchAsOwnerForTenant(self::TENANT_A,
            'SELECT status, paid_at::text AS paid_at, payment_reversed_at::text AS reversed, updated_at::text AS updated_at FROM invoice WHERE id = ?::uuid',
            [$i['invoice']]);

        $this->send('POST', '/invoices/'.$i['invoice'].'/payment-reversal', ['reason' => 'Second']);

        self::assertSame(409, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        self::assertSame($after, $this->fetchAsOwnerForTenant(self::TENANT_A,
            'SELECT status, paid_at::text AS paid_at, payment_reversed_at::text AS reversed, updated_at::text AS updated_at FROM invoice WHERE id = ?::uuid',
            [$i['invoice']]), $why);
        self::assertSame([['reason' => 'First']], $this->fetchAsOwnerForTenant(self::TENANT_A,
            "SELECT metadata->>'reason' AS reason FROM audit_log WHERE event_type = ? AND subject_id = ?::uuid",
            [AuditEvent::INVOICE_PAYMENT_REVERSED, $i['invoice']]), $why);
    }

    private function replayVoidInvoice(string $why): void
    {
        $i = $this->issuedInvoice('IDEM-VOID');
        $this->actAsRole(DefaultRoles::OWNER);

        $this->send('POST', '/invoices/'.$i['invoice'].'/void', ['reason' => 'First']);
        self::assertSame(200, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        $after = $this->fetchAsOwnerForTenant(self::TENANT_A,
            'SELECT status, updated_at::text AS updated_at FROM invoice WHERE id = ?::uuid', [$i['invoice']]);

        $this->send('POST', '/invoices/'.$i['invoice'].'/void', ['reason' => 'Second']);

        self::assertSame(409, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        self::assertSame($after, $this->fetchAsOwnerForTenant(self::TENANT_A,
            'SELECT status, updated_at::text AS updated_at FROM invoice WHERE id = ?::uuid', [$i['invoice']]), $why);
        self::assertSame([['reason' => 'First']], $this->fetchAsOwnerForTenant(self::TENANT_A,
            "SELECT metadata->>'reason' AS reason FROM audit_log WHERE event_type = ? AND subject_id = ?::uuid",
            [AuditEvent::INVOICE_VOIDED, $i['invoice']]), $why);
    }

    private function replayApprovePurchaseOrder(string $why): void
    {
        $draft = $this->seedDraft(self::TENANT_A, 'PO-000001');
        $this->seedLine(self::TENANT_A, $draft['order'], $draft['item']);

        $this->send('POST', '/purchase-orders/'.$draft['order'].'/approval', []);
        self::assertSame(200, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        $after = $this->rowOf($draft['order']);

        $this->send('POST', '/purchase-orders/'.$draft['order'].'/approval', []);

        self::assertSame(409, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        self::assertSame($after, $this->rowOf($draft['order']), $why);
        self::assertSame(PurchaseOrder::STATUS_ORDERED, $after[0]['status']);
    }

    private function replayCancelPurchaseOrder(string $why): void
    {
        $draft = $this->seedDraft(self::TENANT_A, 'PO-000001');

        $this->send('POST', '/purchase-orders/'.$draft['order'].'/cancellation', []);
        self::assertSame(200, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        $after = $this->rowOf($draft['order']);

        $this->send('POST', '/purchase-orders/'.$draft['order'].'/cancellation', []);

        self::assertSame(409, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        self::assertSame($after, $this->rowOf($draft['order']), $why);
        self::assertSame(PurchaseOrder::STATUS_CANCELLED, $after[0]['status']);
    }

    private function replayRestoreItem(string $why): void
    {
        $this->send('POST', '/items', ['sku' => 'SKU-R', 'name' => 'Widget', 'unit_of_measure_code' => 'EA'],
            [IdempotencyKeyGuard::HEADER => Uuid::v4()->toRfc4122()]);
        self::assertSame(201, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        $id = $this->json()['id'];

        $this->send('DELETE', '/items/'.$id);
        self::assertSame(204, $this->httpStatus());

        $this->send('POST', '/items/'.$id.'/restoration');
        self::assertSame(200, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        $after = $this->fetchAsOwnerForTenant(self::TENANT_A, 'SELECT deleted_at, updated_at FROM inventory_item WHERE id = ?::uuid', [$id]);

        $this->send('POST', '/items/'.$id.'/restoration');

        self::assertSame(409, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        self::assertSame($after, $this->fetchAsOwnerForTenant(self::TENANT_A,
            'SELECT deleted_at, updated_at FROM inventory_item WHERE id = ?::uuid', [$id]), $why);
        self::assertNull($after[0]['deleted_at'], 'The first restoration did not restore the row, so the second proves nothing.');
    }

    // -----------------------------------------------------------------------

    /**
     * Everything a repeat could move on the order. `updated_at` is in it on
     * purpose: a guard that refused the transition and still touched the clock
     * has applied an effect.
     *
     * @return list<array<string, mixed>>
     */
    private function rowOf(string $orderId): array
    {
        return $this->fetchAsOwnerForTenant(self::TENANT_A,
            'SELECT status, ordered_at, expected_at, updated_at, total_amount FROM purchase_order WHERE id = ?::uuid',
            [$orderId]);
    }

    /** @return list<array<string, mixed>> */
    private function salesRowOf(string $orderId): array
    {
        return $this->fetchAsOwnerForTenant(self::TENANT_A,
            'SELECT status, ordered_at, updated_at, total_amount FROM sales_order WHERE id = ?::uuid',
            [$orderId]);
    }

    /** @return array{0: string, 1: string, 2: string} order, line, bin */
    private function orderedOrderToReceiveHere(): array
    {
        $draft = $this->seedDraft(self::TENANT_A, 'PO-000001');
        $line = $this->seedLine(self::TENANT_A, $draft['order'], $draft['item']);
        $bin = Uuid::v4()->toRfc4122();
        $this->insertAsOwnerForTenant(self::TENANT_A,
            "INSERT INTO storage_location (id, tenant_id, warehouse_id, code) VALUES (?::uuid, ?::uuid, ?::uuid, 'A-01')",
            [$bin, self::TENANT_A, $draft['warehouse']]);
        $this->insertAsOwnerForTenant(self::TENANT_A,
            "UPDATE purchase_order SET status = 'ordered', ordered_at = now() WHERE id = ?::uuid", [$draft['order']]);

        return [$draft['order'], $line, $bin];
    }

    /**
     * §4.2's mechanism per operation, read from the file that pins them so this
     * one does not become a second copy of the same table.
     *
     * @return array<string, string>
     */
    private static function mechanisms(): array
    {
        /** @var array<string, string> $pinned */
        $pinned = (new \ReflectionClass(IdempotencyContractTest::class))->getConstant('PINNED_MECHANISMS');

        return $pinned;
    }
}
