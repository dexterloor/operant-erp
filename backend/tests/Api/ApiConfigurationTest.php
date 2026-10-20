<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Platform\Http\Idempotency\IdempotencyKeyGuard;
use App\Platform\Http\Problem\ProblemType;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Routing\RouterInterface;

/**
 * The three configuration rules the recipe contradicts: API-2 (one versioned
 * base path), API-3 (JSON, Hydra off) and API-4 (problem+json on every non-2xx).
 */
final class ApiConfigurationTest extends ApiTestCase
{
    /** API-2 / P-1. The expected prefix is the parameter, not a string here — one declaration, two readers. */
    #[Test]
    public function everyContractRouteIsUnderTheVersionedBasePath(): void
    {
        $basePath = self::getContainer()->getParameter('app.api_base_path');
        self::assertSame(self::BASE, $basePath);

        $router = self::getContainer()->get(RouterInterface::class);
        \assert($router instanceof RouterInterface);

        $checked = [];
        $notExposed = [];

        foreach ($router->getRouteCollection() as $name => $route) {
            $resourceClass = $route->getDefault('_api_resource_class');
            if (!\is_string($resourceClass) || !str_starts_with($resourceClass, 'App\\')) {
                continue;
            }

            // API Platform mints a `NotExposed` operation at
            // `/.well-known/genid/{id}` for any resource that declares no item
            // `GET` -- `Token` is one, because a token has no row and no id --
            // and there is no opt-out short of declaring an operation this
            // contract does not have. It is separated rather than filtered by
            // name: the assertion below is that every excluded route is the
            // vendor's not-exposed action, so a real route cannot hide here.
            if ('api_platform.action.not_exposed' === $route->getDefault('_controller')) {
                $notExposed[(string) $name] = $resourceClass;
                self::assertStringStartsWith($basePath.'/', $route->getPath(), $name.' is mounted outside the versioned base path.');

                continue;
            }

            $checked[(string) $name] = $route->getPath();
            self::assertStringStartsWith($basePath.'/', $route->getPath(), $name.' is mounted outside the versioned base path.');
        }

        // Two, and each is the same vendor behaviour for a different reason.
        // `Token` has no row and no id, so its fallback lands on the skolem
        // path. `PurchaseOrderLine` **does** have a row-level URI -- §4.2
        // declares `PATCH` and `DELETE` at `/purchase-orders/{id}/lines/{lineId}`
        // -- but declares no item `GET` there, which is exactly the shape
        // §4.4.1's `AssignRole` paragraph rules legitimate, so API Platform
        // mints one at the pluralised default path instead. It answers 404 by
        // construction (`NotFoundAction`) and `CreatedRowLocation` is what stops
        // the vendor's `Location` naming it.
        //
        // **`SalesOrder` was a third entry from 2026-09-19 until `GetSalesOrder` shipped the same day,
        // and it has left** — as the entry itself said it would. It was the transient case: §4.2 always
        // declared `GetSalesOrder` at `/sales-orders/{id}` and ruling R-A merely deferred it one commit
        // behind its writer, so the resource had no item `GET` for one commit and the vendor minted the
        // fallback. The `GET` now exists, no fallback is minted, and `CreateSalesOrder`'s
        // `CreatedRowLocation` declaration went with it. **Its departure is the check, not a tidy-up:**
        // an entry left behind here would be a stale exemption, and this assertion is what catches it.
        //
        // **A third entry arrived 2026-09-21 with `CreateSalesOrderLine`**, and it is the
        // `PurchaseOrderLine` case exactly: `SalesOrderLine` now has a built write at
        // `/sales-orders/{id}/lines` and **no item `GET`**, so API Platform mints the pluralised
        // fallback. Unlike `SalesOrder`'s transient 2026-09-19 entry this one is **permanent** — §4.2
        // declares no `GetSalesOrderLine` at all, where it always declared `GetSalesOrder`. It was
        // predicted from the purchase twin's shape before the operation was declared and then read off
        // this failure, which is the order that matters: the prediction was checked, not trusted.
        self::assertSame([
            // **2026-10-09, customers slice 1 C3 (final RED M-1)**, the same case again and permanent: `CustomerAddress` has
            // built writes at `/customers/{id}/addresses[/{addressId}]` and §4.2 declares no `GetCustomerAddress` (TB-1c).
            // Predicted from the line twins' shape, then read off `debug:router` before the test ran
            // (the vendor's route order puts it first).
            '_api_/customer_addresses/{id}{._format}_get' => \App\Modules\Customers\ApiResource\CustomerAddressResource::class,
            // **2026-10-19, customers slice 2 C1**, the same case and permanent: `CustomerContact` has built writes at
            // `/customers/{id}/contacts[/{contactId}]` and §4.2 declares no `GetCustomerContact` (TB-1c). Read off this test.
            '_api_/customer_contacts/{id}{._format}_get' => \App\Modules\Customers\ApiResource\CustomerContactResource::class,
            '_api_/purchase_order_lines/{id}{._format}_get' => \App\Modules\Purchasing\ApiResource\PurchaseOrderLineResource::class,
            '_api_/sales_order_lines/{id}{._format}_get' => \App\Modules\Sales\ApiResource\SalesOrderLineResource::class,
            // **2026-10-12, suppliers slice 1 C3**, the same case and permanent: `SupplierProduct` has built writes at
            // `/suppliers/{id}/products[/{productId}]` and §4.2 declares no `GetSupplierProduct` (a bounded sub-list). Read off
            // `debug:router` before the test ran (the vendor's route order puts it after `sales_order_lines`).
            '_api_/supplier_products/{id}{._format}_get' => \App\Modules\Suppliers\ApiResource\SupplierProductResource::class,
            '_api_/.well-known/genid/{id}{._format}_get' => \App\Modules\UserManagement\ApiResource\TokenResource::class,
            // **2026-10-13, warehouses slice 1 C3**, the same case and permanent: `StorageLocation` has built writes at
            // `/warehouses/{id}/locations[/{locationId}]` and §4.2 declares no `GetStorageLocation` (a bounded sub-list).
            // Read off the failing run: the vendor's route order puts it after `.well-known/genid`.
            '_api_/storage_locations/{id}{._format}_get' => \App\Modules\Warehouses\ApiResource\StorageLocationResource::class,
        ], $notExposed,
            'A route was excluded from the count as "not exposed" that is not one of the API Platform mints listed here.');

        // Six for the `/items` slice, three for Output 4 §7's authentication
        // paths (`IssueToken`, `GetCurrentUser`, and `ChangePassword` added
        // 2026-09-09 under E-23), nine for the purchasing slice added
        // 2026-09-10, and **two more on 2026-09-11** — `CreatePurchaseOrder`,
        // which E9-2 released once `Version20260911000100` gave it a
        // client-supplied retry key, and `CancelPurchaseOrder`, which X9-1 held
        // shut until `PurchaseOrder::cancel()` existed. The count is a floor
        // against a route appearing that nobody declared; which operations those
        // are is pinned by name in ItemOperationContractTest,
        // AuthOperationContractTest and PurchaseOrderOperationContractTest.
        // **Two more at step 8 (2026-09-11):** `CreateGoodsReceipt`, released by
        // 8-i's retry key and `PurchaseOrder::recordReceipt()`, and
        // `GetGoodsReceipt`, which exists so its 201's `Location` resolves.
        // **One more on 2026-09-19:** `CreateSalesOrder`, the first sales write, released by
        // `Version20260919000100`'s retry key and granted its two INSERTs by `Version20260919000200`.
        // **And one more the same day:** `GetSalesOrder`, which needed no migration at all — `erp_app`
        // already held SELECT on both sales tables — and whose arrival is also why the not-exposed list
        // above lost an entry.
        // **One more, later on 2026-09-19:** `ConfirmSalesOrder`, the first operation in `src/` to change a
        // sales order that already exists, granted its `UPDATE` on both sales tables by
        // `Version20260919000300`.
        // **And one more, last on 2026-09-19:** `CancelSalesOrder`, which needed no grant migration at
        // all — `erp_app` already held `UPDATE` on both sales tables from `Version20260919000300` and
        // `INSERT` on `audit_log`, both measured with `has_table_privilege()` — but did need
        // `Version20260919000400`, the catalogue extension for `sales_order.cancelled`.
        // **One more on 2026-09-21:** `ListSalesOrderLines`, the bounded sub-list of a sales order's
        // lines. No migration and no grant change — a read issues no write, and `erp_app` already held
        // `SELECT` on `sales_order_line`, `sales_order` and `inventory_item`, re-measured with
        // `has_table_privilege()` in that commit.
        // **And one more the same day:** `ListSalesOrders`, the document list. Also no migration and no
        // grant change, and its `customer_id` filter reads a table `erp_app` holds SELECT on and nothing
        // else.
        // **And one more on 2026-09-21:** `UpdateSalesOrder`. No migration and no grant change --
        // `erp_app` already held `UPDATE` on `sales_order` from `Version20260919000300`, re-measured
        // with `has_table_privilege()` in that commit, and the operation issues nothing outside that
        // footprint (ADR-002 A4.4 duty 1's *"exactly"*).
        // **And one more on 2026-09-21:** `CreateSalesOrderLine`, the first write on the sales line
        // resource. Its migration (`Version20260921000100`) grants nothing -- a new column inherits the
        // table-level ACL -- and `erp_app` already held `INSERT` and `UPDATE` on both sales tables.
        // **And one more the same day:** `UpdateSalesOrderLine`. It adds no `$notExposed` route -- the
        // `SalesOrderLine` fallback route was already minted by `CreateSalesOrderLine`, and a second
        // write on the same resource does not mint a second one.
        // **And one more:** `DeleteSalesOrderLine`. A **soft** delete -- an `UPDATE` of `deleted_at` --
        // so it needs no `DELETE` grant and none was added: `has_table_privilege('erp_app',
        // 'sales_order_line', 'DELETE')` is false and stays false.
        // **And the last of this slice:** `RestoreSalesOrderLine`. It ships while its purchase twin does
        // not, and the asymmetry is deliberate -- see `RestoreSalesOrderLineProcessor`'s docblock and
        // `PurchaseOrderOperationContractTest::PINNED_UNSHIPPED`'s note.
        // **And one more on 2026-09-21 (sales slice 5):** `DeleteSalesOrder`. A **soft** delete as well --
        // `has_table_privilege('erp_app', 'sales_order', 'DELETE')` is false before and after -- and it adds no
        // `$notExposed` route: `SalesOrderResource` already has its item `Get`. `RestoreSalesOrder` is NOT
        // declared: E9-3 blocks it (owner ruling Q1, 2026-09-21).
        // **And one more on 2026-09-22 (sales step 5, commit 3):** `GetPickList`, the 116th §4.2 row (owner ruling
        // R7). A read over tables `erp_app` already SELECTs; its `Get` is its resource's only operation, so it adds
        // no `$notExposed` fallback route.
        // **And two more on 2026-09-22 (sales step 5, commit 4):** `GetFulfilment` and `CreateFulfilment`. Stock moves:
        // the migration grants `erp_app` exactly INSERT on `fulfilment` and `fulfilment_line` (measured before and
        // after with `has_table_privilege()`), and `GetFulfilment` is the resource's item `Get`, declared first, so the
        // 201's `Location` names it and no `$notExposed` fallback route is minted.
        // **And one more on 2026-09-23 (roles slice 1, C2):** `ListPermissions`, a read of the global `permission`
        // table `erp_app` already SELECTs (measured with `has_table_privilege()` before and after -- no migration). Its
        // item DTO `PermissionResource` is not an ApiResource, so no `$notExposed` fallback route is minted.
        // **And one more (roles slice 1, C3):** `GetRole`, a read over `role`, `role_permission`, `user_role` and
        // `app_user`, all of which `erp_app` already SELECTs -- no migration. Its `Get` is `RoleResource`'s only
        // operation, so no `$notExposed` fallback route is minted.
        // **And the last of roles slice 1 (C4):** `ListRoles`, the same four tables, bounded, `archived` + `q`. No
        // migration; no `$notExposed` route (its item DTO is `GetRole`'s `RoleResource`).
        // **And one more on 2026-09-23 (sales step 6, C1):** `ListFulfilments`, the shipment register. A read over tables
        // `erp_app` already SELECTs -- no migration. Its resource `FulfilmentCollection` has a `Get` as its only
        // operation, so no `$notExposed` fallback route is minted.
        // **And two more on 2026-09-28 (sales step 6, C3):** `GetInvoice` and `IssueInvoice`. The migration grants `erp_app`
        // a COLUMN-level INSERT on exactly the ten invoice columns the writer names (table-level INSERT stays false,
        // measured). `GetInvoice` is `InvoiceResource`'s item `Get`, declared first, so the 201's `Location` names it
        // and no `$notExposed` fallback route is minted.
        // **And one more (sales step 6, C4):** `ListInvoices`, a read over tables `erp_app` already SELECTs -- no
        // migration. `InvoiceCollection` has a `Get` as its only operation, so no `$notExposed` fallback route is minted.
        // **And one more on 2026-09-28 (sales step 6, C5):** `PayInvoice`. The migration grants `erp_app` a COLUMN-level
        // UPDATE on exactly the four columns its flush sets (table-level UPDATE stays false, measured). A `Post` on
        // `InvoiceResource`, whose item `Get` already exists, so no `$notExposed` fallback route is minted.
        // **And one more (sales step 6, C6):** `ReverseInvoicePayment`. Its migration grants `erp_app` a column UPDATE on
        // `payment_reversed_at` alone (B′). Another `Post` on `InvoiceResource`; no `$notExposed` fallback route.
        // **And one more (sales step 6, C7, 2026-09-29):** `VoidInvoice`. No grant: its flush sets `status` and `updated_at`,
        // column-granted since C5. Another `Post` on `InvoiceResource`; no `$notExposed` fallback route.
        // **And one more (roles slice 2, C2, 2026-09-29):** `RevokeRole`, a `Delete` on `RoleResource` at
        // `/users/{id}/roles/{roleId}`, `read: false`. Its migration grants `erp_app` `DELETE` on `user_role` -- the first
        // `DELETE` grant anywhere. `RoleResource`'s item `Get` already exists, so no `$notExposed` fallback route is minted.
        // **And one more (roles slice 2, C3, 2026-09-30):** `AssignRole`, a `Post` on `RoleResource` at `/users/{id}/roles`,
        // `read: false`. Its migration grants `erp_app` a COLUMN-level `INSERT` on the four columns its one `INSERT` names
        // (table-level `INSERT` stays false, measured). `RoleResource`'s item `Get` exists, so no `$notExposed` fallback.
        // **And two more (sales step 7 R2, 2026-10-01):** `GetSalesReturn` and `CreateSalesReturn` on `SalesReturnResource`
        // (item `Get` declared first, so no `$notExposed` fallback route). Its migration grants `erp_app` table-level INSERT on
        // `sales_return` and `sales_return_line`.
        // **And one more (sales step 7 R3):** `ListSalesReturns`, a `Get` on `SalesReturnCollection`. No grant.
        // **And one more (sales step 7 C0, 2026-10-01):** `ListInvoiceLines`, a `Get` on `InvoiceLineCollection` at
        // `/invoices/{id}/lines`, bounded. Its migration grants `erp_app` and `erp_ai` SELECT on the two credit tables.
        // **And two more (sales step 7 C1, 2026-10-01):** `GetCreditNote` and `IssueCreditNote` on `CreditNoteResource` (item
        // `Get` declared first, so no `$notExposed` fallback). Its migration grants `erp_app` a column INSERT on `credit_note`
        // and INSERT on `credit_note_line`.
        // **And one more (sales step 7 C1b, 2026-10-01):** `IssueAdjustmentCreditNote` on `CreditNoteResource`, a POST at
        // `/invoices/{id}/credit-note-adjustments` (`credit_note.adjust`). No grant: C1's INSERT already names its columns.
        // **And one more (sales step 7 C2, 2026-10-02):** `ListCreditNotes`, a `Get` on `CreditNoteCollection` at
        // `/credit-notes` (`invoice.view`). No grant: `erp_app` already holds SELECT on the tables it reads.
        // **And three more (roles slice 3 C1, 2026-10-04):** `GetRoleGrantProposal` and `CreateRoleGrantProposal` on
        // `RoleGrantProposalResource` (item `Get` declared first, so no `$notExposed` fallback route), and
        // `ListRoleGrantProposals` on `RoleGrantProposalCollection`. Its migration creates `role_grant_proposal` and grants
        // `erp_app` SELECT, a column INSERT on eight columns and a column UPDATE on four; `erp_ai` nothing.
        // **And one more (roles slice 3 C2, 2026-10-05):** `ConfirmRoleGrantProposal` on `RoleGrantProposalResource`, a POST
        // at `/role-grant-proposals/{id}/confirmation` (bare `role.assign`; the owner check is the handler's). Its migration
        // grants `erp_app` a column UPDATE on `decided_by_user_id`.
        // **And two more (roles slice 3 C3, 2026-10-05):** `RejectRoleGrantProposal` and `CancelRoleGrantProposal` on
        // `RoleGrantProposalResource` (`role.assign`). Their migration grants `erp_app` a column UPDATE on `reason`.
        // **And one more (G-29 / G-30 part 3 C1, 2026-10-05):** `CreateAdjustmentDecision` on `SalesReturnResource`, a POST
        // at `/sales-returns/{id}/adjustment-decisions` (`credit_note.issue`; branch 5). Its migration creates
        // `adjustment_decision`: SELECT to `erp_app`/`erp_ai`, a column INSERT on eleven columns to `erp_app`.
        // **And one more (roles slice 4 C2, 2026-10-07):** `UpdateRole`, a PATCH at `/roles/{id}` on `RoleResource` (`role.edit`;
        // branch 5). Its migration grants `erp_app` column UPDATE (name, description, updated_at) on `role` and DELETE on
        // `role_permission`.
        // **And one more (roles slice 4 C3, 2026-10-07):** `DeleteRole`, a DELETE at `/roles/{id}` on `RoleResource` (`role.delete`;
        // branch 3, a soft delete). Its migration grants `erp_app` column UPDATE (deleted_at) on `role`.
        // **And one more (users slice 1 C1, 2026-10-08):** `GetUser`, a GET at `/users/{id}` on `UserResource` (`app_user.view`).
        // No migration, no grant.
        // **And one more (users slice 1 C2, 2026-10-08):** `ListUsers`, a GET at `/users` on `UserCollection` (`app_user.view`).
        // **And one more (users slice 1 C3, 2026-10-08):** `ListOfferedRoles`, a GET at `/users/{id}/offered-roles` on
        // `OfferedRoleCollection` (`role.assign`; owner ruling Q-U2). No migration, no grant.
        // **And two more (customers slice 1 C1, 2026-10-09):** `GetCustomer`, a GET at `/customers/{id}` on `CustomerResource`,
        // and `ListCustomers`, a GET at `/customers` on `CustomerCollection` (both `customer.view`). No migration, no grant.
        // **And two more (customers slice 1 C2, 2026-10-09):** `CreateCustomer`, a POST at `/customers` (`customer.create`;
        // branch 4), and `UpdateCustomer`, a PATCH at `/customers/{id}` (`customer.edit`), both on `CustomerResource`. Its
        // migration grants `erp_app` a column INSERT and a column UPDATE on `customer`.
        // **And three more (customers slice 1 C3, 2026-10-09):** `CreateCustomerAddress` (POST `/customers/{id}/addresses`,
        // `customer.edit`; branch 4) and `UpdateCustomerAddress` (PATCH `/customers/{id}/addresses/{addressId}`,
        // `customer.edit`) on `CustomerAddressResource`, and `ListCustomerAddresses` (GET `/customers/{id}/addresses`,
        // `customer.view`, bounded) on `CustomerAddressCollection`. Its migration grants `erp_app` a column INSERT and a
        // column UPDATE on `customer_address`; the not-exposed fallback route above is not counted.
        // **And two more (suppliers slice 1 C1, 2026-10-12):** `GetSupplier`, a GET at `/suppliers/{id}` on `SupplierResource`,
        // and `ListSuppliers`, a GET at `/suppliers` on `SupplierCollection` (both `supplier.view`). No migration, no grant.
        // **And two more (suppliers slice 1 C2, 2026-10-12):** `CreateSupplier`, a POST at `/suppliers` (`supplier.create`;
        // branch 4), and `UpdateSupplier`, a PATCH at `/suppliers/{id}` (`supplier.edit`), both on `SupplierResource`. Its
        // migration grants `erp_app` a column INSERT and a column UPDATE on `supplier`.
        // **And three more (suppliers slice 1 C3, 2026-10-12):** `CreateSupplierProduct` (POST `/suppliers/{id}/products`,
        // `supplier_product.edit`; branch 4) and `UpdateSupplierProduct` (PATCH `/suppliers/{id}/products/{productId}`,
        // `supplier_product.edit`) on `SupplierProductResource`, and `ListSupplierProducts` (GET `/suppliers/{id}/products`,
        // `supplier_product.view`, bounded) on `SupplierProductCollection`. Its migration grants `erp_app` a column INSERT and
        // a column UPDATE on `supplier_product`; the not-exposed fallback route above is not counted.
        // **And one more (suppliers slice 1 C4, 2026-10-12):** `DeleteSupplierProduct` (DELETE
        // `/suppliers/{id}/products/{productId}`, `supplier_product.edit`; branch 3, 204) on `SupplierProductResource`. Its
        // migration grants `erp_app` table DELETE on `supplier_product`, the third DELETE grant.
        // **And three more (warehouses slice 1 C1, 2026-10-13):** `GetWarehouse` (GET `/warehouses/{id}`, `warehouse.view`) on
        // `WarehouseResource`, `ListWarehouses` (GET `/warehouses`, `warehouse.view`, bounded) on `WarehouseCollection`, and
        // `ListStorageLocations` (GET `/warehouses/{id}/locations`, `storage_location.view`, bounded) on
        // `StorageLocationCollection`. No migration, no grant.
        // **And two more (warehouses slice 1 C2, 2026-10-13):** `CreateWarehouse`, a POST at `/warehouses` (`warehouse.create`),
        // and `UpdateWarehouse`, a PATCH at `/warehouses/{id}` (`warehouse.edit`), both on `WarehouseResource`. Its migration
        // grants `erp_app` a column INSERT and a column UPDATE on `warehouse`.
        // **And two more (warehouses slice 1 C3, 2026-10-13):** `CreateStorageLocation` (POST `/warehouses/{id}/locations`,
        // `storage_location.create`; branch 4) and `UpdateStorageLocation` (PATCH `/warehouses/{id}/locations/{locationId}`,
        // `storage_location.edit`) on `StorageLocationResource`. Its migration grants `erp_app` a column INSERT and a column
        // UPDATE on `storage_location`; the not-exposed fallback route above is not counted.
        // **And one more (C5 C2a, 2026-10-13):** `RestoreSupplier` (POST `/suppliers/{id}/restoration`, `supplier.delete`;
        // branch 5, 200) on `SupplierResource`. Its migration grants `erp_app` column UPDATE (`deleted_at`) on `supplier`.
        // **And one more (C5 C2b, 2026-10-14):** `DeleteSupplier` (DELETE `/suppliers/{id}`, `supplier.delete`; branch 3, 204)
        // on `SupplierResource`. No grant: it reuses C2a's column UPDATE (`deleted_at`); `erp_app` holds no DELETE on `supplier`.
        // **And one more (C5 C4a, 2026-10-14):** `RestoreCustomer` (POST `/customers/{id}/restoration`, `customer.delete`;
        // branch 5, 200) on `CustomerResource`. Its migration grants `erp_app` column UPDATE (`deleted_at`) on `customer`.
        // **And one more (C5 C4b, 2026-10-14):** `DeleteCustomer` (DELETE `/customers/{id}`, `customer.delete`; branch 3, 204)
        // on `CustomerResource`. No grant: it reuses C4a's column UPDATE (`deleted_at`); `erp_app` holds no DELETE on `customer`.
        // **And one more (stock reads C1, 2026-10-14):** `ListStockLevels` (GET `/stock`, `stock_level.view`, bounded, exactly
        // one of `storage_location_id` / `inventory_item_id`) on `StockLevelCollection`. No migration, no grant.
        // **And two more (stock reads C2, 2026-10-14):** `ListStockMovements` (GET `/stock-movements`, `stock_movement.view`) on
        // `StockMovementCollection` and `GetStockMovement` (GET `/stock-movements/{id}`) on `StockMovementResource`. Its migration
        // rebuilds two indexes with a trailing `id`; no grant.
        // **And one more (stock count C1, 2026-10-16):** `CreateStockAdjustment` (POST `/stock-movements/adjustments`,
        // `stock_movement.adjust`; branch 4, 201) on `StockMovementResource`. No grant (C0's columns inherit the table grants).
        // **And three more (customers slice 2 C1, 2026-10-19):** `CreateCustomerContact` (POST `/customers/{id}/contacts`,
        // `customer.edit`; branch 4) and `UpdateCustomerContact` (PATCH `/customers/{id}/contacts/{contactId}`, `customer.edit`)
        // on `CustomerContactResource`, and `ListCustomerContacts` (GET `/customers/{id}/contacts`, `customer.view`, bounded)
        // on `CustomerContactCollection`. Its migration grants `erp_app` a column INSERT and a column UPDATE on
        // `customer_contact`; the not-exposed fallback route above is not counted.
        // **And one more (customers slice 2 C2, 2026-10-19):** `DeleteCustomerContact` (DELETE `/customers/{id}/contacts/{contactId}`,
        // `customer.edit`; branch 3, 204) on `CustomerContactResource`. Its migration grants `erp_app` table DELETE on
        // `customer_contact`.
        // **And one more (customers slice 2 C3, 2026-10-19):** `DeleteCustomerAddress` (DELETE `/customers/{id}/addresses/{addressId}`,
        // `customer.edit`; branch 3, 204) on `CustomerAddressResource`. Its migration grants `erp_app` table DELETE on
        // `customer_address`.
        // **And one more (stock transfer C1, 2026-10-20):** `CreateStockTransfer` (POST `/stock-movements/transfers`,
        // `stock_movement.adjust`; branch 4, 201) on `StockMovementResource`. No grant (the count's grants serve it, measured).
        // **And one more (warehouse/location delete-restore C1, 2026-10-20):** `RestoreWarehouse` (POST
        // `/warehouses/{id}/restoration`, `warehouse.delete`; branch 5, 200) on `WarehouseResource`. Its migration grants
        // `erp_app` column UPDATE (`deleted_at`) on `warehouse`.
        // **And one more (warehouse/location delete-restore C2, 2026-10-20):** `RestoreStorageLocation` (POST
        // `/warehouses/{id}/locations/{locationId}/restoration`, `storage_location.delete`; branch 5, 200) on
        // `StorageLocationResource`. Its migration grants `erp_app` column UPDATE (`deleted_at`) on `storage_location`.
        // **And one more (warehouse/location delete-restore C3, 2026-10-20):** `DeleteStorageLocation` (DELETE
        // `/warehouses/{id}/locations/{locationId}`, `storage_location.delete`; branch 3, 204) on `StorageLocationResource`.
        // No grant (C2's `UPDATE (deleted_at)` serves it).
        // **And one more (warehouse/location delete-restore C4, 2026-10-20):** `DeleteWarehouse` (DELETE `/warehouses/{id}`,
        // `warehouse.delete`; branch 3, 204) on `WarehouseResource`. No grant (C1's `UPDATE (deleted_at)` serves it).
        // **And one more (audit reads C1, 2026-10-21):** `ListAuditLogEntries` (GET `/audit-log`, `audit_log.view`) on
        // `AuditLogEntryCollection`. Its migration rebuilds three `audit_log` indexes with a trailing `id`, concurrently; no grant.
        self::assertCount(111, $checked, 'The route set changed. This slice declares one hundred and eleven operations: '.implode(', ', array_keys($checked)));
    }

