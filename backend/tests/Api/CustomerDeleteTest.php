<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Modules\Customers\Application\CustomerAddressWriter;
use App\Modules\Customers\Application\CustomerWriter;
use App\Modules\Sales\Application\CustomerOpenSalesWork;
use App\Modules\Sales\Infrastructure\Entity\SalesOrder;
use App\Modules\UserManagement\Domain\DefaultRoles;
use App\Platform\Http\Idempotency\IdempotencyKeyGuard;
use App\SharedKernel\ValueObject\AuditEvent;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Uid\Uuid;

/**
 * **C5 C4b — `DeleteCustomer`, `DELETE /customers/{id}`, `customer.delete`** (`docs/session13/c5-design.md` §3.4, §7.1,
 * Revision 1 R1.2 / R1.3; `c5-red.md` RED round 2 F-1 / F-2; `session29-c5-ruling.md` Q-1 = (b′), Q-3 = (i) + G-45;
 * `SupplierDeleteTest`'s twin, API-21 branch 3).
 *
 * 204, no body, `deleted_at` set; the customer stays readable (`GetCustomer`, `archived_at` set) and is listed on the
 * Archived view, not the live one; its addresses are kept. A repeated delete → 409 `customer.is_archived`; a foreign,
 * absent or malformed id → the same 404. **(b′)**, the first failing reason wins: a live sales order in `draft`,
 * `confirmed` or `partially_fulfilled` → `customer.has_open_sales_orders`; a shipment with something to bill and no live
 * invoice → `customer.has_unbilled_shipments`; an `issued`/`overdue` invoice not credited in full →
 * `customer.has_unpaid_invoices`; a return owed a credit, paid invoice or not → `customer.is_owed_a_credit` — each naming
 * its count. One `customer.deleted` row, `{}`, on success and none on any refusal. Copy is `UX`'s final (`owed-ux-c5.md` FINAL (a1)); `SEC`
 * S-2 kept the count (`owed-sec-c5.md` §2.3).
 *
 * **G-45** (ruling Q-3 (i)): (b′) is a check at the moment of archiving. Afterwards `ReverseInvoicePayment`, an owner's
 * `VoidInvoice`, a new `IssueInvoice` and `CreateSalesReturn` all succeed on the archived customer — pinned here so a later
 * refusal is a decision, not a surprise.
 *
 * **The races (§7.1), real second connections (`hold_order_lock.php`), each failing if the request never waited:** a
 * delete holding the customer ({@see CustomerWriter::DELETE_LOCK} + {@see CustomerWriter::ARCHIVE}, the writer's own
 * statements) against `CreateSalesOrder`, `UpdateSalesOrder` moving a draft onto it, `CreateCustomerAddress`,
 * `UpdateCustomerAddress` and `UpdateCustomer` — each waits and is then refused; and a sales order created (or moved onto
 * the customer) first, against the delete — which waits and then counts it. Mutations: M12 (`FOR UPDATE` → `FOR NO KEY
 * UPDATE`) reddens the order-first races and the delete-first order and address races; C3-M1 (drop Sales' customer
 * `FOR KEY SHARE`) reddens the delete-first order races (the FK check still waits, then lands an order on an archived
 * customer); dropping the address writers' `FOR KEY SHARE` reddens the address races.
 */
final class CustomerDeleteTest extends PurchaseOrderTestCase
{
    use InvoiceFixtures;
    use CreditFixtures;
    use CreditNoteFixtures;

    private const HOLDER_DEADLINE_SECONDS = 10.0;

    /** The customer liveness read `SalesOrderWriter` takes (C5 C3, `PARENT_LIVENESS_READS`), transcribed for the order-first holders. */
    private const SALES_CUSTOMER_KEY_SHARE = 'SELECT 1 FROM customer WHERE id = ?::uuid AND deleted_at IS NULL FOR KEY SHARE';

