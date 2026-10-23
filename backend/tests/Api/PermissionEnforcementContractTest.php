<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Modules\AuditLog\Application\ExportPermission;
use App\Platform\Http\Bulk\BulkArchivalPermission;
use App\Platform\Http\Bulk\BulkRestorationPermission;
use App\Platform\Security\PermissionGate;
use App\Platform\Security\PermissionRequirement;
use App\Tests\Support\ContractDocument;
use App\Tests\Support\PermissionCell;
use App\Tests\Support\SecurityModelDocument;
use PHPUnit\Framework\Attributes\Test;

/**
 * §4.2's `Perm` column, reconciled against what the application declares — and
 * against §3's catalogue, which is where the codes come from.
 *
 * ---------------------------------------------------------------------------
 * Three separate claims, three separate ways to go red
 * ---------------------------------------------------------------------------
 *   1. **Every operation declares.** The registry is the domain; an operation
 *      with no declaration fails here rather than reaching production and
 *      raising a 500 on its first request.
 *   2. **What it declares is §4.2's cell.** Checked in both directions wherever
 *      the document is present, through the `PINNED_` mechanism §12.3 uses —
 *      `docs/` is gitignored, so the pin is what gives a clean checkout a
 *      subject.
 *   3. **The code exists.** A `Perm` cell naming a code §3's catalogue does not
 *      hold is a permission nobody can be granted, so the operation is
 *      unreachable by anyone — an outage that looks exactly like correct
 *      enforcement.
 *
 * `PermissionEnforcementTest` is the behavioural half: it drives every declared
 * code over HTTP. This file is about whether the declarations are the right ones.
 */
