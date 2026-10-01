<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Modules\Sales\Application\InvoiceViews;
use App\Modules\Sales\Infrastructure\Entity\SalesOrder;
use App\Modules\UserManagement\Domain\DefaultRoles;
use App\Tests\Support\CreditedSegment;
use App\Tests\Support\PhpSourceScan;
use PHPUnit\Framework\Attributes\Test;

/**
 * **The Credited segment and the one predicate behind it — sales step 7 C0** (`docs/session13/
 * output9-sales-8-c0-amendments.md` A-5 tests 1–11; `IA.md` §5.4 ³; design §4.6 item 2 as narrowed after RED-2 M-1).
 *
 * *"Credited in full"* = `status IN (issued, overdue) AND Σ credit > 0 AND total − Σ credit <= 0`, on stored columns,
 * decided in `InvoiceCredit` and nowhere else. Credits are seeded **as the owner** (`CreditFixtures`): at C0 no operation
 * writes one, so a green run proves the reads and `PayInvoice`'s refusal, not what the API can produce (the G-7 shape).
 *
 * **The world** (test 1's nine rows, every `due_at` in the past so `overdue` is in play): uncredited · partially
 * credited · fully credited · over-credited (a credit above the total: unreachable through the API, pins `<= 0`) ·
 * zero-total never credited · paid · partially credited then paid · payment-reversed · void.
 */
final class InvoiceCreditedSegmentTest extends PurchaseOrderTestCase
{
    use CreditFixtures;

    /** @var array<string, string> label => invoice id */
    private array $world = [];

    protected function setUp(): void
    {
        parent::setUp();

        $customer = $this->seedCustomer(self::TENANT_A, 'Credited Customer');
        $order = $this->seedSalesOrder(self::TENANT_A, self::freshNumber('SO'), $customer, null, SalesOrder::STATUS_CONFIRMED, '2026-07-01T00:00:00Z');

        foreach ([
            'uncredited' => ['INV-000201', 'issued', '10.0000', null],
            'partial' => ['INV-000202', 'issued', '10.0000', '4.0000'],
            'full' => ['INV-000203', 'issued', '10.0000', '10.0000'],
            'over' => ['INV-000204', 'issued', '10.0000', '12.0000'],
            'zero' => ['INV-000205', 'issued', '0.0000', null],
            'paid' => ['INV-000206', 'paid', '10.0000', null],
            'partial-paid' => ['INV-000207', 'paid', '10.0000', '4.0000'],
            'reversed' => ['INV-000208', 'issued', '10.0000', null],
            'void' => ['INV-000209', 'void', '10.0000', null],
        ] as $label => [$number, $status, $total, $credit]) {
            $this->world[$label] = $this->seedInvoiceRow($this->seedBareFulfilment($order), $number, $status, $total,
                paymentReversed: 'reversed' === $label);
            if (null !== $credit) {
                $this->seedCreditNote($this->world[$label], $credit);
            }
        }
    }

    /**
     * **Test 1, the partition** (`PurchaseOrderListTest`'s precedent: iterate the view model). The four segments are
     * pairwise disjoint and their union is the register; the over-credited row is in Credited and only there; the
     * zero-total uncredited row is in Unpaid.
     */
    #[Test]
    public function everyInvoiceIsInExactlyOneSegment(): void
    {
        $this->actAsRole(DefaultRoles::FINANCE);
        $all = $this->ids('/invoices');
        self::assertCount(9, $all, 'Fixture: the register does not hold the nine seeded invoices.');

        $seen = [];
        $bySegment = [];
        foreach (array_keys(InvoiceViews::SEGMENTS) as $segment) {
            $bySegment[$segment] = $this->ids('/invoices?status='.$segment.'&sort=invoice_number');
            foreach ($bySegment[$segment] as $id) {
                self::assertArrayNotHasKey($id, $seen, \sprintf('Invoice %s is in both %s and %s (TB-4: exactly one segment).', $this->label($id), $seen[$id] ?? '', $segment));
                $seen[$id] = $segment;
            }
        }
        $union = array_keys($seen);
        sort($union);
        sort($all);
        self::assertSame($all, $union, 'The segments do not cover the register: a row is in no segment.');

        self::assertSame('credited', $seen[$this->world['over']], 'The over-credited row is not in Credited (`<= 0`, not `= 0`).');
        self::assertSame('credited', $seen[$this->world['full']]);
        self::assertSame('unpaid', $seen[$this->world['zero']], 'A zero-total invoice with no credit note left Unpaid (`credited > 0` dropped).');
        self::assertSame('unpaid', $seen[$this->world['partial']]);
        self::assertSame('unpaid', $seen[$this->world['reversed']]);
        self::assertSame('paid', $seen[$this->world['partial-paid']]);
        self::assertSame('void', $seen[$this->world['void']]);
    }

