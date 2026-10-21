<?php

declare(strict_types=1);

namespace App\Tests\Api;

use ApiPlatform\Metadata\HttpOperation;
use App\Modules\Purchasing\Infrastructure\Entity\PurchaseOrder;
use App\Modules\Sales\Infrastructure\Entity\SalesOrder;
use App\Platform\Tenancy\SchemaManifest;
use App\Tests\Support\CountingKdf;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Uid\Uuid;

/**
 * **R-29 (Critical) — a client-supplied `tenant_id` in a header, the query
 * string or the body does not change what any operation does.**
 *
 * ARCHITECTURE T-1, API-7 and ADR-005 D-5 say the tenant enters a request in one
 * place only: the `tenant_id` claim of a signed access token. Before this file
 * the only runtime check of that was one body field on one operation
 * (`AuthOperationContractTest::anUndeclaredFieldIsRefusedRatherThanIgnored`,
 * `IssueToken`). The register named `AuthTest::testTenantClaimIgnoresClientInput`,
 * which never existed.
 *
 * ---------------------------------------------------------------------------
 * What is sent
 * ---------------------------------------------------------------------------
 * The domain is the operation registry
 * (`TransactionBoundaryTest::contractOperations()`), never a list. For each
 * operation the request is sent once with no probe (the baseline) and then once
 * per probe, each carrying **tenant B's real id** while the token is tenant A's:
 *
 *   - three headers an implementation might plausibly honour: `X-Tenant-Id`,
 *     `Tenant-Id`, `X-Tenant`;
 *   - a `tenant_id` query parameter;
 *   - a `tenant_id` body field, on every operation that is not a `GET`.
 *
 * Every request runs against **freshly truncated and re-seeded state**, with
 * tenant B holding a mirror of tenant A's fixture — the same supplier, items,
 * warehouse, bin, draft order, placed order and receipt, under its own ids. So
 * an operation that served B instead of A would answer with B's rows (or a 404
 * for A's ids), and one that wrote into B would change B's rows; neither can
 * hide behind an empty tenant.
 *
 * ---------------------------------------------------------------------------
 * What must hold, per probe
 * ---------------------------------------------------------------------------
 *   1. **The outcome is the baseline's**: status, `Location` and body, with
 *      every fixture id replaced by its symbol (`A.item`, `B.draft`, …), other
 *      UUIDs and RFC 3339 instants masked, and a JWT reduced to its `tenant_id`
 *      and `sub` claims — plus the per-table row counts of tenant A after the
 *      request, so a write landing nowhere or twice is a different outcome.
 *   2. **Or it is the operation's existing refusal of an undeclared name** — and
 *      only for the query and body channels, where that refusal exists. It is
 *      not assumed: the same request is re-sent with the name
 *      `not_a_declared_name` instead of `tenant_id`, and the probe's outcome
 *      must equal that control's with the one name swapped, must be a 4xx, and
 *      the control must itself differ from the baseline (a refusal is only a
 *      refusal if the undeclared name is refused).
 *   3. **Tenant B's rows are untouched**: a digest of every tenant-scoped table
 *      (`SchemaManifest`, derived) read as B before and after the request.
 *   4. **No response names one of tenant B's rows** — baseline included.
 *
 * An operation this file does not know how to drive is a failure naming it, not
 * a skip; and a recipe for an operation the registry no longer declares is a
 * failure too. Every baseline must be a 2xx, so a probe is never compared with a
 * request that did nothing.
 */
final class TenantClaimProbeTest extends PurchaseOrderTestCase
{
    /** @var list<string> */
    private const HEADER_PROBES = ['X-Tenant-Id', 'Tenant-Id', 'X-Tenant'];

    private const PROBED_NAME = 'tenant_id';

    private const CONTROL_NAME = 'not_a_declared_name';

    private const PASSWORD = 'the-fixture-principal-password';