final class PermissionEnforcementContractTest extends ApiTestCase
{
    /**
     * §4.2's `Perm` cell for every operation this wave ships, transcribed once.
     * `null` is the column's `—`.
     *
     * **P-0 (2026-10-22, `g3-g7b-final-red.md` F-M2): a pin keeps the DECLARATION form** -- `null`, `'code'`, or a list of
     * two or more codes in byte order -- never a one-element list, which the declarations are forbidden to use. Pin and
     * registry are both normalised through `PermissionRequirement` before they are compared.
     *
     * @var array<string, string|list<string>|null>
     */
    public const PINNED_PERMISSIONS = [
        'IssueToken' => null,
        'GetCurrentUser' => null,
        // Remaining-ops G7a (2026-10-21): §4.2 :870's `Perm` cell.
        'GetSettings' => 'tenant_settings.view',
        // G7b (2026-10-22): §4.2's `UpdateSettings` `Perm` cell — the owner marker (E-15b).
        'UpdateSettings' => 'tenant_settings.edit',
        // Remaining-ops G3-C1 (2026-10-22): the reorder pair. The summary is `forecast.view` ALONE (the ruling's first
        // fails-if); the detail list is the first multi-code ALL_OF declaration — six codes, byte order (P-0's form; `g3-g7b-red.md`
        // H-2 added `purchase_order.view`).
        'GetReorderSummary' => 'forecast.view',
        'ListReorderSuggestions' => ['forecast.view', 'inventory_item.view', 'purchase_order.view', 'stock_level.view', 'supplier.view', 'supplier_product.view'],
        // §4.2's `—`, ruled on `PasswordResource`: §3's catalogue is closed at
        // sixty codes and none of them fits. `app_user.edit` is the near miss and
        // is the administrative code behind `UpdateUser`, held by `owner` and
        // `admin` alone — requiring it would stop most seats changing their own
        // password. The operation is self-service by construction instead: no
        // request field names a subject and the actor comes from the token.
        'ChangePassword' => null,
        'ListItems' => 'inventory_item.view',
        'GetItem' => 'inventory_item.view',
        // Sales step 5 C4 (2026-09-22): SoD-4 -- `fulfilment.create` is held by `owner` and `warehouse_operator` only.
        'CreateFulfilment' => 'fulfilment.create',
        'GetFulfilment' => 'fulfilment.view',
        // Sales step 6, C3: the invoice read and its issue (SoD-4: `invoice.issue` is never `warehouse_operator`'s).
        'GetInvoice' => 'invoice.view',
        'IssueInvoice' => 'invoice.issue',
        // Sales step 7 R2 (owner rulings Q-F, Q-B).
        'CreateSalesReturn' => 'sales_return.create',
        'GetSalesReturn' => 'sales_return.view',
        // G-29 / G-30 part 3 C1: E-1 is `credit_note.issue`; `distinct` and the reversal add the owner marker in the handler.
        'CreateAdjustmentDecision' => 'credit_note.issue',
        'ListSalesReturns' => 'sales_return.view',
        'ListInvoices' => 'invoice.view',
        // Sales step 7 C0: an invoice's derived lines — money, so `invoice.view`, never `fulfilment.view` (Q-B, V2-S-8).
        'ListInvoiceLines' => 'invoice.view',
        // Sales step 7 C1: the credit note (owner rulings Q-F / Q-B; A2.1b) and its read — money, so `invoice.view`.
        'IssueCreditNote' => 'credit_note.issue',
        'GetCreditNote' => 'invoice.view',
        'ListCreditNotes' => 'invoice.view',
        // Sales step 7 C1b: a credit with no return, on its own owner-held code (owner ruling Q-A = B′; ADR-008 A2.1c).
        'IssueAdjustmentCreditNote' => 'credit_note.adjust',
        'PayInvoice' => 'invoice.pay',
        'ReverseInvoicePayment' => 'invoice.pay',
        // Sales step 6 C7 (2026-09-29): `owner` alone among the default roles holds it since A2.1a (ADR-008 A2.7).
        'VoidInvoice' => 'invoice.void',
        // Sales step 6 C1 (2026-09-23): the register, the row's own `Perm` cell. `warehouse_operator` holds it.
        'ListFulfilments' => 'fulfilment.view',
        // Sales step 5 C3 (2026-09-22): the pick read, `fulfilment.view` ALONE (V2-S-1b clause 3, owner ruling R7).
        'GetPickList' => 'fulfilment.view',
        // Roles slice 1 (2026-09-23): the three role reads are `role.view`, which `viewer` holds -- so nothing
        // they serve may carry privilege history (PC-16 condition 1, IA §6.8).
        'ListPermissions' => 'role.view',
        'GetRole' => 'role.view',
        // Roles slice 2, C2: §4.2's `Perm` cell. Re-derived UNDER the role-administration lock as well (S-6, `SEC` S-2).
        'RevokeRole' => 'role.assign',
        // Roles slice 2, C3: the same code, re-derived under the same lock by the same `RoleAdministrationActor`.
        'AssignRole' => 'role.assign',
        // G5-C1 (2026-10-23): static, §4.2's cell.
        'CreateBulkRoleAssignment' => 'role.assign',
        'CreateRole' => 'role.create',
        // Roles slice 4 C2: re-derived under the lock with its own code (`RoleAdministrationActor`, C0's parameter).
        'UpdateRole' => 'role.edit',
        // G4-C1.
        'UpdateUser' => 'app_user.edit',
        // G4-C2.
        'DeleteUser' => 'app_user.delete',
        // Roles slice 4 C3: re-derived under the lock with its own code; the `role.assign` backstop after it is a 409, never
        // a second permission gate (`roles-s4-sec-data-red.md` item 1).
        'DeleteRole' => 'role.delete',
        // G2 (2026-10-22): undoing an archive is the authority that makes one (§4.2).
        'RestoreRole' => 'role.delete',
        // Roles slice 3, C1: E-15e's proposal route and its reads — `role.assign`, deliberately NOT `role.view`
        // (SECURITY_MODEL.md §5.2.3: a pending proposal is privilege history, and `viewer` holds `role.view`).
        'CreateRoleGrantProposal' => 'role.assign',
        // Roles slice 3 C2: the bare code (Q-6 (c′)); the owner check is the handler's, under the lock, never this pin's.
        'ConfirmRoleGrantProposal' => 'role.assign',
        // Roles slice 3 C3: Reject bare `role.assign` (owner check in the handler, Q-6 (c′)); Cancel `role.assign` only.
        'RejectRoleGrantProposal' => 'role.assign',
        'CancelRoleGrantProposal' => 'role.assign',
        'GetRoleGrantProposal' => 'role.assign',
        'ListRoleGrantProposals' => 'role.assign',
        // Users slice 1 C1 (2026-10-08): owner, admin and viewer hold it (owner notice N-1, `session23-owner-ruling.md`).
        'GetUser' => 'app_user.view',
        'ListUsers' => 'app_user.view',
        // Users slice 1 C3 (owner ruling Q-U2; `SEC` V2-R-25 (b) made a gate): owner and admin among the defaults.
        'ListOfferedRoles' => 'role.assign',
        // Customers slice 1 C1 (2026-10-09): `sales_rep`, `viewer`, `admin` and owner hold it (`SECURITY_MODEL.md` §4).
        'GetCustomer' => 'customer.view',
        'ListCustomers' => 'customer.view',
        // Suppliers slice 1 C1 (2026-10-12): `buyer`, `purchasing_manager`, `viewer`, `admin` and owner hold it (`SECURITY_MODEL.md` §4).
        'GetSupplier' => 'supplier.view',
        'ListSuppliers' => 'supplier.view',
        // Suppliers slice 1 C2 (2026-10-12): `buyer`, `purchasing_manager`, `admin` and owner hold both; `viewer` neither.
        'CreateSupplier' => 'supplier.create',
        'UpdateSupplier' => 'supplier.edit',
        'RestoreSupplier' => 'supplier.delete',
        // C5 C2b (2026-10-14): archiving is the same authority restoring needs (§4.2) — `buyer`, `purchasing_manager`, `admin`, owner.
        'DeleteSupplier' => 'supplier.delete',
        // Suppliers slice 1 C3 (2026-10-12): PC-9's first deliberate exception — the catalogue keeps its own codes, `.edit`
        // covering create and change (`buyer`, `purchasing_manager`, `admin`, owner hold both; `viewer` neither, PC-12).
        'ListSupplierProducts' => 'supplier_product.view',
        'CreateSupplierProduct' => 'supplier_product.edit',
        'UpdateSupplierProduct' => 'supplier_product.edit',
        // C4: removing an entry is a catalogue edit (§4.2).
        'DeleteSupplierProduct' => 'supplier_product.edit',
        // Warehouses slice 1 C1 (2026-10-13): `warehouse.view` — owner, `admin`, `buyer`, `purchasing_manager`,
        // `warehouse_operator`, `sales_rep`, `viewer`; `storage_location.view` — owner, `admin`, `warehouse_operator`, `viewer`
        // (`SECURITY_MODEL.md` §4). The location list is gated on its own code alone (PC-9's surface exception).
        'GetWarehouse' => 'warehouse.view',
        'ListWarehouses' => 'warehouse.view',
        'ListStorageLocations' => 'storage_location.view',
        // Stock reads C1 (2026-10-14): `stock_level.view` — owner, `admin`, `buyer`, `purchasing_manager`, `warehouse_operator`,
        // `sales_rep`, `viewer`; not `finance` (`SECURITY_MODEL.md` §4; `SEC` §1.1 derived from `DefaultRoles::codesFor()`).
        'ListStockLevels' => 'stock_level.view',
        // Stock reads C2: `stock_movement.view` — owner, `admin`, `warehouse_operator`, `viewer` (`SECURITY_MODEL.md` §4).
        'ListStockMovements' => 'stock_movement.view',
        'GetStockMovement' => 'stock_movement.view',
        // Audit reads C1 (2026-10-21): `audit_log.view` — owner and `admin` only; `viewer` holds `audit_log.view_record`
        // and not this (PC-11; `SECURITY_MODEL.md` §4).
        'ListAuditLogEntries' => 'audit_log.view',
        // Audit reads C2 (2026-10-21): `audit_log.view_record` — every default role (PC-11's colleague-readable half); the
        // subject's own read code is checked AFTER the gate, in the provider (E-5, `AuditRecordAccess`).
        'ListAuditRecords' => 'audit_log.view_record',
        // Stock count C1 (2026-10-16): `stock_movement.adjust` — owner, `admin`, `warehouse_operator` (`SECURITY_MODEL.md` §4).
        'CreateStockAdjustment' => 'stock_movement.adjust',
        // Stock transfer C1 (2026-10-20): the same code — no new permission (SEC-O-13 no-split, E-25).
        'CreateStockTransfer' => 'stock_movement.adjust',
        // Warehouses slice 1 C2 (2026-10-13): `warehouse.create` — owner and `admin`; `warehouse.edit` — owner, `admin` and
        // `warehouse_operator` (`SECURITY_MODEL.md` §4). `viewer` holds neither.
        'CreateWarehouse' => 'warehouse.create',
        'UpdateWarehouse' => 'warehouse.edit',
        'RestoreWarehouse' => 'warehouse.delete',
        'DeleteWarehouse' => 'warehouse.delete',
        'RestoreStorageLocation' => 'storage_location.delete',
        'DeleteStorageLocation' => 'storage_location.delete',
        // Warehouses slice 1 C3 (2026-10-13): `storage_location.create` — owner and `admin`; `storage_location.edit` — owner,
        // `admin` and `warehouse_operator` (`SECURITY_MODEL.md` §4). `viewer` holds neither.
        'CreateStorageLocation' => 'storage_location.create',
        'UpdateStorageLocation' => 'storage_location.edit',
        // Customers slice 1 C2 (2026-10-09): `sales_rep`, `admin` and owner hold both (`SECURITY_MODEL.md` §4); `viewer` neither.
        'CreateCustomer' => 'customer.create',
        'UpdateCustomer' => 'customer.edit',
        // C5 C4a (2026-10-14): `customer.delete` — `sales_rep`, `admin` and owner (`SECURITY_MODEL.md` §4); `viewer` not.
        'RestoreCustomer' => 'customer.delete',
        // C5 C4b (2026-10-14): archiving is the same authority restoring needs (§4.2) — `sales_rep`, `admin`, owner.
        'DeleteCustomer' => 'customer.delete',
        // Customers slice 1 C3 (2026-10-09): the address writes are `customer.edit` (NOT `.create`, §4.2), the list `.view`.
        'CreateCustomerAddress' => 'customer.edit',
        'UpdateCustomerAddress' => 'customer.edit',
        'ListCustomerAddresses' => 'customer.view',
        // Customers slice 2 C1 (2026-10-19): the contact writes are `customer.edit` (§4.2), the list `.view` (owner Q-3:
        // `viewer` reads contacts; PC-9 as written).
        'CreateCustomerContact' => 'customer.edit',
        'UpdateCustomerContact' => 'customer.edit',
        'ListCustomerContacts' => 'customer.view',
        // Customers slice 2 C2 (2026-10-19): the child's delete is `customer.edit` too (SEC S-3; PC-9 clarification).
        'DeleteCustomerContact' => 'customer.edit',
        // Customers slice 2 C3 (2026-10-19): the address delete is `customer.edit` too (SEC S-3).
        'DeleteCustomerAddress' => 'customer.edit',
        'ListRoles' => 'role.view',
        'CreateItem' => 'inventory_item.create',
        'UpdateItem' => 'inventory_item.edit',
        'DeleteItem' => 'inventory_item.delete',
        'RestoreItem' => 'inventory_item.delete',
        // §4.2's Purchasing block, added 2026-09-10. Every one is the row's own
        // `Perm` cell. Two are worth reading rather than scanning:
        // `ApprovePurchaseOrder` is the only operation in the tree requiring
        // `purchase_order.approve`, which is SoD-1's controlled half and the code
        // `buyer` does not hold; and the three line operations require
        // `purchase_order.edit` rather than a `purchase_order_line.*` code,
        // because §3's catalogue has none — a line is not a resource anybody is
        // separately entitled to.
        'ListPurchaseOrders' => 'purchase_order.view',
        'GetPurchaseOrder' => 'purchase_order.view',
        // Added 2026-09-11. `purchase_order.create` is SoD-1's *create* side —
        // the code `buyer` holds and `purchasing_manager` also holds, which is
        // why DSoD-1 exists on top of it rather than instead of it — and
        // `.cancel` is the second code of SoD-1's **controlled** half, moved
        // there by ADR-008's third pass and given its dynamic check by
        // Amendment 1.
        'CreatePurchaseOrder' => 'purchase_order.create',
        'CreateSalesOrder' => 'sales_order.create',
        // Its own code, not `.edit`: disposing of a draft is `DeletePurchaseOrder`'s act one module over.
        // `sales_rep` holds it (`DefaultRoles`), and no DSoD rule applies -- ADR-008 §2 declines the sales duty
        // split, and a draft is not a commitment. Unattributed: `RESIDUAL_GAPS.md` G-9.
        'DeleteSalesOrder' => 'sales_order.delete',
        // Order restores C2: undoing an archive is the same authority as making one (`RestoreItem`'s reason).
        'RestoreSalesOrder' => 'sales_order.delete',
        // `sales_order.edit`, not a code of its own: a line is part of the order, and §4.2 gives every
        // line write the parent's edit permission -- the purchase twin's rule.
        'CreateSalesOrderLine' => 'sales_order.edit',
        'UpdateSalesOrderLine' => 'sales_order.edit',
        'DeleteSalesOrderLine' => 'sales_order.edit',
        'RestoreSalesOrderLine' => 'sales_order.edit',
        // Added 2026-09-19 with the read. `sales_order.view` is the row's own `Perm` cell and it is the
        // only code this operation wants: `created_by`'s expansion needs no `app_user.view` (PC-15
        // condition 2, ruled for this field by B1N-2 on PC-11 (4a) ground) and neither customer nor
        // warehouse reference needs its own `.view` (PC-15 clause 4).
        'GetSalesOrder' => 'sales_order.view',
        // Added 2026-09-19. `sales_order.confirm` is the row's own `Perm` cell and a code of its own
        // rather than `.edit`: SoD-4 controls it against the movement codes (`SECURITY_MODEL.md` §4's
        // pair table), so an operation that confirmed under `.edit` would put a revenue-commitment
        // capability inside an editing one and quietly dissolve that pair. **No DSoD rule applies** —
        // ADR-008 names none on any sales document, and `sales_order.created_by_user_id` records the
        // creator, not the confirmer, so one could not be expressed on the columns that exist.
        'ConfirmSalesOrder' => 'sales_order.confirm',
        // Added 2026-09-19 with the operation. `sales_order.cancel` is the row's own `Perm` cell and a
        // code of its own in §3's catalogue. ADR-008 §2 records that `sales_rep` holds it **alongside**
        // `sales_order.confirm`, deliberately and asymmetrically with the buying side — so no
        // separation-of-duty check sits behind this code, and the audit row is the compensation.
        'CancelSalesOrder' => 'sales_order.cancel',
        // Added 2026-09-21 with the bounded sub-list. `sales_order.view` is the row's own `Perm` cell,
        // and it is the whole gate: the lines are read under the **parent document's** permission, not a
        // `sales_order_line.*` code — §3's catalogue mints none, because a line is not a document. The
        // expanded `inventory_item` needs no `inventory_item.view` either (PC-15 clause 4, the same
        // reading `GetSalesOrder`'s customer and warehouse references ride on).
        'ListSalesOrderLines' => 'sales_order.view',
        // Added 2026-09-21 with the document list. `sales_order.view` is the row's own `Perm` cell, and
        // the list returns the same document the direct hit does — including `created_by`, which rides on
        // owner ruling O-5 / PC-15 amendment 2 rather than extending it: §4.2 gates `GetSalesOrder` and
        // this row on the same code, so the disclosure condition is met identically.
        'ListSalesOrders' => 'sales_order.view',
        'CancelPurchaseOrder' => 'purchase_order.cancel',
        'UpdatePurchaseOrder' => 'purchase_order.edit',
        'DeletePurchaseOrder' => 'purchase_order.delete',
        // Order restores C3: undoing an archive is the same authority as making one (`RestoreItem`'s reason).
        'RestorePurchaseOrder' => 'purchase_order.delete',
        'ApprovePurchaseOrder' => 'purchase_order.approve',
        'ListPurchaseOrderLines' => 'purchase_order.view',
        // Step 8 (2026-09-11), each the row's own `Perm` cell.
        // `goods_receipt.create` is SoD-2's controlled code, held by `owner` and
        // `warehouse_operator` only: whoever raised the order is not whoever
        // confirms the goods arrived. No dynamic rule (DSoD) applies to
        // receipts — ADR-008 and SECURITY_MODEL.md name DSoD-1 alone, on
        // `.approve` and `.cancel` — so the static split is the whole control.
        'CreateGoodsReceipt' => 'goods_receipt.create',
        'GetGoodsReceipt' => 'goods_receipt.view',
        // Remaining-ops G1 (2026-10-21): the receipt register, the row's own `Perm` cell. `warehouse_operator` holds it.
        'ListGoodsReceipts' => 'goods_receipt.view',
        'CreatePurchaseOrderLine' => 'purchase_order.edit',
        'UpdatePurchaseOrderLine' => 'purchase_order.edit',
        'DeletePurchaseOrderLine' => 'purchase_order.edit',
        'RestorePurchaseOrderLine' => 'purchase_order.edit',
        // `sales_order.edit`, which is a seeded catalogue code -- measured, not assumed:
        // `SELECT code FROM permission WHERE code LIKE 'sales_order%'` returns
        // cancel, confirm, create, delete, edit, view.
        'UpdateSalesOrder' => 'sales_order.edit',
        // G6-C0 (2026-10-22): the first DERIVED operation. §4.2's `Perm` cell is `*(derived — see below)*`: the body's
        // `resource` resolves to the code its `List*` is declared with (`ExportPermission`, pinned below).
        'CreateExport' => PermissionGate::DERIVED,
        // G5-C2 (2026-10-22): §4.2's `Perm` cell is `*(derived)*`: the body's `resource` resolves to the code its single
        // `Delete*` is declared with, `<resource>.delete` (ST-9; `BulkArchivalPermission`, pinned below).
        'CreateBulkArchival' => PermissionGate::DERIVED,
        // G5-C3 (2026-10-23): the single `Restore*`'s declared code, `<resource>.delete` (ST-9; `BulkRestorationPermission`).
        'CreateBulkRestoration' => PermissionGate::DERIVED,
    ];

