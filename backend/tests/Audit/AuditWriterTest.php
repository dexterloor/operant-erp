<?php

declare(strict_types=1);

namespace App\Tests\Audit;

use App\Modules\AuditLog\Infrastructure\CreditNoteAuditWriter;
use App\Modules\AuditLog\Infrastructure\CustomerAddressAuditWriter;
use App\Modules\AuditLog\Infrastructure\CustomerAuditWriter;
use App\Modules\AuditLog\Infrastructure\DenialAuditWriter;
use App\Modules\AuditLog\Infrastructure\Entity\AuditLogEntry;
use App\Modules\AuditLog\Infrastructure\FulfilmentAuditWriter;
use App\Modules\AuditLog\Infrastructure\GoodsReceiptAuditWriter;
use App\Modules\AuditLog\Infrastructure\InvoiceAuditWriter;
use App\Modules\AuditLog\Infrastructure\PasswordChangeAuditWriter;
use App\Modules\AuditLog\Infrastructure\PurchaseOrderAuditWriter;
use App\Modules\AuditLog\Infrastructure\RoleAuditWriter;
use App\Modules\AuditLog\Infrastructure\SalesOrderAuditWriter;
use App\Modules\AuditLog\Infrastructure\SalesReturnAuditWriter;
use App\Modules\AuditLog\Infrastructure\StorageLocationAuditWriter;
use App\Modules\AuditLog\Infrastructure\SupplierAuditWriter;
use App\Modules\AuditLog\Infrastructure\WarehouseAuditWriter;
use App\Modules\UserManagement\Infrastructure\AuthTrailWriter;
use App\Platform\Security\PermissionGate;
use App\SharedKernel\ValueObject\AuditEvent;
use App\Tests\Api\OperationSuccessRecipes;
use App\Tests\Api\PurchaseOrderTestCase;
use App\Tests\Api\TransactionBoundaryTest;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Uid\Uuid;

/**
 * **API-16 / `ROADMAP.md` *Exit criteria — M0-b* — `ip_address` is written on
 * security events and on nothing else, and this is the test that goes red when a
 * business-event writer populates the column.**
 *
 * ===========================================================================
 * Why this file exists at all
 * ===========================================================================
 * M0-b's criterion has two clauses and only one of them was ever about code:
 *
 * > *fails if:* a writer on a business event passes a non-null `ip_address`,
 * > **or** the rule exists only as prose with no test that goes red when a
 * > business-event writer populates the column.
 *
 * The first clause held on inspection and the second did not: before this file,
 * `grep -rn 'ip_address' backend/tests` found no rule test at all, so the whole
 * of the control was two writers that happen to be narrow. A property nothing can
 * fail on is a property nobody will notice losing — which is the shape this
 * project has now found more than a dozen times.
 *
 * ===========================================================================
 * The split is DERIVED, and the pin is what stops it being a tautology
 * ===========================================================================
 * The security/business split comes from `AuditEvent::SECURITY` and
 * `AuditEvent::BUSINESS`, never from a list here — that is what makes an event
 * added to the catalogue tomorrow a subject of this file the same day.
 *
 * `API.md` §9.1 then names the trap in the obvious version of that: a test whose
 * expectation reads the same constant the writer reads compares a constant to
 * itself. **Neither writer here reads `AuditEvent::SECURITY`** — `AuthTrailWriter`
 * branches on the `auth.` prefix and `AuditEvent::SUBJECTLESS`, and
 * `DenialAuditWriter` writes one hard-coded event — so the derivation above is
 * already independent of the code under test. `PINNED_IP_BEARING_EVENTS` is
 * §9.1's layer 1a on top of that: membership declared **in this file**, compared
 * against `AuditEvent::SECURITY` in both directions, so moving an event between
 * the catalogue's two lists takes two deliberate edits in two files and neither
 * can quiet the other.
 *
 * ===========================================================================
 * All three layers are covered here now, and two of them arrived late
 * ===========================================================================
 * §9.1 specifies three enforcement layers. This file shipped with **layers 1 and
 * 1a only**, and said so: layer 3 did not exist and this pass was not authorised
 * to write the migration. **Layers 2 and 3 both landed on 2026-09-10 under
 * `DELIVERY_LEDGER.md`'s entry condition E4-5** (`Version20260910000300`,
 * `audit_log_ip_address_scope`, and the `AuditLogEntry` constructor guard).
 *
 * **Layer 2 was absent and no register said so.** `API.md` §14.1's API-O-23
 * asserted *"Layers 1 and 1a now exist"* and scoped the open gap to layer 3;
 * `AuditLogEntry` in fact carried three constructor guards — catalogue
 * membership, subject pair, permission-code scope — and none of them mentioned
 * `ip_address`. That wrong claim propagated into a dispatch brief before it was
 * caught, and API-O-23's closure note records it rather than erasing it.
 *
 * **The order mattered, and shipping layer 3 alone would have been a net loss.**
 * The `CHECK` on its own converts a bad `(ip_address, event_type)` pair from
 * *silently accepted* into a raw `23514` at flush carrying no hint that a
 * catalogue exists — the exact failure mode layer 2 was specified to prevent.
 *
 * **What each of the four checks below is actually load-bearing for**, because
 * three of them look like the same test and are not:
 *
 *   - `onlySecurityEventsCarryAnIpAddress` — layer 1, over the real writers.
 *   - `thePinAndTheConstantAgreeInBothDirections` — layer 1a.
 *   - `theConstructorRefusesAnIpAddressOnAnEventThePinDoesNotList` — **layer 2**,
 *     and its expectation is `PINNED_IP_BEARING_EVENTS` rather than
 *     `AuditEvent::SECURITY`. The guard reads `SECURITY`; a test that read it too
 *     would compare a constant to itself through one indirection and stay green
 *     the day `SECURITY` gains a business event. That is verbatim the tautology
 *     §9.1 records revision 1 shipping for layer 1.
 *   - `theCheckConstraintExistsOnAuditLogAndIsValidated` and
 *     `theCheckConstraintAndTheConstantAgreeInBothDirections` and
 *     `theDatabaseRefusesABusinessEventCarryingAnIpAddress` — **layer 3**, and
 *     all three are needed. A `NOT VALID` constraint has byte-identical
 *     `pg_get_constraintdef` text to a validated one and enforces nothing about
 *     the rows already there, so the text comparison alone is a fake-green; and
 *     a reconciliation that never inserts anything cannot tell *the migration
 *     landed* from *only the PHP layers exist*, which is what the raw-SQL refusal
 *     probe settles.
 */
final class AuditWriterTest extends PurchaseOrderTestCase
{
    use OperationSuccessRecipes;