    /**
     * How to drive each operation so that its baseline succeeds. The method and
     * the path template are read off the registry; a recipe only fills the
     * template's variables and supplies the body. `@name` is replaced by the
     * acting tenant's fixture id of that name.
     *
     * @var array<string, array{vars?: array<string, string>, query?: array<string, string>, body?: array<string, mixed>|null, setup?: string, authenticated?: bool}>
     */
    private const RECIPES = [
        'IssueToken' => [
            'body' => ['workspace' => 'tenant-a', 'email' => '@actor_email', 'password' => self::PASSWORD],
            'authenticated' => false,
        ],
        'GetCurrentUser' => [],
        'ChangePassword' => [
            'body' => ['current_password' => self::PASSWORD, 'new_password' => 'a-different-password-of-some-length'],
        ],
        'ListItems' => [],
        // Roles slice 1 (2026-09-23). The vocabulary is global -- no tenant column, no RLS -- so a supplied tenant
        // id has nothing to move; the fourth assertion (no response names one of tenant B's rows) holds trivially.
        'ListPermissions' => [],
        // `role` is already remembered per tenant by `seedMirror()`, with names normalised to 'Fixture Role'.
        'GetRole' => ['vars' => ['id' => 'role']],
        // Every tenant holds its principal's fixture role, so the baseline page is non-empty and a supplied tenant
        // id that moved the read would return B's roles where A's were asked for.
        'ListRoles' => [],
        // Roles slice 2, C2: a second user's non-marker grant (seeded in `seedMirror()`), revoked by the actor.
        'RevokeRole' => ['vars' => ['id' => 'revoke_subject', 'roleId' => 'revoke_role']],
        // Roles slice 2, C3: a third user holding nothing, given the same one-code (`role.view`) composed role — inside the
        // actor's set, no `X(admin)` code, no SoD row. A supplied tenant id that moved the write would grant in B, which
        // the digest names. The `Idempotency-Key` is supplied by the driver (`OPERATION_FLAG`).
        'AssignRole' => ['vars' => ['id' => 'assign_subject'], 'body' => ['role_id' => '@revoke_role']],
        // Roles slice 3, C1: the seeded pending proposal (`seedMirror()`) and the register over it; a supplied tenant id that
        // moved the read would return B's proposal. Create proposes the X(admin) composed role for a user holding nothing;
        // a moved write would propose in B, which the digest names.
        'GetRoleGrantProposal' => ['vars' => ['id' => 'proposal']],
        'ListRoleGrantProposals' => [],
        // Users slice 1 C1: the actor's own user, remembered per tenant; a supplied tenant id that moved the read would
        // 404 here (B's actor is another id) or name B's user.
        'GetUser' => ['vars' => ['id' => 'actor']],
        // Users slice 1 C2: every tenant holds its actor and seeded users, so a supplied tenant id that moved the read
        // would return B's users where A's were asked for.
        'ListUsers' => [],
        // Users slice 1 C3: the picker for the remembered revoke subject; a moved tenant would 404 (B's subject is another id).
        'ListOfferedRoles' => ['vars' => ['id' => 'revoke_subject']],
        // Customers slice 1 C1: the customer every tenant remembers (`seedMirror()`); a supplied tenant id that moved the read
        // would 404 here (B's customer is another id), and the list would return B's customers.
        'GetCustomer' => ['vars' => ['id' => 'customer']],
        'ListCustomers' => [],
        // Suppliers slice 1 C1: the supplier every tenant remembers (`seedMirror()`); a supplied tenant id that moved the read
        // would 404 here (B's supplier is another id), and the list would return B's suppliers.
        'GetSupplier' => ['vars' => ['id' => 'supplier']],
        'ListSuppliers' => [],
        // Suppliers slice 1 C2: a create with a letter-bearing code (a moved write would land in B: B's list would hold it),
        // and an update of the remembered supplier (B's supplier is another id, so a moved update would 404).
        'CreateSupplier' => ['body' => ['code' => 'S-NEW', 'name' => 'New Supplier', 'country_code' => 'GB', 'currency_code' => 'GBP']],
        'UpdateSupplier' => ['vars' => ['id' => 'supplier'], 'body' => ['name' => 'Renamed Supplier']],
        'RestoreSupplier' => ['vars' => ['id' => 'archived_supplier']],
        // C5 C2b: a supplier with no order (the remembered `supplier` holds the draft, so its delete is refused); a moved
        // delete would 404 (B's idle supplier is another id) or archive B's.
        'DeleteSupplier' => ['vars' => ['id' => 'idle_supplier']],
        // Suppliers slice 1 C3: the remembered supplier and its remembered catalogue entry (`seedMirror()`). A moved read
        // would 404 or list B's catalogue; a moved create would 404 (B's supplier is another id) — the item is the
        // remembered uncatalogued one; a moved update would 404 on the pair.
        'ListSupplierProducts' => ['vars' => ['id' => 'supplier']],
        'CreateSupplierProduct' => ['vars' => ['id' => 'supplier'], 'body' => ['inventory_item_id' => '@loose_item',
            'supplier_sku' => 'SP-PROBE', 'unit_cost' => '2.5', 'lead_time_days' => 3]],
        'UpdateSupplierProduct' => ['vars' => ['id' => 'supplier', 'productId' => 'supplier_product'], 'body' => ['lead_time_days' => 4]],
        // C4: a moved delete would 404 on the pair (B's supplier and entry are other ids).
        'DeleteSupplierProduct' => ['vars' => ['id' => 'supplier', 'productId' => 'supplier_product']],
        // Warehouses slice 1 C1: the warehouse every tenant remembers (`seedMirror()`, with its `A-01` bin). A moved read
        // would 404 (B's warehouse is another id), and the list would return B's warehouses.
        'GetWarehouse' => ['vars' => ['id' => 'warehouse']],
        'ListWarehouses' => [],
        'ListStorageLocations' => ['vars' => ['id' => 'warehouse']],
        // Stock reads C1 (design R-H4): `/stock` answers 422 bare (API-19b), so its baseline is scoped by a QUERY parameter,
        // `query` => [param => fixture name], filled with the acting tenant's id like `vars`. `seedMirror` puts a stock row
        // in each tenant's `bin`, so A's baseline returns a row and B's must never appear.
        'ListStockLevels' => ['query' => ['storage_location_id' => 'bin']],
        // Stock reads C2 (design R-H4; T-2′ measured: `seedMirror` registered no movement, so it now seeds one per tenant).
        'ListStockMovements' => [],
        'GetStockMovement' => ['vars' => ['id' => 'movement']],
        // Audit reads C1: the whole trail, unfiltered (`seedMirror` writes one row per tenant). A moved read would list B's row.
        'ListAuditLogEntries' => [],
        // Stock count C1: a count INTO the tenant's own bin of an item it never held there (`version: null`), on fresh state per
        // request. A moved write would land in B (B's counts change) or answer an unknown reference for A's ids.
        'CreateStockAdjustment' => ['body' => ['inventory_item_id' => '@loose_item', 'storage_location_id' => '@bin',
            'counted_quantity' => '5', 'version' => null, 'note' => 'Probe count']],
        // Stock transfer C1 (2026-10-20): one unit of the tenant's stocked `@item` out of its `@bin` into a second bin of the same
        // warehouse (`second_bin` setup), on fresh state per request. A moved write would land in B (B's levels change) or
        // answer an unknown reference for A's ids.
        'CreateStockTransfer' => ['setup' => 'second_bin', 'body' => ['inventory_item_id' => '@item', 'source_storage_location_id' => '@bin',
            'destination_storage_location_id' => '@second_bin', 'quantity' => '1', 'note' => 'Probe move']],
        // Warehouses slice 1 C2: a create with a letter-bearing code (a moved write would land in B: B's list would hold it),
        // and an update of the remembered warehouse (B's warehouse is another id, so a moved update would 404).
        'CreateWarehouse' => ['body' => ['code' => 'W-NEW', 'name' => 'New Warehouse', 'country_code' => 'GB']],
        'UpdateWarehouse' => ['vars' => ['id' => 'warehouse'], 'body' => ['name' => 'Renamed Warehouse']],
        // Warehouse/location delete-restore C4: a warehouse no order and no stock names (the remembered `warehouse` carries the
        // mirror's orders and stock — API-O-34 refuses it); B's idle warehouse is another id, so a moved delete 404s.
        'DeleteWarehouse' => ['vars' => ['id' => 'idle_warehouse']],
        // Warehouse/location delete-restore C1: the remembered archived warehouse (B's is another id, so a moved restore 404s).
        'RestoreWarehouse' => ['vars' => ['id' => 'archived_warehouse']],
        // C2: an archived location of the remembered warehouse (B's pair is other ids, so a moved restore 404s).
        'RestoreStorageLocation' => ['vars' => ['id' => 'warehouse', 'locationId' => 'archived_bin']],
        // C3: an EMPTY location of the remembered warehouse (the `A-01` bin may hold stock; owner Q-1 refuses that). B's pair is
        // other ids, so a moved delete 404s or archives B's.
        'DeleteStorageLocation' => ['vars' => ['id' => 'warehouse', 'locationId' => 'idle_bin']],
        // Warehouses slice 1 C3: a create under the remembered warehouse (a moved create would 404: B's warehouse is another
        // id), and a re-label of the remembered `A-01` bin (a moved update would 404 on the pair).
        'CreateStorageLocation' => ['vars' => ['id' => 'warehouse'], 'body' => ['code' => 'B-NEW', 'kind' => 'shelf']],
        'UpdateStorageLocation' => ['vars' => ['id' => 'warehouse', 'locationId' => 'bin'], 'body' => ['code' => 'A-RELABEL']],
        // Customers slice 1 C2: a create with a letter-bearing code (a moved write would land in B: B's list would hold it),
        // and an update of the remembered customer (B's customer is another id, so a moved update would 404).
        'CreateCustomer' => ['body' => ['code' => 'C-NEW', 'name' => 'New Customer', 'country_code' => 'GB', 'currency_code' => 'GBP']],
        'UpdateCustomer' => ['vars' => ['id' => 'customer'], 'body' => ['name' => 'Renamed']],
        // C5 C4a: a restore of the remembered archived customer (B's is another id, so a moved restore would 404).
        'RestoreCustomer' => ['vars' => ['id' => 'archived_customer']],
        // C5 C4b: a customer with no sales document (the remembered `customer` may carry the mirror's orders); a moved delete
        // would 404 (B's idle customer is another id) or archive B's.
        'DeleteCustomer' => ['vars' => ['id' => 'idle_customer']],
        // Customers slice 1 C3: the remembered customer and its remembered address. A moved read would 404 or list B's
        // addresses; a moved create would 404 (B's customer is another id); a moved update would 404 on the pair.
        'ListCustomerAddresses' => ['vars' => ['id' => 'customer']],
        'CreateCustomerAddress' => ['vars' => ['id' => 'customer'], 'body' => ['kind' => 'shipping', 'line' => '1 Probe Street',
            'city' => 'Leeds', 'postal_code' => 'LS1 1AA', 'country_code' => 'GB']],
        'UpdateCustomerAddress' => ['vars' => ['id' => 'customer', 'addressId' => 'customer_address'], 'body' => ['city' => 'York']],
        // Customers slice 2 C1: the remembered customer and its remembered contact. A moved read would 404 or list B's
        // contacts; a moved create would 404 (B's customer is another id); a moved update would 404 on the pair.
        'ListCustomerContacts' => ['vars' => ['id' => 'customer']],
        'CreateCustomerContact' => ['vars' => ['id' => 'customer'], 'body' => ['name' => 'Probe Contact', 'email' => 'probe@example.test']],
        'UpdateCustomerContact' => ['vars' => ['id' => 'customer', 'contactId' => 'customer_contact'], 'body' => ['phone' => '+44 113 496 0000']],
        // Customers slice 2 C2: `DeleteSupplierProduct`'s shape — a moved delete would 404 on the pair, or remove B's contact.
        'DeleteCustomerContact' => ['vars' => ['id' => 'customer', 'contactId' => 'customer_contact']],
        // Customers slice 2 C3: the same shape for the remembered address.
        'DeleteCustomerAddress' => ['vars' => ['id' => 'customer', 'addressId' => 'customer_address']],
        'CreateRoleGrantProposal' => ['vars' => ['id' => 'proposal_subject'], 'body' => ['role_id' => '@proposal_role']],
        // Roles slice 3, C2: the same seeded proposal, confirmed by the actor made an owner for this request (the marker on
        // its role, Q-6 (c′)) with a proposer who still manages roles (C9). A moved write would grant in B: the digest.
        'ConfirmRoleGrantProposal' => ['vars' => ['id' => 'proposal'], 'setup' => 'confirmable_proposal'],
        // Roles slice 3, C3: the same seeded proposal, rejected by the actor made an owner (the Confirm setup), and
        // withdrawn by the actor made its proposer. A moved write would decide B's proposal: the digest.
        'RejectRoleGrantProposal' => ['vars' => ['id' => 'proposal'], 'setup' => 'confirmable_proposal', 'body' => ['reason' => 'Probe']],
        'CancelRoleGrantProposal' => ['vars' => ['id' => 'proposal'], 'setup' => 'withdrawable_proposal'],
        // Roles slice 4, C1: a one-code (`role.view`) role composed by the actor. A supplied tenant id that moved the write
        // would create the role in B, which the digest names.
        'CreateRole' => ['body' => ['name' => 'Probe composed', 'permission_codes' => ['role.view']]],
        // Roles slice 4, C2: the one-code (`role.view`) composed role seeded in `seedMirror()` (never the actor's own fixture
        // role, which holds every declared code and so spans every split — PC-4 on the result would refuse it), re-described.
        // A supplied tenant id that moved the write would edit B's role, which the digest names.
        'UpdateRole' => ['vars' => ['id' => 'revoke_role'], 'body' => ['description' => 'Probe edited']],
        // Roles slice 4, C3: an unheld composed role with no proposal (`seedMirror()`), deleted by the actor (who holds every
        // declared code, `role.assign` included — RED F1-D). A supplied tenant id that moved the write would delete B's role.
        'DeleteRole' => ['vars' => ['id' => 'delete_role']],
        'GetItem' => ['vars' => ['id' => 'item']],
        'CreateItem' => ['body' => ['sku' => 'SKU-NEW', 'name' => 'Widget', 'unit_of_measure_code' => 'EA']],
        'UpdateItem' => ['vars' => ['id' => 'item'], 'body' => ['name' => 'Renamed']],
        'DeleteItem' => ['vars' => ['id' => 'loose_item']],
        'RestoreItem' => ['vars' => ['id' => 'archived_item']],
        'ListPurchaseOrders' => [],
        'GetPurchaseOrder' => ['vars' => ['id' => 'draft']],
        'CreatePurchaseOrder' => ['body' => ['supplier_id' => '@supplier', 'warehouse_id' => '@warehouse']],
        'UpdatePurchaseOrder' => ['vars' => ['id' => 'draft'], 'body' => ['expected_at' => '2026-12-01T00:00:00+00:00']],
        'DeletePurchaseOrder' => ['vars' => ['id' => 'draft']],
        'ApprovePurchaseOrder' => ['vars' => ['id' => 'draft'], 'body' => []],
        'CancelPurchaseOrder' => ['vars' => ['id' => 'draft'], 'body' => []],
        'ListPurchaseOrderLines' => ['vars' => ['id' => 'draft']],
        'CreatePurchaseOrderLine' => [
            'vars' => ['id' => 'draft'],
            'body' => ['inventory_item_id' => '@catalogued_item', 'quantity' => '3.500000', 'unit_cost' => '4.5000'],
        ],
        'UpdatePurchaseOrderLine' => ['vars' => ['id' => 'draft', 'lineId' => 'draft_line'], 'body' => ['quantity' => '7.000000']],
        'DeletePurchaseOrderLine' => ['vars' => ['id' => 'draft', 'lineId' => 'draft_line']],
        // Order-restores C0: an archived second line of the draft, so the baseline restore succeeds.
        'RestorePurchaseOrderLine' => ['vars' => ['id' => 'draft', 'lineId' => 'archived_draft_line']],
        'CreateGoodsReceipt' => [
            'vars' => ['id' => 'placed'],
            'body' => ['lines' => [['purchase_order_line_id' => '@placed_line', 'quantity' => '1', 'storage_location_id' => '@bin']]],
        ],
        'GetGoodsReceipt' => ['vars' => ['id' => 'receipt'], 'setup' => 'receipt'],
        // The ship-to address is a symbol of THIS tenant's customer, so the cross-tenant probe drives the
        // V2-S-9 ownership check as well as the claim: if a supplied tenant id changed which workspace the
        // lookup ran in, the address would resolve against the other tenant's customer.
        'CreateSalesOrder' => ['body' => [
            'customer_id' => '@customer', 'warehouse_id' => '@warehouse', 'ship_to_address_id' => '@customer_address',
        ]],
        // Added 2026-09-19 with the read. Its subject is a seeded order in each tenant, so a supplied
        // tenant id that changed which workspace the provider read in would answer with B's order where
        // A's was asked for — which is what the probe compares.
        'GetSalesOrder' => ['vars' => ['id' => 'sales_order']],
        // Added 2026-09-19 with the confirm. Its subject is the same seeded order, which carries a line so
        // that the baseline **succeeds** — the line guard would otherwise refuse every drive and the probe
        // would be comparing two refusals. `freshState()` re-seeds before each drive, so the order is
        // `draft` again for every one of the five.
        'ConfirmSalesOrder' => ['vars' => ['id' => 'sales_order']],
        // Added 2026-09-19 with the cancel. Same seeded order, and it needs no line: §8.3 makes `draft` a
        // legal source for `cancel()`, so the baseline succeeds on the fixture as it stands. `body` carries
        // the `reason` §4.2 accepts, which is also what puts a value in the audit row's `metadata` on the
        // successful drive -- so a supplied tenant id that moved the write would move a trail row too.
        'CancelSalesOrder' => ['vars' => ['id' => 'sales_order'], 'body' => ['reason' => 'Customer withdrew']],
        // Added 2026-09-21 with the line create. `@loose_item` rather than `@item`, because the seeded
        // order already carries a line for `@item` and a per-tenant symbol is what makes a cross-tenant
        // answer legible in the digest. The `Idempotency-Key` header the operation requires is supplied
        // by the driver for every recipe whose operation carries `OPERATION_FLAG`.
        'CreateSalesOrderLine' => [
            'vars' => ['id' => 'sales_order'],
            'body' => ['inventory_item_id' => '@loose_item', 'quantity' => '3.500000', 'unit_price' => '4.5000'],
        ],
        // The seeded line of the seeded order. `unit_price` is sent as well as `quantity` because that is
        // D-A's frozen column and a probe that never sends it says nothing about it.
        'UpdateSalesOrderLine' => [
            'vars' => ['id' => 'sales_order', 'lineId' => 'sales_order_line'],
            'body' => ['quantity' => '7.000000', 'unit_price' => '9.5000'],
        ],
        'DeleteSalesOrderLine' => ['vars' => ['id' => 'sales_order', 'lineId' => 'sales_order_line']],
        // The seeded order is a DRAFT, so the baseline delete succeeds (204) -- a probe whose baseline is
        // refused would compare a refusal with a refusal.
        'DeleteSalesOrder' => ['vars' => ['id' => 'sales_order']],
        // ⚠ Its subject must be an ARCHIVED line, because the baseline drive has to SUCCEED -- a probe
        // whose baseline is refused compares a refusal with a refusal. `archived_sales_order_line` is a
        // per-tenant symbol seeded beside the live one.
        'RestoreSalesOrderLine' => ['vars' => ['id' => 'sales_order', 'lineId' => 'archived_sales_order_line']],
        // Added 2026-09-21 with the edit. The body carries `@customer_address`, a **per-tenant** symbol,
        // and that is the whole reason to spell it that way rather than move the warehouse: the drive
        // then exercises V2-S-9's ownership predicate — *"this address belongs to this customer"* —
        // cross-tenant as well as the claim itself. A supplied tenant id that moved which workspace
        // `shipToInside()` read in would resolve B's address for A's order, which is precisely what
        // `sales_order.ship_to_*` can never afterwards be shown to have done (G-6).
        'UpdateSalesOrder' => ['vars' => ['id' => 'sales_order'], 'body' => ['ship_to_address_id' => '@customer_address']],
        // Added 2026-09-21 with the bounded sub-list. Its subject is the same seeded order, which already
        // carries a line in **both** tenants (`freshState()`), so the baseline returns a non-empty page
        // and a supplied tenant id that moved the read would answer with B's line where A's was asked
        // for — an empty baseline would make the comparison vacuous.
        'ListSalesOrderLines' => ['vars' => ['id' => 'sales_order']],
        // Added 2026-09-21 with the document list. It takes no path variable: `freshState()` seeds an
        // order in **both** tenants, so the baseline page is non-empty and a supplied tenant id that
        // moved the read would return B's order where A's workspace was asked for.
        'ListSalesOrders' => [],
        // Added 2026-09-22 with the pick read (sales step 5 C3). Its subject must be a CONFIRMED order, because the
        // baseline has to SUCCEED -- a draft is not-found here by design (E-2), and a probe whose baseline is
        // refused compares a refusal with a refusal. `confirmed_sales_order` is seeded in both tenants.
        'GetPickList' => ['vars' => ['id' => 'confirmed_sales_order']],
        // Added 2026-09-22 with the fulfilment create (sales step 5 C4). The confirmed order's line, shipped from
        // this tenant's `@bin`, which `freshState()` stocks with the line's item -- per-tenant symbols, so a supplied
        // tenant id that moved the write would take B's stock or post against B's order, which the digest names.
        'CreateFulfilment' => [
            'vars' => ['id' => 'confirmed_sales_order'],
            'body' => ['lines' => [['sales_order_line_id' => '@confirmed_sales_order_line', 'quantity' => '1', 'storage_location_id' => '@bin']]],
        ],
        // Its subject is a fulfilment booked through the operation itself in each tenant, as `GetGoodsReceipt`'s is.
        'GetFulfilment' => ['vars' => ['id' => 'fulfilment'], 'setup' => 'fulfilment'],
        // Sales step 6 C1: the register, over a fulfilment booked through the operation in each tenant, so the
        // baseline page is non-empty and a supplied tenant id that moved the read would list B's shipment.
        'ListFulfilments' => ['setup' => 'fulfilment'],
        // Sales step 6 C3: the issue hangs off a shipment booked through the operation in each tenant; the body is
        // empty (the operation accepts none), so only a header or query can carry the probe.
        'IssueInvoice' => ['vars' => ['id' => 'fulfilment'], 'setup' => 'fulfilment'],
        // An invoice issued through the operation in each tenant, so a probe that moved the read would answer B's.
        'GetInvoice' => ['vars' => ['id' => 'invoice'], 'setup' => 'invoice'],
        // Sales step 6 C4: the register over an invoice issued in each tenant, so the baseline page is non-empty.
        'ListInvoices' => ['setup' => 'invoice'],
        // Sales step 7 C0: the derived lines of an invoice issued in each tenant; a probe that moved the read would list B's.
        'ListInvoiceLines' => ['vars' => ['id' => 'invoice'], 'setup' => 'invoice'],
        // Sales step 6 C5: the payment of an invoice issued in each tenant; the body is empty (`paid_at` defaults), so a
        // supplied tenant id that moved the write would pay B's invoice, which the digest names.
        'PayInvoice' => ['vars' => ['id' => 'invoice'], 'setup' => 'invoice', 'body' => []],
        // Sales step 6 C6: the reversal of a payment recorded in each tenant by ANOTHER user of that tenant (the
        // reverser may not be the recorder, A2.3), so the baseline succeeds and a probe that moved the write would
        // reverse B's payment, which the digest names.
        'ReverseInvoicePayment' => ['vars' => ['id' => 'invoice'], 'setup' => 'paid_invoice', 'body' => ['reason' => 'Probe']],
        // Sales step 6 C7: the void of an invoice issued in each tenant (never paid, so B′ does not apply to the rig's
        // marker-less principal); a supplied tenant id that moved the write would void B's invoice, which the digest names.
        'VoidInvoice' => ['vars' => ['id' => 'invoice'], 'setup' => 'invoice', 'body' => ['reason' => 'Probe']],
        // Sales step 7 R2: a return of a shipment booked in each tenant, naming this tenant's shipped line and bin; a
        // supplied tenant id that moved the write would return B's goods, which the digest names.
        'CreateSalesReturn' => [
            'vars' => ['id' => 'fulfilment'], 'setup' => 'fulfilment',
            'body' => ['reason' => 'other', 'lines' => [['fulfilment_line_id' => '@fulfilment_line', 'quantity' => '1', 'storage_location_id' => '@bin']]],
        ],
        // A return recorded through the operation in each tenant, so a probe that moved the read would answer B's.
        'GetSalesReturn' => ['vars' => ['id' => 'sales_return'], 'setup' => 'sales_return'],
        // Sales step 7 R3: the register over a return recorded in each tenant, so the baseline page is non-empty.
        'ListSalesReturns' => ['setup' => 'sales_return'],
        // Sales step 7 C1: a credit of a return recorded after its invoice, in each tenant, naming this tenant's return and
        // shipped line; a supplied tenant id that moved the write would credit B's invoice, which the digest names.
        'IssueCreditNote' => [
            'vars' => ['id' => 'invoice'], 'setup' => 'returned_invoice',
            'body' => ['reason' => 'goods_returned', 'sales_return_id' => '@sales_return', 'lines' => [['fulfilment_line_id' => '@fulfilment_line', 'quantity' => '1']]],
        ],
        // Sales step 7 C1b: an adjustment (no return) of the same invoice, naming this tenant's shipped line; a supplied
        // tenant id that moved the write would credit B's invoice, which the digest names.
        'IssueAdjustmentCreditNote' => [
            // G-29 / G-30 part 3 C2: NO return on the line — a pending return would have to be NAMED (step 11, an owner-marker
            // act this probe's principal does not hold), so the baseline is an invoice with nothing pending.
            'vars' => ['id' => 'invoice'], 'setup' => 'invoice',
            'body' => ['reason' => 'damaged_goods_kept', 'lines' => [['fulfilment_line_id' => '@fulfilment_line', 'quantity' => '1']]],
        ],
        // G-29 / G-30 part 3 C1: a `same` decision on a return recorded after an adjustment, in each tenant, naming this
        // tenant's return line and adjustment line; a supplied tenant id that moved the write would decide B's return.
        'CreateAdjustmentDecision' => [
            'vars' => ['id' => 'sales_return'], 'setup' => 'adjusted_return',
            'body' => ['kind' => 'same', 'sales_return_line_id' => '@sales_return_line', 'credit_note_line_id' => '@adjustment_line', 'quantity' => '1'],
        ],
        // A credit note issued through the operation in each tenant, so a probe that moved the read would answer B's.
        'GetCreditNote' => ['vars' => ['id' => 'credit_note'], 'setup' => 'credit_note'],
        // Sales step 7 C2: the register over a credit note issued in each tenant, so the baseline page is non-empty.
        'ListCreditNotes' => ['setup' => 'credit_note'],
    ];