    /**
     * **P-0 (RED M-2): a derived pin's resolver.** A pin spells a derived permission as `PermissionGate::DERIVED`, exactly
     * as the declaration does, and its resolver service id lives here (the declaration's second extra). Empty until
     * G5-D0 ships a derived operation. Every pin read goes through {@see self::pinnedRequirement()}, so G5-D0 adds a row
     * here and edits no consumer.
     *
     * G6-C0 (2026-10-22): `CreateExport` is the first. Its resolver's MAP is pinned by name in
     * `ExportPermissionTest::eachExportableResourceResolvesToItsListsDeclaredCode` (each code against its List's own cell).
     *
     * @var array<string, string>
     */
    public const PINNED_RESOLVERS = [
        'CreateExport' => ExportPermission::class,
        // G5-C2: its map is pinned by name in `BulkAdmissibleSetTest::eachResourceResolvesToItsSingleDeletesDeclaredCode`.
        'CreateBulkArchival' => BulkArchivalPermission::class,
        // G5-C3: pinned by name in `BulkAdmissibleSetTest::eachResourceResolvesToItsSingleRestoresDeclaredCode`.
        'CreateBulkRestoration' => BulkRestorationPermission::class,
    ];

    /** One pin, normalised through the same parser as the registry. */
    public static function pinnedRequirement(string $operationId): PermissionRequirement
    {
        return PermissionRequirement::fromDeclaration(
            self::PINNED_PERMISSIONS[$operationId] ?? null,
            self::PINNED_RESOLVERS[$operationId] ?? null,
            $operationId,
        );
    }

