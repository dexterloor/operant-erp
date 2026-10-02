<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Modules\UserManagement\Domain\DefaultRoles;
use App\SharedKernel\ValueObject\AuditEvent;
use App\Tests\Support\CreditedSegment;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Uid\Uuid;

/**
 * **`IssueCreditNote` and `GetCreditNote` — sales step 7, commit C1** (2026-10-01), built from
 * `docs/session13/output9-sales-8-design.md` §4.4–§4.6 under the owner's rulings of 2026-09-30
 * (`output9-sales-8-ruling.md`), ADR-008 A2.1b + SoD-5, and `docs/session13/output9-sales-8-c0-amendments.md` A-7.
 *
 * Every shipment, invoice, return and credit note goes THROUGH THE API; an adjustment credit (no writer until C1b) and
 * deliberate corruptions are seeded as the owner and say so. The races (void ∥ credit, credit ∥ credit, return ∥ pay,
 * key and number) are in `PurchaseOrderRowLockTest`, beside the holder harness.
 */
final class CreditNoteIssueTest extends PurchaseOrderTestCase
{
    use InvoiceFixtures;
    use CreditFixtures;
    use CreditNoteFixtures;

    /** The operations under `/credit-notes`. `IssueCreditNote` hangs off the invoice and is pinned with it. */
    public const PINNED_CREDIT_NOTE_OPERATIONS = [
        'ListCreditNotes' => ['method' => 'GET', 'path' => '/credit-notes', 'idem' => false],
        'GetCreditNote' => ['method' => 'GET', 'path' => '/credit-notes/{id}', 'idem' => false],
    ];

    #[Test]
    public function theCreditNotePathsDeclareExactlyThePinnedOperations(): void
    {
        $declared = [];
        foreach (TransactionBoundaryTest::contractOperations() as $name => $operation) {
            if (str_starts_with((string) $operation->getUriTemplate(), '/credit-notes')) {
                $declared[$name] = ['method' => $operation->getMethod(), 'path' => $operation->getUriTemplate()];
            }
        }

        self::assertSame(array_map(static fn (array $p): array => ['method' => $p['method'], 'path' => $p['path']], self::PINNED_CREDIT_NOTE_OPERATIONS), $declared);
    }