    /** @var array<string, string> uuid => symbol, for the state the current request runs against */
    private array $symbols = [];

    /** @var array<string, array<string, string>> tenant letter => fixture name => id */
    private array $fixture = [];

    private ?string $passwordHash = null;

    private int $requestsSent = 0;

    /**
     * *Mutation, run 2026-09-14 (`var/cache/test` deleted before each run):*
     * `AuthenticationGate` builds the request's `Identity` with the
     * `X-Tenant-Id` header's value as its tenant when the header is present.
     * Red on the first operation, "ListItems probed via header X-Tenant-Id
     * answered with one of tenant B's rows (B.item)"; with the loop narrowed
     * to two writes, red at "CreateItem probed via header X-Tenant-Id changed
     * tenant B's rows". The rest of the suite, with this test excluded, stayed
     * green under it: OK (717 tests, 6327 assertions).
     *
     * *Second mutation, run the same day:* `ItemProvider` (`GetItem`) switches
     * the identity's tenant to a `tenant_id` query parameter. Red, "GetItem with
     * tenant B's id in query tenant_id changed the outcome, and the operation
     * does NOT refuse an undeclared name there" — 200 with `A.item` against a
     * 404. The rest of the suite stayed green under it too: OK (717 tests,
     * 6327 assertions). Both reverted; green.
     *
     * ⚠ **That second note went stale at `4e99cdd` (step F2, 2026-09-15)** and is
     * kept only as history (V1-R-7, V4 B4b). `UndeclaredQueryParameterRefusal`
     * now refuses `?tenant_id` on every operation before `read`, so the provider
     * is never reached. **Re-run 2026-09-16** (Rule 17, cache deleted before and
     * after): the same `ItemProvider` switch → `OK (1 test, 1296 assertions)` —
     * GREEN, the correct outcome, and no longer evidence. **The query-channel
     * mutation that still reaches this check** is to *declare* the name and read
     * it: `parameters: ['tenant_id' => new QueryParameter(key: 'tenant_id')]` on
     * `GetItem`, plus the same `ItemProvider` switch → RED, "GetItem with tenant
     * B's id in query tenant_id was refused, but not the way the operation
     * refuses any undeclared name. R-29." (404 `not-found` against the control's
     * 422 `undeclared_parameter`). Reverted; green. Since F2 the query and body
     * channels stop at refusal gates on every built operation; only the header
     * channel reaches operation logic undeclared.
     */
    #[Test]
    public function aClientSuppliedTenantIdInAHeaderTheQueryOrTheBodyChangesNoOperationsOutcome(): void
    {
        $operations = TransactionBoundaryTest::contractOperations();

        // The positive control, inside the test that depends on it (a sibling
        // would not protect a --filter run): the registry is not empty, and the
        // recipes and the registry name the same operations, both ways.
        self::assertNotSame([], $operations, 'The registry produced no operations, so this test would assert nothing.');

        $unrecipedOperations = array_values(array_diff(array_keys($operations), array_keys(self::RECIPES)));
        self::assertSame([], $unrecipedOperations,
            'These operations are in the registry and this test does not know how to drive them, so no probe would '
            .'ever reach them. Add a recipe (fixture ids for the path, a body the baseline succeeds with).');
        $staleRecipes = array_values(array_diff(array_keys(self::RECIPES), array_keys($operations)));
        self::assertSame([], $staleRecipes, 'These recipes name operations the registry no longer declares.');

        $driven = [];
        $probesPerChannel = ['header' => 0, 'query' => 0, 'body' => 0];

        foreach ($operations as $name => $operation) {
            $name = (string) $name;
            $baseline = $this->drive($name, $operation, null);

            self::assertGreaterThanOrEqual(200, $baseline['status'], $name.' baseline: '.$this->describe($baseline));
            self::assertLessThan(300, $baseline['status'],
                $name.'\'s baseline did not succeed, so a probe compared with it would be compared with nothing: '
                .$this->describe($baseline));

            foreach ($this->probesFor($operation) as [$channel, $where]) {
                $probed = $this->drive($name, $operation, [$channel, $where, self::PROBED_NAME]);
                ++$probesPerChannel[$channel];

                if ($probed === $baseline) {
                    continue;
                }

                $label = $name.' with tenant B\'s id in '.$channel.' '.$where;

                self::assertNotSame('header', $channel,
                    $label.' changed the outcome. R-29: the tenant enters only as a signed claim.'
                    ."\nBaseline: ".$this->describe($baseline)."\nProbed:   ".$this->describe($probed));

                $control = $this->drive($name, $operation, [$channel, $where === self::PROBED_NAME ? self::CONTROL_NAME : $where, self::CONTROL_NAME]);

                self::assertNotSame($baseline, $control,
                    $label.' changed the outcome, and the operation does NOT refuse an undeclared name there, so the '
                    .'change is the tenant id being read. R-29.'
                    ."\nBaseline: ".$this->describe($baseline)."\nProbed:   ".$this->describe($probed));
                self::assertGreaterThanOrEqual(400, $probed['status'], $label.': '.$this->describe($probed));
                self::assertLessThan(500, $probed['status'], $label.': '.$this->describe($probed));
                self::assertSame(
                    $this->describe($control),
                    str_replace(self::PROBED_NAME, self::CONTROL_NAME, $this->describe($probed)),
                    $label.' was refused, but not the way the operation refuses any undeclared name. R-29.',
                );
            }

            $driven[] = $name;
        }

        sort($driven);
        $declared = array_keys($operations);
        sort($declared);

        self::assertSame($declared, $driven, 'Not every operation in the registry was driven.');
        self::assertGreaterThan(0, $probesPerChannel['header']);
        self::assertGreaterThan(0, $probesPerChannel['query']);
        self::assertGreaterThan(0, $probesPerChannel['body'], 'No write operation was probed through its body.');
        self::assertSame(\count($operations) * \count(self::HEADER_PROBES), $probesPerChannel['header']);
        self::assertSame(\count($operations), $probesPerChannel['query']);
        self::assertGreaterThanOrEqual(\count($operations) * 5, $this->requestsSent - $probesPerChannel['body'],
            'Fewer requests went out than one baseline, three header probes and one query probe per operation.');
    }