    /**
     * §9.1's layer 1a: the events that may carry an `ip_address`, **declared
     * here and never in `AuditEvent`**, and reconciled with the catalogue in both
     * directions by `thePinAndTheConstantAgreeInBothDirections`.
     *
     * Adding a member here without adding it to `AuditEvent::SECURITY` fails, and
     * so does the reverse. That is the whole job of the copy.
     *
     * @var list<string>
     */
    public const PINNED_IP_BEARING_EVENTS = [
        'auth.login.success',
        'auth.login.failure',
        'auth.login.tenant_inactive',
        // E4-1 clause (5) / API-O-18's sixth step, added 2026-09-09 with E-23's
        // `AUTH_PASSWORD_CHANGED`. `SEC` ruled `ip_address: yes` (§5.1.4) --
        // "the source address of a credential change is the forensic question
        // the event exists to answer" -- and API-16's pin is two-directional, so
        // a security event omitted here fails
        // `thePinAndTheConstantAgreeInBothDirections` rather than silently
        // sitting outside §9.1's layer 1a.
        'auth.password.changed',
        'authz.permission.denied',
        'data.export.completed',
        'role.assigned',
        'role.revoked',
        // Roles slice 3 C1 (2026-10-04): E-15e's proposal events, SECURITY (`SECURITY_MODEL.md` §5.2.8), carrying the
        // request's IP; `Version20261004000200` re-issues `audit_log_ip_address_scope` with both.
        'role.assignment_proposed',
        'role.assignment_lapsed',
        // Roles slice 3 C3 (2026-10-05): the other two decisions; `Version20261005000300` re-issues the scope with both.
        'role.assignment_rejected',
        'role.assignment_cancelled',
        // Roles slice 4 C1 (2026-10-07): E-15f's definition event, SECURITY (`roles-s4-sec-data.md` D-7.3), carrying the
        // request's IP; `Version20261007000200` re-issues the scope with it.
        'role.permissions_changed',
        // Roles slice 4 C2 (2026-10-07): an edit of only a role's name/description, SECURITY (D-7.3), IP-bearing;
        // `Version20261007000400` re-issues the scope with it.
        'role.updated',
        // Roles slice 4 C3 (2026-10-07): a composed role soft-deleted, SECURITY, IP-bearing; `Version20261007000600`
        // re-issues the scope with it.
        'role.deleted',
    ];

    /**
     * Every file under `backend/src/` that can put a row in `audit_log`, pinned
     * so that a **new** one cannot arrive unclassified.
     *
     * This is the list `everyClassThatCanWriteTheTrailIsClassifiedHere`
     * reconciles against a scan of the tree. It is deliberately about *files*
     * rather than about classes: the scan is textual, and a name resolved from
     * the text would be a second thing that can be wrong.
     *
     * @var list<string>
     */
    private const DECLARED_WRITERS = [
        'Modules/AuditLog/Infrastructure/DenialAuditWriter.php',
        'Modules/AuditLog/Infrastructure/PasswordChangeAuditWriter.php',
        // F-2 step 8's writer, added 2026-09-10 with `ApprovePurchaseOrder`. It
        // is the **first business-event writer in the tree**, which is why it is
        // driven below rather than only listed: every previous writer's rule was
        // "this event may carry an address", and this one's is "this event may
        // not", and until now that half of §9.2's table had no subject at all.
        'Modules/AuditLog/Infrastructure/PurchaseOrderAuditWriter.php',
        // Added 2026-09-19 with `CancelSalesOrder` -- the **first writer of a
        // `sales_order.*` row this project has had**. Its ip_address rule is the
        // purchase writer's: `sales_order.cancelled` is a `BUSINESS` event, so the
        // column must stay null and `audit_log_ip_address_scope` refuses one at the
        // database. It is listed here AND driven below, because a writer this file
        // only lists is a writer `onlySecurityEventsCarryAnIpAddress` never checks.
        'Modules/AuditLog/Infrastructure/SalesOrderAuditWriter.php',
        // Added 2026-09-28 with `IssueInvoice` (sales step 6, C3): `invoice.issued`, a `BUSINESS` event, so its
        // ip_address is null by construction (the method has no parameter for one). Listed AND driven below.
        'Modules/AuditLog/Infrastructure/InvoiceAuditWriter.php',
        // Added 2026-09-29 with B-4a (roles slice 2, C1): `goods_receipt.created` and `fulfilment.created`, both
        // `BUSINESS`, so no parameter could send an address. Listed AND driven below.
        'Modules/AuditLog/Infrastructure/GoodsReceiptAuditWriter.php',
        'Modules/AuditLog/Infrastructure/FulfilmentAuditWriter.php',
        // Added 2026-10-01 with `CreateSalesReturn` (sales step 7 R2): `sales_return.created`, BUSINESS, so no parameter
        // could send an address. Listed AND driven below.
        'Modules/AuditLog/Infrastructure/SalesReturnAuditWriter.php',
        // Added 2026-10-01 with `IssueCreditNote` (sales step 7 C1): `credit_note.issued`, BUSINESS. Listed AND driven below.
        'Modules/AuditLog/Infrastructure/CreditNoteAuditWriter.php',
        // Added 2026-10-09 with CreateCustomer/UpdateCustomer (customers slice 1 C2): customer.created and customer.updated,
        // BUSINESS, so no parameter could send an address. Listed AND driven below.
        'Modules/AuditLog/Infrastructure/CustomerAuditWriter.php',
        // Added 2026-10-12 with CreateSupplier/UpdateSupplier (suppliers slice 1 C2): supplier.created and supplier.updated,
        // BUSINESS, so no parameter could send an address. Listed AND driven below.
        'Modules/AuditLog/Infrastructure/SupplierAuditWriter.php',
        // Added 2026-10-13 with CreateWarehouse/UpdateWarehouse (warehouses slice 1 C2): warehouse.created and
        // warehouse.updated, BUSINESS, so no parameter could send an address. Listed AND driven below.
        'Modules/AuditLog/Infrastructure/WarehouseAuditWriter.php',
        // Added 2026-10-13 with CreateStorageLocation/UpdateStorageLocation (warehouses slice 1 C3): storage_location.created
        // and storage_location.updated, BUSINESS, so no parameter could send an address. Listed AND driven below.
        'Modules/AuditLog/Infrastructure/StorageLocationAuditWriter.php',
        // Added 2026-10-12 with the customer address trail (suppliers slice 1 C2b): customer_address.created and
        // customer_address.updated, BUSINESS, so no parameter could send an address. Listed AND driven below.
        'Modules/AuditLog/Infrastructure/CustomerAddressAuditWriter.php',
        // Added 2026-09-29 with `RevokeRole` (roles slice 2, C2): `role.revoked`, a SECURITY event, so it carries the
        // request's IP (E-3; `audit_log_ip_address_scope` admits one for `role.*`). Listed AND driven below — and
        // `role.assigned` (C3, `AssignRole`) through the same writer, also driven.
        'Modules/AuditLog/Infrastructure/RoleAuditWriter.php',
        'Modules/UserManagement/Infrastructure/AuthTrailWriter.php',
    ];

    private const PROBE_IP = '203.0.113.7';

    /**
     * §9.1's layer 3, installed by `Version20260910000300` under E4-5. Named here
     * rather than derived so that a *renamed* constraint fails loudly instead of
     * this file quietly checking whatever `audit_log` happens to carry.
     */
    private const IP_CONSTRAINT = 'audit_log_ip_address_scope';

