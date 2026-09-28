<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Modules\Sales\Infrastructure\Entity\SalesOrder;
use App\Modules\UserManagement\Domain\DefaultRoles;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Uid\Uuid;

/**
 * **`ListInvoices` — sales step 6, C4** (design §19 C4; IA §5.6's `/invoices` rows; owner ruling Q2).
 *
 * Invoices are seeded as the owner so the views can hold paid and void rows, which no built operation can write yet
 * (`PayInvoice` is C5, `VoidInvoice` C7 — `RESIDUAL_GAPS.md` G-14). The seed is the whole of what the list reads.
 */
final class InvoiceListTest extends PurchaseOrderTestCase
{
    /** @var array{c1: string, c2: string, f: list<string>, ids: array<string, string>} */
    private array $world;

    protected function setUp(): void
    {
        parent::setUp();

        // Two customers; six shipments of C1's order and one of C2's. Each invoice bills its own shipment except
        // the two voided ones on F0, which the live INV-000105 on F0 superseded.
        $c1 = $this->seedCustomer(self::TENANT_A, 'Customer One');
        $c2 = $this->seedCustomer(self::TENANT_A, 'Customer Two');
        $o1 = $this->seedSalesOrder(self::TENANT_A, self::freshNumber('SO'), $c1, null, SalesOrder::STATUS_CONFIRMED, '2026-09-01T00:00:00Z');
        $o2 = $this->seedSalesOrder(self::TENANT_A, self::freshNumber('SO'), $c2, null, SalesOrder::STATUS_CONFIRMED, '2026-09-01T00:00:00Z');
        $f = [];
        for ($i = 0; $i < 5; ++$i) {
            $f[] = $this->seedFulfilment($o1);
        }
        $f[] = $this->seedFulfilment($o2);

        $ids = [];
        // number => [fulfilment, status, issued_at, due_at]
        foreach ([
            'INV-000101' => [$f[1], 'issued', '2026-08-01', '2026-08-31'],   // overdue by the clock
            'INV-000102' => [$f[2], 'issued', '2026-08-10', '2030-08-20'],   // due in 2030
            'INV-000103' => [$f[3], 'paid', '2026-08-05', '2026-09-04'],
            'INV-000104' => [$f[0], 'void', '2026-08-02', '2026-09-01'],
            'INV-000105' => [$f[0], 'issued', '2026-08-20', '2030-09-19'],
            'INV-000106' => [$f[0], 'void', '2026-08-03', '2026-09-02'],
            'INV-000107' => [$f[5], 'issued', '2026-08-15', '2030-09-14'],   // Customer Two
        ] as $number => [$fulfilment, $status, $issued, $due]) {
            $ids[$number] = $this->seedInvoiceRow($fulfilment, $number, $status, $issued, $due);
        }

        $this->world = ['c1' => $c1, 'c2' => $c2, 'f' => $f, 'ids' => $ids];
    }

    /** The default view: every invoice, by number. */
    #[Test]
    public function theRegisterListsEveryInvoiceByNumber(): void
    {
        self::assertSame(['INV-000101', 'INV-000102', 'INV-000103', 'INV-000104', 'INV-000105', 'INV-000106', 'INV-000107'],
            $this->numbers('/invoices'));
    }

