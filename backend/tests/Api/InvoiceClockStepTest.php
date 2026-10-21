<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Modules\UserManagement\Domain\DefaultRoles;
use PHPUnit\Framework\Attributes\Test;

/**
 * **A payment, or its reversal, is never dated before the invoice, even when the database clock steps back** (proposal
 * clock fix, 2026-10-21; `docs/session13/proposal-clock-fix-design.md` §3.2–§3.3 as corrected by
 * `proposal-clock-fix-red.md` H-1 and M-1).
 *
 * `issued_at` is read from the database's `now()` at issue; `paid_at` and `payment_reversed_at` from a later
 * transaction's `now()`. When that wall clock steps back in between, the shipped code answered:
 *  - `PayInvoice` with no `paid_at`: 23514 `invoice_paid_not_before_issue` as a **500** (the default returned before the
 *    issue check);
 *  - `PayInvoice` with an honest `paid_at` on or after the issue: a **false 409** `invoice.paid_after_issue` (the clamp to
 *    `now()` dropped it below `issued_at`);
 *  - `ReverseInvoicePayment`: 23514 `invoice_payment_reversed_not_before_issue` as a **500**.
 * The fix dates the later instant no earlier than the issue, within 60 seconds; beyond that the invoice was written outside
 * the application (G-15) and nothing is changed.
 *
 * **No clock is moved:** `issued_at` is moved into the database's future as the owner (one statement, every invoice CHECK
 * kept), which is what the later transaction sees after a backward step. Whole seconds, so an explicit `paid_at` can name
 * it exactly. One request per test: a failed request closes the shared entity manager.
 */
final class InvoiceClockStepTest extends PurchaseOrderTestCase
{
    use InvoiceFixtures;