    /**
     * API-3 — Hydra off. Asserted on what the recipe actually leaves behind:
     * the entrypoint and the JSON-LD context route, both of which are Hydra
     * documents, and the enabled format list.
     */
    #[Test]
    public function hydraAndJsonLdAreOff(): void
    {
        $router = self::getContainer()->get(RouterInterface::class);
        \assert($router instanceof RouterInterface);

        foreach (['api_entrypoint', 'api_jsonld_context'] as $recipeRoute) {
            self::assertNull($router->getRouteCollection()->get($recipeRoute), $recipeRoute.' is still registered.');
        }

        // Asserted over every format list API Platform keeps, and on the
        // media types rather than on the format keys: a format named something
        // else that still emits `application/ld+json` is the same defect.
        $lists = ['api_platform.formats', 'api_platform.patch_formats', 'api_platform.error_formats', 'api_platform.docs_formats'];
        $mediaTypes = [];

        foreach ($lists as $parameter) {
            /** @var array<string, list<string>> $formats */
            $formats = self::getContainer()->getParameter($parameter);
            self::assertNotSame([], $formats, $parameter.' is empty, which would make the assertion below vacuous.');
            self::assertArrayNotHasKey('jsonld', $formats, $parameter.' still registers the jsonld format.');

            foreach ($formats as $types) {
                $mediaTypes = [...$mediaTypes, ...$types];
            }
        }

        self::assertNotContains('application/ld+json', $mediaTypes);
        self::assertNotContains('application/vnd.api+json', $mediaTypes);
        self::assertSame(['json' => ['application/json']], self::getContainer()->getParameter('api_platform.formats'));
    }