    /** @return array<string, PermissionRequirement> every pin, normalised */
    public static function pinnedRequirements(): array
    {
        $requirements = [];
        foreach (array_keys(self::PINNED_PERMISSIONS) as $operationId) {
            $requirements[$operationId] = self::pinnedRequirement($operationId);
        }

        return $requirements;
    }

    /**
     * Claim 1. The domain is the registry and the pin has to cover all of it, so
     * an operation added without a `Perm` decision fails twice — once for having
     * no declaration, once for not being in the pin.
     *
     * *Mutation, run:* the `extraProperties` entry removed from `UpdateItem`. Red,
     * naming the operation. *Second mutation, run:* `PermissionGate`'s
     * `array_key_exists` guard changed to `?? null`, i.e. an absent declaration
     * silently meaning "no permission required". This test is still red — which is
     * the point of it being a separate check from the gate's own behaviour.
     */
    #[Test]
    public function everyOperationDeclaresItsRequiredPermission(): void
    {
        $declared = [];

        foreach (TransactionBoundaryTest::contractOperations() as $name => $operation) {
            // P-0: the one parser. An absent declaration, and any declaration outside the forms, is its LogicException.
            try {
                $declared[$name] = PermissionRequirement::of($operation)->describe();
            } catch (\LogicException $e) {
                self::fail($name.' declares no usable required permission ('.$e->getMessage().'). §4.2 gives every row a '
                    .'`Perm` cell, and an operation that declares nothing is one nobody decided about — PermissionGate '
                    .'refuses to guess, so this would be a 500 on its first request.');
            }
        }

        ksort($declared);
        $pinned = [];
        foreach (self::pinnedRequirements() as $name => $pin) {
            $pinned[$name] = $pin->describe();
        }
        self::assertSame([], array_diff_key(self::PINNED_RESOLVERS, self::PINNED_PERMISSIONS),
            'A resolver is pinned for an operation that has no pin.');
        ksort($pinned);

        self::assertSame($pinned, $declared,
            'The registry and the pin disagree. Every shipped operation needs a reviewed `Perm` decision.');
    }