    /**
     * The whole path: `finance` credits one unit of a return recorded after issue. 201 with `Location` naming
     * `GetCreditNote`, the §4.4.3 shape, the telescoped amount, `credit_note.issued` `{"reason"}` cell by cell, the
     * invoice's credit fields and the return's `quantity_credited` moved, and the return leaves the credit queue.
     */
    #[Test]
    public function financeCreditsAReturnAndTheInvoiceOwesLess(): void
    {
        $i = $this->invoicedShipment('CN-A', [['4', '12.5000', '2']]);
        $this->actAsRole(DefaultRoles::OWNER);
        $return = $this->returnOf($i, [[0, '1']]);

        $issuer = $this->actAsRole(DefaultRoles::FINANCE);
        $this->credit($i, $return, [[0, '1']]);

        self::assertSame(201, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        $body = $this->json();
        self::assertSame('/api/v1/credit-notes/'.$body['id'], $this->client()->getResponse()->headers->get('Location'));
        self::assertSame(['id', 'credit_note_number', 'reason', 'invoice', 'sales_return', 'currency_code', 'total_amount',
            'issued_at', 'issued_by', 'lines'], array_keys($body));
        self::assertSame('CN-000001', $body['credit_note_number']);
        self::assertSame('goods_returned', $body['reason']);
        self::assertSame(['id' => $i['invoice'], 'invoice_number' => $i['number']], $body['invoice']);
        self::assertSame($return, $body['sales_return']['id']);
        self::assertSame(['EUR', '12.5000'], [$body['currency_code'], $body['total_amount']]);
        self::assertSame(['id' => $issuer, 'display_name' => 'Fixture Actor'], $body['issued_by']);
        self::assertCount(1, $body['lines']);
        self::assertSame(['id', 'fulfilment_line', 'inventory_item', 'quantity', 'amount'], array_keys($body['lines'][0]));
        self::assertSame(['id', 'sales_order_line', 'inventory_item', 'quantity'], array_keys($body['lines'][0]['fulfilment_line']), 'Q-H money side: binless, with the shipped quantity.');
        self::assertSame(['1.000000', '12.5000', '2.000000'], [$body['lines'][0]['quantity'], $body['lines'][0]['amount'], $body['lines'][0]['fulfilment_line']['quantity']]);

        $this->send('GET', '/credit-notes/'.$body['id']);
        self::assertSame($body, $this->json(), 'PC-17: the 201 is not GetCreditNote\'s document.');

        self::assertSame([[
            'event_type' => AuditEvent::CREDIT_NOTE_ISSUED, 'actor_user_id' => $issuer, 'subject_type' => 'credit_note',
            'subject_id' => $body['id'], 'ip_address' => null, 'permission_code' => null, 'metadata' => '{"reason": "goods_returned"}',
        ]], $this->fetchAsOwnerForTenant(self::TENANT_A, <<<'SQL'
            SELECT event_type, actor_user_id::text AS actor_user_id, subject_type, subject_id::text AS subject_id,
                   ip_address::text AS ip_address, permission_code, metadata::text AS metadata
              FROM audit_log WHERE event_type = ?
            SQL, [AuditEvent::CREDIT_NOTE_ISSUED]));

        $this->send('GET', '/invoices/'.$i['invoice']);
        self::assertSame(['12.5000', '12.5000', false], [$this->json()['amount_credited'], $this->json()['amount_due'], $this->json()['credited_in_full']]);

        $this->send('GET', '/sales-returns/'.$return);
        self::assertSame('1.000000', $this->json()['lines'][0]['quantity_credited']);
        self::assertNull($this->json()['credit_target']);
        $this->send('GET', '/sales-returns?creditable=true');
        self::assertNotContains($return, array_column($this->json()['items'], 'id'));
    }

    /**
     * **`ListCreditNotes` — the register (sales step 7 C2; design §4.5; API-O-35 closes).** Return-linked notes and an
     * adjustment (C1b) on one invoice, and a note on another customer's invoice. Newest first by `credit_note_number`;
     * `invoice_id`, `customer_id` (through the invoice), `sales_return_id` (an adjustment never matches) and `q` (number
     * prefix) each narrow it; a foreign id is an empty page, never a 404; a row IS `GetCreditNote`'s document; the
     * cursor walks both ways; a bad id or cursor is a 422; and the list is `invoice.view`'s (Q-B) — a
     * `warehouse_operator`, who records returns, cannot read the money.
     */
    #[Test]
    public function theRegisterFiltersAndPagesNewestFirst(): void
    {
        $a = $this->invoicedShipment('CN-LA', [['4', '12.5000', '3']]);
        $b = $this->invoicedShipment('CN-LB', [['4', '10.0000', '2']]);
        $this->actAsRole(DefaultRoles::OWNER);
        $returnA = $this->returnOf($a, [[0, '1']]);
        $returnB = $this->returnOf($b, [[0, '1']]);

        $this->actAsRole(DefaultRoles::FINANCE);
        $this->credit($a, $returnA, [[0, '1']]);
        self::assertSame(201, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        $first = $this->json();
        $this->actAsRole(DefaultRoles::OWNER);
        $this->send('POST', '/invoices/'.$a['invoice'].'/credit-note-adjustments', ['reason' => 'damaged_goods_kept',
            'lines' => [['fulfilment_line_id' => $a['lines'][0], 'quantity' => '1']]], ['Idempotency-Key' => Uuid::v4()->toRfc4122()]);
        self::assertSame(201, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        $adjustment = $this->json();
        $this->actAsRole(DefaultRoles::FINANCE);
        $this->credit($b, $returnB, [[0, '1']]);
        self::assertSame(201, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        $third = $this->json();
        $this->send('GET', '/invoices/'.$b['invoice']);
        $customerB = $this->json()['customer']['id'];
        [$n1, $n2, $n3] = [$first['id'], $adjustment['id'], $third['id']];

        self::assertSame([$n3, $n2, $n1], $this->creditNoteIds('/credit-notes'), 'Not newest first by number.');
        self::assertSame([$n2, $n1], $this->creditNoteIds('/credit-notes?invoice_id='.$a['invoice']));
        self::assertSame([$n3], $this->creditNoteIds('/credit-notes?customer_id='.$customerB));
        self::assertSame([$n1], $this->creditNoteIds('/credit-notes?sales_return_id='.$returnA), 'An adjustment credits no return.');
        self::assertSame([$n3], $this->creditNoteIds('/credit-notes?sales_return_id='.$returnB.'&customer_id='.$customerB));
        self::assertSame([], $this->creditNoteIds('/credit-notes?sales_return_id='.$returnB.'&invoice_id='.$a['invoice']), 'The filters do not combine with AND.');
        self::assertSame([], $this->creditNoteIds('/credit-notes?invoice_id='.Uuid::v4()->toRfc4122()));
        self::assertTrue($this->json()['tenant_has_rows']);
        self::assertSame([$n3], $this->creditNoteIds('/credit-notes?q='.$third['credit_note_number']));
        self::assertSame([$n3, $n2, $n1], $this->creditNoteIds('/credit-notes?q=CN-00'));
        self::assertSame([], $this->creditNoteIds('/credit-notes?q=CN-1'));
        self::assertSame([], $this->creditNoteIds('/credit-notes?q=000'), 'TB-5a: q is a PREFIX match, never an infix.');

        $this->send('GET', '/credit-notes?invoice_id='.$a['invoice']);
        self::assertSame([$adjustment, $first], $this->json()['items'], 'A list row is not the 201\'s (= GetCreditNote\'s) document.');

        $this->send('GET', '/credit-notes?limit=2');
        self::assertSame([$n3, $n2], array_column($this->json()['items'], 'id'));
        self::assertNull($this->json()['previous_cursor']);
        $this->send('GET', '/credit-notes?limit=2&after='.urlencode((string) $this->json()['next_cursor']));
        self::assertSame([$n1], array_column($this->json()['items'], 'id'));
        self::assertNull($this->json()['next_cursor']);
        $this->send('GET', '/credit-notes?limit=2&before='.urlencode((string) $this->json()['previous_cursor']));
        self::assertSame([$n3, $n2], array_column($this->json()['items'], 'id'), 'The backward walk does not return the first page.');

        foreach (['customer_id=nope', 'sales_return_id=nope', 'after=nope', 'limit=201'] as $bad) {
            $this->send('GET', '/credit-notes?'.$bad);
            self::assertSame(422, $this->httpStatus(), $bad.': '.(string) $this->client()->getResponse()->getContent());
        }

        $this->actAsRole(DefaultRoles::WAREHOUSE_OPERATOR);
        $this->send('GET', '/credit-notes');
        self::assertSame(403, $this->httpStatus(), 'Q-B: credit notes are invoice.view\'s.');
    }

    /** @return list<string> */
    private function creditNoteIds(string $path): array
    {
        $this->send('GET', $path);
        self::assertSame(200, $this->httpStatus(), $path.': '.(string) $this->client()->getResponse()->getContent());

        return array_column($this->json()['items'], 'id');
    }

    /** API-14 / D-B: the same key and body is the same note (201, identical body); the same key, another body, 409. */
    #[Test]
    public function aRetryIsAReplayAndADifferentBodyIsAConflict(): void
    {
        $i = $this->invoicedShipment('CN-B', [['4', '10.0000', '3']]);
        $this->actAsRole(DefaultRoles::OWNER);
        $return = $this->returnOf($i, [[0, '2']]);
        $this->actAsRole(DefaultRoles::FINANCE);
        $key = Uuid::v4()->toRfc4122();

        $this->credit($i, $return, [[0, '1']], $key);
        self::assertSame(201, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        $first = $this->json();
        $this->credit($i, $return, [[0, '1']], $key);
        self::assertSame(201, $this->httpStatus());
        self::assertSame($first, $this->json());
        $this->credit($i, $return, [[0, '2']], $key);
        self::assertSame(409, $this->httpStatus());
        self::assertStringContainsString('idempotency-key-conflict', (string) $this->json()['type']);
        self::assertSame(1, $this->countAsOwnerForTenant(self::TENANT_A, 'SELECT count(*) FROM credit_note'));
        self::assertSame(1, $this->countAsOwnerForTenant(self::TENANT_A, 'SELECT count(*) FROM audit_log WHERE event_type = ?', [AuditEvent::CREDIT_NOTE_ISSUED]));
    }

    /**
     * **A-7, C1 — the Q-E half of TB-4's Credited condition (1):** a paid invoice, with a body that would otherwise be
     * accepted (a real return, creditable against this invoice until it was paid), is refused EXACTLY 409
     * `invoice.is_not_paid`. Mutation (run): delete the `paid` branch → the exact-code assertion is red.
     */
    #[Test]
    public function aPaidInvoiceCannotBeCredited(): void
    {
        $i = $this->invoicedShipment('CN-PAID', [['4', '10.0000', '2']]);
        $this->actAsRole(DefaultRoles::OWNER);
        $return = $this->returnOf($i, [[0, '1']]);
        $this->actAsRole(DefaultRoles::FINANCE);
        $this->send('POST', '/invoices/'.$i['invoice'].'/payment', ['acknowledged_return_ids' => [$return]]);
        self::assertSame(200, $this->httpStatus(), 'Fixture: paying past the return. '.(string) $this->client()->getResponse()->getContent());

        $this->credit($i, $return, [[0, '1']]);
        self::assertSame([409, 'invoice.is_not_paid'], [$this->httpStatus(), $this->json()['rule'] ?? null],
            'Q-E changed: a paid invoice was credited. '.CreditedSegment::LAPSE);
        self::assertSame(0, $this->countAsOwnerForTenant(self::TENANT_A, 'SELECT count(*) FROM credit_note'));
    }

    /** V2-R-17's mirror: a void invoice cannot be credited (`invoice.is_not_void`), under the lock. */
    #[Test]
    public function aVoidInvoiceCannotBeCredited(): void
    {
        $i = $this->invoicedShipment('CN-VOID', [['4', '10.0000', '2']]);
        $this->actAsRole(DefaultRoles::OWNER);
        $return = $this->returnOf($i, [[0, '1']]);
        $this->send('POST', '/invoices/'.$i['invoice'].'/void', ['reason' => 'Wrong customer.']);
        self::assertSame(200, $this->httpStatus(), 'Fixture: the void. '.(string) $this->client()->getResponse()->getContent());

        $this->actAsRole(DefaultRoles::FINANCE);
        $this->credit($i, $return, [[0, '1']]);
        self::assertSame([409, 'invoice.is_not_void'], [$this->httpStatus(), $this->json()['rule'] ?? null]);
    }

    /**
     * Step 6: the return must be one THIS invoice credits — a deducting return (taken off the bill at issue), or a return
     * of another shipment, is `credit_note.return_is_against_this_invoice`; an unknown or foreign return id is a 422.
     */
    #[Test]
    public function theReturnMustBeOneThisInvoiceCredits(): void
    {
        $i = $this->invoicedShipment('CN-RET', [['5', '10.0000', '4']], issue: false);
        $this->actAsRole(DefaultRoles::OWNER);
        $deducted = $this->returnOf($i, [[0, '1']]);
        $i = $this->issueOn($i);
        $other = $this->invoicedShipment('CN-RET2', [['4', '10.0000', '2']]);
        $this->actAsRole(DefaultRoles::OWNER);
        $foreignShipmentReturn = $this->returnOf($other, [[0, '1']]);

        $this->actAsRole(DefaultRoles::FINANCE);
        foreach ([$deducted, $foreignShipmentReturn] as $return) {
            $this->send('POST', '/invoices/'.$i['invoice'].'/credit-notes', ['reason' => 'goods_returned', 'sales_return_id' => $return,
                'lines' => [['fulfilment_line_id' => $i['lines'][0], 'quantity' => '1']]], ['Idempotency-Key' => Uuid::v4()->toRfc4122()]);
            self::assertSame([409, 'credit_note.return_is_against_this_invoice'], [$this->httpStatus(), $this->json()['rule'] ?? null]);
        }

        $this->credit($i, Uuid::v4()->toRfc4122(), [[0, '1']]);
        self::assertSame(422, $this->httpStatus());
        self::assertSame(['/sales_return_id', 'unknown_reference'], [$this->json()['errors'][0]['pointer'], $this->json()['errors'][0]['code']]);
    }

    /** V2-S-9: a line must be a shipped line of the invoice's shipment, and one the return took back. */
    #[Test]
    public function eachLineIsAShippedLineTheReturnTookBack(): void
    {
        $i = $this->invoicedShipment('CN-LN', [['4', '10.0000', '2'], ['4', '5.0000', '2']]);
        $this->actAsRole(DefaultRoles::OWNER);
        $return = $this->returnOf($i, [[0, '1']]);
        $other = $this->invoicedShipment('CN-LN2', [['4', '10.0000', '2']]);

        $this->actAsRole(DefaultRoles::FINANCE);
        $this->credit($i, $return, [[1, '1']]);
        self::assertSame(422, $this->httpStatus());
        self::assertSame(['/lines/0/fulfilment_line_id', 'not_on_the_return'], [$this->json()['errors'][0]['pointer'], $this->json()['errors'][0]['code']]);

        $this->send('POST', '/invoices/'.$i['invoice'].'/credit-notes', ['reason' => 'goods_returned', 'sales_return_id' => $return,
            'lines' => [['fulfilment_line_id' => $other['lines'][0], 'quantity' => '1']]], ['Idempotency-Key' => Uuid::v4()->toRfc4122()]);
        self::assertSame(422, $this->httpStatus());
        self::assertSame('unknown_reference', $this->json()['errors'][0]['code']);
    }

    /**
     * Step 7's two bounds: no more than the return line took back less what was credited against it
     * (`credit_note.quantity_within_returned`), and no more than the invoice billed on the shipped line less every credit
     * on it, adjustments included (`credit_note.quantity_within_billed`; the adjustment is owner-seeded, C1b's writer).
     */
    #[Test]
    public function moreCannotBeCreditedThanReturnedOrBilled(): void
    {
        $i = $this->invoicedShipment('CN-BND', [['4', '10.0000', '3']]);
        $this->actAsRole(DefaultRoles::OWNER);
        $return = $this->returnOf($i, [[0, '2']]);
        $this->actAsRole(DefaultRoles::FINANCE);

        $this->credit($i, $return, [[0, '2.5']]);
        self::assertSame([409, 'credit_note.quantity_within_returned'], [$this->httpStatus(), $this->json()['rule'] ?? null]);
        $this->credit($i, $return, [[0, '1.5']]);
        self::assertSame(201, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        $this->credit($i, $return, [[0, '1']]);
        self::assertSame([409, 'credit_note.quantity_within_returned'], [$this->httpStatus(), $this->json()['rule'] ?? null]);

        // Billed 3; 1.5 credited against the return; an adjustment of 1.5 more leaves nothing billed to credit.
        $this->seedCreditNote($i['invoice'], '15.0000', [[$i['lines'][0], '1.5', '15.0000']]);
        $this->credit($i, $return, [[0, '0.5']]);
        self::assertSame([409, 'credit_note.quantity_within_billed'], [$this->httpStatus(), $this->json()['rule'] ?? null]);
    }

    /**
     * **Rounding never over-credits** (design §4.4 step 8b; §13 item 7, RED's fixture): one unit at `0.0003`, credited in
     * two halves. A naive `round(q × p, 4)` would credit `0.0002 + 0.0002 = 0.0004` against `0.0003` billed; the telescope
     * credits `0.0002` then `round(1 × p) − round(0.5 × p) = 0.0001`, exactly what was billed, and the invoice is credited
     * in full.
     */
    #[Test]
    public function creditsTelescopeSoRoundingNeverOverCredits(): void
    {
        $i = $this->invoicedShipment('CN-TEL', [['1', '0.0003', '1']]);
        $this->actAsRole(DefaultRoles::OWNER);
        $return = $this->returnOf($i, [[0, '1']]);
        $this->actAsRole(DefaultRoles::FINANCE);

        $this->credit($i, $return, [[0, '0.5']]);
        self::assertSame('0.0002', $this->json()['total_amount'] ?? null, (string) $this->client()->getResponse()->getContent());
        $this->credit($i, $return, [[0, '0.5']]);
        self::assertSame('0.0001', $this->json()['total_amount'] ?? null, (string) $this->client()->getResponse()->getContent());

        $this->send('GET', '/invoices/'.$i['invoice']);
        self::assertSame(['0.0003', '0.0003', '0.0000', true], [$this->json()['total_amount'], $this->json()['amount_credited'], $this->json()['amount_due'], $this->json()['credited_in_full']]);
    }

    /**
     * **A line that telescopes to 0 is accepted beside a positive one** (RED L-1: refusing it would strand quantity in the
     * queue); **a note whose every line is 0** is `credit_note.amount_is_positive`. Line A: one unit at `0.0001`, the
     * first half credited for `0.0001`, so the second half telescopes to 0.
     */
    #[Test]
    public function aZeroLineIsAcceptedOnlyBesideAPositiveOne(): void
    {
        $i = $this->invoicedShipment('CN-ZERO', [['1', '0.0001', '1'], ['2', '10.0000', '2']]);
        $this->actAsRole(DefaultRoles::OWNER);
        $return = $this->returnOf($i, [[0, '1'], [1, '1']]);
        $this->actAsRole(DefaultRoles::FINANCE);

        $this->credit($i, $return, [[0, '0.5']]);
        self::assertSame('0.0001', $this->json()['total_amount'] ?? null, (string) $this->client()->getResponse()->getContent());
        $this->credit($i, $return, [[0, '0.5']]);
        self::assertSame([409, 'credit_note.amount_is_positive'], [$this->httpStatus(), $this->json()['rule'] ?? null]);

        $this->credit($i, $return, [[0, '0.5'], [1, '1']]);
        self::assertSame(201, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        self::assertSame(['0.0000', '10.0000'], array_column($this->json()['lines'], 'amount'));
        self::assertSame('10.0000', $this->json()['total_amount']);
    }

    /**
     * Step 8 (defensive): a line priced `0` (free goods, an API path) bills nothing and is not creditable; a NEGATIVE
     * price exists only by SQL (M-12) and is refused the same way — owner-seeded with the invoice total moved with it,
     * so 7a's equality holds and step 8 is what answers.
     */
    #[Test]
    public function aLinePricedAtOrBelowZeroIsNotCreditable(): void
    {
        // Every fixture first: a refused request closes the shared entity manager, so nothing is seeded after one.
        $free = $this->invoicedShipment('CN-FREE', [['2', '0', '1'], ['2', '10.0000', '1']]);
        $this->actAsRole(DefaultRoles::OWNER);
        $freeReturn = $this->returnOf($free, [[0, '1']]);
        $negative = $this->invoicedShipment('CN-NEG', [['2', '1.0000', '1'], ['2', '10.0000', '1']]);
        $this->actAsRole(DefaultRoles::OWNER);
        $negativeReturn = $this->returnOf($negative, [[0, '1']]);
        $this->executeAsOwnerForTenant(self::TENANT_A,
            'UPDATE sales_order_line SET unit_price = -1 WHERE id = (SELECT sales_order_line_id FROM fulfilment_line WHERE id = ?::uuid)', [$negative['lines'][0]]);
        $this->executeAsOwnerForTenant(self::TENANT_A, 'UPDATE invoice SET total_amount = 9 WHERE id = ?::uuid', [$negative['invoice']]);

        $this->actAsRole(DefaultRoles::FINANCE);
        $this->credit($free, $freeReturn, [[0, '1']]);
        self::assertSame([409, 'credit_note.line_is_creditable'], [$this->httpStatus(), $this->json()['rule'] ?? null]);
        $this->credit($negative, $negativeReturn, [[0, '1']]);
        self::assertSame([409, 'credit_note.line_is_creditable'], [$this->httpStatus(), $this->json()['rule'] ?? null]);
    }

    /**
     * **Step 7a — the recomputation must equal what was billed** (RED M-7). Read-back: every invoice issued through the
     * API above credits without tripping it, including the per-line rounding of `0.5 × 0.0001` lines. Corrupted: an
     * owner-seeded change of the stored total (G-15's shape) → the credit is refused 500-class and nothing is written.
     * Mutation (run): change the issuer's rounding to round the SUM → this read-back goes red.
     */
    #[Test]
    public function aCreditIsRefusedWhenTheInvoiceNoLongerAddsUp(): void
    {
        $i = $this->invoicedShipment('CN-7A', [['1', '0.0001', '0.5'], ['1', '0.0001', '0.5'], ['4', '12.5000', '2']]);
        $this->actAsRole(DefaultRoles::OWNER);
        $return = $this->returnOf($i, [[2, '1']]);
        $this->actAsRole(DefaultRoles::FINANCE);
        $this->credit($i, $return, [[2, '0.5']]);
        self::assertSame(201, $this->httpStatus(), 'The issuer\'s own total does not recompute: '.(string) $this->client()->getResponse()->getContent());

        $this->executeAsOwnerForTenant(self::TENANT_A, 'UPDATE invoice SET total_amount = total_amount + 1 WHERE id = ?::uuid', [$i['invoice']]);
        $this->credit($i, $return, [[2, '0.5']]);
        self::assertSame(500, $this->httpStatus());
        self::assertSame(1, $this->countAsOwnerForTenant(self::TENANT_A, 'SELECT count(*) FROM credit_note'), 'A credit was written against an invoice that does not add up.');
    }

    /**
     * **A-7, C1, through the API — TB-4's Credited segment's three exits stay shut:** a credit that brings the invoice to
     * zero puts it in Credited and out of Unpaid; `PayInvoice` → 409 `invoice.has_an_amount_due`; `VoidInvoice` → 409
     * `invoice.is_not_credited` (V2-R-17). Either turning into a 200 breaks condition (1).
     */
    #[Test]
    public function creditingAnInvoiceToZeroMovesItToCreditedAndShutsPaymentAndVoid(): void
    {
        $i = $this->invoicedShipment('CN-ZRO', [['4', '10.0000', '2']]);
        $this->actAsRole(DefaultRoles::OWNER);
        $return = $this->returnOf($i, [[0, '2']]);
        $this->actAsRole(DefaultRoles::FINANCE);
        $this->credit($i, $return, [[0, '2']]);
        self::assertSame(201, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());

        $this->send('GET', '/invoices?status=credited');
        self::assertContains($i['invoice'], array_column($this->json()['items'], 'id'));
        $this->send('GET', '/invoices?status=unpaid');
        self::assertNotContains($i['invoice'], array_column($this->json()['items'], 'id'));

        $this->send('POST', '/invoices/'.$i['invoice'].'/payment', []);
        self::assertSame([409, 'invoice.has_an_amount_due'], [$this->httpStatus(), $this->json()['rule'] ?? null], CreditedSegment::LAPSE);

        $this->actAsRole(DefaultRoles::OWNER);
        $this->send('POST', '/invoices/'.$i['invoice'].'/void', ['reason' => 'Try.']);
        self::assertSame([409, 'invoice.is_not_credited'], [$this->httpStatus(), $this->json()['rule'] ?? null], CreditedSegment::LAPSE);
        self::assertSame('Credit notes have been issued against this invoice, so it can no longer be voided. Issue a further credit note instead.', $this->json()['detail']);
    }

    /**
     * **V2-R-17 precedes B′** (`OwedAmendmentTripwireTest`'s former item 6, `SEC` §16): an invoice both credited AND
     * payment-reversed, voided by a marker-less delegate holding `invoice.void` and by the owner → 409
     * `invoice.is_not_credited` in both cases, the invoice unchanged and no `invoice.voided` row. Mutation (run): swap the
     * two checks → the delegate is answered `invoice.is_not_payment_reversed` → red.
     */
    #[Test]
    public function aCreditedInvoiceIsRefusedVoidingBeforeBPrimeIsAsked(): void
    {
        $i = $this->invoicedShipment('CN-PREC', [['4', '10.0000', '2']]);
        $this->actAsRole(DefaultRoles::FINANCE);
        $this->send('POST', '/invoices/'.$i['invoice'].'/payment', []);
        self::assertSame(200, $this->httpStatus());
        $this->actAsRole(DefaultRoles::FINANCE);
        $this->send('POST', '/invoices/'.$i['invoice'].'/payment-reversal', ['reason' => 'Bounced.']);
        self::assertSame(200, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        $this->actAsRole(DefaultRoles::OWNER);
        $return = $this->returnOf($i, [[0, '1']]);
        $this->actAsRole(DefaultRoles::FINANCE);
        $this->credit($i, $return, [[0, '1']]);
        self::assertSame(201, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());

        foreach ([['invoice.view', 'invoice.void'], DefaultRoles::codesFor(DefaultRoles::OWNER)] as $codes) {
            $this->actAs(self::TENANT_A, $codes);
            $this->send('POST', '/invoices/'.$i['invoice'].'/void', ['reason' => 'Try.']);
            self::assertSame([409, 'invoice.is_not_credited'], [$this->httpStatus(), $this->json()['rule'] ?? null]);
        }
        self::assertSame([['status' => 'issued']], $this->fetchAsOwnerForTenant(self::TENANT_A, 'SELECT status FROM invoice WHERE id = ?::uuid', [$i['invoice']]));
        self::assertSame(0, $this->countAsOwnerForTenant(self::TENANT_A, 'SELECT count(*) FROM audit_log WHERE event_type = ?', [AuditEvent::INVOICE_VOIDED]));
    }

    /** @return iterable<string, array{string}> */
    public static function rolesThatMayNotCredit(): iterable
    {
        // Q-F / A2.1b: `finance` and `owner` only. `admin` loses it by PC-14 (SoD-3's controlled half); `sales_rep` by
        // SoD-3; the warehouse records the return (SoD-5's first person).
        foreach ([DefaultRoles::ADMIN, DefaultRoles::SALES_REP, DefaultRoles::WAREHOUSE_OPERATOR, DefaultRoles::VIEWER, DefaultRoles::BUYER] as $role) {
            yield $role => [$role];
        }
    }

    #[Test]
    #[DataProvider('rolesThatMayNotCredit')]
    public function aRoleWithoutCreditNoteIssueIsRefused(string $role): void
    {
        $i = $this->invoicedShipment('CN-R-'.$role, [['4', '10.0000', '2']]);
        $this->actAsRole(DefaultRoles::OWNER);
        $return = $this->returnOf($i, [[0, '1']]);
        $this->actAsRole($role);
        $this->credit($i, $return, [[0, '1']]);
        self::assertSame(403, $this->httpStatus());
        self::assertSame(0, $this->countAsOwnerForTenant(self::TENANT_A, 'SELECT count(*) FROM credit_note'));
    }

    /** `GetCreditNote` is money: `invoice.view` (Q-B), never `fulfilment.view` or `sales_return.view`; another workspace's is a 404. */
    #[Test]
    public function aCreditNoteIsReadUnderInvoiceViewAndOnlyInItsWorkspace(): void
    {
        $i = $this->invoicedShipment('CN-RD', [['4', '10.0000', '2']]);
        $this->actAsRole(DefaultRoles::OWNER);
        $return = $this->returnOf($i, [[0, '1']]);
        $this->actAsRole(DefaultRoles::FINANCE);
        $this->credit($i, $return, [[0, '1']]);
        $note = $this->json()['id'];

        $this->actAsRole(DefaultRoles::WAREHOUSE_OPERATOR);
        $this->send('GET', '/credit-notes/'.$note);
        self::assertSame(403, $this->httpStatus());
        $this->actAsRole(DefaultRoles::SALES_REP);
        $this->send('GET', '/credit-notes/'.$note);
        self::assertSame(200, $this->httpStatus());

        $this->actAs(self::TENANT_B, DefaultRoles::codesFor(DefaultRoles::FINANCE));
        $this->send('GET', '/credit-notes/'.$note);
        self::assertSame(404, $this->httpStatus());
        $foreign = (string) $this->client()->getResponse()->getContent();
        $this->send('GET', '/credit-notes/'.Uuid::v4()->toRfc4122());
        self::assertSame($foreign, (string) $this->client()->getResponse()->getContent());
        $this->credit($i, $return, [[0, '1']]);
        self::assertSame(404, $this->httpStatus(), 'Another workspace\'s invoice was credited or answered differently.');
    }

    /**
     * **The queue equals what the operation accepts, in both directions** (design §4.2's both-directions test; RED-3
     * N-3; RED-4 M4-2). One shipment per kind, so no credit changes another kind's membership; for each, the WHOLE
     * operation is asked to credit the return's remainder (or a token 0.000001 where nothing remains), and "accepted" is
     * a 201. Kinds: deducting NULL; non-deducting NULL (recorded by the shipper); stamped live; stamped with a VOID
     * invoice of the same shipment (the reissue); stamped live and fully credited; stamped live with the invoice PAID;
     * zero-priced; a remainder that telescopes to 0.
     */
    #[Test]
    public function theCreditQueueIsExactlyWhatIssueCreditNoteAccepts(): void
    {
        $kinds = [];

        $k = $this->invoicedShipment('Q-DED', [['4', '10.0000', '2']], issue: false);
        $this->actAsRole(DefaultRoles::OWNER);
        $kinds['deducting NULL'] = [$k, $this->returnOf($k, [[0, '1']])];
        $kinds['deducting NULL'][0] = $this->issueOn($k);

        // `invoicedShipment()` leaves the actor as the shipment's own recorder, so this return is the shipper's: it does
        // not deduct (Q-L (a′)) and goes to finance.
        $k = $this->invoicedShipment('Q-SHP', [['4', '10.0000', '2']], issue: false);
        $r = $this->returnOf($k, [[0, '1']]);
        $kinds['non-deducting NULL'] = [$this->issueOn($k), $r];

        $k = $this->invoicedShipment('Q-LIVE', [['4', '10.0000', '2']]);
        $this->actAsRole(DefaultRoles::OWNER);
        $kinds['stamped live'] = [$k, $this->returnOf($k, [[0, '1']])];

        $k = $this->invoicedShipment('Q-VOID', [['4', '10.0000', '2']]);
        $this->actAsRole(DefaultRoles::OWNER);
        $r = $this->returnOf($k, [[0, '1']]);
        $this->send('POST', '/invoices/'.$k['invoice'].'/void', ['reason' => 'Reissue.']);
        self::assertSame(200, $this->httpStatus());
        $kinds['stamped void, reissued'] = [$this->issueOn($k), $r];

        $k = $this->invoicedShipment('Q-FULL', [['4', '10.0000', '2']]);
        $this->actAsRole(DefaultRoles::OWNER);
        $r = $this->returnOf($k, [[0, '1']]);
        $this->actAsRole(DefaultRoles::FINANCE);
        $this->credit($k, $r, [[0, '1']]);
        self::assertSame(201, $this->httpStatus());
        $kinds['stamped live, fully credited'] = [$k, $r];

        $k = $this->invoicedShipment('Q-PAID', [['4', '10.0000', '2']]);
        $this->actAsRole(DefaultRoles::FINANCE);
        $this->send('POST', '/invoices/'.$k['invoice'].'/payment', []);
        self::assertSame(200, $this->httpStatus());
        $this->actAsRole(DefaultRoles::OWNER);
        $kinds['stamped live, paid'] = [$k, $this->returnOf($k, [[0, '1']])];

        $k = $this->invoicedShipment('Q-FREE', [['4', '0', '2']]);
        $this->actAsRole(DefaultRoles::OWNER);
        $kinds['zero-priced'] = [$k, $this->returnOf($k, [[0, '1']])];

        $k = $this->invoicedShipment('Q-TEL', [['1', '0.0001', '1']]);
        $this->actAsRole(DefaultRoles::OWNER);
        $r = $this->returnOf($k, [[0, '1']]);
        $this->actAsRole(DefaultRoles::FINANCE);
        $this->credit($k, $r, [[0, '0.5']]);
        self::assertSame(201, $this->httpStatus());
        $kinds['zero-telescope remainder'] = [$k, $r];

        $this->actAsRole(DefaultRoles::FINANCE);
        $this->send('GET', '/sales-returns?creditable=true&limit=200');
        $queue = array_column($this->json()['items'], 'id');

        $mismatch = [];
        foreach ($kinds as $kind => [$shipment, $return]) {
            $remaining = (string) $this->fetchAsOwnerForTenant(self::TENANT_A, <<<'SQL'
                SELECT GREATEST(rl.quantity - COALESCE((SELECT SUM(c.quantity) FROM credit_note_line c WHERE c.sales_return_line_id = rl.id), 0), 0.000001)::numeric(19,6)::text AS q
                  FROM sales_return_line rl WHERE rl.sales_return_id = ?::uuid
                SQL, [$return])[0]['q'];
            $this->credit($shipment, $return, [[0, $remaining]]);
            $accepted = 201 === $this->httpStatus();
            if ($accepted !== \in_array($return, $queue, true)) {
                $mismatch[] = \sprintf('%s: in the queue %s, accepted %s (%d %s)', $kind, \in_array($return, $queue, true) ? 'yes' : 'no',
                    $accepted ? 'yes' : 'no', $this->httpStatus(), $this->json()['rule'] ?? json_encode($this->json()['errors'] ?? null));
            }
        }

        self::assertSame([], $mismatch, 'The credit queue and what IssueCreditNote accepts disagree.');
        self::assertCount(3, array_intersect(array_column($kinds, 1), $queue), 'Control: the creditable kinds (NULL non-deducting, live, void-reissued) are not all in the queue.');
    }
}