    /** A sales rep archives a customer with no open work: 204, no body; readable, on the Archived view, addresses kept. */
    #[Test]
    public function aSalesRepArchivesACustomerWithNoOpenWork(): void
    {
        $id = $this->seedCustomer(self::TENANT_A, 'Leaving Customer');
        $address = $this->seedCustomerAddress(self::TENANT_A, $id);
        $before = $this->fetchAsOwnerForTenant(self::TENANT_A, 'SELECT * FROM customer_address WHERE id = ?::uuid', [$address]);
        $this->actAs(self::TENANT_A, DefaultRoles::codesFor(DefaultRoles::SALES_REP));

        $this->delete($id);

        self::assertSame(204, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        self::assertSame('', (string) $this->client()->getResponse()->getContent());
        self::assertNotNull($this->stored($id)['deleted_at']);

        $this->send('GET', '/customers/'.$id);
        self::assertSame(200, $this->httpStatus());
        self::assertNotNull($this->json()['archived_at'], 'An archived customer is read with archived_at set (§5.2a).');
        self::assertNotContains('Leaving Customer', array_column($this->listed(''), 'name'), 'An archived customer is still on the live list.');
        self::assertContains('Leaving Customer', array_column($this->listed('?archived=true'), 'name'), 'An archived customer is not on the Archived view.');
        self::assertSame($before, $this->fetchAsOwnerForTenant(self::TENANT_A, 'SELECT * FROM customer_address WHERE id = ?::uuid', [$address]),
            'Archiving touched an address (design §4.1: no cascade).');
    }

    /** E9-3 end to end: what `DeleteCustomer` archives, `RestoreCustomer` brings back, and the Archived view shows between. */
    #[Test]
    public function anArchivedCustomerIsRestoredByTheOperationThatPairsWithIt(): void
    {
        $id = $this->seedCustomer(self::TENANT_A, 'Round Trip Customer');

        $this->delete($id);
        self::assertSame(204, $this->httpStatus());
        $this->send('POST', '/customers/'.$id.'/restoration');
        self::assertSame(200, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        self::assertNull($this->json()['archived_at']);
        $this->delete($id);
        self::assertSame(204, $this->httpStatus(), 'A restored customer could not be archived again.');
    }

    /** ST-9: a repeated delete is refused, not replayed (§4.2 `Idem` `no`), and writes nothing. */
    #[Test]
    public function aRepeatedDeleteIsRefusedBecauseTheCustomerIsArchived(): void
    {
        $id = $this->seedCustomer(self::TENANT_A, 'Twice Deleted Customer');

        $this->delete($id);
        self::assertSame(204, $this->httpStatus());
        $before = $this->stored($id);

        $this->delete($id);
        self::assertSame(409, $this->httpStatus());
        self::assertSame('customer.is_archived', $this->json()['rule']);
        self::assertSame(CustomerWriter::ALREADY_ARCHIVED, $this->json()['detail']);
        self::assertSame('This customer is already archived. It\'s in the archived customers.', $this->json()['detail']);
        self::assertSame($before, $this->stored($id), 'A refused delete wrote the row.');
        self::assertCount(1, $this->auditRowsOf(AuditEvent::CUSTOMER_DELETED), 'A refused delete wrote a trail row.');
    }

    /** ST-5: a foreign, absent or malformed id is the same 404 as `GetCustomer`'s, and nothing is written. */
    #[Test]
    public function aDeleteOfAnUnknownCustomerIsTheSame404(): void
    {
        $theirs = $this->seedCustomer(self::TENANT_B, 'Theirs');
        $bodies = [];
        foreach ([$theirs, Uuid::v4()->toRfc4122(), 'not-a-uuid'] as $id) {
            $this->delete($id);
            self::assertSame(404, $this->httpStatus(), $id);
            $bodies[] = (string) $this->client()->getResponse()->getContent();
        }
        self::assertSame($bodies[0], $bodies[1]);
        self::assertSame(0, $this->countAsOwnerForTenant(self::TENANT_B, "SELECT count(*) FROM customer WHERE name = 'Theirs' AND deleted_at IS NOT NULL"));
        self::assertSame([], $this->auditRowsOf(AuditEvent::CUSTOMER_DELETED));
    }

    /** One `customer.deleted`, `{}`, naming the deleter; the subject is the customer (business, PC-11 (4a), no IP). */
    #[Test]
    public function aDeleteWritesExactlyOneCustomerDeletedRow(): void
    {
        $id = $this->seedCustomer(self::TENANT_A, 'Audited Leaver');
        $this->actAs(self::TENANT_A, DefaultRoles::codesFor(DefaultRoles::ADMIN));

        $this->delete($id);

        self::assertSame(204, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        self::assertSame([$this->businessRow(AuditEvent::CUSTOMER_DELETED, 'customer', $id)], $this->auditRowsOf(AuditEvent::CUSTOMER_DELETED));
        self::assertSame([], $this->auditRowsOf(AuditEvent::CUSTOMER_UPDATED), 'A delete wrote an update row.');
    }

    /** `customer.delete` gates the delete (§4.2): `viewer` and a holder of `customer.edit` alone are refused, and nothing moves. */
    #[Test]
    public function onlyAHolderOfCustomerDeleteMayArchive(): void
    {
        $id = $this->seedCustomer(self::TENANT_A, 'Guarded Leaver');

        foreach ([DefaultRoles::codesFor(DefaultRoles::VIEWER), ['customer.view', 'customer.edit']] as $codes) {
            $this->actAs(self::TENANT_A, $codes);
            $this->delete($id);
            self::assertSame(403, $this->httpStatus());
        }
        self::assertNull($this->stored($id)['deleted_at']);
        self::assertSame([], $this->auditRowsOf(AuditEvent::CUSTOMER_DELETED));
    }

    // ---- (b′): the four legs ---------------------------------------------------------------------------------------

    /**
     * Leg 1 (R1.2): each open status blocks — `draft`, `confirmed`, `partially_fulfilled` — and the refusal counts every
     * open order of the customer, never another customer's.
     */
    #[Test]
    public function aCustomerWithOpenSalesOrdersCannotBeArchived(): void
    {
        foreach ([SalesOrder::STATUS_DRAFT, SalesOrder::STATUS_CONFIRMED, SalesOrder::STATUS_PARTIALLY_FULFILLED] as $status) {
            $customer = $this->seedCustomer(self::TENANT_A, 'Open '.$status);
            $this->seedSalesOrder(self::TENANT_A, self::freshNumber('SO'), $customer, status: $status);

            $this->delete($customer);

            self::assertSame(409, $this->httpStatus(), $status.': '.$this->client()->getResponse()->getContent());
            self::assertSame('customer.has_open_sales_orders', $this->json()['rule'], $status);
            self::assertSame('This customer still has 1 open sales order, so it can\'t be deleted until each open order is shipped in full or cancelled. A draft can simply be deleted.', $this->json()['detail'], $status);
            self::assertNull($this->stored($customer)['deleted_at'], $status);
        }

        $many = $this->seedCustomer(self::TENANT_A, 'Many Open');
        $this->seedSalesOrder(self::TENANT_A, self::freshNumber('SO'), $many);
        $this->seedSalesOrder(self::TENANT_A, self::freshNumber('SO'), $many, status: SalesOrder::STATUS_CONFIRMED);
        // Another customer's open order is not this customer's work.
        $this->seedSalesOrder(self::TENANT_A, self::freshNumber('SO'), $this->seedCustomer(self::TENANT_A, 'Bystander'));

        $this->delete($many);
        self::assertSame(409, $this->httpStatus());
        self::assertSame(\sprintf(CustomerWriter::HAS_OPEN_SALES_ORDERS, CustomerWriter::openSalesOrders(2)), $this->json()['detail']);
        self::assertSame([], $this->auditRowsOf(AuditEvent::CUSTOMER_DELETED), 'A refused delete wrote a trail row.');
    }

    /** Leg 1's "open": `fulfilled` (nothing shipped unbilled), `cancelled` and a deleted draft are closed, so they do not block. */
    #[Test]
    public function closedAndDeletedSalesOrdersDoNotBlockArchiving(): void
    {
        $customer = $this->seedCustomer(self::TENANT_A, 'Only Closed Orders');
        $this->seedSalesOrder(self::TENANT_A, self::freshNumber('SO'), $customer, status: SalesOrder::STATUS_FULFILLED, orderedAt: '2026-09-01T10:00:00Z');
        $this->seedSalesOrder(self::TENANT_A, self::freshNumber('SO'), $customer, status: SalesOrder::STATUS_CANCELLED);
        $deleted = $this->seedSalesOrder(self::TENANT_A, self::freshNumber('SO'), $customer);
        $this->executeAsOwnerForTenant(self::TENANT_A, 'UPDATE sales_order SET deleted_at = now() WHERE id = ?::uuid', [$deleted]);

        $this->delete($customer);

        self::assertSame(204, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        // The documents keep naming the archived customer (G-38's live join): nothing is rewritten.
        self::assertSame(3, $this->countAsOwnerForTenant(self::TENANT_A, 'SELECT count(*) FROM sales_order WHERE customer_id = ?::uuid', [$customer]));
    }

    /** Leg 2 (mutation M18): a shipment with something to bill and no live invoice blocks, though its order is fulfilled. */
    #[Test]
    public function aShipmentNotYetInvoicedBlocksArchiving(): void
    {
        $s = $this->shipped('UNBILLED', [['1', '10.0000', '1']]);
        self::assertSame(SalesOrder::STATUS_FULFILLED, $this->statusOf($s['order']), 'Fixture: the order should be closed, so only leg 2 can refuse.');

        $this->delete($s['customer']);

        self::assertSame(409, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        self::assertSame('customer.has_unbilled_shipments', $this->json()['rule']);
        self::assertSame(\sprintf(CustomerWriter::HAS_UNBILLED_SHIPMENTS, CustomerWriter::shipments(1)), $this->json()['detail']);
        self::assertSame('This customer has 1 shipment waiting to be invoiced, so it can\'t be deleted until every shipment is invoiced.', $this->json()['detail']);
    }

    /**
     * Leg 2's "billable" is the issuer's own `billed(fl) > 0` (F-1; M19′): a shipment returned in full before invoicing has
     * nothing to bill — `IssueInvoice` refuses it — so it does not block (RED H-1: blocking on it would be a dead end).
     */
    #[Test]
    public function aShipmentReturnedInFullBeforeInvoicingDoesNotBlock(): void
    {
        $s = $this->invoicedShipment('RETURNED-ALL', [['2', '10.0000', '2']], issue: false);
        $customer = $this->customerOf($s['order']);
        $this->actAsRole(DefaultRoles::WAREHOUSE_OPERATOR);
        $this->returnOf($s, [[0, '2']]);
        self::assertTrue($this->json()['deducts'], 'Fixture: a return recorded before the invoice deducts (Q-L (a′)).');
        $this->actAsRole(DefaultRoles::SALES_REP);

        $this->delete($customer);

        self::assertSame(204, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
    }

    /** Leg 3 (R1.2): an issued invoice not credited in full blocks. */
    #[Test]
    public function anUnpaidInvoiceBlocksArchiving(): void
    {
        $i = $this->issuedInvoice('UNPAID');

        $this->delete($i['shipment']['customer']);

        self::assertSame(409, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        self::assertSame('customer.has_unpaid_invoices', $this->json()['rule']);
        self::assertSame(\sprintf(CustomerWriter::HAS_UNPAID_INVOICES, CustomerWriter::unpaidInvoices(1)), $this->json()['detail']);
        // The literal beside the constant (`owed-ux-c5.md` FINAL (a1) `+`): a wording change shows up as an edit here.
        self::assertSame('This customer has 1 unpaid invoice, so it can\'t be deleted until every invoice is paid or fully credited.', $this->json()['detail']);
    }

    /** Leg 3 spelled as `InvoiceCredit` spells unpaid (mutation M18′): a STORED `overdue` invoice blocks too. */
    #[Test]
    public function anOverdueInvoiceBlocksArchiving(): void
    {
        $customer = $this->seedCustomer(self::TENANT_A, 'Overdue Customer');
        $order = $this->seedSalesOrder(self::TENANT_A, self::freshNumber('SO'), $customer, status: SalesOrder::STATUS_FULFILLED, orderedAt: '2026-08-01T10:00:00Z');
        $this->seedInvoiceRow($this->seedBareFulfilment($order), self::freshNumber('INV'), 'overdue', '10.0000');

        $this->delete($customer);

        self::assertSame(409, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        self::assertSame('customer.has_unpaid_invoices', $this->json()['rule']);
    }

    /**
     * Leg 3's "not credited in full" (RED H-1): an issued invoice credited in full is terminal — `PayInvoice` and
     * `VoidInvoice` both refuse it — so it does not block (M18′'s other half: `status = 'issued'` alone would be a dead end).
     */
    #[Test]
    public function anInvoiceCreditedInFullDoesNotBlock(): void
    {
        $i = $this->issuedInvoice('CREDITED-FULL');
        $this->seedCreditNote($i['invoice'], '10.0000');

        $this->delete($i['shipment']['customer']);

        self::assertSame(204, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
    }

    /**
     * Leg 4 (RED H-2; mutations M20, M21): a return owed a credit on a PAID invoice (G-23's queue, `blockedByPayment`) blocks.
     * `creditable()` alone would miss it — the paid invoice makes it not creditable.
     */
    #[Test]
    public function aReturnOwedACreditOnAPaidInvoiceBlocksArchiving(): void
    {
        $s = $this->invoicedShipment('OWED-PAID', [['1', '10.0000', '1']]);
        $customer = $this->customerOf($s['order']);
        $this->actAsRole(DefaultRoles::FINANCE);
        $this->send('POST', '/invoices/'.$s['invoice'].'/payment', []);
        self::assertSame(200, $this->httpStatus(), 'Fixture: the payment. '.(string) $this->client()->getResponse()->getContent());
        $this->actAsRole(DefaultRoles::WAREHOUSE_OPERATOR);
        $this->returnOf($s, [[0, '1']]);
        $this->actAsRole(DefaultRoles::SALES_REP);

        $this->delete($customer);

        self::assertSame(409, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        self::assertSame('customer.is_owed_a_credit', $this->json()['rule']);
        self::assertSame(\sprintf(CustomerWriter::IS_OWED_A_CREDIT, CustomerWriter::returns(1)), $this->json()['detail']);
        self::assertSame('This customer is still owed a credit for 1 return, so it can\'t be deleted until each return is credited. A return on a paid invoice can be credited only after that payment is reversed.', $this->json()['detail']);
    }

    /**
     * The refusal order (R1.2): orders → unbilled → owed by the customer → owed to the customer. A partly shipped order is
     * open AND its shipment is unbilled → orders win; a creditable return on an unpaid invoice is owed both ways → the unpaid
     * invoice wins.
     */
    #[Test]
    public function theFirstFailingReasonIsTheOneReported(): void
    {
        $partly = $this->shipped('PARTLY', [['2', '10.0000', '1']]);
        self::assertSame(SalesOrder::STATUS_PARTIALLY_FULFILLED, $this->statusOf($partly['order']));
        $creditable = $this->invoicedShipment('CREDITABLE', [['1', '10.0000', '1']]);
        $this->actAsRole(DefaultRoles::WAREHOUSE_OPERATOR);
        $this->returnOf($creditable, [[0, '1']]);
        $this->actAsRole(DefaultRoles::SALES_REP);

        $this->delete($partly['customer']);
        self::assertSame(409, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        self::assertSame('customer.has_open_sales_orders', $this->json()['rule'], 'An open order is reported before its unbilled shipment.');

        $this->delete($this->customerOf($creditable['order']));
        self::assertSame(409, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        self::assertSame('customer.has_unpaid_invoices', $this->json()['rule'], 'An unpaid invoice is reported before the credit owed on it.');
    }

    /**
     * **Mutation M17 — the four legs are ONE statement** (one READ COMMITTED snapshot: separate counts could each miss a
     * shipment a concurrent `VoidInvoice` moves between "billed" and "unbilled"). Observed, not read: the request's own SQL
     * is recorded, and exactly one statement touches Sales' tables — `CustomerOpenSalesWork::statement()` itself.
     */
    #[Test]
    public function theOpenWorkIsCountedInOneStatement(): void
    {
        $customer = $this->seedCustomer(self::TENANT_A, 'One Snapshot');
        $this->seedSalesOrder(self::TENANT_A, self::freshNumber('SO'), $customer);

        $this->recorder()->start();
        $this->delete($customer);
        $this->recorder()->stop();

        self::assertSame(409, $this->httpStatus());
        $sales = $this->recorder()->matching('/\b(sales_order|fulfilment|invoice|sales_return)\b/i');
        self::assertSame([CustomerOpenSalesWork::statement()], $sales,
            'DeleteCustomer read Sales\' tables in more than one statement (or not through the port\'s statement): the four legs must share one snapshot.');
        self::assertSame(4, substr_count(CustomerOpenSalesWork::statement(), 'count(*)'));
    }

    // ---- G-45 (ruling Q-3 (i)) ---------------------------------------------------------------------------------------

    /**
     * **G-45's mechanism** (RED round 2 F-2): (b′) checks open work at the moment of archiving only. On a customer archived
     * by `DeleteCustomer`, the bank's reversal (`ReverseInvoicePayment` → 200), an owner's void (B′), a NEW invoice to the
     * archived customer (`IssueInvoice` → 201) and goods coming back (`CreateSalesReturn` → 201) are all accepted — none
     * reads customer liveness. A later refusal of any of these is a decision (owner `PO`), not a surprise.
     */
    #[Test]
    public function workCanReopenOnAnArchivedCustomerAndIsNotRefused(): void
    {
        $s = $this->invoicedShipment('G45', [['1', '10.0000', '1']]);
        $customer = $this->customerOf($s['order']);
        $this->actAsRole(DefaultRoles::FINANCE);
        $this->send('POST', '/invoices/'.$s['invoice'].'/payment', []);
        self::assertSame(200, $this->httpStatus(), 'Fixture: the payment. '.(string) $this->client()->getResponse()->getContent());

        $this->actAsRole(DefaultRoles::SALES_REP);
        $this->delete($customer);
        self::assertSame(204, $this->httpStatus(), 'A customer with only a paid invoice is square: '.(string) $this->client()->getResponse()->getContent());

        // A second finance user (the reverser is not the recorder, ADR-008 A2.3).
        $this->actAsRole(DefaultRoles::FINANCE);
        $this->send('POST', '/invoices/'.$s['invoice'].'/payment-reversal', ['reason' => 'The bank returned the payment.']);
        self::assertSame(200, $this->httpStatus(), 'G-45: ReverseInvoicePayment on an archived customer. '.(string) $this->client()->getResponse()->getContent());

        $this->actAsRole(DefaultRoles::OWNER);
        $this->send('POST', '/invoices/'.$s['invoice'].'/void', ['reason' => 'Reissue after the reversal.']);
        self::assertSame(200, $this->httpStatus(), 'G-45: an owner\'s VoidInvoice on an archived customer. '.(string) $this->client()->getResponse()->getContent());

        $this->actAsRole(DefaultRoles::SALES_REP);
        $this->issue($s['fulfilment']);
        self::assertSame(201, $this->httpStatus(), 'G-45: IssueInvoice to an archived customer. '.(string) $this->client()->getResponse()->getContent());

        $this->actAsRole(DefaultRoles::WAREHOUSE_OPERATOR);
        $this->returnOf($s, [[0, '1']]);
        self::assertSame(201, $this->httpStatus());

        self::assertNotNull($this->stored($customer)['deleted_at'], 'The customer is still archived: nothing restored it.');
    }

    // ---- races: the delete first ------------------------------------------------------------------------------------

    /** Delete first ∥ `CreateSalesOrder`: the create's customer `FOR KEY SHARE` waits, re-reads, and is refused (C3-M1, M12). */
    #[Test]
    public function aSalesOrderCreatedWhileItsCustomerIsBeingArchivedIsRefused(): void
    {
        $customer = $this->seedCustomer(self::TENANT_A, 'Archiving Under Create');
        $address = $this->seedCustomerAddress(self::TENANT_A, $customer);
        $warehouse = $this->seedWarehouse(self::TENANT_A, 'WH so race create');

        $holder = $this->raceAgainstAHolder($this->theDeletesOwnStatements($customer), function () use ($customer, $address, $warehouse): void {
            $this->assertTheRequestConnectionIsReadCommitted();
            $this->send('POST', '/sales-orders', ['customer_id' => $customer, 'warehouse_id' => $warehouse, 'currency_code' => 'EUR', 'ship_to_address_id' => $address],
                [IdempotencyKeyGuard::HEADER => Uuid::v4()->toRfc4122()]);
        });

        self::assertTrue($holder['waiter_seen'], 'The sales-order create never waited on the delete. '.json_encode($holder));
        self::assertTrue($holder['committed'], 'The holder did not commit: '.json_encode($holder));
        self::assertSame(422, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        self::assertContains(['pointer' => '/customer_id', 'code' => 'unknown_reference'], $this->pointersAndCodes());
        self::assertSame(0, $this->countAsOwnerForTenant(self::TENANT_A, 'SELECT count(*) FROM sales_order WHERE customer_id = ?::uuid', [$customer]),
            'A sales order landed on a customer archived under it.');
    }

    /** Delete first ∥ `UpdateSalesOrder` moving a draft onto the customer: waits, re-reads, refused (C3-M1, M12). */
    #[Test]
    public function aDraftMovedOntoACustomerBeingArchivedIsRefused(): void
    {
        $from = $this->seedCustomer(self::TENANT_A, 'Draft Owner');
        $draft = $this->seedSalesOrder(self::TENANT_A, self::freshNumber('SO'), $from);
        $target = $this->seedCustomer(self::TENANT_A, 'Archiving Under Move');
        $address = $this->seedCustomerAddress(self::TENANT_A, $target);

        $holder = $this->raceAgainstAHolder($this->theDeletesOwnStatements($target), function () use ($draft, $target, $address): void {
            $this->send('PATCH', '/sales-orders/'.$draft, ['customer_id' => $target, 'ship_to_address_id' => $address]);
        });

        self::assertTrue($holder['waiter_seen'], 'The sales-order edit never waited on the delete. '.json_encode($holder));
        self::assertTrue($holder['committed'], 'The holder did not commit: '.json_encode($holder));
        self::assertSame(422, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        self::assertContains(['pointer' => '/customer_id', 'code' => 'unknown_reference'], $this->pointersAndCodes());
        self::assertSame($from, $this->customerOf($draft), 'The draft moved onto a customer archived under it.');
    }

    /** Delete first ∥ `CreateCustomerAddress`: its parent read is `FOR KEY SHARE` since C4b, so it waits and is refused. */
    #[Test]
    public function anAddressAddedWhileTheCustomerIsBeingArchivedIsRefused(): void
    {
        $customer = $this->seedCustomer(self::TENANT_A, 'Archiving Under Address Add');

        $holder = $this->raceAgainstAHolder($this->theDeletesOwnStatements($customer), function () use ($customer): void {
            $this->send('POST', '/customers/'.$customer.'/addresses',
                ['kind' => 'shipping', 'line' => '1 Race Street', 'city' => 'London', 'postal_code' => 'EC1A 1BB', 'country_code' => 'GB'],
                [IdempotencyKeyGuard::HEADER => Uuid::v4()->toRfc4122()]);
        });

        $this->assertWaitedThenRefusedAsArchived($holder, CustomerAddressWriter::ARCHIVED_ON_CREATE);
        self::assertSame(0, $this->countAsOwnerForTenant(self::TENANT_A, 'SELECT count(*) FROM customer_address WHERE customer_id = ?::uuid', [$customer]));
    }

    /** Delete first ∥ `UpdateCustomerAddress`: `FOR KEY SHARE` since C4b, so it waits and the address is unchanged. */
    #[Test]
    public function anAddressChangedWhileTheCustomerIsBeingArchivedIsRefused(): void
    {
        $customer = $this->seedCustomer(self::TENANT_A, 'Archiving Under Address Change');
        $address = $this->seedCustomerAddress(self::TENANT_A, $customer);

        $holder = $this->raceAgainstAHolder($this->theDeletesOwnStatements($customer), function () use ($customer, $address): void {
            $this->send('PATCH', '/customers/'.$customer.'/addresses/'.$address, ['city' => 'Leeds']);
        });

        $this->assertWaitedThenRefusedAsArchived($holder, CustomerAddressWriter::ARCHIVED_ON_UPDATE);
        self::assertSame('London', $this->fetchAsOwnerForTenant(self::TENANT_A, 'SELECT city FROM customer_address WHERE id = ?::uuid', [$address])[0]['city']);
    }

    /** Delete first ∥ `UpdateCustomer`: its `FOR NO KEY UPDATE` conflicts with `FOR UPDATE`; it waits, then sees the archive. */
    #[Test]
    public function aCustomerEditWhileItIsBeingArchivedIsRefused(): void
    {
        $customer = $this->seedCustomer(self::TENANT_A, 'Archiving Under Edit');

        $holder = $this->raceAgainstAHolder($this->theDeletesOwnStatements($customer), function () use ($customer): void {
            $this->send('PATCH', '/customers/'.$customer, ['payment_terms_days' => 45]);
        });

        $this->assertWaitedThenRefusedAsArchived($holder, CustomerWriter::ARCHIVED);
        self::assertSame([], $this->auditRowsOf(AuditEvent::CUSTOMER_UPDATED));
    }

    // ---- races: the order first -------------------------------------------------------------------------------------

    /**
     * A sales order created first ∥ the delete: the order's customer lock (its liveness read's `FOR KEY SHARE`, then its
     * FK check) makes the delete's `FOR UPDATE` wait; the count, a later statement under READ COMMITTED, sees the committed
     * draft and refuses (mutation M12: under `FOR NO KEY UPDATE` the delete never waits — red).
     */
    #[Test]
    public function aDeleteRacingASalesOrderCreateCountsTheOrder(): void
    {
        $customer = $this->seedCustomer(self::TENANT_A, 'Ordered Meanwhile');
        $warehouse = $this->seedWarehouse(self::TENANT_A, 'WH so order first');

        $holder = $this->raceAgainstAHolder([
            [self::SALES_CUSTOMER_KEY_SHARE, [$customer]],
            $this->aSalesOrderInsert($customer, $warehouse),
        ], function () use ($customer): void {
            $this->assertTheRequestConnectionIsReadCommitted();
            $this->delete($customer);
        });

        $this->assertTheDeleteWaitedAndCountedOneOrder($holder, $customer);
    }

    /** A draft moved onto the customer first ∥ the delete: the delete waits on the move's customer lock, then counts it. */
    #[Test]
    public function aDeleteRacingADraftMovedOntoTheCustomerCountsTheDraft(): void
    {
        $draft = $this->seedSalesOrder(self::TENANT_A, self::freshNumber('SO'), $this->seedCustomer(self::TENANT_A, 'Previous Owner'));
        $target = $this->seedCustomer(self::TENANT_A, 'Moved Onto Meanwhile');

        $holder = $this->raceAgainstAHolder([
            ['SELECT 1 FROM sales_order WHERE id = ?::uuid FOR NO KEY UPDATE', [$draft]],
            [self::SALES_CUSTOMER_KEY_SHARE, [$target]],
            ['UPDATE sales_order SET customer_id = ?::uuid, updated_at = now() WHERE id = ?::uuid', [$target, $draft]],
        ], function () use ($target): void {
            $this->delete($target);
        });

        $this->assertTheDeleteWaitedAndCountedOneOrder($holder, $target);
    }

    // -----------------------------------------------------------------------

    private function delete(string $id): void
    {
        $this->send('DELETE', '/customers/'.$id);
    }

    /** @return list<array{pointer: string, code: string}> */
    private function pointersAndCodes(): array
    {
        return array_map(static fn (array $e): array => ['pointer' => $e['pointer'], 'code' => $e['code']], $this->json()['errors'] ?? []);
    }

    /** @return list<array<string, mixed>> */
    private function listed(string $query): array
    {
        $this->send('GET', '/customers'.$query);
        self::assertSame(200, $this->httpStatus());

        return $this->json()['items'];
    }

    /** @return array{deleted_at: ?string, updated_at: string} */
    private function stored(string $id): array
    {
        return $this->fetchAsOwnerForTenant(self::TENANT_A, 'SELECT deleted_at, updated_at FROM customer WHERE id = ?::uuid', [$id])[0];
    }

    private function customerOf(string $orderId): string
    {
        return (string) $this->fetchAsOwnerForTenant(self::TENANT_A, 'SELECT customer_id::text AS c FROM sales_order WHERE id = ?::uuid', [$orderId])[0]['c'];
    }

    private function statusOf(string $orderId): string
    {
        return (string) $this->fetchAsOwnerForTenant(self::TENANT_A, 'SELECT status FROM sales_order WHERE id = ?::uuid', [$orderId])[0]['status'];
    }

    /**
     * The delete's own two statements — THE WRITER'S constants, never a transcription — so a mutation of either is what the
     * delete-first races hold.
     *
     * @return list<array{0: string, 1: list<string>}>
     */
    private function theDeletesOwnStatements(string $customer): array
    {
        return [[CustomerWriter::DELETE_LOCK, [$customer]], [CustomerWriter::ARCHIVE, [$customer]]];
    }

    /**
     * A draft sales order as `seedSalesOrder()` writes it, run by the holder as `erp_app` (table-level INSERT, measured).
     *
     * @return array{0: string, 1: list<string>}
     */
    private function aSalesOrderInsert(string $customer, string $warehouse): array
    {
        return [<<<'SQL'
            INSERT INTO sales_order (id, tenant_id, order_number, customer_id, warehouse_id, currency_code, ship_to_country_code,
                                     status, idempotency_key, request_digest)
            VALUES (?::uuid, ?::uuid, ?, ?::uuid, ?::uuid, 'EUR', 'GB', 'draft', ?, ?)
            SQL, [
            Uuid::v4()->toRfc4122(),
            self::TENANT_A,
            self::freshNumber('SO'),
            $customer,
            $warehouse,
            hash('sha256', 'key:'.Uuid::v4()->toRfc4122()),
            hash('sha256', 'digest:'.Uuid::v4()->toRfc4122()),
        ]];
    }

    /** @param array{waiter_seen: bool, committed: bool, sqlstate: ?string, error: ?string} $holder */
    private function assertWaitedThenRefusedAsArchived(array $holder, string $sentence): void
    {
        self::assertTrue($holder['waiter_seen'], 'The request never waited on the delete. '.json_encode($holder));
        self::assertTrue($holder['committed'], 'The holder did not commit: '.json_encode($holder));
        self::assertSame(409, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        self::assertSame('customer.is_archived', $this->json()['rule']);
        self::assertSame($sentence, $this->json()['detail']);
    }

    /** @param array{waiter_seen: bool, committed: bool, sqlstate: ?string, error: ?string} $holder */
    private function assertTheDeleteWaitedAndCountedOneOrder(array $holder, string $customer): void
    {
        self::assertTrue($holder['waiter_seen'], 'The delete never waited on the order: its row lock is not FOR UPDATE. '.json_encode($holder));
        self::assertTrue($holder['committed'], 'The holder did not commit: '.json_encode($holder));
        self::assertSame(409, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        self::assertSame('customer.has_open_sales_orders', $this->json()['rule']);
        self::assertSame(\sprintf(CustomerWriter::HAS_OPEN_SALES_ORDERS, CustomerWriter::openSalesOrders(1)), $this->json()['detail']);
        self::assertNull($this->stored($customer)['deleted_at']);
        self::assertSame([], $this->auditRowsOf(AuditEvent::CUSTOMER_DELETED));
    }

    /** §5.2.5's precedent (`PurchaseOrderRowLockTest`): the re-reads are correct only under READ COMMITTED — asserted, not assumed. */
    private function assertTheRequestConnectionIsReadCommitted(): void
    {
        $em = self::getContainer()->get('doctrine.orm.entity_manager');
        \assert($em instanceof EntityManagerInterface);
        self::assertSame('read committed', $em->getConnection()->fetchOne('SHOW transaction_isolation'),
            'The request connection is not READ COMMITTED; the delete\'s count and the readers\' re-checks are only correct under it.');
    }

    /**
     * `SupplierDeleteTest::raceAgainstAHolder`'s shape, a sixth copy (not extracted, the earlier copies' reason): the
     * holder's own lock is on a random `sales_order` id, so it matches no row; the real locks are `HOLD_BEFORE`'s.
     *
     * @param list<array{0: string, 1: list<string>}> $before
     *
     * @return array{waiter_seen: bool, committed: bool, sqlstate: ?string, error: ?string}
     */
    private function raceAgainstAHolder(array $before, callable $request): array
    {
        $readyFile = tempnam(sys_get_temp_dir(), 'erp_customer_delete_');
        file_put_contents($readyFile, '');

        $proc = proc_open(
            [PHP_BINARY, __DIR__.'/support/hold_order_lock.php'],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            null,
            [
                'APP_DIRECT_DATABASE_URL' => $_ENV['APP_DIRECT_DATABASE_URL'],
                'HOLD_TENANT' => self::TENANT_A,
                'HOLD_ORDER' => Uuid::v4()->toRfc4122(),
                'HOLD_TABLE' => 'sales_order',
                'HOLD_BEFORE' => json_encode($before, \JSON_THROW_ON_ERROR),
                'HOLD_AFTER' => json_encode([], \JSON_THROW_ON_ERROR),
                'HOLD_READY_FILE' => $readyFile,
                'HOLD_DEADLINE_SECONDS' => (string) self::HOLDER_DEADLINE_SECONDS,
                'PATH' => (string) getenv('PATH'),
            ],
        );
        self::assertIsResource($proc);

        try {
            $deadline = microtime(true) + self::HOLDER_DEADLINE_SECONDS;
            while ('' === trim((string) file_get_contents($readyFile))) {
                if (microtime(true) > $deadline) {
                    self::fail('The holder never took the lock: '.stream_get_contents($pipes[2]));
                }
                usleep(1000);
            }
            if ('failed' === trim((string) file_get_contents($readyFile))) {
                self::fail('The holder failed before taking the lock: '.stream_get_contents($pipes[1]).stream_get_contents($pipes[2]));
            }

            $request();

            $out = (string) stream_get_contents($pipes[1]);
            $err = (string) stream_get_contents($pipes[2]);
        } finally {
            if (proc_get_status($proc)['running']) {
                proc_terminate($proc);
            }
            foreach ($pipes as $pipe) {
                if (\is_resource($pipe)) {
                    fclose($pipe);
                }
            }
            proc_close($proc);
            @unlink($readyFile);
        }

        $report = json_decode(trim($out), true);
        self::assertIsArray($report, 'The holder printed no report. stdout: '.$out.' stderr: '.$err);

        return $report;
    }
}