    /**
     * Claim 3, and it runs on **every** checkout because its domain is the code.
     *
     * A declared code must be a member of §3's catalogue. Where the document is
     * absent the check falls back to the shape §3's own rule fixes — `PC-1`'s
     * `<resource>.<action>` — which is weaker but is not nothing, and the
     * document-backed clause below is where the real comparison happens.
     */
    #[Test]
    public function everyDeclaredCodeIsShapedLikeACatalogueMember(): void
    {
        $examined = 0;

        foreach (self::everyDeclaredPermission() as $code) {
            ++$examined;
            self::assertMatchesRegularExpression('/^[a-z][a-z0-9_]*\.[a-z][a-z0-9_]*$/', $code,
                $code.' is not shaped like a §3 permission code.');
        }

        self::assertGreaterThan(0, $examined, 'No code was examined, so this assertion had no subject.');
    }

    /**
     * Claim 3, against the catalogue itself.
     *
     * The parser is checked against §3's own stated total before anything is
     * compared to it: a parser that found the wrong table, or stopped expanding
     * the `.suffix` shorthand, would otherwise turn this into a check that admits
     * everything it happens to have parsed.
     *
     * *Mutation, run:* `GetItem`'s declared code changed to
     * `inventory_item.read` — a plausible name §3 does not hold. Red.
     */
    #[Test]
    public function theCatalogueThisApiDrawsFromIsStillTheOneSecurityModelPublishes(): void
    {
        if (!SecurityModelDocument::isAvailable()) {
            self::markTestSkipped('docs/SECURITY_MODEL.md is gitignored and absent from this checkout, so the declared codes are checked for shape only — by `everyDeclaredCodeIsShapedLikeACatalogueMember` in this file. §3\'s catalogue is the side that is unavailable here.');
        }

        $catalogue = SecurityModelDocument::permissionCodes();
        $stated = SecurityModelDocument::catalogueSizeFromTheHeading();

        self::assertNotNull($stated, '§3\'s heading no longer states a total, so the parser has nothing to be wrong against.');
        self::assertCount($stated, $catalogue,
            'The §3 parser produced '.\count($catalogue).' codes and the heading states '.$stated
            .'. Every comparison below would be against a catalogue that is not the catalogue.');

        foreach (self::everyDeclaredPermission() as $code) {
            self::assertContains($code, $catalogue,
                $code.' is declared by an operation and is not in §3\'s catalogue. Nobody can be granted it, '
                .'so the operation is unreachable by every caller — which looks exactly like correct enforcement.');
        }
    }