    /**
     * Unpaid holds the STORED `issued` rows, by `due_at` ascending — so the overdue invoice leads — and each row's
     * `status` is derived exactly as `GetInvoice`'s: the one past its due date reads `overdue`, the others `issued`.
     */
    #[Test]
    public function unpaidLeadsWithWhatIsOverdueAndLabelsItByTheDatabaseClock(): void
    {
        $this->send('GET', '/invoices?status=unpaid');
        self::assertSame(200, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        self::assertSame([
            ['INV-000101', 'overdue'], ['INV-000102', 'issued'], ['INV-000107', 'issued'], ['INV-000105', 'issued'],
        ], array_map(static fn (array $i): array => [$i['invoice_number'], $i['status']], $this->json()['items']));

        $this->send('GET', '/invoices/'.$this->world['ids']['INV-000101']);
        self::assertSame('overdue', $this->json()['status'], 'GetInvoice and ListInvoices derive the status differently.');

        self::assertSame(['INV-000101', 'INV-000102', 'INV-000105', 'INV-000107'], $this->numbers('/invoices?status=unpaid&sort=invoice_number'));
    }

    #[Test]
    public function paidAndVoidAreTheirOwnSegmentsAndNeverReadOverdue(): void
    {
        self::assertSame(['INV-000103'], $this->numbers('/invoices?status=paid'));
        self::assertSame(['INV-000104', 'INV-000106'], $this->numbers('/invoices?status=void'));
        $this->send('GET', '/invoices?status=void');
        self::assertSame(['void', 'void'], array_column($this->json()['items'], 'status'));
    }

    /** Filtered to a customer: `issued_at` by default, `invoice_number` as the alternate. */
    #[Test]
    public function aCustomersInvoicesAreListedByIssueDate(): void
    {
        self::assertSame(['INV-000101', 'INV-000104', 'INV-000106', 'INV-000103', 'INV-000102', 'INV-000105'],
            $this->numbers('/invoices?customer_id='.$this->world['c1']));
        self::assertSame(['INV-000101', 'INV-000102', 'INV-000103', 'INV-000104', 'INV-000105', 'INV-000106'],
            $this->numbers('/invoices?customer_id='.$this->world['c1'].'&sort=invoice_number'));
        self::assertSame(['INV-000107'], $this->numbers('/invoices?customer_id='.$this->world['c2']));
    }

    /**
     * Filtered to a shipment (V2-C-11, UX §1): `invoice_number` NEWEST FIRST, so the live invoice leads the voided
     * ones; it composes with the segment (TB-5); and no other order is served on this view.
     */
    #[Test]
    public function aShipmentsInvoicesAreListedNewestFirstAndOfferNoOtherOrder(): void
    {
        $f0 = $this->world['f'][0];
        self::assertSame(['INV-000106', 'INV-000105', 'INV-000104'], $this->numbers('/invoices?fulfilment_id='.$f0));
        self::assertSame(['INV-000105'], $this->numbers('/invoices?status=unpaid&fulfilment_id='.$f0));
        self::assertSame(['INV-000106', 'INV-000104'], $this->numbers('/invoices?status=void&fulfilment_id='.$f0));

        foreach (['due_at', 'issued_at'] as $sort) {
            $this->send('GET', '/invoices?status=unpaid&fulfilment_id='.$f0.'&sort='.$sort);
            self::assertSame(422, $this->httpStatus(), $sort.' was served on the fulfilment view.');
            self::assertSame('unserved_sort', $this->json()['errors'][0]['code']);
        }
    }

    /** @return iterable<string, array{string}> */
    public static function unservedSorts(): iterable
    {
        yield 'due_at on Paid' => ['status=paid&sort=due_at'];
        yield 'due_at on the register' => ['sort=due_at'];
        yield 'issued_at on Unpaid' => ['status=unpaid&sort=issued_at'];
    }

    /** API-19: an order the view does not offer is refused, never ignored. */
    #[Test]
    #[DataProvider('unservedSorts')]
    public function anOrderTheViewDoesNotOfferIsRefused(string $query): void
    {
        $this->send('GET', '/invoices?'.$query);
        self::assertSame(422, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        self::assertSame('unserved_sort', $this->json()['errors'][0]['code']);
    }

    /** **No `paid_at` sort** (design §19 C4): it is not in the declared enum, so the framework refuses it. */
    #[Test]
    public function thereIsNoPaymentDateOrder(): void
    {
        $this->send('GET', '/invoices?sort=paid_at');
        self::assertNotSame(200, $this->httpStatus());
        $this->send('GET', '/invoices?status=paid&sort=paid_at');
        self::assertNotSame(200, $this->httpStatus());
    }

    /** Keyset both ways, on the Unpaid `due_at` view: forward page by page, then back from the last page. */
    #[Test]
    public function theUnpaidViewPagesForwardAndBack(): void
    {
        $seen = [];
        $cursor = null;
        $pages = [];
        do {
            $this->send('GET', '/invoices?status=unpaid&limit=1'.(null === $cursor ? '' : '&after='.urlencode($cursor)));
            self::assertSame(200, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
            $pages[] = $this->json();
            $seen[] = $this->json()['items'][0]['invoice_number'];
            $cursor = $this->json()['next_cursor'];
        } while (null !== $cursor);
        self::assertSame(['INV-000101', 'INV-000102', 'INV-000107', 'INV-000105'], $seen);

        $back = [];
        $previous = end($pages)['previous_cursor'];
        while (null !== $previous) {
            $this->send('GET', '/invoices?status=unpaid&limit=1&before='.urlencode($previous));
            self::assertSame(200, $this->httpStatus());
            $back[] = $this->json()['items'][0]['invoice_number'];
            $previous = $this->json()['previous_cursor'];
        }
        self::assertSame(['INV-000107', 'INV-000102', 'INV-000101'], $back);
    }

    /** Keyset both ways on the newest-first view too, where forward runs DESC. */
    #[Test]
    public function theShipmentViewPagesForwardAndBackNewestFirst(): void
    {
        $f0 = $this->world['f'][0];
        $this->send('GET', '/invoices?fulfilment_id='.$f0.'&limit=2');
        self::assertSame(['INV-000106', 'INV-000105'], array_column($this->json()['items'], 'invoice_number'));
        $this->send('GET', '/invoices?fulfilment_id='.$f0.'&limit=2&after='.urlencode((string) $this->json()['next_cursor']));
        self::assertSame(['INV-000104'], array_column($this->json()['items'], 'invoice_number'));
        self::assertNull($this->json()['next_cursor']);
        $this->send('GET', '/invoices?fulfilment_id='.$f0.'&limit=2&before='.urlencode((string) $this->json()['previous_cursor']));
        self::assertSame(['INV-000106', 'INV-000105'], array_column($this->json()['items'], 'invoice_number'));
    }

    /** A register cursor sent to a `due_at` view cannot be its key: refused, never a 500. */
    #[Test]
    public function aCursorFromAnotherViewIsRefused(): void
    {
        $this->send('GET', '/invoices?limit=1');
        $cursor = (string) $this->json()['next_cursor'];

        $this->send('GET', '/invoices?status=unpaid&limit=1&after='.urlencode($cursor));
        self::assertSame(422, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        self::assertSame('malformed_cursor', $this->json()['errors'][0]['code']);
    }

    /** `SEC` §9.6: another workspace's customer or shipment, or none, is the same empty 200 — never a 404. */
    #[Test]
    public function aForeignOrUnknownFilterIsTheSameEmptyPage(): void
    {
        $this->actAs(self::TENANT_B);
        $answers = [];
        foreach ([
            'customer_id='.$this->world['c1'], 'customer_id='.Uuid::v4()->toRfc4122(),
            'fulfilment_id='.$this->world['f'][0], 'fulfilment_id='.Uuid::v4()->toRfc4122(),
        ] as $filter) {
            $this->send('GET', '/invoices?'.$filter);
            self::assertSame(200, $this->httpStatus(), $filter);
            $answers[] = (string) $this->client()->getResponse()->getContent();
        }
        self::assertCount(1, array_unique($answers), 'A foreign filter answered differently from an unknown one.');
        self::assertSame([], json_decode($answers[0], true)['items']);
    }

    /** `q` searches the invoice number by prefix, and nothing else. */
    #[Test]
    public function theSearchIsTheInvoiceNumberByPrefix(): void
    {
        self::assertSame(['INV-000105'], $this->numbers('/invoices?q=INV-000105'));
        self::assertSame([], $this->numbers('/invoices?q=Customer'));
    }

    /** E-1: without `invoice.view` the list is refused before anything is read. */
    #[Test]
    public function withoutTheViewCodeTheListIsRefused(): void
    {
        $this->actAs(self::TENANT_A, DefaultRoles::codesFor(DefaultRoles::WAREHOUSE_OPERATOR));
        $this->send('GET', '/invoices');
        self::assertSame(403, $this->httpStatus());
    }

    // -----------------------------------------------------------------------

    /** @return list<string> */
    private function numbers(string $path): array
    {
        $this->send('GET', $path);
        self::assertSame(200, $this->httpStatus(), $path.': '.(string) $this->client()->getResponse()->getContent());

        return array_column($this->json()['items'], 'invoice_number');
    }

    private function seedFulfilment(string $orderId): string
    {
        $id = Uuid::v4()->toRfc4122();
        $this->insertAsOwnerForTenant(self::TENANT_A, <<<'SQL'
            INSERT INTO fulfilment (id, tenant_id, sales_order_id, warehouse_id, fulfilment_number, shipped_by_user_id,
                                    idempotency_key, request_digest)
            SELECT ?::uuid, so.tenant_id, so.id, so.warehouse_id, ?, ?::uuid,
                   encode(sha256(gen_random_uuid()::text::bytea), 'hex'), encode(sha256(gen_random_uuid()::text::bytea), 'hex')
              FROM sales_order so WHERE so.id = ?::uuid
            SQL, [$id, self::freshNumber('FUL'), (string) $this->actorId, $orderId]);

        return $id;
    }

    /**
     * An invoice written as the owner — the only writer of a void one until C7. A paid one names the actor as its
     * recorder, because `invoice_paid_by_matches_status` (C5) makes a paid invoice without one impossible.
     */
    private function seedInvoiceRow(string $fulfilmentId, string $number, string $status, string $issuedOn, string $dueOn): string
    {
        $id = Uuid::v4()->toRfc4122();
        $this->insertAsOwnerForTenant(self::TENANT_A, <<<'SQL'
            INSERT INTO invoice (id, tenant_id, fulfilment_id, sales_order_id, customer_id, invoice_number, currency_code,
                                 total_amount, issued_at, due_at, status, paid_at, issued_by_user_id, paid_by_user_id)
            SELECT ?::uuid, f.tenant_id, f.id, so.id, so.customer_id, ?, so.currency_code, 10,
                   (?::date)::timestamptz, (?::date)::timestamptz, ?,
                   CASE WHEN ? = 'paid' THEN (?::date)::timestamptz + interval '1 day' END, ?::uuid,
                   CASE WHEN ? = 'paid' THEN ?::uuid END
              FROM fulfilment f JOIN sales_order so ON so.tenant_id = f.tenant_id AND so.id = f.sales_order_id
             WHERE f.id = ?::uuid
            SQL, [$id, $number, $issuedOn, $dueOn, $status, $status, $issuedOn, (string) $this->actorId, $status, (string) $this->actorId, $fulfilmentId]);

        return $id;
    }
}