    /**
     * @return list<array{0: string, 1: string}>
     */
    private function probesFor(HttpOperation $operation): array
    {
        $probes = [];

        foreach (self::HEADER_PROBES as $header) {
            $probes[] = ['header', $header];
        }

        $probes[] = ['query', self::PROBED_NAME];

        if ('GET' !== $operation->getMethod()) {
            $probes[] = ['body', self::PROBED_NAME];
        }

        return $probes;
    }

    /**
     * One request against fresh state.
     *
     * @param array{0: string, 1: string, 2: string}|null $probe channel, where (header name or field name), the name placed
     *
     * @return array{status: int, location: string|null, body: mixed, tenant_a_rows: array<string, int>}
     */
    private function drive(string $name, HttpOperation $operation, ?array $probe): array
    {
        $recipe = self::RECIPES[$name];

        $this->freshState();

        if ('receipt' === ($recipe['setup'] ?? null)) {
            $this->seedReceipt('A');
            $this->seedReceipt('B');
        }
        if ('fulfilment' === ($recipe['setup'] ?? null)) {
            $this->seedFulfilment('A');
            $this->seedFulfilment('B');
        }
        if ('sales_return' === ($recipe['setup'] ?? null)) {
            $this->seedSalesReturn('A');
            $this->seedSalesReturn('B');
        }
        if ('invoice' === ($recipe['setup'] ?? null)) {
            $this->seedInvoice('A');
            $this->seedInvoice('B');
        }
        if ('adjusted_return' === ($recipe['setup'] ?? null)) {
            $this->seedAdjustedReturn('A');
            $this->seedAdjustedReturn('B');
        }
        if ('returned_invoice' === ($recipe['setup'] ?? null)) {
            $this->seedReturnedInvoice('A');
            $this->seedReturnedInvoice('B');
        }
        if ('credit_note' === ($recipe['setup'] ?? null)) {
            $this->seedCreditNote('A');
            $this->seedCreditNote('B');
        }
        if ('confirmable_proposal' === ($recipe['setup'] ?? null)) {
            $this->seedConfirmableProposal(self::TENANT_A);
            $this->seedConfirmableProposal(self::TENANT_B);
        }
        if ('withdrawable_proposal' === ($recipe['setup'] ?? null)) {
            $this->seedWithdrawableProposal(self::TENANT_A, 'A');
            $this->seedWithdrawableProposal(self::TENANT_B, 'B');
        }
        if ('second_bin' === ($recipe['setup'] ?? null)) {
            foreach (['A' => self::TENANT_A, 'B' => self::TENANT_B] as $letter => $tenantId) {
                $this->insertAsOwnerForTenant($tenantId,
                    "INSERT INTO storage_location (id, tenant_id, warehouse_id, code) VALUES (?::uuid, ?::uuid, ?::uuid, 'A-02')",
                    [$this->remember($letter, 'second_bin', Uuid::v4()->toRfc4122()), $tenantId, $this->id($letter, 'warehouse')]);
            }
        }
        if ('paid_invoice' === ($recipe['setup'] ?? null)) {
            $this->seedPaidInvoice('A', self::TENANT_A);
            $this->seedPaidInvoice('B', self::TENANT_B);
        }

        $before = $this->tenantBDigest();

        $path = (string) $operation->getUriTemplate();
        $path = str_starts_with($path, self::BASE) ? substr($path, \strlen(self::BASE)) : $path;
        foreach ($recipe['vars'] ?? [] as $variable => $fixtureName) {
            $path = str_replace('{'.$variable.'}', $this->id('A', $fixtureName), $path);
        }
        self::assertStringNotContainsString('{', $path, $name.'\'s recipe leaves a path variable unfilled.');
        if ([] !== ($recipe['query'] ?? [])) {
            $path .= '?'.http_build_query(array_map(fn (string $fixtureName): string => $this->id('A', $fixtureName), $recipe['query']));
        }

        $body = \array_key_exists('body', $recipe) ? $this->resolve('A', $recipe['body']) : null;
        $headers = [];

        if (true === ($operation->getExtraProperties()['idempotency_key_required'] ?? null)) {
            $headers['Idempotency-Key'] = Uuid::v4()->toRfc4122();
        }

        if (false === ($recipe['authenticated'] ?? true)) {
            // Pre-authentication: the request carries no bearer at all.
            $headers['Authorization'] = '';
        }

        if (null !== $probe) {
            [$channel, $where, $placed] = $probe;

            match ($channel) {
                'header' => $headers[$where] = self::TENANT_B,
                'query' => $path .= (str_contains($path, '?') ? '&' : '?').http_build_query([$placed => self::TENANT_B]),
                'body' => $body = [...($body ?? []), $placed => self::TENANT_B],
            };
        }

        $this->send((string) $operation->getMethod(), $path, $body, $headers);
        ++$this->requestsSent;

        $response = $this->client()->getResponse();
        $content = (string) $response->getContent();
        $decoded = '' === $content ? null : json_decode($content, true);

        $outcome = [
            'status' => $response->getStatusCode(),
            'location' => $this->normalise($response->headers->get('Location')),
            'body' => $this->normalise(null === $decoded && '' !== $content ? $content : $decoded),
            'tenant_a_rows' => $this->tenantARowCounts(),
        ];

        $label = $name.(null === $probe ? ' (baseline)' : ' probed via '.$probe[0].' '.$probe[1]);

        self::assertSame($before, $this->tenantBDigest(),
            $label.' changed tenant B\'s rows. R-29: a request authenticated as A wrote into B.');

        $rendered = json_encode($outcome, \JSON_THROW_ON_ERROR);
        self::assertSame(0, preg_match('/\bB\.(?!tenant\b)[a-z_]+\b/', $rendered, $leak),
            $label.' answered with one of tenant B\'s rows ('.($leak[0] ?? '').'). R-29. '.$this->describe($outcome));

        return $outcome;
    }

