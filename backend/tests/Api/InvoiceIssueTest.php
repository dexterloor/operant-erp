<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Modules\Sales\Infrastructure\Entity\SalesOrder;
use App\Modules\UserManagement\Domain\DefaultRoles;
use App\SharedKernel\ValueObject\AuditEvent;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Uid\Uuid;

/**
 * **`IssueInvoice` and `GetInvoice` — sales step 6, C3** (design §19 C3; owner rulings Q1, Q2, Q5, Q9, Q12 of
 * 2026-09-23 and CD7-1 of 2026-09-28).
 *
 * Every fulfilment here is shipped THROUGH THE API (`CreateFulfilment`), so the rows the invoice prices were written
 * by an operation; the order, its lines and the stock are seeded as the owner. The three concurrency branches of
 * step 9 (D-3) are in `PurchaseOrderRowLockTest`, beside the holder harness they need; the cap is in
 * `DocumentNumberSeriesTest`, beside CD-7's four series.
 */
final class InvoiceIssueTest extends PurchaseOrderTestCase
{
    /**
     * The operations shipped under `/invoices` — `FulfilmentCreateTest::PINNED_FULFILMENT_OPERATIONS`' twin, joined
     * into `AuthOperationContractTest`'s whole-registry union. `IssueInvoice` hangs off the shipment (N-8) and is
     * pinned there, under `/fulfilments`.
     *
     * @var array<string, array{method: string, path: string, idem: bool}>
     */
    public const PINNED_INVOICE_OPERATIONS = [
        'GetInvoice' => ['method' => 'GET', 'path' => '/invoices/{id}', 'idem' => false],
    ];

    #[Test]
    public function theInvoicePathsDeclareExactlyThePinnedOperations(): void
    {
        $declared = [];
        foreach (TransactionBoundaryTest::contractOperations() as $name => $operation) {
            if (str_starts_with((string) $operation->getUriTemplate(), '/invoices')) {
                $declared[$name] = ['method' => $operation->getMethod(), 'path' => $operation->getUriTemplate()];
            }
        }

        self::assertSame(
            array_map(static fn (array $pin): array => ['method' => $pin['method'], 'path' => $pin['path']], self::PINNED_INVOICE_OPERATIONS),
            $declared,
        );
    }

