<?php

declare(strict_types=1);

namespace App\Tests\Tenancy;

use App\Modules\Customers\Application\Contract\PaymentTerms;
use PHPUnit\Framework\Attributes\Test;

/**
 * **The customer's payment-terms bound and the invoice's due window are one number** (customers slice 1 C2; design §4.1).
 *
 * `PaymentTerms::MAXIMUM_DAYS` is read by `CustomerWriter` (refusing terms beyond it) and by `InvoiceIssuer` (refusing to
 * issue on them); `invoice_due_window` is the database's own bound on `due_at`. This test reads the CHECK's definition
 * from the server (`pg_constraint`), never from a migration file, and holds its interval equal to the constant — so a
 * constant moved without the CHECK, or a CHECK moved without the constant, is red here before any request is made.
 */
final class PaymentTermsBoundTest extends TenancyTestCase
{
    #[Test]
    public function theMaximumTermsAreTheInvoiceDueWindow(): void
    {
        $definition = (string) $this->owner()->fetchOne(
            "SELECT pg_get_constraintdef(oid) FROM pg_constraint WHERE conrelid = 'invoice'::regclass AND conname = 'invoice_due_window'");

        self::assertNotSame('', $definition, 'invoice_due_window was not found, so nothing was compared.');
        self::assertSame(1, preg_match_all("/'(\\d+) days'::interval/", $definition, $matches),
            'invoice_due_window should name exactly one interval in days: '.$definition);
        self::assertSame(PaymentTerms::MAXIMUM_DAYS, (int) $matches[1][0],
            'PaymentTerms::MAXIMUM_DAYS and invoice_due_window disagree: a customer could hold terms no invoice can be issued on, or the reverse.');
        self::assertSame(0, PaymentTerms::MINIMUM_DAYS, 'invoice_due_window also requires due_at >= issued_at: terms below zero are never usable.');
        self::assertStringContainsString('due_at >= issued_at', $definition);
    }
}