    private function freshState(): void
    {
        $this->truncateTenantTables();
        // Not tenant-scoped, so the manifest truncate does not reach them, and
        // the sign-in budget is per IP: `AuthTestCase::setUp()` does the same.
        $this->owner()->executeStatement('TRUNCATE auth_attempt, auth_ip_budget, auth_lockout');
        $this->seedTenantRegistry();

        $this->symbols = [self::TENANT_A => 'A.tenant', self::TENANT_B => 'B.tenant'];
        $this->fixture = [];

        // B first, so that the rig's bearer and identity holder end on A.
        foreach (['B' => self::TENANT_B, 'A' => self::TENANT_A] as $letter => $tenantId) {
            $this->seedMirror($letter, $tenantId);
        }
    }

    /** The same shape for either tenant, so a request served from B has somewhere to find a row. */
    private function seedMirror(string $letter, string $tenantId): void
    {
        $this->actAs($tenantId);
        $actor = (string) $this->actorId;
        $this->remember($letter, 'actor', $actor);
        $this->fixture[$letter]['actor_email'] = 'actor-'.$actor.'@example.test';
        $this->fixture[$letter]['token'] = $this->tokenFor($tenantId, $actor);

        // A real hash, so `IssueToken` and `ChangePassword` can succeed; and one
        // role name, because `seedPrincipal()` puts part of a random id in it.
        $this->passwordHash ??= $this->kdf()->hash(self::PASSWORD);
        $this->insertAsOwnerForTenant($tenantId, 'UPDATE app_user SET password_hash = ? WHERE id = ?::uuid', [$this->passwordHash, $actor]);
        $this->insertAsOwnerForTenant($tenantId, "UPDATE role SET name = 'Fixture Role'");
        foreach ($this->fetchAsOwnerForTenant($tenantId, 'SELECT id::text AS id FROM role') as $row) {
            $this->remember($letter, 'role', (string) $row['id']);
        }

        // Roles slice 2, C2: a second user holding one non-marker role, so `RevokeRole`'s baseline revokes a real grant
        // that is not the actor's own (E-15a). Seeded AFTER the rename above: `role_tenant_name_live` would refuse two
        // roles both renamed 'Fixture Role'.
        $subject = $this->remember($letter, 'revoke_subject', Uuid::v4()->toRfc4122());
        $this->insertAsOwnerForTenant($tenantId,
            "INSERT INTO app_user (id, tenant_id, email, password_hash, display_name) VALUES (?::uuid, ?::uuid, ?, 'not-a-hash', 'Revoke Subject')",
            [$subject, $tenantId, 'subject-'.$subject.'@example.test']);
        $revokeRole = $this->remember($letter, 'revoke_role', Uuid::v4()->toRfc4122());
        $this->insertAsOwnerForTenant($tenantId, "INSERT INTO role (id, tenant_id, name) VALUES (?::uuid, ?::uuid, 'Revokable Role')", [$revokeRole, $tenantId]);
        $this->insertAsOwnerForTenant($tenantId,
            "INSERT INTO role_permission (id, tenant_id, role_id, permission_code) VALUES (gen_random_uuid(), ?::uuid, ?::uuid, 'role.view')",
            [$tenantId, $revokeRole]);
        $this->insertAsOwnerForTenant($tenantId,
            'INSERT INTO user_role (id, tenant_id, user_id, role_id) VALUES (gen_random_uuid(), ?::uuid, ?::uuid, ?::uuid)',
            [$tenantId, $subject, $revokeRole]);
        // Roles slice 4, C3: `DeleteRole`'s role — composed, unheld, no proposal, so the baseline deletes it.
        $this->insertAsOwnerForTenant($tenantId, "INSERT INTO role (id, tenant_id, name) VALUES (?::uuid, ?::uuid, 'Deletable Role')",
            [$this->remember($letter, 'delete_role', Uuid::v4()->toRfc4122()), $tenantId]);
        // Roles slice 2, C3: `AssignRole`'s subject — active, holding nothing.
        $assignSubject = $this->remember($letter, 'assign_subject', Uuid::v4()->toRfc4122());
        $this->insertAsOwnerForTenant($tenantId,
            "INSERT INTO app_user (id, tenant_id, email, password_hash, display_name) VALUES (?::uuid, ?::uuid, ?, 'not-a-hash', 'Assign Subject')",
            [$assignSubject, $tenantId, 'assign-'.$assignSubject.'@example.test']);

        // Roles slice 3, C1: a composed role carrying an X(admin) code the actor holds (`invoice.pay`) plus `role.view`, so a
        // non-owner's grant of it needs confirmation and is proposed; a subject holding nothing to propose it for; and one
        // PENDING proposal of it for `assign_subject` (seeded by owner INSERT), so Get and List read a real row.
        $proposalRole = $this->remember($letter, 'proposal_role', Uuid::v4()->toRfc4122());
        $this->insertAsOwnerForTenant($tenantId, "INSERT INTO role (id, tenant_id, name) VALUES (?::uuid, ?::uuid, 'Proposable Role')", [$proposalRole, $tenantId]);
        foreach (['invoice.pay', 'role.view'] as $code) {
            $this->insertAsOwnerForTenant($tenantId,
                'INSERT INTO role_permission (id, tenant_id, role_id, permission_code) VALUES (gen_random_uuid(), ?::uuid, ?::uuid, ?)',
                [$tenantId, $proposalRole, $code]);
        }
        $proposalSubject = $this->remember($letter, 'proposal_subject', Uuid::v4()->toRfc4122());
        $this->insertAsOwnerForTenant($tenantId,
            "INSERT INTO app_user (id, tenant_id, email, password_hash, display_name) VALUES (?::uuid, ?::uuid, ?, 'not-a-hash', 'Proposal Subject')",
            [$proposalSubject, $tenantId, 'proposal-'.$proposalSubject.'@example.test']);
        $this->insertAsOwnerForTenant($tenantId, <<<'SQL'
            INSERT INTO role_grant_proposal (id, tenant_id, user_id, role_id, permission_codes, proposed_by_user_id, proposed_at, expires_at)
            SELECT ?::uuid, ?::uuid, ?::uuid, ?::uuid, '["invoice.pay", "role.view"]'::jsonb, ?::uuid, c.t, c.t + interval '336 hours'
              FROM (SELECT clock_timestamp() AS t) c
            SQL, [$this->remember($letter, 'proposal', Uuid::v4()->toRfc4122()), $tenantId, $assignSubject, $proposalRole, $subject]);

        $supplier = $this->remember($letter, 'supplier', $this->seedSupplier($tenantId, 'Acme'));
        $customer = $this->remember($letter, 'customer', $this->seedCustomer($tenantId, 'Acme Retail'));
        // Customers slice 1 C1: `seedCustomer()`'s code is random per seeding (an md5 of name and id), and from C1 the code is
        // on the wire under `GetCustomer`/`ListCustomers`, so the baseline and the probe would differ by it alone. Pinned to
        // one letter-bearing value per tenant, as role names are normalised to 'Fixture Role'.
        $this->insertAsOwnerForTenant($tenantId, "UPDATE customer SET code = 'C-ACME' WHERE id = ?::uuid", [$customer]);
        $this->remember($letter, 'customer_address', $this->seedCustomerAddress($tenantId, $customer));
        $this->remember($letter, 'customer_contact', $this->seedCustomerContact($tenantId, $customer));

        $warehouse = $this->remember($letter, 'warehouse', Uuid::v4()->toRfc4122());
        $this->insertAsOwnerForTenant($tenantId,
            "INSERT INTO warehouse (id, tenant_id, code, name, country_code) VALUES (?::uuid, ?::uuid, 'WH1', 'Main', 'DE')",
            [$warehouse, $tenantId]);
        $this->insertAsOwnerForTenant($tenantId,
            "INSERT INTO storage_location (id, tenant_id, warehouse_id, code) VALUES (?::uuid, ?::uuid, ?::uuid, 'A-01')",
            [$this->remember($letter, 'bin', Uuid::v4()->toRfc4122()), $tenantId, $warehouse]);
        // Warehouse/location delete-restore C2: an archived location of the same warehouse, so `RestoreStorageLocation`'s
        // baseline drive succeeds.
        $this->insertAsOwnerForTenant($tenantId,
            "INSERT INTO storage_location (id, tenant_id, warehouse_id, code, deleted_at) VALUES (?::uuid, ?::uuid, ?::uuid, 'Z-ARCHIVED', now())",
            [$this->remember($letter, 'archived_bin', Uuid::v4()->toRfc4122()), $tenantId, $warehouse]);
        // C3: a live location no stock names, so `DeleteStorageLocation`'s baseline drive succeeds.
        $this->insertAsOwnerForTenant($tenantId,
            "INSERT INTO storage_location (id, tenant_id, warehouse_id, code) VALUES (?::uuid, ?::uuid, ?::uuid, 'Y-IDLE')",
            [$this->remember($letter, 'idle_bin', Uuid::v4()->toRfc4122()), $tenantId, $warehouse]);
        // Warehouse/location delete-restore C4: a live warehouse no order and no stock names, so `DeleteWarehouse`'s baseline
        // drive succeeds. Its code pinned (`ListWarehouses` serves it).
        $this->insertAsOwnerForTenant($tenantId,
            "INSERT INTO warehouse (id, tenant_id, code, name, country_code) VALUES (?::uuid, ?::uuid, 'W-IDLE', 'Idle', 'DE')",
            [$this->remember($letter, 'idle_warehouse', Uuid::v4()->toRfc4122()), $tenantId]);

        $item = $this->remember($letter, 'item', $this->seedItem($tenantId, 'SKU-1', $supplier));
        $catalogued = $this->remember($letter, 'catalogued_item', $this->seedItem($tenantId, 'SKU-2', $supplier));
        $this->insertAsOwnerForTenant($tenantId, <<<'SQL'
            INSERT INTO supplier_product
                (id, tenant_id, supplier_id, inventory_item_id, supplier_sku, lead_time_days, unit_cost, currency_code)
            VALUES (?::uuid, ?::uuid, ?::uuid, ?::uuid, 'SP-2', 7, 1.0000, 'EUR')
            SQL, [$this->remember($letter, 'supplier_product', Uuid::v4()->toRfc4122()), $tenantId, $supplier, $catalogued]);
        $this->remember($letter, 'loose_item', $this->seedItem($tenantId, 'SKU-LOOSE'));
        $archived = $this->remember($letter, 'archived_item', $this->seedItem($tenantId, 'SKU-ARCHIVED'));
        $this->insertAsOwnerForTenant($tenantId, 'UPDATE inventory_item SET deleted_at = now() WHERE id = ?::uuid', [$archived]);
        // C5 C2a: an archived supplier, so `RestoreSupplier`'s baseline drive succeeds.
        $archivedSupplier = $this->remember($letter, 'archived_supplier', $this->seedSupplier($tenantId, 'Archived Acme'));
        $this->insertAsOwnerForTenant($tenantId, 'UPDATE supplier SET deleted_at = now() WHERE id = ?::uuid', [$archivedSupplier]);
        // Warehouse/location delete-restore C1: an archived warehouse, so `RestoreWarehouse`'s baseline drive succeeds. Its
        // code pinned (`seedWarehouse()`'s is random per seeding and the restore's body serves it).
        $archivedWarehouse = $this->remember($letter, 'archived_warehouse', $this->seedWarehouse($tenantId, 'Archived Site'));
        $this->insertAsOwnerForTenant($tenantId, "UPDATE warehouse SET code = 'W-ARCHIVED', deleted_at = now() WHERE id = ?::uuid", [$archivedWarehouse]);
        // C5 C4a: an archived customer, so `RestoreCustomer`'s baseline drive succeeds.
        $archivedCustomer = $this->remember($letter, 'archived_customer', $this->seedCustomer($tenantId, 'Archived Retail'));
        // The code pinned as the live customer's is above (`seedCustomer()`'s is random per seeding and is on the wire).
        $this->insertAsOwnerForTenant($tenantId, "UPDATE customer SET code = 'C-ARCHIVED', deleted_at = now() WHERE id = ?::uuid", [$archivedCustomer]);
        // C5 C4b: a live customer no sales document names, so `DeleteCustomer`'s baseline drive succeeds.
        $idleCustomer = $this->remember($letter, 'idle_customer', $this->seedCustomer($tenantId, 'Idle Retail'));
        // Its code pinned, as the archived one's (`seedCustomer()`'s is random per seeding and `ListCustomers` serves it).
        $this->insertAsOwnerForTenant($tenantId, "UPDATE customer SET code = 'C-IDLE' WHERE id = ?::uuid", [$idleCustomer]);
        // C5 C2b: a live supplier no order names, so `DeleteSupplier`'s baseline drive succeeds.
        $this->remember($letter, 'idle_supplier', $this->seedSupplier($tenantId, 'Idle Acme'));

        $draft = $this->remember($letter, 'draft', $this->seedOrder($tenantId, 'PO-000001', $supplier, $warehouse));
        $this->remember($letter, 'draft_line', $this->seedLine($tenantId, $draft, $item));
        // Order-restores C0: a second line of the draft, ARCHIVED, so `RestorePurchaseOrderLine`'s baseline succeeds.
        $archivedDraftLine = $this->remember($letter, 'archived_draft_line', $this->seedLine($tenantId, $draft, $item, 2));
        $this->insertAsOwnerForTenant($tenantId,
            'UPDATE purchase_order_line SET deleted_at = now() WHERE id = ?::uuid', [$archivedDraftLine]);

        $placed = $this->remember($letter, 'placed',
            $this->seedOrder($tenantId, 'PO-000002', $supplier, $warehouse, status: PurchaseOrder::STATUS_ORDERED));
        $this->remember($letter, 'placed_line', $this->seedLine($tenantId, $placed, $item));

        // The sales order reuses this tenant's own customer and warehouse rather than seeding fresh ones,
        // so the response's `customer` expansion is a symbol the digest can name — a cross-tenant answer
        // shows up as `B.customer` and not as an anonymous uuid.
        $salesOrder = $this->remember($letter, 'sales_order', $this->seedSalesOrder($tenantId, 'SO-000001', $customer, $warehouse));
        // One line, so `ConfirmSalesOrder`'s baseline succeeds. Without it the line guard refuses every
        // drive with the same 409 and the probe compares a refusal with a refusal.
        $this->remember($letter, 'sales_order_line', $this->seedSalesOrderLine($tenantId, $salesOrder, $item));
        // A second line of the same order, ARCHIVED, so `RestoreSalesOrderLine`'s baseline drive succeeds.
        // A probe whose baseline is refused compares a refusal with a refusal and can see nothing.
        $archivedLine = $this->remember($letter, 'archived_sales_order_line',
            $this->seedSalesOrderLine($tenantId, $salesOrder, $item, lineNumber: 2));
        $this->insertAsOwnerForTenant($tenantId,
            'UPDATE sales_order_line SET deleted_at = now() WHERE id = ?::uuid', [$archivedLine]);

        // A CONFIRMED order with a line, so `GetPickList`'s baseline answers 200 (a draft is not-found there).
        $confirmed = $this->remember($letter, 'confirmed_sales_order', $this->seedSalesOrder(
            $tenantId, 'SO-000002', $customer, $warehouse, SalesOrder::STATUS_CONFIRMED, '2026-09-20T10:00:00Z'));
        $this->remember($letter, 'confirmed_sales_order_line', $this->seedSalesOrderLine($tenantId, $confirmed, $item));
        // Stock of that item in this tenant's `@bin` (same warehouse), so `CreateFulfilment`'s baseline succeeds.
        $this->insertAsOwnerForTenant($tenantId,
            'INSERT INTO stock_level (id, tenant_id, inventory_item_id, storage_location_id, qty_on_hand) VALUES (?::uuid, ?::uuid, ?::uuid, ?::uuid, 50)',
            [Uuid::v4()->toRfc4122(), $tenantId, $item, $this->id($letter, 'bin')]);
        // Stock reads C2 (T-2′): a movement of that stock, so `GetStockMovement` has a subject and `ListStockMovements` a row.
        $this->insertAsOwnerForTenant($tenantId,
            "INSERT INTO stock_movement (id, tenant_id, inventory_item_id, storage_location_id, quantity, reason) VALUES (?::uuid, ?::uuid, ?::uuid, ?::uuid, 50, 'receipt')",
            [$this->remember($letter, 'movement', Uuid::v4()->toRfc4122()), $tenantId, $item, $this->id($letter, 'bin')]);
        // Audit reads C1: one trail row per tenant, by the tenant's own actor, so `ListAuditLogEntries`' baseline has a row and
        // B's (another id, another tenant) must never appear in A's trail.
        $this->insertAsOwnerForTenant($tenantId,
            "INSERT INTO audit_log (id, tenant_id, event_type, actor_user_id, actor_label) VALUES (?::uuid, ?::uuid, 'purchase_order.placed', ?::uuid, 'Fixture Actor')",
            [$this->remember($letter, 'audit_entry', Uuid::v4()->toRfc4122()), $tenantId, $actor]);

        foreach ($this->fetchAsOwnerForTenant($tenantId, 'SELECT id::text AS id FROM tenant_settings') as $row) {
            $this->remember($letter, 'tenant_settings', (string) $row['id']);
        }
    }