    /**
     * Claim 2 — the pin against the document, in both directions.
     *
     * *Mutation, run:* `DeleteItem`'s pin changed to `inventory_item.archive`;
     * red against §4.2's cell **and** red against the registry in
     * `everyOperationDeclaresItsRequiredPermission`, which is the two-sided
     * property a pin is for.
     */
    #[Test]
    public function thePinsMatchTheContractDocument(): void
    {
        if (!ContractDocument::isAvailable()) {
            self::markTestSkipped('docs/API.md is gitignored and absent from this checkout, so the pins above are checked against the code only — by `everyOperationDeclaresItsRequiredPermission` in this file, which holds `self::PINNED_PERMISSIONS` against the operation registry. §4.2\'s table is the side that is unavailable here.');
        }

        $rows = ContractDocument::operationRows();
        self::assertGreaterThan(50, \count($rows), 'The §4.2 parser found almost nothing, so every comparison below would be vacuous.');

        foreach (self::pinnedRequirements() as $operationId => $pin) {
            self::assertArrayHasKey($operationId, $rows, $operationId.' is shipped and §4.2 declares no row for it.');

            $cell = $rows[$operationId]['perm'];
            $parsed = $rows[$operationId]['permission'];
            $enforced = PermissionRequirement::ALL_OF === $pin->kind() ? $pin->codes() : [];

            // Cell order is checked as written: the codes() of a pin are byte-sorted by construction, so a cell listing
            // the same codes in another order is red here (F-L1: declaration == sorted == cell).
            self::assertSame([$pin->kind(), $enforced], [$parsed['kind'], $parsed['codes']],
                $operationId.'\'s `Perm` cell is "'.$cell.'" and this suite enforces "'.$pin->describe().'".');
        }
    }