    /**
     * **The negative test M0-b's criterion asks for.**
     *
     * Every event in `AuditEvent::ALL` is offered to every writer that takes an
     * event type, with a non-null `ip_address` in hand, and what actually reached
     * the table is read back. The rule is then asserted against the **catalogue's
     * own split**:
     *
     *   - no member of `AuditEvent::BUSINESS` wrote a row carrying an IP;
     *   - every event that did carry one is on `PINNED_IP_BEARING_EVENTS`;
     *   - and at least one did, so the two clauses above cannot be satisfied by a
     *     writer that fell over and wrote nothing.
     *
     * `DenialAuditWriter` takes no event type — it can only write
     * `authz.permission.denied` — so it contributes one row rather than a loop,
     * and the assertion that the event it hard-codes is a security event is the
     * form of the rule that applies to it.
     *
     * *Mutation, run:* `AuthTrailWriter`'s guard (the `auth.` prefix plus
     * `AuditEvent::SUBJECTLESS`) removed, which is exactly *"a business-event
     * writer populates the column"* — `purchase_order.placed` and
     * `stock_movement.adjusted` then write rows carrying `203.0.113.7`, and this
     * is red naming both.
     *
     * *Second mutation, run, and it corrected a claim this docblock first made:*
     * the `?::inet` parameter replaced by a literal `NULL` in `AuthTrailWriter`
     * alone leaves this **green**, because `DenialAuditWriter` still writes one —
     * which is what the anti-vacuity clause is for and also its limit, since it is
     * satisfied by *any* writer carrying an IP. Nulled in **both** writers it is
     * red on *"No row carries an ip_address at all"*. Recorded rather than
     * rewritten to look tidier: the first form was assumed and the second was run.
     *
     * **Re-measured 2026-09-09 with a third writer, and the limit got wider — so
     * it is restated rather than left at "both".** With
     * `PasswordChangeAuditWriter` added, nulling the address in **that writer
     * alone** leaves this file green (measured, `OK (3 tests, 9 assertions)`);
     * nulling it in **all three** is red on the same anti-vacuity clause
     * (measured). The small mutation is caught, but by
     * `PasswordChangeTest::oneChangeWritesExactlyOneSecurityRow`, which asserts
     * that writer's address directly — **not by this file**.
     *
     * **The generalisation is worth more than the fix and it is the reason this
     * paragraph exists: this clause's blind spot grows by one writer every time a
     * writer is added.** *"At least one row carried an IP"* is satisfied by any
     * survivor, so with N writers a mutation has to hit all N before it fires
     * here. A mutation that does **not** fire can therefore mean the mutation was
     * too small rather than that the check is strong, and telling those apart
     * means running the larger one. The per-writer assertion belongs with the
     * writer; this file's job is the *rule over the split*, and it should not be
     * read as covering any individual writer's column.
     */
    #[Test]
    public function onlySecurityEventsCarryAnIpAddress(): void
    {
        $actor = $this->seedActor();
        $accepted = [];
        $refused = [];

        foreach (AuditEvent::ALL as $eventType) {
            try {
                $this->trailWriter()->record($eventType, self::TENANT_A, $actor, 'Probe Actor', self::PROBE_IP);
                $accepted[] = $eventType;
            } catch (\InvalidArgumentException) {
                // Refusing the event outright is one of the two ways this rule
                // can hold, and it is the one that holds for every business
                // event today.
                $refused[] = $eventType;
            }
        }

        self::assertNotSame([], $accepted,
            'The writer refused every event in the catalogue, so nothing below was measured on a real write.');

        // The two writers with no event-type parameter to loop over. Each writes
        // one hard-coded event, so the form of the rule that applies to them is
        // that the event they hard-code is a security event -- which the clauses
        // below assert against the catalogue's own split, not against a list.
        $this->denialWriter()->record(
            tenantId: self::TENANT_A,
            actorUserId: $actor,
            actorLabel: 'Probe Actor',
            permissionCode: 'inventory_item.view',
            subjectId: Uuid::v4()->toRfc4122(),
            ipAddress: self::PROBE_IP,
        );

        // E-23's writer, added 2026-09-09. `everyClassThatCanWriteTheTrailIsClassifiedHere`
        // requires every file that can write the trail to be declared here, and
        // its own failure message says why declaring is not enough: "a writer
        // that is not exercised by `onlySecurityEventsCarryAnIpAddress` is a
        // writer M0-b's ip_address rule is not being checked against." So it is
        // driven rather than merely listed.
        $this->passwordChangeWriter()->record(
            tenantId: self::TENANT_A,
            actorUserId: $actor,
            actorLabel: 'Probe Actor',
            ipAddress: self::PROBE_IP,
        );

        // F-2 step 8's writer, added 2026-09-10. It is the first writer here
        // that **cannot** carry an address -- §9.2's `ip_address` cell for
        // `PURCHASE_ORDER_PLACED` is null, API-16 restricts the column to
        // security events, and the method has no parameter that could send one.
        // Driving it is what turns "structurally cannot" into a measurement:
        // the row it writes appears in `$written` and must not appear in
        // `$withIp`, which is the business half of the split the clauses below
        // assert.
        $this->purchaseOrderWriter()->recordPlaced(
            tenantId: self::TENANT_A,
            actorUserId: $actor,
            actorLabel: 'Probe Actor',
            purchaseOrderId: Uuid::v4()->toRfc4122(),
        );

        // Sales step 6, C3: the invoice writer, driven for the same reason -- a second business writer that
        // structurally cannot carry an address, measured on the row it writes.
        $invoiceWriter = self::getContainer()->get(InvoiceAuditWriter::class);
        \assert($invoiceWriter instanceof InvoiceAuditWriter);
        $invoiceWriter->recordIssued(
            tenantId: self::TENANT_A,
            actorUserId: $actor,
            actorLabel: 'Probe Actor',
            invoiceId: Uuid::v4()->toRfc4122(),
        );
        // Sales step 6, C5: `invoice.paid`, the same writer's second business event.
        $invoiceWriter->recordPaid(
            tenantId: self::TENANT_A,
            actorUserId: $actor,
            actorLabel: 'Probe Actor',
            invoiceId: Uuid::v4()->toRfc4122(),
            paidAt: '2026-09-28T00:00:00.000+00:00',
        );
        // Sales step 6, C6: `invoice.payment_reversed`, the writer's third business event.
        $invoiceWriter->recordPaymentReversed(
            tenantId: self::TENANT_A,
            actorUserId: $actor,
            actorLabel: 'Probe Actor',
            invoiceId: Uuid::v4()->toRfc4122(),
            reason: 'Probe',
        );
        // Sales step 6, C7: `invoice.voided`, the writer's fourth business event.
        $invoiceWriter->recordVoided(
            tenantId: self::TENANT_A,
            actorUserId: $actor,
            actorLabel: 'Probe Actor',
            invoiceId: Uuid::v4()->toRfc4122(),
            reason: 'Probe',
        );

        // B-4a (roles slice 2, C1, 2026-09-29): the five new business events, every method driven so none is a
        // listed-only writer this test never checks.
        $receiptWriter = self::getContainer()->get(GoodsReceiptAuditWriter::class);
        \assert($receiptWriter instanceof GoodsReceiptAuditWriter);
        $receiptWriter->recordCreated(tenantId: self::TENANT_A, actorUserId: $actor, actorLabel: 'Probe Actor', goodsReceiptId: Uuid::v4()->toRfc4122());
        $fulfilmentWriter = self::getContainer()->get(FulfilmentAuditWriter::class);
        \assert($fulfilmentWriter instanceof FulfilmentAuditWriter);
        $fulfilmentWriter->recordCreated(tenantId: self::TENANT_A, actorUserId: $actor, actorLabel: 'Probe Actor', fulfilmentId: Uuid::v4()->toRfc4122());
        // Sales step 7 R2: `sales_return.created`, BUSINESS.
        $returnWriter = self::getContainer()->get(SalesReturnAuditWriter::class);
        \assert($returnWriter instanceof SalesReturnAuditWriter);
        $returnWriter->recordCreated(tenantId: self::TENANT_A, actorUserId: $actor, actorLabel: 'Probe Actor', salesReturnId: Uuid::v4()->toRfc4122(), reason: 'other', deducts: true);
        // Sales step 7 C1: `credit_note.issued`, BUSINESS.
        $creditWriter = self::getContainer()->get(CreditNoteAuditWriter::class);
        \assert($creditWriter instanceof CreditNoteAuditWriter);
        $creditWriter->recordIssued(tenantId: self::TENANT_A, actorUserId: $actor, actorLabel: 'Probe Actor', creditNoteId: Uuid::v4()->toRfc4122(), reason: 'goods_returned');
        // Customers slice 1 C2: `customer.created` / `customer.updated`, BUSINESS (final RED F-1: listed AND driven).
        $customerWriter = self::getContainer()->get(CustomerAuditWriter::class);
        \assert($customerWriter instanceof CustomerAuditWriter);
        $customerWriter->recordCreated(tenantId: self::TENANT_A, actorUserId: $actor, actorLabel: 'Probe Actor', customerId: Uuid::v4()->toRfc4122());
        $customerWriter->recordUpdated(tenantId: self::TENANT_A, actorUserId: $actor, actorLabel: 'Probe Actor', customerId: Uuid::v4()->toRfc4122(), fields: ['name']);
        // C5 C4a: `customer.restored`, BUSINESS (listed AND driven).
        $customerWriter->recordRestored(tenantId: self::TENANT_A, actorUserId: $actor, actorLabel: 'Probe Actor', customerId: Uuid::v4()->toRfc4122());
        // Suppliers slice 1 C2: `supplier.created` / `supplier.updated`, BUSINESS (listed AND driven).
        $supplierWriter = self::getContainer()->get(SupplierAuditWriter::class);
        \assert($supplierWriter instanceof SupplierAuditWriter);
        $supplierWriter->recordCreated(tenantId: self::TENANT_A, actorUserId: $actor, actorLabel: 'Probe Actor', supplierId: Uuid::v4()->toRfc4122());
        $supplierWriter->recordUpdated(tenantId: self::TENANT_A, actorUserId: $actor, actorLabel: 'Probe Actor', supplierId: Uuid::v4()->toRfc4122(), fields: ['name']);
        // C5 C2a: `supplier.restored`, BUSINESS (listed AND driven).
        $supplierWriter->recordRestored(tenantId: self::TENANT_A, actorUserId: $actor, actorLabel: 'Probe Actor', supplierId: Uuid::v4()->toRfc4122());
        // Warehouses slice 1 C2: `warehouse.created` / `warehouse.updated`, BUSINESS (listed AND driven).
        $warehouseWriter = self::getContainer()->get(WarehouseAuditWriter::class);
        \assert($warehouseWriter instanceof WarehouseAuditWriter);
        $warehouseWriter->recordCreated(tenantId: self::TENANT_A, actorUserId: $actor, actorLabel: 'Probe Actor', warehouseId: Uuid::v4()->toRfc4122(),
            values: ['code' => 'W-PROBE', 'name' => 'Probe', 'country_code' => 'GB']);
        $warehouseWriter->recordUpdated(tenantId: self::TENANT_A, actorUserId: $actor, actorLabel: 'Probe Actor', warehouseId: Uuid::v4()->toRfc4122(),
            from: ['name' => 'Probe'], to: ['name' => 'Probe Renamed']);
        // Warehouses slice 1 C3: `storage_location.created` / `storage_location.updated`, BUSINESS (listed AND driven).
        $locationWriter = self::getContainer()->get(StorageLocationAuditWriter::class);
        \assert($locationWriter instanceof StorageLocationAuditWriter);
        $locationWriter->recordCreated(tenantId: self::TENANT_A, actorUserId: $actor, actorLabel: 'Probe Actor', storageLocationId: Uuid::v4()->toRfc4122(),
            values: ['code' => 'P-01', 'kind' => 'bin']);
        $locationWriter->recordUpdated(tenantId: self::TENANT_A, actorUserId: $actor, actorLabel: 'Probe Actor', storageLocationId: Uuid::v4()->toRfc4122(),
            from: ['kind' => 'bin'], to: ['kind' => 'quarantine'], heldStock: false);
        // Suppliers slice 1 C2b: `customer_address.created` / `customer_address.updated`, BUSINESS (listed AND driven).
        $addressWriter = self::getContainer()->get(CustomerAddressAuditWriter::class);
        \assert($addressWriter instanceof CustomerAddressAuditWriter);
        $addressWriter->recordCreated(tenantId: self::TENANT_A, actorUserId: $actor, actorLabel: 'Probe Actor', addressId: Uuid::v4()->toRfc4122());
        $addressWriter->recordUpdated(tenantId: self::TENANT_A, actorUserId: $actor, actorLabel: 'Probe Actor', addressId: Uuid::v4()->toRfc4122(), fields: ['city']);
        $salesWriter = self::getContainer()->get(SalesOrderAuditWriter::class);
        \assert($salesWriter instanceof SalesOrderAuditWriter);
        $salesWriter->recordConfirmed(tenantId: self::TENANT_A, actorUserId: $actor, actorLabel: 'Probe Actor', salesOrderId: Uuid::v4()->toRfc4122());
        $salesWriter->recordDeleted(tenantId: self::TENANT_A, actorUserId: $actor, actorLabel: 'Probe Actor', salesOrderId: Uuid::v4()->toRfc4122());
        $this->purchaseOrderWriter()->recordDeleted(tenantId: self::TENANT_A, actorUserId: $actor, actorLabel: 'Probe Actor', purchaseOrderId: Uuid::v4()->toRfc4122());
        // Roles slice 2, C2: the first role-event writer, on the IP-bearing side of the split.
        $roleWriter = self::getContainer()->get(RoleAuditWriter::class);
        \assert($roleWriter instanceof RoleAuditWriter);
        $roleWriter->recordRevoked(tenantId: self::TENANT_A, actorUserId: $actor, actorLabel: 'Probe Actor', subjectUserId: $actor, roleId: Uuid::v4()->toRfc4122(), ipAddress: self::PROBE_IP);
        // Roles slice 2, C3: `role.assigned`, the same SECURITY shape through the same private statement.
        $roleWriter->recordAssigned(tenantId: self::TENANT_A, actorUserId: $actor, actorLabel: 'Probe Actor', subjectUserId: $actor, roleId: Uuid::v4()->toRfc4122(), ipAddress: self::PROBE_IP);
        // Roles slice 3, C1: E-15e's proposal events, the same SECURITY shape through the same private statement.
        $roleWriter->recordProposed(tenantId: self::TENANT_A, actorUserId: $actor, actorLabel: 'Probe Actor', subjectUserId: $actor, roleId: Uuid::v4()->toRfc4122(), proposalId: Uuid::v4()->toRfc4122(), supersedesProposalId: null, ipAddress: self::PROBE_IP);
        $roleWriter->recordLapsed(tenantId: self::TENANT_A, actorUserId: $actor, actorLabel: 'Probe Actor', subjectUserId: $actor, roleId: Uuid::v4()->toRfc4122(), proposalId: Uuid::v4()->toRfc4122(), lapseReason: 'expired', ipAddress: self::PROBE_IP);

        $withIp = [];
        $written = [];

        foreach ($this->trail() as $row) {
            $written[] = (string) $row['event_type'];

            if (null !== $row['ip_address']) {
                $withIp[] = (string) $row['event_type'];
            }
        }

        $withIp = array_values(array_unique($withIp));
        sort($withIp);

        self::assertNotSame([], $withIp,
            'No row carries an ip_address at all, so both clauses below would hold over an empty set.');

        self::assertSame([], array_values(array_intersect($withIp, AuditEvent::BUSINESS)),
            'A business event was written with a non-null ip_address. `ROADMAP.md` *Exit criteria — M0-b*: '
            .'ip_address is personal data and has no forensic value on the bulk business event, which the '
            .'project\'s own fixture makes the overwhelming majority of this table.');

        self::assertSame([], array_values(array_diff($withIp, self::PINNED_IP_BEARING_EVENTS)),
            'An event outside the pinned IP-bearing set carried an ip_address: '.implode(', ', $withIp));

        // Anti-vacuity from the other side: the loop must actually have reached
        // the table, or "no business event carried an IP" is a claim about a
        // writer that wrote nothing.
        self::assertNotSame([], array_intersect($written, AuditEvent::SECURITY),
            'No security event reached the table, so the writers under test wrote nothing at all.');

        // The same rule one step earlier, on the writer rather than on the row:
        // the trail writer is the pre-authentication one and E-13 scopes it to
        // `auth.*`, so a business event must not even be accepted by it. Asserted
        // after the row clauses deliberately — M0-b's criterion is about the
        // column, and it should be the failure a reader sees first.
        self::assertSame([], array_values(array_diff(AuditEvent::BUSINESS, $refused)),
            'A business event was accepted by the pre-authentication trail writer, which was handed an '
            .'ip_address. E-13 scopes that writer to `auth.*`.');
    }