    /** A client that asks for JSON-LD is refused rather than served it. */
    #[Test]
    public function aRequestForJsonLdIsRefused(): void
    {
        $this->seedItem(self::TENANT_A, 'SKU-1');

        foreach (['application/ld+json', 'application/vnd.api+json', 'application/xml'] as $mediaType) {
            $this->send('GET', '/items', null, [], $mediaType);

            self::assertSame(406, $this->httpStatus(), $mediaType.' was served.');
            self::assertStringStartsWith('application/problem+json', $this->contentType());
            self::assertSame(ProblemType::NotAcceptable->uri(), $this->json()['type']);
        }
    }

    /**
     * API-4 / P-2 — every non-2xx is `application/problem+json`, and API-13 —
     * every `type` is a `urn:erp:problem:` URN.
     *
     * The cases are the ones this slice can reach, and they deliberately span
     * both routes into the problem shape: the four raised inside an operation
     * (API Platform's error pipeline) and the two raised during routing, which
     * API Platform hands to Symfony's error controller unless something
     * intervenes.
     */
    #[Test]
    public function everyNonSuccessResponseIsProblemJsonWithAUrnType(): void
    {
        $id = $this->seedItem(self::TENANT_A, 'SKU-1');

        $cases = [
            'not found (in an operation)' => fn () => $this->send('GET', '/items/00000000-0000-4000-8000-000000000000'),
            'not found (no route at all)' => fn () => $this->send('GET', '/nothing-is-here'),
            'method not allowed (routing)' => fn () => $this->send('PUT', '/items/'.$id, ['name' => 'x']),
            'not acceptable' => fn () => $this->send('GET', '/items', null, [], 'application/ld+json'),
            'unsupported media type' => fn () => $this->sendRaw('POST', '/items', '{}', 'text/plain'),
            'validation failed' => fn () => $this->send('GET', '/items?nope=1'),
            'business rule violated' => fn () => $this->send('POST', '/items/'.$id.'/restoration', null, [IdempotencyKeyGuard::HEADER => 'k']),
            'idempotency key required' => fn () => $this->send('POST', '/items', ['sku' => 'x', 'name' => 'x', 'unit_of_measure_code' => 'EA']),
        ];

        foreach ($cases as $label => $drive) {
            $drive();

            self::assertGreaterThanOrEqual(400, $this->httpStatus(), $label);
            self::assertStringStartsWith('application/problem+json', $this->contentType(), $label.' is not problem+json.');

            $body = $this->json();
            self::assertArrayHasKey('type', $body, $label);
            self::assertStringStartsWith(ProblemType::URN_PREFIX, $body['type'], $label.' carries a type that is not a urn:erp:problem: URN.');
            self::assertSame($this->httpStatus(), $body['status'], $label);
            self::assertArrayNotHasKey('trace', $body, $label.' leaked a stack trace.');
            self::assertArrayNotHasKey('class', $body, $label.' leaked the exception class.');
        }
    }

    /** ST-8a/ST-8b — `Retry-After` is the remaining time, and the remaining time is reconnaissance. */
    #[Test]
    public function noResponseCarriesRetryAfter(): void
    {
        $this->send('GET', '/items');
        self::assertFalse($this->client()->getResponse()->headers->has('Retry-After'));

        $this->send('GET', '/items?nope=1');
        self::assertFalse($this->client()->getResponse()->headers->has('Retry-After'));
    }

    private function sendRaw(string $method, string $path, string $content, string $contentType): void
    {
        $this->client()->request(
            $method,
            self::BASE.$path,
            [],
            [],
            ['HTTP_ACCEPT' => 'application/json', 'CONTENT_TYPE' => $contentType, 'HTTP_IDEMPOTENCY_KEY' => 'k'],
            $content,
        );
    }
}