    /** **Test 2, the view model cannot build Unpaid twice**, and **test 7, the names match the table.** */
    #[Test]
    public function creditedIsItsOwnViewAndOffersOnlyTheNumberOrder(): void
    {
        self::assertSame(array_keys(InvoiceViews::SEGMENTS), InvoiceViews::SEGMENT_NAMES, 'SEGMENT_NAMES drifted from SEGMENTS.');

        $this->actAsRole(DefaultRoles::FINANCE);
        $credited = $this->ids('/invoices?status=credited');
        sort($credited);
        $expected = [$this->world['full'], $this->world['over']];
        sort($expected);
        self::assertSame($expected, $credited);

        $this->send('GET', '/invoices?status=credited&sort=due_at');
        self::assertSame(422, $this->httpStatus(), 'Credited offered `due_at`: it was built as Unpaid\'s statuses alone.');
        self::assertSame('unserved_sort', $this->json()['errors'][0]['code']);

        $unpaid = $this->ids('/invoices?status=unpaid');
        self::assertNotContains($this->world['full'], $unpaid);
        self::assertNotContains($this->world['over'], $unpaid);
    }

    /** **Test 3, the label condition**: credited then paid reads `paid`, is in Paid and not Credited, owes `0.0000`. */
    #[Test]
    public function aPartlyCreditedInvoiceThatWasPaidReadsPaid(): void
    {
        $this->actAsRole(DefaultRoles::FINANCE);
        $body = $this->read($this->world['partial-paid']);
        self::assertSame('paid', $body['status']);
        self::assertSame('4.0000', $body['amount_credited']);
        self::assertSame('0.0000', $body['amount_due']);
        self::assertFalse($body['credited_in_full']);
        self::assertContains($this->world['partial-paid'], $this->ids('/invoices?status=paid'));
        self::assertNotContains($this->world['partial-paid'], $this->ids('/invoices?status=credited'));

        // The literal scale on an uncredited invoice (a bare COALESCE(SUM(…), 0) would read "0") and the arithmetic.
        $plain = $this->read($this->world['uncredited']);
        self::assertSame(['0.0000', '10.0000'], [$plain['amount_credited'], $plain['amount_due']]);
        $partial = $this->read($this->world['partial']);
        self::assertSame(['4.0000', '6.0000'], [$partial['amount_credited'], $partial['amount_due']]);
        $over = $this->read($this->world['over']);
        self::assertSame(['12.0000', '-2.0000'], [$over['amount_credited'], $over['amount_due']], 'amount_due is not clamped: a G-15 over-credit reads negative.');
    }

    /**
     * **Test 4, no new status, and the `overdue` derivation.** Every `due_at` is past. The fully credited and the
     * over-credited rows read `issued`; the zero-total uncredited row reads `overdue` (so the rejected `amount_due > 0`
     * form did not ship). And a credited row STORED as `overdue` — seeded here only, never in test 1's world — reads
     * `issued`: the predicate is the CASE's FIRST branch (mutation M-7).
     */
    #[Test]
    public function aCreditedInvoiceIsNeverOverdueAndNoNewStatusExists(): void
    {
        $this->actAsRole(DefaultRoles::FINANCE);
        self::assertSame('issued', $this->read($this->world['full'])['status']);
        self::assertSame('issued', $this->read($this->world['over'])['status']);
        self::assertSame('overdue', $this->read($this->world['zero'])['status'], 'A zero-total uncredited invoice no longer reads overdue.');
        self::assertSame('overdue', $this->read($this->world['uncredited'])['status']);

        $stored = $this->seedInvoiceRow($this->seedBareFulfilment($this->orderOf($this->world['full'])), 'INV-000210', 'overdue', '5.0000');
        $this->seedCreditNote($stored, '5.0000');
        $body = $this->read($stored);
        self::assertSame('issued', $body['status'], 'A credited invoice STORED as overdue read overdue: the credited branch is not first.');
        self::assertTrue($body['credited_in_full']);

        foreach ($this->world as $id) {
            self::assertContains($this->read($id)['status'], ['issued', 'overdue', 'paid', 'void'], 'A status outside the stored domain was served.');
        }
    }

    /** **Test 5, `PayInvoice` refuses both credited rows**; the zero-total uncredited row is still paid. */
    #[Test]
    public function anInvoiceCreditedInFullCannotBePaid(): void
    {
        $this->actAsRole(DefaultRoles::FINANCE);
        foreach (['full', 'over'] as $label) {
            $this->send('POST', '/invoices/'.$this->world[$label].'/payment', []);
            self::assertSame(409, $this->httpStatus(), CreditedSegment::LAPSE.' ('.$label.': '.$this->client()->getResponse()->getContent().')');
            self::assertSame('invoice.has_an_amount_due', $this->json()['rule'], CreditedSegment::LAPSE);
            self::assertSame('issued', $this->storedStatus($this->world[$label]));
        }

        $this->send('POST', '/invoices/'.$this->world['zero'].'/payment', []);
        self::assertSame(200, $this->httpStatus(), 'A zero-total uncredited invoice can no longer be paid: '.(string) $this->client()->getResponse()->getContent());
    }