    /**
     * §9.1's layer 1a, in both directions.
     *
     * *Mutation, run:* `PURCHASE_ORDER_PLACED` moved from `AuditEvent::BUSINESS`
     * into `AuditEvent::SECURITY`. `onlySecurityEventsCarryAnIpAddress` stays
     * **green** — measured, not assumed: nothing writes that event either way, so
     * the split it derives moved underneath it in silence — and this method is the
     * one that goes red. That is the whole reason the pin is a second copy rather
     * than a reference.
     */
    #[Test]
    public function thePinAndTheConstantAgreeInBothDirections(): void
    {
        $pinned = self::PINNED_IP_BEARING_EVENTS;
        $catalogue = AuditEvent::SECURITY;
        sort($pinned);
        sort($catalogue);

        self::assertSame($catalogue, $pinned,
            'The events this file pins as IP-bearing and `AuditEvent::SECURITY` disagree. §9.1 requires two '
            .'deliberate edits in two files, so whichever side moved, the other has to be argued rather than '
            .'made to follow.');
    }

    /**
     * **The domain of writers is derived from the source tree, so a new one
     * cannot arrive unnoticed.**
     *
     * `onlySecurityEventsCarryAnIpAddress` can only drive the writers it knows
     * about. This is what makes that domain honest: every file under `src/` that
     * issues an `INSERT INTO audit_log`, or constructs an `AuditLogEntry` for the
     * entity manager to flush, must be on `DECLARED_WRITERS` — and adding one is
     * therefore a deliberate act that also has to answer the rule above.
     *
     * *Mutation, run:* a file added under `src/` containing
     * `INSERT INTO audit_log`; this names it and is red, while every other test
     * in the suite stays green.
     */
    #[Test]
    public function everyClassThatCanWriteTheTrailIsClassifiedHere(): void
    {
        $root = \dirname(__DIR__, 2).'/src';
        $found = [];

        /** @var \SplFileInfo $file */
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)) as $file) {
            if (!$file->isFile() || 'php' !== $file->getExtension()) {
                continue;
            }

            $source = (string) file_get_contents($file->getPathname());

            if (1 === preg_match('/INSERT\s+INTO\s+audit_log/i', $source)
                || 1 === preg_match('/new\s+AuditLogEntry\s*\(/', $source)) {
                $found[] = str_replace($root.'/', '', $file->getPathname());
            }
        }

        sort($found);
        $declared = self::DECLARED_WRITERS;
        sort($declared);

        self::assertNotSame([], $found, 'The scan found no writer at all, so this check had no subject.');
        self::assertSame($declared, $found,
            'The set of files that can write `audit_log` is not the set this file drives. A writer that is '
            .'not exercised by `onlySecurityEventsCarryAnIpAddress` is a writer M0-b\'s ip_address rule is '
            .'not being checked against.');
    }

    /**
     * **§9.1's layer 2 — the constructor guard — and its expectation is the pin,
     * not the constant the guard reads.**
     *
     * The domain is `AuditEvent::ALL`, so an event added to the catalogue
     * tomorrow is a subject of this check the same day. The expectation is
     * `PINNED_IP_BEARING_EVENTS`, declared in this file as independent literals.
     * A test asserting *"the constructor refuses exactly the members not in
     * `AuditEvent::SECURITY`"* would compare that constant to itself through one
     * indirection and stay green if `SECURITY` gained `purchase_order.placed`
     * tomorrow -- the guard would then accept an IP on it and the test would
     * agree. §9.1 records revision 1 shipping exactly that defect for layer 1.
     *
     * Both directions are asserted from one pass: an event that **accepted** an
     * IP and is not pinned fails, and a pinned event that was **refused** fails.
     *
     * *Mutation, run:* the guard's condition negated to
     * `\in_array($eventType, AuditEvent::SECURITY, true)`. Red on both directions
     * at once -- the accepted set collapses to the two business events and all
     * eight pinned events are refused.
     *
     * *Second mutation, run:* the guard short-circuited to unreachable. Every
     * event accepts an IP; red naming `purchase_order.placed` and
     * `stock_movement.adjusted` as *accepted but not pinned*. **This is the one
     * that matters**,
     * because it is the state the tree was in before E4-5 -- and
     * `onlySecurityEventsCarryAnIpAddress` stays green through it, since nothing
     * in `src/` constructs an `AuditLogEntry` for a business event.
     */
    #[Test]
    public function theConstructorRefusesAnIpAddressOnAnEventThePinDoesNotList(): void
    {
        $accepted = [];
        $refused = [];

        foreach (AuditEvent::ALL as $eventType) {
            try {
                new AuditLogEntry(
                    Uuid::v4(),
                    Uuid::fromString(self::TENANT_A),
                    new \DateTimeImmutable(),
                    $eventType,
                    'system',
                    null,
                    null,
                    null,
                    self::PROBE_IP,
                );
                $accepted[] = $eventType;
            } catch (\InvalidArgumentException) {
                $refused[] = $eventType;
            }
        }

        self::assertNotSame([], AuditEvent::ALL,
            'An empty catalogue would pass this test vacuously.');

        $pinned = self::PINNED_IP_BEARING_EVENTS;
        sort($pinned);
        sort($accepted);

        self::assertSame($pinned, $accepted,
            "The events AuditLogEntry lets carry an ip_address and the pin disagree.\n"
            ."  accepted but not pinned: ".implode(', ', array_diff($accepted, $pinned))."\n"
            ."  pinned but refused:      ".implode(', ', array_diff($pinned, $accepted)));

        self::assertNotSame([], $refused,
            'No catalogue value was refused an ip_address, so §9.1 layer 2 is not enforcing anything. '
            .'A guard that accepts everything passes a set comparison only when the pin is the whole '
            .'catalogue, and it is not.');
    }

    /**
     * **§9.1's layer 3, first clause: the constraint exists, is a CHECK, is on
     * `audit_log` -- and is VALIDATED.**
     *
     * `AuditEventCatalogueTest::theEventTypeConstraintExistsOnAuditLogAndIs`
     * `Validated` is the precedent and its measurement is the reason this
     * assertion is here rather than being folded into the reconciliation below.
     * `Version20260910000300::isTransactional()` returns `false` so that
     * `ADD CONSTRAINT ... NOT VALID` releases its `AccessExclusiveLock` before
     * `VALIDATE CONSTRAINT` runs; the cost is that the three statements commit
     * independently, so a `VALIDATE` that fails -- or is dropped from `up()` --
     * leaves a constraint that is enforced for new rows and **silent about every
     * row already there**, with `pg_get_constraintdef` text byte-identical to the
     * validated one.
     *
     * `convalidated::text` rather than the bare column, and compared against
     * `'true'` rather than `'t'`: `boolean::text` yields the former, and psql's
     * `t`/`f` is the type's output function, which the cast does not use. That
     * spelling cost the precedent a draft and is not re-derived here.
     *
     * *Mutation, run:* the `VALIDATE CONSTRAINT` line deleted from
     * `Version20260910000300::up()` and the volume destroyed and rebuilt. This
     * method goes red on `convalidated`; the reconciliation below stays **green**,
     * which is the whole reason both exist.
     */
    #[Test]
    public function theCheckConstraintExistsOnAuditLogAndIsValidated(): void
    {
        $row = $this->owner()->fetchAssociative(
            'SELECT pg_get_constraintdef(oid) AS definition, convalidated::text AS validated
             FROM pg_constraint
             WHERE conrelid = ?::regclass AND conname = ? AND contype = ?',
            ['audit_log', self::IP_CONSTRAINT, 'c'],
        );

        self::assertIsArray($row,
            self::IP_CONSTRAINT.' is not a CHECK constraint on audit_log. §9.1 layer 3 does not exist, '
            .'so a writer outside backend/src/ -- a psql session, a fixture, a COPY -- can put personal '
            .'data on a business event and nothing refuses it.');
        self::assertStringContainsString('ip_address', (string) $row['definition'],
            'The constraint exists but does not mention ip_address: '.$row['definition']);
        self::assertSame('true', $row['validated'],
            self::IP_CONSTRAINT.' is NOT VALID. It constrains new rows only, and every row written '
            .'before it was added is unchecked -- which is exactly what the migration\'s VALIDATE '
            .'CONSTRAINT step exists to rule out, and its text is identical either way: '
            .$row['definition']);
    }

    /**
     * **§9.1's layer 3, second clause: the constraint's literals and
     * `AuditEvent::SECURITY` agree in both directions.**
     *
     * The `CHECK` is written with hand-typed literals precisely so it is
     * independent of the constant -- E4-1 clause (4), for the fake-green measured
     * on `audit_log_event_type_values`. Independence with no reconciliation is
     * drift, so the domain is **derived from the server**: the definition is read
     * back out of `pg_constraint` and its literals extracted, then compared with
     * the constant set-wise. A literal the constant lacks fails, and a constant
     * member the literal lacks fails.
     *
     * The literal extraction is deliberately anchored on the `event_type` half of
     * the definition. The `ip_address IS NULL` disjunct contributes no `'...'
     * ::text` literal today, but a future re-issue that added one would silently
     * widen the derived set, and a check whose domain can grow without anyone
     * editing the expectation is the shape this project keeps finding.
     *
     * *Mutation, run:* `'role.revoked'` dropped from the migration's list, volume
     * destroyed and rebuilt. Red, naming `role.revoked` as *only in the code*,
     * with no PHP change anywhere.
     *
     * *Reverse mutation, run:* `'purchase_order.placed'` added to the migration's
     * list. Red, naming it as *only in the database* -- and
     * `theDatabaseRefusesABusinessEventCarryingAnIpAddress` went red in the same
     * run, independently, because the widened constraint really does admit the
     * row. Two checks, two mechanisms, one defect.
     */
    #[Test]
    public function theCheckConstraintAndTheConstantAgreeInBothDirections(): void
    {
        $definition = (string) $this->owner()->fetchOne(
            'SELECT pg_get_constraintdef(oid) FROM pg_constraint
             WHERE conrelid = ?::regclass AND conname = ?',
            ['audit_log', self::IP_CONSTRAINT],
        );

        self::assertNotSame('', $definition,
            self::IP_CONSTRAINT.' could not be read back from pg_constraint. This check derives its '
            .'domain from the server, so with no constraint it must fail rather than pass vacuously.');

        // Membership over a literal list, in the form the server stores it:
        // PostgreSQL normalises `IN (a, b)` to `= ANY (ARRAY[a, b])` before it
        // reaches pg_get_constraintdef. Pinned by infra/docker-compose.yml's
        // postgres:17-alpine; `AuditEventCatalogueTest` records the same
        // deparse-artefact caveat and it is not restated here.
        self::assertStringContainsString('= ANY (ARRAY[', $definition,
            'The constraint is not equality-membership over a literal list: '.$definition);

        foreach ([' LIKE ', ' ~ ', 'NOT LIKE'] as $patternForm) {
            self::assertStringNotContainsString($patternForm, $definition,
                'A prefix or pattern predicate here fails open on the first security event somebody '
                .'names outside it: '.$definition);
        }

        // Anchored on the event_type half rather than run over the whole
        // definition: see the docblock.
        $eventTypeHalf = strstr($definition, 'event_type');
        self::assertIsString($eventTypeHalf,
            'The constraint does not restrict event_type, so it cannot be §9.1 layer 3: '.$definition);

        preg_match_all("/'([^']+)'::text/", $eventTypeHalf, $matches);
        $fromServer = $matches[1];

        self::assertNotSame([], $fromServer,
            'No literals could be read out of '.self::IP_CONSTRAINT.'. If this test cannot read the '
            .'server it is not deriving anything: '.$definition);

        sort($fromServer);
        $inCode = AuditEvent::SECURITY;
        sort($inCode);

        self::assertSame($inCode, $fromServer,
            "The database's IP-bearing set and AuditEvent::SECURITY disagree. A security event the "
            ."constraint omits is silently refused an ip_address at the database.\n"
            ."  only in the database: ".implode(', ', array_diff($fromServer, $inCode))."\n"
            ."  only in the code:     ".implode(', ', array_diff($inCode, $fromServer)));
    }

    /**
     * **§9.1's layer 3, third clause -- and the only one that can tell *the
     * migration landed* from *only the PHP layers exist*.**
     *
     * §9.1's own third mutation, run as a permanent check rather than once: a
     * `purchase_order.placed` row with a non-null `ip_address`, inserted **in raw
     * SQL as the owner**, bypassing the writer's predicate and the entity
     * constructor entirely. That is the writer API-O-23 names -- *"a writer added
     * outside PHP, or a direct `INSERT`"*. The `CHECK` must refuse it. **A green
     * result here means the migration is missing and only the PHP layer exists.**
     *
     * The refusal is asserted to name **this constraint**, not merely to have
     * happened: a test that only asserts "the insert failed" cannot tell a `CHECK`
     * from a missing grant, a `NOT NULL` or a type error, and `ip_address` is
     * `inet`, which is a type error waiting to be mistaken for enforcement.
     * `AuditEventCatalogueTest::aValueOutsideTheCatalogueIsRejectedByTheNamed`
     * `Constraint` is the precedent.
     *
     * The tenant context is not needed and is not taken: `insertAsOwnerForTenant`
     * declares the tenant for RLS the way every other seeding path in this suite
     * does, and a `CHECK` is evaluated before any policy could matter.
     *
     * *Mutation, run:* `up()`'s `ADD CONSTRAINT` and `VALIDATE` both removed,
     * volume destroyed and rebuilt -- the state the tree was in before E4-5. The
     * insert succeeds and this method goes red. **All three layer-3 checks go red
     * together and that is worth stating rather than claiming this one is
     * distinctive**: what it is distinctive about is the *other* mutations. It
     * stayed green through the dropped `VALIDATE`, which only
     * `theCheckConstraintExistsOnAuditLogAndIsValidated` caught -- correctly, a
     * `NOT VALID` constraint really does still refuse this insert -- and it
     * caught the extra-literal mutation (`purchase_order.placed` added to the
     * `CHECK`) independently of the reconciliation, which caught it too. **What all three
     * share, and what is the actual point: the four PHP-side checks in this file
     * -- layers 1, 1a, 2 and the writer scan -- stayed green through every one of
     * them.** Nothing above the database can see the database's absence.
     */
    #[Test]
    public function theDatabaseRefusesABusinessEventCarryingAnIpAddress(): void
    {
        $thrown = null;

        try {
            $this->insertAsOwnerForTenant(self::TENANT_A, <<<'SQL'
                INSERT INTO audit_log (id, tenant_id, event_type, actor_label, ip_address)
                VALUES (?::uuid, ?::uuid, ?, 'system', ?::inet)
                SQL, [Uuid::v4()->toRfc4122(), self::TENANT_A, AuditEvent::PURCHASE_ORDER_PLACED, self::PROBE_IP]);
        } catch (\Throwable $e) {
            $thrown = $e;
        }

        self::assertNotNull($thrown,
            'A business event carrying an ip_address was accepted by the database. §9.1 layer 3 is '
            .'absent and the control is PHP-side only -- which is the state API-O-23 describes.');
        self::assertStringContainsString(self::IP_CONSTRAINT, $thrown->getMessage(),
            'The insert failed, but not because of '.self::IP_CONSTRAINT.': '.$thrown->getMessage());
    }

    private function trailWriter(): AuthTrailWriter
    {
        $writer = self::getContainer()->get(AuthTrailWriter::class);
        \assert($writer instanceof AuthTrailWriter);

        return $writer;
    }

    private function denialWriter(): DenialAuditWriter
    {
        $writer = self::getContainer()->get(DenialAuditWriter::class);
        \assert($writer instanceof DenialAuditWriter);

        return $writer;
    }

    private function passwordChangeWriter(): PasswordChangeAuditWriter
    {
        $writer = self::getContainer()->get(PasswordChangeAuditWriter::class);
        \assert($writer instanceof PasswordChangeAuditWriter);

        return $writer;
    }

    /**
     * **`API.md` §9.2 — no operation writes an event outside the catalogue.**
     *
     * Domain: `AuditEvent::ALL`, read from the constant. Subject: every operation
     * in `TransactionBoundaryTest::contractOperations()`, each driven to success
     * (so every writer on its path runs) and then once more by a caller holding
     * nothing (so the denial writer runs for every operation that declares a
     * code). Every `INSERT INTO audit_log` executed on any connection during
     * those requests is read off the SQL recorder **with its bound values**, and
     * its `event_type` must be a catalogue member. The attempt is what is read,
     * not the row: a write the `CHECK` refuses leaves no row behind.
     *
     * The success recipes are keyed by operation and held equal to the registry,
     * so an operation built tomorrow is red here until it is driven.
     *
     * Controls inside this test: the recipes equal the registry; every recipe
     * answers its success status; at least one audit write was captured; and the
     * captured writes equal the rows `audit_log` gained, so no write slipped past
     * the capture.
     *
     * Mutation (API.md's; C5 batch A, 2026-09-14, cache deleted before the
     * mutated and the revert run — Rule 17): `GoodsReceiptWriter` made to insert
     * an `audit_log` row with event type `goods_receipt.created` → RED, "§9.2: an
     * operation wrote an event type outside AuditEvent::ALL." with
     * `goods_receipt.created` — read off the bound values, since the `CHECK`
     * left no row. Revert → GREEN. Control mutations, run the same day: the
     * recorder's `recordExecution()` call removed → RED "No audit write was
     * captured…"; the `GetCurrentUser` recipe removed → RED "Every operation in
     * the registry must be driven to success here…".
     *
     * **Capture widened, V1-R-8 (V4 B4c, 2026-09-16; cache deleted before and
     * after each run).** Plant: `PurchaseOrderAuditWriter::recordPlaced()` first
     * issues a CHECK-refused insert (event type `b4.not_in_catalogue`) inside a
     * savepoint and swallows the refusal. Spelt `INSERT INTO audit_log` it was
     * already RED. Spelt `INSERT INTO "audit_log"`, `INSERT INTO public.audit_log`,
     * with a leading block comment, with a leading `--` comment, as
     * `WITH x AS (SELECT 1) INSERT INTO audit_log`, or as
     * `insert /* gap *\/ into "public"."audit_log"`, each was
     * `OK (1 test, 88 assertions)` before {@see self::isAnAuditInsert()}, and each
     * is RED after it: "§9.2: an operation wrote an event type outside
     * AuditEvent::ALL." naming `b4.not_in_catalogue`.
     */
    #[Test]
    public function noOperationWritesAnEventOutsideTheCatalogue(): void
    {
        self::assertNotSame([], AuditEvent::ALL, 'The catalogue is empty, so the domain is vacuous.');

        $registry = TransactionBoundaryTest::contractOperations();
        $recipes = $this->successRecipes();
        $names = array_keys($registry);
        sort($names);
        $recipeNames = array_keys($recipes);
        sort($recipeNames);
        self::assertNotSame([], $names, 'The registry is empty, so there is no subject.');
        self::assertSame($names, $recipeNames, 'Every operation in the registry must be driven to success here, and only those.');

        // Every fixture before the first request.
        $prepared = [];
        foreach ($recipes as $name => [$prepare]) {
            $prepared[$name] = $prepare();
        }

        $before = $this->countAsOwnerForTenant(self::TENANT_A, 'SELECT count(*) FROM audit_log');
        $recorder = $this->recorder();
        $recorder->start();

        $failed = [];
        foreach ($recipes as $name => [, $drive]) {
            [$expected, $label] = $drive($prepared[$name]);
            if ($expected !== $this->httpStatus()) {
                $failed[] = \sprintf('%s (%s) answered %d, not %d: %s', $name, $label, $this->httpStatus(), $expected,
                    substr((string) $this->client()->getResponse()->getContent(), 0, 300));
            }
        }

        $this->actAs(self::TENANT_A, []);
        foreach ($registry as $name => $operation) {
            if (!\is_string($operation->getExtraProperties()[PermissionGate::OPERATION_PERMISSION] ?? null)) {
                continue;
            }
            $this->send((string) $operation->getMethod(),
                (string) preg_replace_callback('/\{[^}]+\}/', static fn (): string => Uuid::v4()->toRfc4122(), (string) $operation->getUriTemplate()),
                \in_array($operation->getMethod(), ['POST', 'PATCH'], true) ? [] : null);
            if (403 !== $this->httpStatus()) {
                $failed[] = $name.' (no permission) answered '.$this->httpStatus().', not 403';
            }
        }

        $recorder->stop();

        $attempted = [];
        foreach ($recorder->executions() as $execution) {
            if (self::isAnAuditInsert($execution['sql'])) {
                $attempted[] = self::eventTypeOf($execution['sql'], $execution['params']);
            }
        }

        self::assertNotSame([], $attempted, 'No audit write was captured, so the catalogue check had no subject.');
        self::assertSame([], array_values(array_unique(array_diff($attempted, AuditEvent::ALL))),
            '§9.2: an operation wrote an event type outside AuditEvent::ALL.');

        self::assertSame([], $failed, 'An operation was not driven down the path its writers are on.');
        self::assertSame(\count($attempted), $this->countAsOwnerForTenant(self::TENANT_A, 'SELECT count(*) FROM audit_log') - $before,
            'The captured audit writes are not the rows audit_log gained, so a write escaped the capture.');
    }

    /**
     * **Whether a statement inserts into `audit_log`, in any spelling PostgreSQL
     * accepts** (V1-R-8, V4 B4c): anywhere in the statement (so `WITH … INSERT`),
     * after SQL comments are removed (a leading `--` or block comment, or one
     * between the words), in any case, with the table bare, quoted, or qualified
     * by a bare or quoted `public`. Until 2026-09-16 the capture was
     * `^\s*INSERT\s+INTO\s+audit_log\b`, and a writer that swallowed a
     * CHECK-refused insert spelt any other way left neither a capture nor a row,
     * so the count control could not see it. **Not seen:** another schema on the
     * search path, a `U&"…"` identifier, an insert issued inside a function or a
     * `DO` block, and `COPY audit_log FROM`.
     */
    private static function isAnAuditInsert(string $sql): bool
    {
        return 1 === preg_match('/\bINSERT\s+INTO\s+(?:"?public"?\s*\.\s*)?"?audit_log"?(?![\w$])/i', self::withoutComments($sql));
    }

    /** `$sql` with `--` and block comments replaced by a space (string literals are not parsed; errs towards matching). */
    private static function withoutComments(string $sql): string
    {
        return (string) preg_replace('~--[^\n]*|/\*.*?\*/~s', ' ', $sql);
    }

    /**
     * The value an `INSERT INTO audit_log (…) VALUES (…)` puts in `event_type`:
     * the bound value at that placeholder, or the literal written there.
     *
     * @param array<int|string, mixed> $params
     */
    private static function eventTypeOf(string $sql, array $params): string
    {
        self::assertSame(1, preg_match('/audit_log"?\s*\(([^)]*)\)\s*VALUES\s*\((.*)\)\s*$/is', self::withoutComments($sql), $m), 'Unreadable audit INSERT: '.$sql);

        $columns = array_map(trim(...), explode(',', $m[1]));
        $position = array_search('event_type', $columns, true);
        self::assertIsInt($position, 'An audit INSERT names no event_type column: '.$sql);

        // Split the VALUES list on top-level commas; `gen_random_uuid()` and
        // `'{}'::jsonb` must each stay one expression.
        $expressions = [];
        $depth = 0;
        $current = '';
        foreach (str_split($m[2]) as $char) {
            $depth += '(' === $char ? 1 : (')' === $char ? -1 : 0);
            if (',' === $char && 0 === $depth) {
                $expressions[] = trim($current);
                $current = '';
                continue;
            }
            $current .= $char;
        }
        $expressions[] = trim($current);
        self::assertCount(\count($columns), $expressions, 'An audit INSERT has a different number of columns and values: '.$sql);

        $expression = $expressions[$position];
        if (1 === preg_match("/^'([^']*)'/", $expression, $literal)) {
            return $literal[1];
        }

        self::assertStringContainsString('?', $expression, 'event_type is neither bound nor a literal: '.$sql);
        $index = substr_count(implode(',', \array_slice($expressions, 0, $position)), '?');

        return (string) array_values($params)[$index];
    }

    private function purchaseOrderWriter(): PurchaseOrderAuditWriter
    {
        $writer = self::getContainer()->get(PurchaseOrderAuditWriter::class);
        \assert($writer instanceof PurchaseOrderAuditWriter);

        return $writer;
    }

    /** `audit_log_actor_fk` is composite on `(tenant_id, actor_user_id)`, so the principal has to be real. */
    private function seedActor(): string
    {
        $actor = Uuid::v4()->toRfc4122();

        $this->insertAsOwnerForTenant(self::TENANT_A, <<<'SQL'
            INSERT INTO app_user (id, tenant_id, email, password_hash, display_name)
            VALUES (?::uuid, ?::uuid, 'ip-probe@example.test', 'not-a-hash', 'Probe Actor')
            SQL, [$actor, self::TENANT_A]);

        return $actor;
    }

    /** @return list<array<string, mixed>> */
    private function trail(): array
    {
        return $this->fetchAsOwnerForTenant(self::TENANT_A,
            'SELECT event_type, host(ip_address) AS ip_address FROM audit_log ORDER BY occurred_at, id');
    }
}