    /**
     * The shape and the effects: 201 with `Location` naming `GetInvoice` (M-19), the body §4.4.3 derives, the total
     * **rounded per line** (D-A) — two lines of 0.5 × 0.0001 price to 0.0001 each, where rounding the sum would give
     * 0.0001 for both — `issued_by` the caller (read back as the owner, never exposed), and the `invoice.issued` row
     * cell by cell with `metadata` exactly `{}` (`SEC` §5.1).
     */
    #[Test]
    public function aSalesRepIssuesTheInvoiceForAShipment(): void
    {
        $s = $this->shipped('INV-A', [['1', '0.0001', '0.5'], ['1', '0.0001', '0.5'], ['4', '12.5000', '2']]);
        $rep = $this->actAsRole(DefaultRoles::SALES_REP);

        $this->issue($s['fulfilment']);

        self::assertSame(201, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        $body = $this->json();
        self::assertSame('/api/v1/invoices/'.$body['id'], $this->client()->getResponse()->headers->get('Location'));
        self::assertSame(['id', 'invoice_number', 'status', 'total_amount', 'currency_code', 'issued_at', 'due_at',
            'paid_at', 'fulfilment', 'customer'], array_keys($body));
        self::assertSame('INV-000001', $body['invoice_number']);
        self::assertSame('issued', $body['status']);
        self::assertSame('25.0002', $body['total_amount'], 'The total is not rounded per line (D-A).');
        self::assertSame('EUR', $body['currency_code']);
        self::assertNull($body['paid_at']);
        self::assertSame(['id' => $s['fulfilment'], 'fulfilment_number' => $s['fulfilment_number']], $body['fulfilment']);
        self::assertSame(['id' => $s['customer'], 'name' => 'Customer INV-A'], $body['customer']);
        self::assertArrayNotHasKey('issued_by', $body);
        self::assertSame(30 * 86400, strtotime($body['due_at']) - strtotime($body['issued_at']), 'due_at is not issued_at + the customer\'s 30 days.');

        $stored = $this->fetchAsOwnerForTenant(self::TENANT_A,
            'SELECT issued_by_user_id::text AS u, status, sales_order_id::text AS o FROM invoice WHERE id = ?::uuid', [$body['id']]);
        self::assertSame([['u' => $rep, 'status' => 'issued', 'o' => $s['order']]], $stored);

        self::assertSame([[
            'event_type' => AuditEvent::INVOICE_ISSUED,
            'actor_user_id' => $rep,
            'actor_label' => 'Fixture Actor',
            'subject_type' => 'invoice',
            'subject_id' => $body['id'],
            'ip_address' => null,
            'permission_code' => null,
            'metadata' => '{}',
        ]], $this->fetchAsOwnerForTenant(self::TENANT_A, <<<'SQL'
            SELECT event_type, actor_user_id::text AS actor_user_id, actor_label, subject_type,
                   subject_id::text AS subject_id, ip_address::text AS ip_address, permission_code, metadata::text AS metadata
              FROM audit_log WHERE event_type = ?
            SQL, [AuditEvent::INVOICE_ISSUED]));

        // GetInvoice — the Location answers, with the same body.
        $this->send('GET', '/invoices/'.$body['id']);
        self::assertSame(200, $this->httpStatus());
        self::assertSame($body, $this->json());
    }

    /**
     * Q2, *"Worked out when shown"*: an issued invoice whose `due_at` has passed reads `overdue`, by the database's
     * clock, and nothing wrote that status. Seeded by issuing on a zero-terms customer and then backdating both
     * instants a day **as the owner** (no operation can backdate); the 30-day invoice beside it still reads `issued`.
     */
    #[Test]
    public function anInvoicePastItsDueDateReadsOverdueAndNothingStoresIt(): void
    {
        $late = $this->shipped('INV-B', [['1', '10.0000', '1']], terms: 0);
        $current = $this->shipped('INV-C', [['1', '10.0000', '1']]);
        $this->actAsRole(DefaultRoles::SALES_REP);

        $this->issue($late['fulfilment']);
        self::assertSame(201, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        $lateId = $this->json()['id'];
        $this->issue($current['fulfilment']);
        $currentId = $this->json()['id'];

        $this->executeAsOwnerForTenant(self::TENANT_A,
            "UPDATE invoice SET issued_at = issued_at - interval '1 day', due_at = due_at - interval '1 day' WHERE id = ?::uuid", [$lateId]);

        $this->send('GET', '/invoices/'.$lateId);
        self::assertSame('overdue', $this->json()['status']);
        $this->send('GET', '/invoices/'.$currentId);
        self::assertSame('issued', $this->json()['status']);
        self::assertSame([['status' => 'issued']], $this->fetchAsOwnerForTenant(self::TENANT_A,
            'SELECT status FROM invoice WHERE id = ?::uuid', [$lateId]), 'The derived status was written back.');
    }

    /**
     * **The three-copy read-back** (`ARCH` §1, M-13): two orders, two customers, two currencies, one shipment each.
     * Issuing the SECOND shipment must copy its own order, customer and currency — a writer that took any of the three
     * from the first order (or the currency from the customer) is red here. And the invoice, the shipment and the order
     * are one workspace's (`SEC` §9.4).
     */
    #[Test]
    public function theInvoiceCopiesItsOwnShipmentsOrderCustomerAndCurrency(): void
    {
        $this->shipped('INV-D', [['1', '1.0000', '1']], currency: 'USD');
        $second = $this->shipped('INV-E', [['1', '1.0000', '1']], currency: 'GBP');
        $this->actAsRole(DefaultRoles::SALES_REP);

        $this->issue($second['fulfilment']);

        self::assertSame(201, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        $id = $this->json()['id'];
        self::assertSame('GBP', $this->json()['currency_code']);
        self::assertSame([[
            'sales_order_id' => $second['order'],
            'customer_id' => $second['customer'],
            'currency_code' => 'GBP',
            'same_tenant' => true,
        ]], $this->fetchAsOwnerForTenant(self::TENANT_A, <<<'SQL'
            SELECT i.sales_order_id::text AS sales_order_id, i.customer_id::text AS customer_id, i.currency_code,
                   (i.tenant_id = f.tenant_id AND f.tenant_id = so.tenant_id AND i.tenant_id = ?::uuid) AS same_tenant
              FROM invoice i
              JOIN fulfilment f ON f.id = i.fulfilment_id
              JOIN sales_order so ON so.id = f.sales_order_id
             WHERE i.id = ?::uuid
            SQL, [self::TENANT_A, $id]));
    }

    /** One issue in each of two workspaces: each numbers from its own series and sees only its own invoice. */
    #[Test]
    public function eachWorkspaceIssuesFromItsOwnSeriesAndSeesOnlyItsOwnInvoices(): void
    {
        $a = $this->shipped('INV-F', [['1', '3.0000', '1']]);
        $this->actAsRole(DefaultRoles::SALES_REP);
        $this->issue($a['fulfilment']);
        self::assertSame(201, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        $inA = $this->json();

        $b = $this->shipped('INV-G', [['1', '3.0000', '1']], tenant: self::TENANT_B);
        $this->actAs(self::TENANT_B, DefaultRoles::codesFor(DefaultRoles::SALES_REP));
        $this->issue($b['fulfilment']);
        self::assertSame(201, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        self::assertSame('INV-000001', $this->json()['invoice_number'], 'Workspace B does not number from its own series.');
        self::assertSame('INV-000001', $inA['invoice_number']);

        // E-2: A's invoice and A's fulfilment are B's not-found, byte-identical to a random uuid's.
        $this->send('GET', '/invoices/'.$inA['id']);
        self::assertSame(404, $this->httpStatus());
        $foreign = (string) $this->client()->getResponse()->getContent();
        $this->send('GET', '/invoices/'.Uuid::v4()->toRfc4122());
        self::assertSame($foreign, (string) $this->client()->getResponse()->getContent());

        $this->issue($a['fulfilment']);
        self::assertSame(404, $this->httpStatus());
        $foreignIssue = (string) $this->client()->getResponse()->getContent();
        $this->issue(Uuid::v4()->toRfc4122());
        self::assertSame(404, $this->httpStatus());
        self::assertSame($foreignIssue, (string) $this->client()->getResponse()->getContent());
        self::assertSame(1, $this->countAsOwnerForTenant(self::TENANT_A, 'SELECT count(*) FROM invoice'));
    }

    /**
     * **A repeat is refused, never replayed** (§4.2 item 1), and R-14 decides what the refusal says: the live number
     * only to a holder of `invoice.view`. The composed role holds `invoice.issue` and `fulfilment.view` and nothing
     * else; the raw body is searched, not a field, so the number cannot hide in another member.
     */
    #[Test]
    public function aSecondIssueForTheSameShipmentIsRefusedAndOnlyAnInvoiceReaderIsToldItsNumber(): void
    {
        $s = $this->shipped('INV-H', [['1', '5.0000', '1']]);
        $this->actAsRole(DefaultRoles::SALES_REP);
        $this->issue($s['fulfilment']);
        self::assertSame(201, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        $number = $this->json()['invoice_number'];

        $this->issue($s['fulfilment']);
        self::assertSame(409, $this->httpStatus());
        self::assertSame('invoice.fulfilment_is_not_already_invoiced', $this->json()['rule']);
        self::assertSame('This shipment already has an invoice that has not been voided. It is '.$number.'.', $this->json()['detail']);

        $this->actAs(self::TENANT_A, ['invoice.issue', 'fulfilment.view']);
        $this->issue($s['fulfilment']);
        self::assertSame(409, $this->httpStatus());
        self::assertSame('invoice.fulfilment_is_not_already_invoiced', $this->json()['rule']);
        self::assertStringNotContainsString($number, (string) $this->client()->getResponse()->getContent(),
            'A caller without invoice.view was told the live invoice\'s number (R-14).');
        self::assertSame(1, $this->countAsOwnerForTenant(self::TENANT_A, 'SELECT count(*) FROM invoice'));
        self::assertSame(1, $this->countAsOwnerForTenant(self::TENANT_A, 'SELECT count(*) FROM audit_log WHERE event_type = ?',
            [AuditEvent::INVOICE_ISSUED]), 'A refused issue wrote a trail row.');
    }

    /** A void releases the shipment (`invoice_tenant_fulfilment_not_void`): a corrected re-issue is legal. */
    #[Test]
    public function aVoidedInvoiceReleasesItsShipmentForACorrectedReissue(): void
    {
        $s = $this->shipped('INV-I', [['1', '5.0000', '1']]);
        $this->actAsRole(DefaultRoles::SALES_REP);
        $this->issue($s['fulfilment']);
        $first = $this->json()['id'];
        // No `VoidInvoice` yet (C7): the owner stands in for it.
        $this->executeAsOwnerForTenant(self::TENANT_A, "UPDATE invoice SET status = 'void' WHERE id = ?::uuid", [$first]);

        $this->issue($s['fulfilment']);
        self::assertSame(201, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        self::assertSame('INV-000002', $this->json()['invoice_number'], 'A voided invoice\'s number was reissued.');
    }

    /** @return iterable<string, array{int}> */
    public static function unusableTerms(): iterable
    {
        yield 'negative' => [-1];
        yield 'beyond ten years' => [3651];
    }

    /** Step 4: terms `invoice_due_window` would refuse as a 23514 are refused in domain terms first. */
    #[Test]
    #[DataProvider('unusableTerms')]
    public function aCustomerWithUnusablePaymentTermsCannotBeInvoiced(int $terms): void
    {
        $s = $this->shipped('INV-J', [['1', '5.0000', '1']], terms: $terms);
        $this->actAsRole(DefaultRoles::SALES_REP);

        $this->issue($s['fulfilment']);

        self::assertSame(409, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        self::assertSame('invoice.payment_terms_are_usable', $this->json()['rule']);
        self::assertSame(0, $this->countAsOwnerForTenant(self::TENANT_A, 'SELECT count(*) FROM invoice'));
    }

    /**
     * The within-bound edge: ten years of terms is accepted — `due_at` lands exactly on `invoice_due_window`'s bound,
     * and both sides of that CHECK come from the database's clock (RED-3 F-1). A `due_at` computed by PHP would reach
     * the CHECK on a second clock.
     */
    #[Test]
    public function tenYearsOfTermsIsTheLastUsableBound(): void
    {
        $s = $this->shipped('INV-K', [['1', '5.0000', '1']], terms: 3650);
        $this->actAsRole(DefaultRoles::SALES_REP);

        $this->issue($s['fulfilment']);

        self::assertSame(201, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
    }

    /**
     * **Q2's DST test.** The request's session runs in `Europe/Berlin` and the terms reach across Berlin's next
     * clock change, so a naive `issued_at + interval 'n days'` (calendar days in the session zone) would move
     * `due_at`'s UTC wall time by an hour. The writer computes in UTC: `due_at` is exactly n × 86 400 s after
     * `issued_at`, whatever zone the session is in.
     */
    #[Test]
    public function theDueDateIsCountedInUtcWhateverTheSessionZone(): void
    {
        $terms = self::daysToCrossTheNextBerlinClockChange();
        $s = $this->shipped('INV-L', [['1', '5.0000', '1']], terms: $terms);
        $this->actAsRole(DefaultRoles::SALES_REP);

        $this->app()->executeStatement("SET TimeZone = 'Europe/Berlin'");
        try {
            $this->issue($s['fulfilment']);
            self::assertSame('Europe/Berlin', $this->app()->fetchOne('SHOW TimeZone'),
                'Control: the session zone the request ran in was not Berlin, so this test proves nothing.');
        } finally {
            $this->app()->executeStatement('RESET TimeZone');
        }

        self::assertSame(201, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        $row = $this->fetchAsOwnerForTenant(self::TENANT_A,
            'SELECT extract(epoch FROM due_at - issued_at)::bigint AS seconds FROM invoice WHERE id = ?::uuid', [$this->json()['id']]);
        self::assertSame($terms * 86400, (int) $row[0]['seconds'],
            'due_at is not a whole number of UTC days after issued_at: the session zone moved it across a clock change.');
    }

    /** M-12: `unit_price` has no sign CHECK, so a negative price can be seeded as the owner; it is refused, never a 500. */
    #[Test]
    public function aShipmentPricedBelowNothingIsRefused(): void
    {
        $s = $this->shipped('INV-M', [['1', '-5.0000', '1']]);
        $this->actAsRole(DefaultRoles::SALES_REP);

        $this->issue($s['fulfilment']);

        self::assertSame(409, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        self::assertSame('invoice.total_is_not_negative', $this->json()['rule']);
        self::assertSame(0, $this->countAsOwnerForTenant(self::TENANT_A, 'SELECT count(*) FROM invoice'));
    }

    /** An archived customer was still billed for what shipped: the invoice issues, and names them. */
    #[Test]
    public function anArchivedCustomerIsStillInvoiced(): void
    {
        $s = $this->shipped('INV-N', [['1', '5.0000', '1']]);
        $this->executeAsOwnerForTenant(self::TENANT_A, 'UPDATE customer SET deleted_at = now() WHERE id = ?::uuid', [$s['customer']]);
        $this->actAsRole(DefaultRoles::SALES_REP);

        $this->issue($s['fulfilment']);

        self::assertSame(201, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        self::assertSame($s['customer'], $this->json()['customer']['id']);
    }

    /**
     * An order line archived as the owner AFTER it shipped still prices what shipped: the amount join carries no
     * `deleted_at` filter, so the total is the full total.
     */
    #[Test]
    public function anOrderLineArchivedAfterShippingIsStillBilled(): void
    {
        $s = $this->shipped('INV-O', [['2', '4.0000', '2'], ['1', '3.0000', '1']]);
        $this->executeAsOwnerForTenant(self::TENANT_A, 'UPDATE sales_order_line SET deleted_at = now() WHERE id = ?::uuid', [$s['lines'][0]]);
        $this->actAsRole(DefaultRoles::SALES_REP);

        $this->issue($s['fulfilment']);

        self::assertSame(201, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        self::assertSame('11.0000', $this->json()['total_amount']);
    }

    /** Q1, *"What shipped"*: an order cancelled after part of it shipped still bills that part. */
    #[Test]
    public function aShipmentOfAnOrderCancelledLaterIsStillInvoiced(): void
    {
        $s = $this->shipped('INV-P', [['5', '2.0000', '2']]);
        $this->send('POST', '/sales-orders/'.$s['order'].'/cancellation', ['reason' => 'Customer withdrew']);
        self::assertSame(200, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        self::assertSame(SalesOrder::STATUS_CANCELLED, $this->fetchAsOwnerForTenant(self::TENANT_A,
            'SELECT status FROM sales_order WHERE id = ?::uuid', [$s['order']])[0]['status']);
        $this->actAsRole(DefaultRoles::SALES_REP);

        $this->issue($s['fulfilment']);

        self::assertSame(201, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        self::assertSame('4.0000', $this->json()['total_amount']);
    }

    /**
     * **R-B's residual pin** (design §19 C3, RED-3 N-5): the schema accepts an invoice whose `sales_order_id`
     * disagrees with its fulfilment's — nothing but the writer's own statement ties the three copies. This test
     * ASSERTS the acceptance, so it goes red the day the `BEFORE INSERT` trigger that closes `RESIDUAL_GAPS.md` R-B
     * lands, forcing that row to close in the same commit.
     */
    #[Test]
    public function residualRB_theSchemaAcceptsAnInvoiceWhoseOrderIsNotItsShipmentsOrder(): void
    {
        $shipment = $this->shipped('INV-Q', [['1', '1.0000', '1']]);
        $other = $this->shipped('INV-R', [['1', '1.0000', '1']]);

        $this->insertAsOwnerForTenant(self::TENANT_A, <<<'SQL'
            INSERT INTO invoice (id, tenant_id, fulfilment_id, sales_order_id, customer_id, invoice_number, currency_code,
                                 total_amount, due_at, issued_by_user_id)
            VALUES (?::uuid, ?::uuid, ?::uuid, ?::uuid, ?::uuid, 'INV-000777', 'EUR', 1, now() + interval '1 day', ?::uuid)
            SQL, [Uuid::v4()->toRfc4122(), self::TENANT_A, $shipment['fulfilment'], $other['order'], $other['customer'], (string) $this->actorId]);

        self::assertSame(1, $this->countAsOwnerForTenant(self::TENANT_A, 'SELECT count(*) FROM invoice WHERE invoice_number = ?', ['INV-000777']),
            'RESIDUAL_GAPS.md R-B: an invoice disagreeing with its shipment\'s order was REFUSED. If a BEFORE INSERT trigger now '
            .'verifies the three copies, R-B\'s INSERT half is closed: close the row in this commit and delete this pin.');
    }

    /** E-1: a caller without `invoice.issue` is refused before any row is read — the same 403 for any id. */
    #[Test]
    public function withoutTheIssueCodeEveryShipmentIsTheSameRefusal(): void
    {
        $s = $this->shipped('INV-S', [['1', '1.0000', '1']]);
        $this->actAsRole(DefaultRoles::WAREHOUSE_OPERATOR);

        $this->issue($s['fulfilment']);
        self::assertSame(403, $this->httpStatus());
        $known = (string) $this->client()->getResponse()->getContent();
        $this->issue(Uuid::v4()->toRfc4122());
        self::assertSame(403, $this->httpStatus());
        self::assertSame($known, (string) $this->client()->getResponse()->getContent());
        self::assertSame(0, $this->countAsOwnerForTenant(self::TENANT_A, 'SELECT count(*) FROM invoice'));
    }

    // -----------------------------------------------------------------------

    private function issue(string $fulfilmentId): void
    {
        $this->send('POST', '/fulfilments/'.$fulfilmentId.'/invoices', null, ['Idempotency-Key' => Uuid::v4()->toRfc4122()]);
    }

    /** @return string the new actor's id */
    private function actAsRole(string $role): string
    {
        $this->actAs(self::TENANT_A, DefaultRoles::codesFor($role));

        return (string) $this->actorId;
    }

    /**
     * A confirmed order of `$tenant`, seeded as the owner — one line per `[ordered, unit price, shipped]` — and one
     * fulfilment of it shipped THROUGH THE API by a principal holding everything. The actor is left as that principal.
     *
     * @param list<array{0: string, 1: string, 2: string}> $lines
     *
     * @return array{order: string, customer: string, lines: list<string>, fulfilment: string, fulfilment_number: string}
     */
    private function shipped(string $tag, array $lines, int $terms = 30, string $currency = 'EUR', string $tenant = self::TENANT_A): array
    {
        $this->actAs($tenant);
        $customer = $this->seedCustomer($tenant, 'Customer '.$tag);
        $this->executeAsOwnerForTenant($tenant, 'UPDATE customer SET payment_terms_days = ? WHERE id = ?::uuid', [$terms, $customer]);
        $warehouse = $this->seedWarehouse($tenant, 'WH '.$tag);
        $order = $this->seedSalesOrder($tenant, self::freshNumber('SO'), $customer, $warehouse, SalesOrder::STATUS_CONFIRMED, '2026-09-20T10:00:00Z');
        $this->executeAsOwnerForTenant($tenant, 'UPDATE sales_order SET currency_code = ? WHERE id = ?::uuid', [$currency, $order]);

        $bin = Uuid::v4()->toRfc4122();
        $this->insertAsOwnerForTenant($tenant,
            "INSERT INTO storage_location (id, tenant_id, warehouse_id, code, kind) VALUES (?::uuid, ?::uuid, ?::uuid, 'A-01', 'bin')",
            [$bin, $tenant, $warehouse]);

        $lineIds = [];
        $shipping = [];
        foreach ($lines as $i => [$ordered, $price, $ship]) {
            $item = $this->seedItem($tenant, 'SKU-'.$tag.'-'.$i);
            $lineIds[] = $line = $this->seedSalesOrderLine($tenant, $order, $item, lineNumber: $i + 1, quantity: $ordered, unitPrice: $price);
            $this->insertAsOwnerForTenant($tenant,
                'INSERT INTO stock_level (id, tenant_id, inventory_item_id, storage_location_id, qty_on_hand) VALUES (?::uuid, ?::uuid, ?::uuid, ?::uuid, ?::numeric)',
                [Uuid::v4()->toRfc4122(), $tenant, $item, $bin, $ordered]);
            $shipping[] = ['sales_order_line_id' => $line, 'quantity' => $ship, 'storage_location_id' => $bin];
        }

        $this->send('POST', '/sales-orders/'.$order.'/fulfilments', ['lines' => $shipping], ['Idempotency-Key' => Uuid::v4()->toRfc4122()]);
        self::assertSame(201, $this->httpStatus(), 'Fixture: the shipment was refused. '.(string) $this->client()->getResponse()->getContent());

        return [
            'order' => $order,
            'customer' => $customer,
            'lines' => $lineIds,
            'fulfilment' => $this->json()['id'],
            'fulfilment_number' => $this->json()['fulfilment_number'],
        ];
    }

    /** The smallest whole number of days from now that lands after Berlin's next change of UTC offset, plus one. */
    private static function daysToCrossTheNextBerlinClockChange(): int
    {
        $zone = new \DateTimeZone('Europe/Berlin');
        $now = new \DateTimeImmutable('now', $zone);
        for ($days = 1; $days <= 250; ++$days) {
            if ($now->modify('+'.$days.' days')->getOffset() !== $now->getOffset()) {
                return $days + 1;
            }
        }

        throw new \LogicException('No Berlin clock change within 250 days.');
    }
}
