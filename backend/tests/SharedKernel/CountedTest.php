<?php

declare(strict_types=1);

namespace App\Tests\SharedKernel;

use App\Modules\Customers\Application\CustomerWriter;
use App\Modules\Suppliers\Application\SupplierWriter;
use App\Modules\UserManagement\Application\RefusalCounts;
use App\SharedKernel\Display\Counted;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * `UX` R-2 — **a count is written singular or plural, never `(s)`** — in its one executable form, {@see Counted::of()}
 * (`ARCH` F-10, `docs/session13/owed-arch-c5.md`), and every noun function that feeds a refusal sentence through it.
 *
 * Why each noun function has its own n=1 / n=2 test (`owed-ux-c5.md` Response to RED, M-1): the API tests pin the
 * sentences at n=1 almost everywhere, so the `many` spelling of most nouns would otherwise be checked nowhere, and a
 * typo there ("open sales order" for both) would ship green. A pure unit test, no kernel, no database.
 */
final class CountedTest extends TestCase
{
    #[Test]
    public function oneIsSingularAndEveryOtherCountIsPlural(): void
    {
        self::assertSame('1 box', Counted::of(1, 'box', 'boxes'));
        self::assertSame('2 boxes', Counted::of(2, 'box', 'boxes'));
        self::assertSame('0 boxes', Counted::of(0, 'box', 'boxes'));
        self::assertSame('11 boxes', Counted::of(11, 'box', 'boxes'));
    }

    /**
     * `RefusalCounts` delegates (F-10) and must keep its words byte-identical: the literal pins in `RoleUpdateTest` /
     * `RoleDeleteTest` are the shipped check; this one is the fast one, and it states the expected strings literally so a
     * delegation that passed the wrong nouns goes red here.
     */
    #[Test]
    public function refusalCountsDelegatesWithoutChangingItsWords(): void
    {
        self::assertSame(['0 people', '1 person', '2 people'], array_map(RefusalCounts::people(...), [0, 1, 2]));
        self::assertSame(
            ['0 role proposals are', '1 role proposal is', '2 role proposals are'],
            array_map(RefusalCounts::openProposals(...), [0, 1, 2]),
        );
    }

    #[Test]
    public function openPurchaseOrders(): void
    {
        self::assertSame('1 open purchase order', SupplierWriter::openPurchaseOrders(1));
        self::assertSame('2 open purchase orders', SupplierWriter::openPurchaseOrders(2));
    }

    #[Test]
    public function openSalesOrders(): void
    {
        self::assertSame('1 open sales order', CustomerWriter::openSalesOrders(1));
        self::assertSame('2 open sales orders', CustomerWriter::openSalesOrders(2));
    }

    #[Test]
    public function shipments(): void
    {
        self::assertSame('1 shipment', CustomerWriter::shipments(1));
        self::assertSame('2 shipments', CustomerWriter::shipments(2));
    }

    #[Test]
    public function unpaidInvoices(): void
    {
        self::assertSame('1 unpaid invoice', CustomerWriter::unpaidInvoices(1));
        self::assertSame('2 unpaid invoices', CustomerWriter::unpaidInvoices(2));
    }

    #[Test]
    public function returns(): void
    {
        self::assertSame('1 return', CustomerWriter::returns(1));
        self::assertSame('2 returns', CustomerWriter::returns(2));
    }

    /** The counted sentences take `%s`, never `%d`: a `%d` would print `0` for the noun phrase and never show the noun. */
    #[Test]
    public function everyCountedSentenceTakesTheNounPhrase(): void
    {
        foreach ([
            SupplierWriter::HAS_OPEN_PURCHASE_ORDERS,
            CustomerWriter::HAS_OPEN_SALES_ORDERS,
            CustomerWriter::HAS_UNBILLED_SHIPMENTS,
            CustomerWriter::HAS_UNPAID_INVOICES,
            CustomerWriter::IS_OWED_A_CREDIT,
        ] as $sentence) {
            self::assertSame(1, substr_count($sentence, '%s'), $sentence);
            self::assertStringNotContainsString('%d', $sentence);
            self::assertStringNotContainsString('(s)', $sentence);
        }
    }
}