    /** A receipt booked through the operation itself, in the tenant's own token, so `GetGoodsReceipt` has a subject. */
    private function seedReceipt(string $letter): void
    {
        $this->send('POST', '/purchase-orders/'.$this->id($letter, 'placed').'/goods-receipts', [
            'lines' => [['purchase_order_line_id' => $this->id($letter, 'placed_line'), 'quantity' => '2', 'storage_location_id' => $this->id($letter, 'bin')]],
        ], [
            'Idempotency-Key' => Uuid::v4()->toRfc4122(),
            'Authorization' => 'Bearer '.$this->id($letter, 'token'),
        ]);

        self::assertSame(201, $this->httpStatus(), 'The receipt fixture for tenant '.$letter.' was not booked: '
            .substr((string) $this->client()->getResponse()->getContent(), 0, 400));

        $this->remember($letter, 'receipt', (string) $this->json()['id']);
    }

    /** A fulfilment booked through the operation itself, in the tenant's own token, so `GetFulfilment` has a subject. */
    private function seedFulfilment(string $letter): void
    {
        $this->send('POST', '/sales-orders/'.$this->id($letter, 'confirmed_sales_order').'/fulfilments', [
            'lines' => [['sales_order_line_id' => $this->id($letter, 'confirmed_sales_order_line'), 'quantity' => '2', 'storage_location_id' => $this->id($letter, 'bin')]],
        ], [
            'Idempotency-Key' => Uuid::v4()->toRfc4122(),
            'Authorization' => 'Bearer '.$this->id($letter, 'token'),
        ]);

        self::assertSame(201, $this->httpStatus(), 'The fulfilment fixture for tenant '.$letter.' was not booked: '
            .substr((string) $this->client()->getResponse()->getContent(), 0, 400));

        $this->remember($letter, 'fulfilment', (string) $this->json()['id']);
        // Sales step 7 R2: the shipped line a return names.
        $this->remember($letter, 'fulfilment_line', (string) $this->json()['lines'][0]['id']);
    }