    /**
     * **Test 9, the order keeps shipped answers** (amendments RED M-1): the dates keep their shipped place AHEAD of the
     * status guard. Mutation: move the status guard ahead of `paidAt()` → red on all three.
     */
    #[Test]
    public function paymentDatesAreJudgedBeforeTheStatusAsShipped(): void
    {
        $this->actAsRole(DefaultRoles::FINANCE);
        $this->send('POST', '/invoices/'.$this->world['paid'].'/payment', ['paid_at' => '2099-01-01T00:00:00Z']);
        self::assertSame([422, 'in_the_future'], [$this->httpStatus(), $this->json()['errors'][0]['code'] ?? null]);

        $this->send('POST', '/invoices/'.$this->world['paid'].'/payment', ['paid_at' => '2026-07-01T00:00:00Z']);
        self::assertSame([409, 'invoice.paid_after_issue'], [$this->httpStatus(), $this->json()['rule'] ?? null]);

        $this->send('POST', '/invoices/'.$this->world['void'].'/payment', ['paid_at' => '2099-01-01T00:00:00Z']);
        self::assertSame([422, 'in_the_future'], [$this->httpStatus(), $this->json()['errors'][0]['code'] ?? null]);
    }

    /** **Test 10, where the new refusal sits**: behind the dates (422 first), and the 409 otherwise. */
    #[Test]
    public function theCreditedRefusalComesAfterTheDates(): void
    {
        $this->actAsRole(DefaultRoles::FINANCE);
        $this->send('POST', '/invoices/'.$this->world['full'].'/payment', ['paid_at' => '2099-01-01T00:00:00Z']);
        self::assertSame([422, 'in_the_future'], [$this->httpStatus(), $this->json()['errors'][0]['code'] ?? null]);

        foreach ([['paid_at' => '2026-08-15T00:00:00Z'], []] as $body) {
            $this->send('POST', '/invoices/'.$this->world['full'].'/payment', $body);
            self::assertSame([409, 'invoice.has_an_amount_due'], [$this->httpStatus(), $this->json()['rule'] ?? null], CreditedSegment::LAPSE);
        }
    }

    /** **Test 11, `credited_in_full` matches membership** for every row; paid and void rows read false. Wire type boolean. */
    #[Test]
    public function theServedLabelIsExactlyCreditedMembership(): void
    {
        $this->actAsRole(DefaultRoles::FINANCE);
        $credited = $this->ids('/invoices?status=credited');
        foreach ($this->world as $label => $id) {
            $flag = $this->read($id)['credited_in_full'];
            self::assertIsBool($flag, $label.': credited_in_full is not a boolean on the wire.');
            self::assertSame(\in_array($id, $credited, true), $flag, $label.': credited_in_full disagrees with Credited membership.');
        }
        $this->send('GET', '/invoices');
        foreach ($this->json()['items'] as $item) {
            self::assertSame(\in_array($item['id'], $credited, true), $item['credited_in_full'], 'ListInvoices and GetInvoice disagree on credited_in_full.');
        }
    }

    /**
     * **Test 6 (optional, UX L2), taken:** `InvoiceViews::SEGMENT_NAMES` is `API.md` §4.2 `ListInvoices`' value set.
     */
    #[Test]
    public function theSegmentNamesAreTheContractsValueSet(): void
    {
        if (!\App\Tests\Support\ContractDocument::isAvailable()) {
            self::markTestSkipped('docs/API.md is gitignored.');
        }
        $row = \App\Tests\Support\ContractDocument::operationRows()['ListInvoices'];
        self::assertSame(1, preg_match('/`status` \(([^)]*)\)/', $row['query'], $m), 'ListInvoices\' parameter cell no longer lists the status values.');
        self::assertSame(InvoiceViews::SEGMENT_NAMES, array_map(static fn (string $v): string => trim($v, '`'), explode('·', $m[1])));
    }

    // -----------------------------------------------------------------------

    /** @return list<string> */
    private function ids(string $path): array
    {
        $this->send('GET', $path);
        self::assertSame(200, $this->httpStatus(), $path.': '.(string) $this->client()->getResponse()->getContent());

        return array_column($this->json()['items'], 'id');
    }

    /** @return array<string, mixed> */
    private function read(string $id): array
    {
        $this->send('GET', '/invoices/'.$id);
        self::assertSame(200, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());

        return $this->json();
    }

    private function label(string $id): string
    {
        return (string) (array_search($id, $this->world, true) ?: $id);
    }

    private function storedStatus(string $id): string
    {
        return (string) $this->fetchAsOwnerForTenant(self::TENANT_A, 'SELECT status FROM invoice WHERE id = ?::uuid', [$id])[0]['status'];
    }

    private function orderOf(string $invoiceId): string
    {
        return (string) $this->fetchAsOwnerForTenant(self::TENANT_A, 'SELECT sales_order_id::text AS o FROM invoice WHERE id = ?::uuid', [$invoiceId])[0]['o'];
    }

    private function actAsRole(string $role): void
    {
        $this->actAs(self::TENANT_A, DefaultRoles::codesFor($role));
    }
}
