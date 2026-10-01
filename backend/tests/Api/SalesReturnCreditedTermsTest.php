<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Modules\UserManagement\Domain\DefaultRoles;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Uid\Uuid;

/**
 * **Test 12 — no return is creditable against an invoice credited in full** (sales step 7 C0;
 * `output9-sales-8-c0-amendments.md` A-5 test 12 and A-8; `SEC` `output9-sales-8-l5-1-sec.md` CORRECTED FINAL C0 note).
 *
 * The implication that ties the money-level segment (`InvoiceCredit`) to chunk A's quantity-level `creditable`
 * (`SalesReturnReader`). It holds only because the queue prices a return with `IssueCreditNote`'s own telescope over
 * TWO credited terms — credited on the return line, and credited on the shipped line (other returns' credits and
 * adjustments included). Shipments, invoices and returns go THROUGH THE API; the credit notes are seeded as the owner
 * (no credit writer exists at C0 — the G-7 caveat; switch to `IssueCreditNote` / `IssueAdjustmentCreditNote` at
 * C1/C1b). **`GetSalesReturn` serves no `creditable` member** — its served signal is `credit_target`, null unless
 * creditable (L5-1 (b)) — so the "creditable: false" half of the amendment is asserted as a null `credit_target`.
 *
 * Mutations (run, never predicted — `output9-sales-8-build-progress.md` chunk B): **M-8** the non-telescoped test
 * `round(r × p, 4) > 0` → red on the 0.0001 rounding row; **M-9** one shared term in both `LEAST` positions → red on
 * the other-return row.
 */
final class SalesReturnCreditedTermsTest extends PurchaseOrderTestCase
{
    use InvoiceFixtures;
    use CreditFixtures;

    /**
     * **The rounding row** (amendments A-5 test 12): 1 unit at `0.0001`, invoiced `0.0001`; a return of that unit after
     * issue; one adjustment credit of 0.5 of it, amount `round(0.5 × 0.0001, 4) = 0.0001`. The invoice is credited in
     * full, and the return must not be creditable — under the telescope the remaining 0.5 prices to
     * `round(1.0 × p) − round(0.5 × p) = 0`. **Control:** a return on a second, uncredited invoice at a normal price IS
     * creditable, and the credited return IS in the unfiltered list (the absence is not vacuous).
     */
    #[Test]
    public function aReturnOnAnInvoiceCreditedInFullIsNotCreditableEvenWhenRoundingLeavesQuantity(): void
    {
        $tiny = $this->invoiced('CT-TINY', '0.0001', '1');
        $tinyReturn = $this->returned($tiny, [['line' => 0, 'quantity' => '1']]);
        $this->seedCreditNote($tiny['invoice'], '0.0001', [[$tiny['lines'][0], '0.5', '0.0001']]);

        $control = $this->invoiced('CT-CTRL', '10.0000', '1');
        $controlReturn = $this->returned($control, [['line' => 0, 'quantity' => '1']]);

        $this->actAsRole(DefaultRoles::FINANCE);
        $this->send('GET', '/invoices/'.$tiny['invoice']);
        self::assertTrue($this->json()['credited_in_full'], 'Fixture: the 0.0001 invoice is not credited in full.');

        self::assertContains($tinyReturn, $this->ids('/sales-returns'), 'Seeded, not vacuous.');
        $creditable = $this->ids('/sales-returns?creditable=true');
        self::assertNotContains($tinyReturn, $creditable, 'A return is creditable against an invoice credited in full (the rounding row).');
        self::assertContains($controlReturn, $creditable, 'Control: an uncredited invoice\'s return is not creditable.');
        $this->send('GET', '/sales-returns/'.$tinyReturn);
        self::assertNull($this->json()['credit_target']);
        self::assertSame('0.000000', $this->json()['lines'][0]['quantity_credited'], 'An adjustment credited nothing against the return line.');
    }

    /**
     * **The other-return row** (`SEC` §M-g's second fixture): price `1.0000`, 2 shipped, returns A and B of 1 each
     * after issue; a credit of 1 against A's line plus a 1-unit adjustment on the same shipped line — the shipped line's
     * credited quantity is 2 and the invoice is credited in full. B: `r = min(1 − 0, 2 − 0 − 2) = 0`, not creditable.
     * A's own line reads `quantity_credited` 1 (the return-line term), B's 0.
     */
    #[Test]
    public function anotherReturnsCreditAndAnAdjustmentUseUpTheShippedLine(): void
    {
        $i = $this->invoiced('CT-TWO', '1.0000', '2');
        $a = $this->returned($i, [['line' => 0, 'quantity' => '1']]);
        $b = $this->returned($i, [['line' => 0, 'quantity' => '1']]);
        $aLine = (string) $this->fetchAsOwnerForTenant(self::TENANT_A, 'SELECT id::text AS l FROM sales_return_line WHERE sales_return_id = ?::uuid', [$a])[0]['l'];
        $this->seedCreditNote($i['invoice'], '1.0000', [[$i['lines'][0], '1', '1.0000', $aLine]], $a);
        $this->seedCreditNote($i['invoice'], '1.0000', [[$i['lines'][0], '1', '1.0000']]);

        $this->actAsRole(DefaultRoles::FINANCE);
        $this->send('GET', '/invoices/'.$i['invoice']);
        self::assertTrue($this->json()['credited_in_full'], 'Fixture: the invoice is not credited in full.');

        $all = $this->ids('/sales-returns');
        self::assertContains($b, $all, 'Seeded, not vacuous.');
        $creditable = $this->ids('/sales-returns?creditable=true');
        self::assertNotContains($b, $creditable, 'B is creditable though the shipped line is credited in full (other returns\' credits are not counted).');
        self::assertNotContains($a, $creditable);

        $this->send('GET', '/sales-returns/'.$a);
        self::assertSame('1.000000', $this->json()['lines'][0]['quantity_credited']);
        $this->send('GET', '/sales-returns/'.$b);
        self::assertSame('0.000000', $this->json()['lines'][0]['quantity_credited']);
        self::assertNull($this->json()['credit_target']);
    }