    /** Sales step 7 R2: a return recorded through the operation, on a shipment booked through the operation. */
    private function seedSalesReturn(string $letter): void
    {
        $this->seedFulfilment($letter);
        $this->send('POST', '/fulfilments/'.$this->id($letter, 'fulfilment').'/sales-returns', [
            'reason' => 'other',
            'lines' => [['fulfilment_line_id' => $this->id($letter, 'fulfilment_line'), 'quantity' => '1', 'storage_location_id' => $this->id($letter, 'bin')]],
        ], [
            'Idempotency-Key' => Uuid::v4()->toRfc4122(),
            'Authorization' => 'Bearer '.$this->id($letter, 'token'),
        ]);

        self::assertSame(201, $this->httpStatus(), 'The return fixture for tenant '.$letter.' was not recorded: '
            .substr((string) $this->client()->getResponse()->getContent(), 0, 400));

        $this->remember($letter, 'sales_return', (string) $this->json()['id']);
    }

    private function seedInvoice(string $letter): void
    {
        $this->seedFulfilment($letter);
        $this->send('POST', '/fulfilments/'.$this->id($letter, 'fulfilment').'/invoices', null, [
            'Idempotency-Key' => Uuid::v4()->toRfc4122(),
            'Authorization' => 'Bearer '.$this->id($letter, 'token'),
        ]);

        self::assertSame(201, $this->httpStatus(), 'The invoice fixture for tenant '.$letter.' was not issued: '
            .substr((string) $this->client()->getResponse()->getContent(), 0, 400));

        $this->remember($letter, 'invoice', (string) $this->json()['id']);
    }