    /** **T-D:** no `paid_at`, the invoice 5–6 seconds in the future → 200, `paid_at` = `issued_at`. Was a 500. */
    #[Test]
    public function aPaymentWithNoDateAfterABackwardStepIsDatedAtTheIssue(): void
    {
        $invoice = $this->issuedAhead('CLK-PAY', 5);
        $this->actAsRole(DefaultRoles::FINANCE);

        $this->send('POST', '/invoices/'.$invoice.'/payment', []);

        self::assertSame(200, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        self::assertSame([['status' => 'paid', 'equal' => true]], $this->fetchAsOwnerForTenant(self::TENANT_A,
            'SELECT status, paid_at = issued_at AS equal FROM invoice WHERE id = ?::uuid', [$invoice]));
    }

    /**
     * **T-D′:** an explicit `paid_at` equal to the issue (inside the 60-second skew allowance) → 200, stored as the issue.
     * Was a false 409 `invoice.paid_after_issue` about a date that is not before the issue. The honest twin — a date
     * before the issue is still 409 — is `InvoicePaymentTest::aPaymentDatedBeforeTheInvoiceIsRefused`.
     */
    #[Test]
    public function aPaymentDatedAtTheIssueAfterABackwardStepIsAccepted(): void
    {
        $invoice = $this->issuedAhead('CLK-PAYX', 5);
        $issuedAt = (string) $this->fetchAsOwnerForTenant(self::TENANT_A,
            "SELECT to_char(issued_at AT TIME ZONE 'UTC', 'YYYY-MM-DD\"T\"HH24:MI:SS\"Z\"') AS i FROM invoice WHERE id = ?::uuid", [$invoice])[0]['i'];
        $this->actAsRole(DefaultRoles::FINANCE);

        $this->send('POST', '/invoices/'.$invoice.'/payment', ['paid_at' => $issuedAt]);

        self::assertSame(200, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        self::assertSame([['status' => 'paid', 'equal' => true]], $this->fetchAsOwnerForTenant(self::TENANT_A,
            'SELECT status, paid_at = issued_at AS equal FROM invoice WHERE id = ?::uuid', [$invoice]));
    }

    /** **M-1 for `PayInvoice`:** an invoice two minutes in the future is no clock step (G-15) — 500, still unpaid. */
    #[Test]
    public function aPaymentOnAnInvoiceFarInTheFutureIsNotRecorded(): void
    {
        $invoice = $this->issuedAhead('CLK-PAYF', 120);
        $this->actAsRole(DefaultRoles::FINANCE);

        $this->send('POST', '/invoices/'.$invoice.'/payment', []);

        self::assertSame(500, $this->httpStatus(), 'A payment was recorded on an invoice dated two minutes ahead.');
        self::assertSame([['status' => 'issued', 'p' => null]], $this->fetchAsOwnerForTenant(self::TENANT_A,
            'SELECT status, paid_at::text AS p FROM invoice WHERE id = ?::uuid', [$invoice]));
    }

    /** **T-C:** a paid invoice whose issue lies 5–6 seconds ahead → the reversal is 200, `payment_reversed_at` = `issued_at`. Was a 500. */
    #[Test]
    public function aReversalAfterABackwardStepIsDatedAtTheIssue(): void
    {
        $invoice = $this->paidAhead('CLK-REV', 5);
        $this->actAsRole(DefaultRoles::FINANCE);

        $this->send('POST', '/invoices/'.$invoice.'/payment-reversal', ['reason' => 'Recorded in error.']);

        self::assertSame(200, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        self::assertSame([['status' => 'issued', 'equal' => true]], $this->fetchAsOwnerForTenant(self::TENANT_A,
            'SELECT status, payment_reversed_at = issued_at AS equal FROM invoice WHERE id = ?::uuid', [$invoice]));
    }

    /** **The normal path still uses the clock:** a reversal of an ordinary invoice is dated strictly after its issue. */
    #[Test]
    public function anOrdinaryReversalIsDatedByTheClock(): void
    {
        $invoice = $this->paidAhead('CLK-REVN', -60);
        $this->actAsRole(DefaultRoles::FINANCE);

        $this->send('POST', '/invoices/'.$invoice.'/payment-reversal', ['reason' => 'Recorded in error.']);

        self::assertSame(200, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        self::assertSame([['after' => true, 'recent' => true]], $this->fetchAsOwnerForTenant(self::TENANT_A,
            "SELECT payment_reversed_at > issued_at AS after, payment_reversed_at >= now() - interval '1 minute' AS recent FROM invoice WHERE id = ?::uuid",
            [$invoice]));
    }

    /** **M-1 for `ReverseInvoicePayment`:** an issue two minutes ahead is not clamped — 500, still paid, never reversed. */
    #[Test]
    public function aReversalOnAnInvoiceFarInTheFutureIsNotRecorded(): void
    {
        $invoice = $this->paidAhead('CLK-REVF', 120);
        $this->actAsRole(DefaultRoles::FINANCE);

        $this->send('POST', '/invoices/'.$invoice.'/payment-reversal', ['reason' => 'Recorded in error.']);

        self::assertSame(500, $this->httpStatus(), 'A reversal was recorded on an invoice dated two minutes ahead.');
        self::assertSame([['status' => 'paid', 'r' => null]], $this->fetchAsOwnerForTenant(self::TENANT_A,
            'SELECT status, payment_reversed_at::text AS r FROM invoice WHERE id = ?::uuid', [$invoice]));
    }

    /** An invoice issued through the API, then its `issued_at` moved to a whole second `$seconds`–`$seconds + 1` ahead (negative: behind). */
    private function issuedAhead(string $tag, int $seconds): string
    {
        $invoice = $this->issuedInvoice($tag)['invoice'];
        $this->executeAsOwnerForTenant(self::TENANT_A, <<<'SQL'
            UPDATE invoice SET issued_at = c.t FROM (SELECT date_trunc('second', clock_timestamp()) + make_interval(secs => ?) + interval '1 second' AS t) c
             WHERE id = ?::uuid
            SQL, [$seconds, $invoice]);

        return $invoice;
    }

    /** As `issuedAhead()`, then paid through the API by one `finance` user and `paid_at` set to the issue in the same statement. */
    private function paidAhead(string $tag, int $seconds): string
    {
        $invoice = $this->issuedInvoice($tag)['invoice'];
        $this->actAsRole(DefaultRoles::FINANCE);
        $this->send('POST', '/invoices/'.$invoice.'/payment', []);
        self::assertSame(200, $this->httpStatus(), 'Fixture: the payment was refused. '.(string) $this->client()->getResponse()->getContent());
        $this->executeAsOwnerForTenant(self::TENANT_A, <<<'SQL'
            UPDATE invoice SET issued_at = c.t, paid_at = c.t
              FROM (SELECT date_trunc('second', clock_timestamp()) + make_interval(secs => ?) + interval '1 second' AS t) c
             WHERE id = ?::uuid
            SQL, [$seconds, $invoice]);

        return $invoice;
    }
}