    /**
     * **The implication over the segment world** (amendments test 12's statement): for every invoice reading
     * `credited_in_full = true`, `?creditable=true` holds no return whose `credit_target` is that invoice, and each such
     * return reads a null `credit_target`. A partially credited invoice's return stays creditable (control).
     */
    #[Test]
    public function noReturnInTheCreditableQueueTargetsAnInvoiceCreditedInFull(): void
    {
        $full = $this->invoiced('CT-FULL', '10.0000', '2');
        $fullReturn = $this->returned($full, [['line' => 0, 'quantity' => '1']]);
        $this->seedCreditNote($full['invoice'], '20.0000', [[$full['lines'][0], '2', '20.0000']]);
        $part = $this->invoiced('CT-PART', '10.0000', '2');
        $partReturn = $this->returned($part, [['line' => 0, 'quantity' => '1']]);
        $this->seedCreditNote($part['invoice'], '10.0000', [[$part['lines'][0], '1', '10.0000']]);

        $this->actAsRole(DefaultRoles::FINANCE);
        $this->send('GET', '/invoices?status=credited');
        $credited = array_column($this->json()['items'], 'id');
        self::assertContains($full['invoice'], $credited);
        self::assertNotContains($part['invoice'], $credited);

        $this->send('GET', '/sales-returns?creditable=true');
        foreach ($this->json()['items'] as $return) {
            self::assertNotContains($return['credit_target']['id'] ?? null, $credited, 'A creditable return targets an invoice credited in full.');
        }
        self::assertContains($partReturn, array_column($this->json()['items'], 'id'), 'Control: a partly credited invoice\'s return left the queue.');
        $this->send('GET', '/sales-returns/'.$fullReturn);
        self::assertNull($this->json()['credit_target']);
    }

    // -----------------------------------------------------------------------

    /**
     * A one-line shipment of `$shipped` at `$price`, written through the API, and its invoice, issued through it.
     *
     * @return array{invoice: string, fulfilment: string, lines: list<string>, bin: string}
     */
    private function invoiced(string $tag, string $price, string $shipped): array
    {
        $s = $this->shipped($tag, [[$shipped, $price, $shipped]]);
        $this->send('GET', '/fulfilments/'.$s['fulfilment']);
        $lines = array_column($this->json()['lines'], 'id');
        $bin = $this->json()['lines'][0]['storage_location']['id'];
        $this->actAsRole(DefaultRoles::SALES_REP);
        $this->issue($s['fulfilment']);
        self::assertSame(201, $this->httpStatus(), 'Fixture: the invoice. '.(string) $this->client()->getResponse()->getContent());

        return ['invoice' => $this->json()['id'], 'fulfilment' => $s['fulfilment'], 'lines' => $lines, 'bin' => $bin];
    }

    /**
     * A return recorded after issue by an owner, through the API (stamped with the live invoice, so non-deducting).
     *
     * @param array{invoice: string, fulfilment: string, lines: list<string>, bin: string} $i
     * @param list<array{line: int, quantity: string}>                                       $lines
     */
    private function returned(array $i, array $lines): string
    {
        $this->actAsRole(DefaultRoles::OWNER);
        $this->send('POST', '/fulfilments/'.$i['fulfilment'].'/sales-returns', ['reason' => 'other', 'lines' => array_map(
            static fn (array $l): array => ['fulfilment_line_id' => $i['lines'][$l['line']], 'quantity' => $l['quantity'], 'storage_location_id' => $i['bin']],
            $lines,
        )], ['Idempotency-Key' => Uuid::v4()->toRfc4122()]);
        self::assertSame(201, $this->httpStatus(), 'Fixture: the return. '.(string) $this->client()->getResponse()->getContent());

        return $this->json()['id'];
    }

    /** @return list<string> */
    private function ids(string $path): array
    {
        $this->send('GET', $path);
        self::assertSame(200, $this->httpStatus(), $path.': '.(string) $this->client()->getResponse()->getContent());

        return array_column($this->json()['items'], 'id');
    }
}