    /** Sales step 7 C1: an invoice issued through the operation, then a return recorded after it (so creditable). */
    private function seedReturnedInvoice(string $letter): void
    {
        $this->seedInvoice($letter);
        $this->send('POST', '/fulfilments/'.$this->id($letter, 'fulfilment').'/sales-returns', [
            'reason' => 'other',
            'lines' => [['fulfilment_line_id' => $this->id($letter, 'fulfilment_line'), 'quantity' => '1', 'storage_location_id' => $this->id($letter, 'bin')]],
        ], [
            'Idempotency-Key' => Uuid::v4()->toRfc4122(),
            'Authorization' => 'Bearer '.$this->id($letter, 'token'),
        ]);
        self::assertSame(201, $this->httpStatus(), 'The return fixture for tenant '.$letter.' was not recorded: '
            .substr((string) $this->client()->getResponse()->getContent(), 0, 400));
        $this->remember($letter, 'sales_return', (string) $this->json()['id']);
    }

    /**
     * G-29 / G-30 part 3 C1/C2: an invoice whose shipped line carries an adjustment of 1 (no return), then a return of 1
     * recorded AFTER it — the order C2 allows without naming anything (a return recorded first would be pending, and the
     * adjustment would have to name it).
     */
    private function seedAdjustedReturn(string $letter): void
    {
        $this->seedInvoice($letter);
        $this->send('POST', '/invoices/'.$this->id($letter, 'invoice').'/credit-note-adjustments', [
            'reason' => 'damaged_goods_kept',
            'lines' => [['fulfilment_line_id' => $this->id($letter, 'fulfilment_line'), 'quantity' => '1']],
        ], [
            'Idempotency-Key' => Uuid::v4()->toRfc4122(),
            'Authorization' => 'Bearer '.$this->id($letter, 'token'),
        ]);
        self::assertSame(201, $this->httpStatus(), 'The adjustment fixture for tenant '.$letter.' was not issued: '
            .substr((string) $this->client()->getResponse()->getContent(), 0, 400));
        $this->remember($letter, 'adjustment_line', (string) $this->json()['lines'][0]['id']);
        $this->send('POST', '/fulfilments/'.$this->id($letter, 'fulfilment').'/sales-returns', [
            'reason' => 'other',
            'lines' => [['fulfilment_line_id' => $this->id($letter, 'fulfilment_line'), 'quantity' => '1', 'storage_location_id' => $this->id($letter, 'bin')]],
        ], [
            'Idempotency-Key' => Uuid::v4()->toRfc4122(),
            'Authorization' => 'Bearer '.$this->id($letter, 'token'),
        ]);
        self::assertSame(201, $this->httpStatus(), 'The return fixture for tenant '.$letter.' was not recorded: '
            .substr((string) $this->client()->getResponse()->getContent(), 0, 400));
        $this->remember($letter, 'sales_return', (string) $this->json()['id']);
        $this->remember($letter, 'sales_return_line', (string) $this->json()['lines'][0]['id']);
    }

    /** Sales step 7 C1: a credit note issued through the operation on that return. */
    private function seedCreditNote(string $letter): void
    {
        $this->seedReturnedInvoice($letter);
        $this->send('POST', '/invoices/'.$this->id($letter, 'invoice').'/credit-notes', [
            'reason' => 'goods_returned', 'sales_return_id' => $this->id($letter, 'sales_return'),
            'lines' => [['fulfilment_line_id' => $this->id($letter, 'fulfilment_line'), 'quantity' => '1']],
        ], [
            'Idempotency-Key' => Uuid::v4()->toRfc4122(),
            'Authorization' => 'Bearer '.$this->id($letter, 'token'),
        ]);
        self::assertSame(201, $this->httpStatus(), 'The credit-note fixture for tenant '.$letter.' was not issued: '
            .substr((string) $this->client()->getResponse()->getContent(), 0, 400));
        $this->remember($letter, 'credit_note', (string) $this->json()['id']);
    }

    /**
     * An invoice issued through the operation, then marked paid AS THE OWNER by a second user of the same tenant, so
     * the tenant's own token may reverse it (C6: the reverser may not be the recorder).
     */
    /**
     * Roles slice 3 C2: the seeded proposal becomes confirmable — its proposer (`revoke_subject`) gains `role.assign` (C9)
     * and the actor's fixture role gains the owner marker (Q-6 (c′)). In both tenants, so the mirror stays a mirror.
     */
    private function seedConfirmableProposal(string $tenantId): void
    {
        $this->insertAsOwnerForTenant($tenantId, <<<'SQL'
            INSERT INTO role_permission (id, tenant_id, role_id, permission_code)
            SELECT gen_random_uuid(), r.tenant_id, r.id, c.code
              FROM role r, (VALUES ('Revokable Role', 'role.assign'), ('Fixture Role', 'tenant_settings.edit')) c(name, code)
             WHERE r.tenant_id = ?::uuid AND r.name = c.name
            SQL, [$tenantId]);
    }

    /** Roles slice 3 C3: the seeded proposal is the actor's own, so the actor may withdraw it. In both tenants. */
    private function seedWithdrawableProposal(string $tenantId, string $letter): void
    {
        $this->insertAsOwnerForTenant($tenantId, 'UPDATE role_grant_proposal SET proposed_by_user_id = ?::uuid WHERE id = ?::uuid',
            [$this->id($letter, 'actor'), $this->id($letter, 'proposal')]);
    }

    private function seedPaidInvoice(string $letter, string $tenantId): void
    {
        $this->seedInvoice($letter);
        $recorder = Uuid::v4()->toRfc4122();
        $this->insertAsOwnerForTenant($tenantId, <<<'SQL'
            INSERT INTO app_user (id, tenant_id, email, password_hash, display_name)
            VALUES (?::uuid, ?::uuid, ?, 'not-a-hash', 'Recorder')
            SQL, [$recorder, $tenantId, 'recorder-'.$recorder.'@example.test']);
        $this->insertAsOwnerForTenant($tenantId,
            "UPDATE invoice SET status = 'paid', paid_at = now(), paid_by_user_id = ?::uuid WHERE id = ?::uuid",
            [$recorder, $this->id($letter, 'invoice')]);
    }

    private function remember(string $letter, string $name, string $id): string
    {
        $this->fixture[$letter][$name] = $id;
        $this->symbols[strtolower($id)] = $letter.'.'.$name;

        return $id;
    }

    private function id(string $letter, string $name): string
    {
        return $this->fixture[$letter][$name] ?? throw new \LogicException('No fixture named '.$letter.'.'.$name);
    }

    private function resolve(string $letter, mixed $value): mixed
    {
        if (\is_array($value)) {
            return array_map(fn (mixed $v): mixed => $this->resolve($letter, $v), $value);
        }

        return \is_string($value) && str_starts_with($value, '@') ? $this->id($letter, substr($value, 1)) : $value;
    }

    /** Fixture ids to symbols, other ids and instants masked, a JWT reduced to the two claims that name a principal. */
    private function normalise(mixed $value): mixed
    {
        if (\is_array($value)) {
            return array_map(fn (mixed $v): mixed => $this->normalise($v), $value);
        }

        if (!\is_string($value)) {
            return $value;
        }

        if (1 === preg_match('/^[A-Za-z0-9_-]+\.([A-Za-z0-9_-]+)\.[A-Za-z0-9_-]+$/', $value, $jwt)) {
            $claims = json_decode((string) base64_decode(strtr($jwt[1], '-_', '+/'), true), true);

            if (\is_array($claims)) {
                return '{jwt tenant_id='.$this->normalise((string) ($claims['tenant_id'] ?? '')).' sub='.$this->normalise((string) ($claims['sub'] ?? '')).'}';
            }
        }

        $value = (string) preg_replace_callback(
            '/[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}/i',
            fn (array $m): string => $this->symbols[strtolower($m[0])] ?? '{uuid}',
            $value,
        );

        return (string) preg_replace('/\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(\.\d+)?(Z|[+-]\d{2}:\d{2})/', '{instant}', $value);
    }

    /** @return array<string, string> table => row count and content digest, read as tenant B */
    private function tenantBDigest(): array
    {
        $digest = [];
        foreach ($this->fetchAsOwnerForTenant(self::TENANT_B, $this->perTableSql(true)) as $row) {
            $digest[(string) $row['t']] = $row['n'].':'.$row['d'];
        }

        return $digest;
    }

    /** @return array<string, int> */
    private function tenantARowCounts(): array
    {
        $counts = [];
        foreach ($this->fetchAsOwnerForTenant(self::TENANT_A, $this->perTableSql(false)) as $row) {
            $counts[(string) $row['t']] = (int) $row['n'];
        }

        return $counts;
    }

    private function perTableSql(bool $withDigest): string
    {
        $tables = SchemaManifest::tenantScopedTables();
        self::assertNotSame([], $tables, 'The manifest lists no tenant-scoped table, so the row checks would see nothing.');

        return implode(' UNION ALL ', array_map(
            static fn (string $table): string => \sprintf(
                "SELECT '%1\$s' AS t, count(*) AS n, %2\$s AS d FROM %1\$s x",
                $table,
                $withDigest ? "md5(coalesce(string_agg(x::text, '|' ORDER BY x::text), ''))" : "''",
            ),
            $tables,
        ));
    }

    /** @param array<string, mixed> $outcome */
    private function describe(array $outcome): string
    {
        return json_encode($outcome, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES);
    }

    private function kdf(): CountingKdf
    {
        $kdf = self::getContainer()->get(CountingKdf::class);
        \assert($kdf instanceof CountingKdf);

        return $kdf;
    }
}