    /**
     * **P-0: the `Perm`-cell grammar, both directions** (`g3-g7b-final-red.md` F-M2). Planted cells first (they run on
     * every checkout): each form parses to its kind, both live derived spellings are derived, and a cell outside the
     * grammar is `unparsed` -- never silently `none`. Then every §4.2 row, built or not, must parse.
     */
    #[Test]
    public function everyPermCellIsInTheGrammar(): void
    {
        self::assertSame(['kind' => 'none', 'codes' => []], PermissionCell::parse(' — '));
        self::assertSame(['kind' => 'all_of', 'codes' => ['a.view']], PermissionCell::parse('`a.view`'));
        self::assertSame(['kind' => 'all_of', 'codes' => ['b.view', 'a.view']], PermissionCell::parse('`b.view` `a.view`'),
            'Cell order must be preserved, so an unsorted cell can be caught against the byte-sorted declaration.');
        self::assertSame(['kind' => 'derived', 'codes' => []], PermissionCell::parse('*(derived)*'));
        self::assertSame(['kind' => 'derived', 'codes' => []], PermissionCell::parse('*(derived — see below)*'));
        foreach (['', '`a.view`,`b.view`', 'a.view', '`A.View`', '*derived*'] as $outside) {
            self::assertSame('unparsed', PermissionCell::parse($outside)['kind'], var_export($outside, true).' parsed.');
        }

        if (!ContractDocument::isAvailable()) {
            self::markTestSkipped('docs/API.md is gitignored and absent from this checkout; the planted cells above were checked, §4.2\'s rows were not.');
        }

        $rows = ContractDocument::operationRows();
        self::assertGreaterThan(50, \count($rows), 'The §4.2 parser found almost nothing.');
        $unparsed = [];
        foreach ($rows as $operationId => $row) {
            if ('unparsed' === $row['permission']['kind']) {
                $unparsed[] = $operationId.' => '.($row['permission']['codes'][0] ?? '');
            }
        }
        self::assertSame([], $unparsed, '§4.2 rows whose `Perm` cell is outside the grammar.');

        // RED L-3 / F-L1, for every row built or not: a multi-code cell is distinct codes in byte order -- the order the
        // declaration must use and the 403 names the first missing one in.
        $misordered = [];
        $multi = 0;
        foreach ($rows as $operationId => $row) {
            $codes = $row['permission']['codes'];
            if ('all_of' !== $row['permission']['kind'] || \count($codes) < 2) {
                continue;
            }
            ++$multi;
            $sorted = array_values(array_unique($codes));
            sort($sorted, \SORT_STRING);
            if ($sorted !== $codes) {
                $misordered[] = $operationId.' => '.implode(' ', $codes);
            }
        }
        self::assertGreaterThan(0, $multi, 'No §4.2 cell carries two or more codes, so the order rule had no subject.');
        self::assertSame([], $misordered, '§4.2 multi-code `Perm` cells that are not distinct and byte-sorted.');
    }

    /**
     * §4.2's other rule about the same column, and it is the one a reader gets
     * wrong: `—` does **not** mean public.
     *
     * *"Whether it also requires a JWT is decided by the row's own `Path` …
     * `/auth/*` is pre-authentication; everything else requires a valid token."*
     * `GetCurrentUser` holds no code and is authenticated, and that pair is
     * exactly the case the sentence was written for.
     */
    #[Test]
    public function anOperationWithNoCodeIsNotThereforePublic(): void
    {
        $withoutACode = [];

        foreach (TransactionBoundaryTest::contractOperations() as $name => $operation) {
            // A declared `null` is NONE and an absent key is a LogicException inside `PermissionRequirement::of()` --
            // the distinction §4.2's `—` turns on ("no code required" vs "nobody decided") lives in that one parser.
            if (PermissionRequirement::NONE !== PermissionRequirement::of($operation)->kind()) {
                continue;
            }

            $path = (string) $operation->getUriTemplate();
            $path = str_starts_with($path, self::BASE) ? substr($path, \strlen(self::BASE)) : $path;
            $withoutACode[$name] = $path;
        }

        self::assertNotSame([], $withoutACode, 'No operation declares `—`, so this assertion had no subject.');
        self::assertArrayHasKey('GetCurrentUser', $withoutACode,
            'GetCurrentUser no longer declares `—`. It is the operation §4.2\'s sentence about `—` is written about.');

        // Driven rather than reasoned about: the one that is not under /auth/
        // still refuses an anonymous request.
        $this->actAsNobody();
        $this->send('GET', $withoutACode['GetCurrentUser']);

        self::assertSame(401, $this->httpStatus(),
            'An operation with no permission code was reachable without a token, so `—` is being read as "public".');
    }
}
