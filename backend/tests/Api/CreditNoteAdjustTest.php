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
 * **`IssueAdjustmentCreditNote` — sales step 7, commit C1b** (2026-10-01), built from
 * `docs/session13/output9-sales-8-design.md` §4.4b under owner ruling Q-A = **B′** (`output9-sales-8-ruling.md`) and
 * ADR-008 **A2.1c**, and `docs/session13/output9-sales-8-c0-amendments.md` A-7 (C1b's pin).
 *
 * A credit for goods the customer received damaged and kept (`damaged_goods_kept`) or did not receive (`short_shipped`):
 * no return, `sales_return_id` NULL, on its own code `credit_note.adjust`, held by
 * the owner alone among the default roles. One refusal `IssueCreditNote` does not have (design §4.4b (ii)): an invoice
 * whose payment was ever reversed is adjusted only by an owner-marker holder, so a delegate holding `invoice.pay` +
 * `credit_note.adjust` cannot reverse a colleague's payment and then write the invoice off in one seat.
 *
 * Every shipment, invoice, payment, reversal, return and credit goes THROUGH THE API. A refused request closes the
 * shared entity manager, so every fixture of a test is laid before its first refusal.
 */
final class CreditNoteAdjustTest extends PurchaseOrderTestCase
{
    use InvoiceFixtures;
    use CreditFixtures;
    use CreditNoteFixtures;

    /**
     * The whole path: an owner credits one unit the customer kept. 201 with `Location` naming `GetCreditNote` (the
     * adjustment's row IS a credit note, §4.4.3's AdjustmentCreditNote row), the `CreditNote` shape member for member
     * with `sales_return` null, the telescoped amount, `credit_note.issued` `{"reason"}` cell by cell, and the invoice's
     * credit fields moved.
     */
    #[Test]
    public function anOwnerCreditsGoodsTheCustomerKept(): void
    {
        $i = $this->invoicedShipment('ADJ-A', [['4', '12.5000', '2']]);
        $owner = $this->actAsRole(DefaultRoles::OWNER);

        $this->adjust($i, [[0, '1']], 'damaged_goods_kept');

        self::assertSame(201, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        $body = $this->json();
        self::assertSame('/api/v1/credit-notes/'.$body['id'], $this->client()->getResponse()->headers->get('Location'));
        self::assertSame(['id', 'credit_note_number', 'reason', 'invoice', 'sales_return', 'currency_code', 'total_amount',
            'issued_at', 'issued_by', 'lines'], array_keys($body), 'The AdjustmentCreditNote row is the CreditNote shape.');
        self::assertSame('damaged_goods_kept', $body['reason']);
        self::assertNull($body['sales_return'], 'An adjustment has no return.');
        self::assertSame(['id' => $i['invoice'], 'invoice_number' => $i['number']], $body['invoice']);
        self::assertSame(['EUR', '12.5000'], [$body['currency_code'], $body['total_amount']]);
        self::assertSame(['id' => $owner, 'display_name' => 'Fixture Actor'], $body['issued_by']);
        self::assertSame(['1.000000', '12.5000'], [$body['lines'][0]['quantity'], $body['lines'][0]['amount']]);
        self::assertNull($this->fetchAsOwnerForTenant(self::TENANT_A, 'SELECT sales_return_id FROM credit_note WHERE id = ?::uuid', [$body['id']])[0]['sales_return_id']);

        $this->send('GET', '/credit-notes/'.$body['id']);
        self::assertSame($body, $this->json(), 'PC-17: the 201 is not GetCreditNote\'s document.');

        self::assertSame([[
            'event_type' => AuditEvent::CREDIT_NOTE_ISSUED, 'actor_user_id' => $owner, 'subject_type' => 'credit_note',
            'subject_id' => $body['id'], 'ip_address' => null, 'permission_code' => null, 'metadata' => '{"reason": "damaged_goods_kept"}',
        ]], $this->fetchAsOwnerForTenant(self::TENANT_A, <<<'SQL'
            SELECT event_type, actor_user_id::text AS actor_user_id, subject_type, subject_id::text AS subject_id,
                   ip_address::text AS ip_address, permission_code, metadata::text AS metadata
              FROM audit_log WHERE event_type = ?
            SQL, [AuditEvent::CREDIT_NOTE_ISSUED]));

        $this->send('GET', '/invoices/'.$i['invoice']);
        self::assertSame(['12.5000', '12.5000', false], [$this->json()['amount_credited'], $this->json()['amount_due'], $this->json()['credited_in_full']]);
    }

    /** API-14 / D-B: the same key and body is the same note (201, identical body); the same key, another body, 409. */
    #[Test]
    public function aRetryIsAReplayAndADifferentBodyIsAConflict(): void
    {
        $i = $this->invoicedShipment('ADJ-B', [['4', '10.0000', '3']]);
        $this->actAsRole(DefaultRoles::OWNER);
        $key = Uuid::v4()->toRfc4122();

        $this->adjust($i, [[0, '1']], 'short_shipped', $key);
        self::assertSame(201, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        $first = $this->json();
        $this->adjust($i, [[0, '1']], 'short_shipped', $key);
        self::assertSame(201, $this->httpStatus());
        self::assertSame($first, $this->json());
        $this->adjust($i, [[0, '1']], 'damaged_goods_kept', $key);
        self::assertSame(409, $this->httpStatus());
        self::assertStringContainsString('idempotency-key-conflict', (string) $this->json()['type']);
        self::assertSame(1, $this->countAsOwnerForTenant(self::TENANT_A, 'SELECT count(*) FROM credit_note'));
    }

    /**
     * **The digest binds the operation** (design §14 item 2): both operations share `credit_note_idem_unique`, so one key
     * reused across the two — same invoice, same lines, same caller — answers `idempotency-key-conflict`, never a replay
     * of the other kind's note. Both directions.
     *
     * **Mutations, run 2026-10-02:** the replay made to ignore the digest → red. ⚠ **Naming `IssueCreditNote` in
     * `adjust()`'s digest stays GREEN, and that is the honest result, not a gap in this test:** the two digests also
     * differ in their FIELDS (`IssueCreditNote` digests a required `sales_return_id`, an adjustment has none) and in their
     * `reason` domains, so no pair of real requests can collide even without the name. The operation name is defence
     * in depth for a future change that makes the field sets meet; nothing on the built surface can show it load-bearing.
     */
    #[Test]
    public function oneKeyNeverReplaysTheOtherKindOfCreditNote(): void
    {
        $i = $this->invoicedShipment('ADJ-K', [['6', '10.0000', '4']]);
        $this->actAsRole(DefaultRoles::OWNER);
        $return = $this->returnOf($i, [[0, '1']]);
        $first = Uuid::v4()->toRfc4122();
        $second = Uuid::v4()->toRfc4122();

        // A return credit, then an adjustment under its key.
        $this->credit($i, $return, [[0, '1']], $first);
        self::assertSame(201, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        // An adjustment, then a return credit under its key.
        $this->adjust($i, [[0, '1']], 'damaged_goods_kept', $second);
        self::assertSame(201, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());

        $this->adjust($i, [[0, '1']], 'damaged_goods_kept', $first);
        self::assertSame(409, $this->httpStatus(), 'An adjustment under a return credit\'s key replayed the other kind of note.');
        self::assertStringContainsString('idempotency-key-conflict', (string) $this->json()['type']);
        $this->credit($i, $return, [[0, '1']], $second);
        self::assertSame(409, $this->httpStatus(), 'A return credit under an adjustment\'s key replayed the other kind of note.');
        self::assertStringContainsString('idempotency-key-conflict', (string) $this->json()['type']);
        self::assertSame(2, $this->countAsOwnerForTenant(self::TENANT_A, 'SELECT count(*) FROM credit_note'));
    }

    /**
     * **A-7, C1b — the Q-E half of TB-4's Credited condition (1):** a paid invoice, with a body that would otherwise be
     * accepted, is refused EXACTLY 409 `invoice.is_not_paid`. Mutation (run): delete the `paid` branch → red.
     */
    #[Test]
    public function aPaidInvoiceCannotBeAdjusted(): void
    {
        $i = $this->invoicedShipment('ADJ-PAID', [['4', '10.0000', '2']]);
        $this->actAsRole(DefaultRoles::OWNER);
        $this->send('POST', '/invoices/'.$i['invoice'].'/payment', []);
        self::assertSame(200, $this->httpStatus(), 'Fixture: the payment. '.(string) $this->client()->getResponse()->getContent());

        $this->adjust($i, [[0, '1']], 'damaged_goods_kept');
        self::assertSame([409, 'invoice.is_not_paid'], [$this->httpStatus(), $this->json()['rule'] ?? null],
            'Q-E changed: a paid invoice was credited. '.CreditedSegment::LAPSE);
        self::assertSame(0, $this->countAsOwnerForTenant(self::TENANT_A, 'SELECT count(*) FROM credit_note'));
    }

    /** V2-R-17's mirror: a void invoice cannot be adjusted (`invoice.is_not_void`), under the lock. */
    #[Test]
    public function aVoidInvoiceCannotBeAdjusted(): void
    {
        $i = $this->invoicedShipment('ADJ-VOID', [['4', '10.0000', '2']]);
        $this->actAsRole(DefaultRoles::OWNER);
        $this->send('POST', '/invoices/'.$i['invoice'].'/void', ['reason' => 'Wrong customer.']);
        self::assertSame(200, $this->httpStatus(), 'Fixture: the void. '.(string) $this->client()->getResponse()->getContent());

        $this->adjust($i, [[0, '1']], 'damaged_goods_kept');
        self::assertSame([409, 'invoice.is_not_void'], [$this->httpStatus(), $this->json()['rule'] ?? null]);
    }

    /**
     * **Design §4.4b (ii) — the payment-reversed owner-marker check, the four cells.** A delegate (a composed role holding
     * `credit_note.adjust`, `invoice.pay` and `invoice.view`, without the owner marker) is refused 409
     * `invoice.is_not_payment_reversed` on an invoice whose payment was ever reversed, and nothing is written; on a
     * never-reversed invoice the same delegate is answered 201; an owner is answered 201 on the reversed one. The
     * reversal is real: paid by `finance`, reversed by the owner, so `payment_reversed_at` is set and the status is
     * `issued` again. Mutation (run): drop the check → the delegate's cell is red.
     */
    #[Test]
    public function aReversedPaymentLeavesTheAdjustmentToAnOwner(): void
    {
        $reversed = $this->invoicedShipment('ADJ-REV', [['4', '10.0000', '2']]);
        $never = $this->invoicedShipment('ADJ-NEV', [['4', '10.0000', '2']]);
        $this->actAsRole(DefaultRoles::FINANCE);
        $this->send('POST', '/invoices/'.$reversed['invoice'].'/payment', []);
        self::assertSame(200, $this->httpStatus(), 'Fixture: the payment. '.(string) $this->client()->getResponse()->getContent());
        $owner = $this->actAsRole(DefaultRoles::OWNER);
        $this->send('POST', '/invoices/'.$reversed['invoice'].'/payment-reversal', ['reason' => 'Bounced.']);
        self::assertSame(200, $this->httpStatus(), 'Fixture: the reversal. '.(string) $this->client()->getResponse()->getContent());
        self::assertSame([['status' => 'issued', 'reversed' => true]], $this->fetchAsOwnerForTenant(self::TENANT_A,
            'SELECT status, payment_reversed_at IS NOT NULL AS reversed FROM invoice WHERE id = ?::uuid', [$reversed['invoice']]));

        $this->actAs(self::TENANT_A, ['credit_note.adjust', 'invoice.pay', 'invoice.view']);
        $delegate = (string) $this->actorId;
        $this->adjust($never, [[0, '1']], 'damaged_goods_kept');
        self::assertSame(201, $this->httpStatus(), 'A delegate on a never-reversed invoice. '.(string) $this->client()->getResponse()->getContent());

        $this->actAsUser(self::TENANT_A, $owner);
        $this->adjust($reversed, [[0, '1']], 'damaged_goods_kept');
        self::assertSame(201, $this->httpStatus(), 'An owner on a reversed invoice. '.(string) $this->client()->getResponse()->getContent());

        $this->actAsUser(self::TENANT_A, $delegate);
        $this->adjust($reversed, [[0, '1']], 'damaged_goods_kept');
        self::assertSame([409, 'invoice.is_not_payment_reversed'], [$this->httpStatus(), $this->json()['rule'] ?? null],
            'A delegate wrote off an invoice whose payment was reversed (design §4.4b (ii)).');
        self::assertSame(1, $this->countAsOwnerForTenant(self::TENANT_A, 'SELECT count(*) FROM credit_note WHERE invoice_id = ?::uuid', [$reversed['invoice']]));
    }

    /**
     * **Test 2a (G-29 / G-30 part 3 C2; design §7 2a, J-5) — the SPLIT keeps the adjust path's billed bound and A-8's
     * queue/write agreement under a fixture C2 allows: ADJUST FIRST.** An adjustment is held to what the line billed
     * (`credit_note.quantity_within_billed`) and counts in every credited term (`CREDITED_ON_FULFILMENT_LINE` covers
     * adjustments), so once the kept goods are credited in full, a later return of the same line is not creditable, through
     * the queue AND the write. ("Return credited first" cannot carry the queue assertion — no pending return remains —
     * and return-first cannot either: the pending bound is exactly what stops the shrink.)
     *
     * **The positive control the shipped fixture's "the return starts creditable" precondition becomes:** a second,
     * unadjusted line's return in the same shipment IS creditable. Step 7's billed bound fires before 8c and step 10, so
     * both refusals keep their code; mutation M20 (drop the billed bound on the adjust path) reddens this method.
     */
    #[Test]
    public function anAdjustmentIsBoundByWhatWasBilledAndShrinksWhatAReturnCanBeCredited(): void
    {
        $i = $this->invoicedShipment('ADJ-BND', [['4', '10.0000', '2'], ['4', '10.0000', '2']]);
        $this->actAsRole(DefaultRoles::OWNER);

        $this->adjust($i, [[0, '2']], 'short_shipped');
        self::assertSame(201, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        $adjusted = $this->returnOf($i, [[0, '1']]);
        $control = $this->returnOf($i, [[1, '1']]);

        $this->send('GET', '/invoices/'.$i['invoice']);
        self::assertSame('20.0000', $this->json()['amount_credited']);
        $this->send('GET', '/sales-returns?creditable=true');
        $creditable = array_column($this->json()['items'], 'id');
        self::assertContains($control, $creditable, 'Positive control: a return of an UNADJUSTED line of the same shipment is creditable.');
        self::assertNotContains($adjusted, $creditable, 'An adjustment did not count against the return\'s creditable quantity.');

        $this->adjust($i, [[0, '0.5']], 'short_shipped');
        self::assertSame([409, 'credit_note.quantity_within_billed'], [$this->httpStatus(), $this->json()['rule'] ?? null]);
        $this->credit($i, $adjusted, [[0, '1']]);
        self::assertSame([409, 'credit_note.quantity_within_billed'], [$this->httpStatus(), $this->json()['rule'] ?? null]);
        self::assertSame(1, $this->countAsOwnerForTenant(self::TENANT_A, 'SELECT count(*) FROM credit_note'));
    }

    /** @return iterable<string, array{string, string, bool, bool}> reason, the return's reason, whether the return comes first, whether the goods are the same */
    public static function adjustedUnitsReturned(): iterable
    {
        // G-30 path (i): damaged units the customer said they would keep, sent back anyway. G-30 paths (ii)/(iii): units
        // claimed not received that turn up and come back. Two reasons x two orders x {same, different} = 8 rows (design
        // §4.3 rows 1-4, walked).
        foreach ([['damaged_goods_kept', 'damaged_in_transit'], ['short_shipped', 'other']] as [$reason, $returnReason]) {
            foreach ([false, true] as $returnFirst) {
                foreach ([true, false] as $same) {
                    yield $reason.', '.($returnFirst ? 'returned then adjusted' : 'adjusted then returned').', '.($same ? 'the same units' : 'different units')
                        => [$reason, $returnReason, $returnFirst, $same];
                }
            }
        }
    }

    /**
     * **Test 1 (G-29 / G-30 part 3 C2) — REWRITTEN from `aReturnOfAdjustedUnitsIsCreditedAgainToday`, never deleted.** The
     * part-1 pin recorded G-30's J-1 case: billed 10, an owner credits 2 as an adjustment, the customer's return of 2 on
     * the same line is recorded (after it or before it), finance credits the return's 2 — all 201, **4** units of credit for
     * **2** units. The decided behaviour (design §4.3 rows 1-4) is that a return on an adjusted line WAITS FOR A DECISION:
     *
     *  - adjust then return, the SAME units: finance's credit is refused 409 `credit_note.adjustment_decision_owed`; finance
     *    records `same` 2; the return leaves `creditable`, 2 units / 20.0000 are credited in all, payment needs no
     *    acknowledgement and the return never enters `credit_blocked_by_payment`;
     *  - adjust then return, DIFFERENT units: the same refusal; an OWNER records `distinct`; finance's credit is accepted —
     *    4 units / 40.0000, correct, both seats on record;
     *  - return then adjust, the same units: the owner's adjustment that does not name the pending return is refused 409
     *    `credit_note.has_no_pending_return`; finance credits the return — 2 units / 20.0000;
     *  - return then adjust, different units: the owner names the return (writing a `distinct`), and finance's credit is
     *    accepted without being asked again — 4 units / 40.0000.
     *
     * Assertions are rule codes, quantities and amounts (`2.000000` / `20.0000` vs `4.000000` / `40.0000`), queue
     * membership and `amount_due`, never a bare status. At most one refusal precedes a success per row.
     */
    #[Test]
    #[DataProvider('adjustedUnitsReturned')]
    public function aReturnOnAnAdjustedLineWaitsForADecision(string $reason, string $returnReason, bool $returnFirst, bool $same): void
    {
        $i = $this->invoicedShipment('ADJ-G30-'.$reason.($returnFirst ? '-R' : '-A').($same ? 'S' : 'D'), [['10', '10.0000', '10']]);
        $this->actAsRole(DefaultRoles::OWNER);

        if ($returnFirst) {
            $return = $this->returnOf($i, [[0, '2']], $returnReason);
            $returnLine = (string) $this->json()['lines'][0]['id'];
            if ($same) {
                // The owner forgets to name the return: refused; finance then credits the return (the correct paperwork).
                $this->adjust($i, [[0, '2']], $reason);
                self::assertSame([409, 'credit_note.has_no_pending_return'], [$this->httpStatus(), $this->json()['rule'] ?? null],
                    (string) $this->client()->getResponse()->getContent());
                $this->actAsRole(DefaultRoles::FINANCE);
                $this->credit($i, $return, [[0, '2']]);
                self::assertSame(201, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
                self::assertSame(['20.0000', '80.0000', 1, '2.000000'], $this->moneyAndUnits($i));

                return;
            }
            $this->adjust($i, [[0, '2']], $reason, null, [$return]);
            self::assertSame(201, $this->httpStatus(), 'The owner names the return. '.(string) $this->client()->getResponse()->getContent());
            self::assertSame(['distinct'], array_column($this->json()['lines'][0]['return_decisions'], 'kind'));
            $this->actAsRole(DefaultRoles::FINANCE);
            $this->credit($i, $return, [[0, '2']]);
            self::assertSame(201, $this->httpStatus(), 'Finance is not asked again. '.(string) $this->client()->getResponse()->getContent());
            self::assertSame(['40.0000', '60.0000', 2, '4.000000'], $this->moneyAndUnits($i));
            unset($returnLine);

            return;
        }

        [$adjustment, $adjustmentLine] = $this->adjustAndRead($i, '2', $reason);
        $return = $this->returnOf($i, [[0, '2']], $returnReason);
        $returnLine = (string) $this->json()['lines'][0]['id'];

        $this->actAsRole(DefaultRoles::FINANCE);
        $this->credit($i, $return, [[0, '2']]);
        self::assertSame([409, 'credit_note.adjustment_decision_owed'], [$this->httpStatus(), $this->json()['rule'] ?? null],
            'G-30 closed: finance credited a return of adjusted units without a decision. '.(string) $this->client()->getResponse()->getContent());

        if ($same) {
            $this->decide($return, ['kind' => 'same', 'sales_return_line_id' => $returnLine, 'credit_note_line_id' => $adjustmentLine, 'quantity' => '2']);
            self::assertSame(200, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
            self::assertSame(['0.000000', '2.000000'], [$this->json()['lines'][0]['quantity_credited'], $this->json()['lines'][0]['quantity_covered_by_adjustment']]);
            $this->send('GET', '/sales-returns?creditable=true');
            self::assertNotContains($return, array_column($this->json()['items'], 'id'), 'A decided return leaves the creditable queue.');
            $this->send('GET', '/sales-returns?credit_blocked_by_payment=true');
            self::assertNotContains($return, array_column($this->json()['items'], 'id'));
            self::assertSame(['20.0000', '80.0000', 1, '2.000000'], $this->moneyAndUnits($i));
            $this->send('POST', '/invoices/'.$i['invoice'].'/payment', []);
            self::assertSame(200, $this->httpStatus(), 'Payment needs no acknowledgement of a decided return. '.(string) $this->client()->getResponse()->getContent());

            return;
        }

        $this->actAsRole(DefaultRoles::OWNER);
        $this->decide($return, ['kind' => 'distinct', 'sales_return_line_id' => $returnLine, 'credit_note_line_id' => $adjustmentLine]);
        self::assertSame(200, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        $this->send('GET', '/sales-returns?creditable=true');
        self::assertContains($return, array_column($this->json()['items'], 'id'), 'Different units: the return is owed and stays creditable.');
        $this->actAsRole(DefaultRoles::FINANCE);
        $this->credit($i, $return, [[0, '2']]);
        self::assertSame(201, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        self::assertSame(['40.0000', '60.0000', 2, '4.000000'], $this->moneyAndUnits($i));
        unset($adjustment);
    }

    /**
     * `[amount credited, amount due, number of credit notes, units credited]` of the shipment's invoice and line 0.
     *
     * @param array{invoice: string, lines: list<string>} $shipment
     *
     * @return array{0: string, 1: string, 2: int, 3: string}
     */
    private function moneyAndUnits(array $shipment): array
    {
        $this->send('GET', '/invoices/'.$shipment['invoice']);
        $money = [$this->json()['amount_credited'], $this->json()['amount_due']];
        $row = $this->fetchAsOwnerForTenant(self::TENANT_A, <<<'SQL'
            SELECT count(DISTINCT cnl.credit_note_id)::int AS notes, sum(cnl.quantity)::numeric(19,6)::text AS quantity
              FROM credit_note_line cnl WHERE cnl.fulfilment_line_id = ?::uuid
            SQL, [$shipment['lines'][0]])[0];

        return [...$money, (int) $row['notes'], (string) $row['quantity']];
    }

    /**
     * An adjustment of line 0; returns `[credit note id, its line id]`.
     *
     * @param array{invoice: string, lines: list<string>} $shipment
     *
     * @return array{0: string, 1: string}
     */
    private function adjustAndRead(array $shipment, string $quantity, string $reason): array
    {
        $this->adjust($shipment, [[0, $quantity]], $reason);
        self::assertSame(201, $this->httpStatus(), 'The adjustment. '.(string) $this->client()->getResponse()->getContent());

        return [(string) $this->json()['id'], (string) $this->json()['lines'][0]['id']];
    }

    /** @param array<string, mixed> $body */
    private function decide(string $returnId, array $body): void
    {
        $this->send('POST', '/sales-returns/'.$returnId.'/adjustment-decisions', $body, ['Idempotency-Key' => Uuid::v4()->toRfc4122()]);
    }

    /**
     * The body: `reason` is an adjustment reason only (`goods_returned` is `IssueCreditNote`'s and needs a return); a
     * line must be a shipped line of the invoice's shipment; a line priced at nothing is not creditable.
     */
    #[Test]
    public function theBodyIsAnAdjustmentsBody(): void
    {
        $i = $this->invoicedShipment('ADJ-BODY', [['2', '0', '1'], ['2', '10.0000', '1']]);
        $other = $this->invoicedShipment('ADJ-BODY2', [['2', '10.0000', '1']]);
        $this->actAsRole(DefaultRoles::OWNER);

        $this->adjust($i, [[1, '1']], 'goods_returned');
        self::assertSame(422, $this->httpStatus());
        self::assertSame(['/reason', 'not_a_reason'], [$this->json()['errors'][0]['pointer'], $this->json()['errors'][0]['code']]);

        $this->send('POST', '/invoices/'.$i['invoice'].'/credit-note-adjustments', ['reason' => 'short_shipped',
            'lines' => [['fulfilment_line_id' => $other['lines'][0], 'quantity' => '1']]], ['Idempotency-Key' => Uuid::v4()->toRfc4122()]);
        self::assertSame(422, $this->httpStatus());
        self::assertSame(['/lines/0/fulfilment_line_id', 'unknown_reference'], [$this->json()['errors'][0]['pointer'], $this->json()['errors'][0]['code']]);

        $this->adjust($i, [[0, '1']], 'short_shipped');
        self::assertSame([409, 'credit_note.line_is_creditable'], [$this->httpStatus(), $this->json()['rule'] ?? null]);
        self::assertSame(0, $this->countAsOwnerForTenant(self::TENANT_A, 'SELECT count(*) FROM credit_note'));
    }

    /** @return iterable<string, array{string}> */
    public static function rolesThatMayNotAdjust(): iterable
    {
        // A2.1c / Q-F: `owner` alone among the default roles. `finance` issues return credits and still may not adjust;
        // `admin` loses it by PC-14 (SoD-3's controlled half).
        // ⚠ G-32 (`RESIDUAL_GAPS.md`; G-29 / G-30's remainder after G-29 / G-30 part 3): this provider is the ONLY
        // mechanical trigger of "any change giving `credit_note.adjust` to a default role other than `owner`". If a role
        // here starts answering 201, do not drop it from the list to get green: **`credit_note.adjust` on a non-owner
        // default role re-rates G-32's over-credit part from Low to Medium** (`SEC`, `g29-g30-sec.md` §1.3 item 1), and
        // `SEC` must re-rate in that commit. A NEW default role holding the code is not caught here; a composed role is
        // caught by OwedAmendmentTripwireTest (CreateRole / UpdateRole); a grant written by SQL (G-10) by nothing.
        // Default roles only. Since roles slice 4 C1 a composed `credit_note.adjust` seat can exist (an owner's composition
        // and grant); it is NOT G-32's Medium part (that is marker + `credit_note.issue` without adjust, `roles-s4-sec-data-red.md`
        // N-1/N-2) — see `aComposedAdjustSeatMayAdjustAndMustHoldInvoiceView`.
        foreach ([DefaultRoles::ADMIN, DefaultRoles::FINANCE, DefaultRoles::SALES_REP, DefaultRoles::WAREHOUSE_OPERATOR, DefaultRoles::VIEWER, DefaultRoles::BUYER, DefaultRoles::PURCHASING_MANAGER] as $role) {
            yield $role => [$role];
        }
    }

    /**
     * **G-32 / D-7.7's composed-role cases (roles slice 4 C1).** An owner composes a delegated adjuster through `CreateRole`;
     * without `invoice.view` it cannot be composed at all (owner D-5a, `role.missing_companion_view`); with it, it is
     * composed, granted by the owner, and its holder adjusts — a delegated, bounded power (G-30's part 3), not G-32's Medium.
     */
    #[Test]
    public function aComposedAdjustSeatMayAdjustAndMustHoldInvoiceView(): void
    {
        $i = $this->invoicedShipment('ADJ-COMPOSED', [['4', '10.0000', '2']]);
        $this->actAsRole(DefaultRoles::OWNER);
        $this->send('POST', '/roles', ['name' => 'Blind adjuster', 'permission_codes' => ['credit_note.adjust']], ['Idempotency-Key' => Uuid::v4()->toRfc4122()]);
        self::assertSame([409, 'role.missing_companion_view'], [$this->httpStatus(), $this->json()['rule'] ?? null]);
        $this->send('POST', '/roles', ['name' => 'Adjust desk', 'permission_codes' => ['credit_note.adjust', 'invoice.view']], ['Idempotency-Key' => Uuid::v4()->toRfc4122()]);
        self::assertSame(201, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        $role = (string) $this->json()['id'];
        $delegate = $this->seedPrincipal(self::TENANT_A, []);
        $this->send('POST', '/users/'.$delegate.'/roles', ['role_id' => $role], ['Idempotency-Key' => Uuid::v4()->toRfc4122()]);
        self::assertSame(201, $this->httpStatus(), 'The owner grants the composed adjuster directly: '.(string) $this->client()->getResponse()->getContent());

        $this->actAsUser(self::TENANT_A, $delegate);
        $this->adjust($i, [[0, '1']], 'damaged_goods_kept');
        self::assertSame(201, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
    }

    #[Test]
    #[DataProvider('rolesThatMayNotAdjust')]
    public function aRoleWithoutCreditNoteAdjustIsRefused(string $role): void
    {
        $i = $this->invoicedShipment('ADJ-R-'.$role, [['4', '10.0000', '2']]);
        $this->actAsRole($role);
        $this->adjust($i, [[0, '1']], 'damaged_goods_kept');
        self::assertSame(403, $this->httpStatus());
        self::assertSame(0, $this->countAsOwnerForTenant(self::TENANT_A, 'SELECT count(*) FROM credit_note'));
    }

    /**
     * `IssueAdjustmentCreditNote`, as the current actor.
     *
     * @param array{invoice: string, lines: list<string>} $shipment
     * @param list<array{0: int, 1: string}>             $lines    `[line index, quantity]`
     * @param list<string>|null                          $acknowledged `acknowledged_return_ids`
     */
    private function adjust(array $shipment, array $lines, string $reason, ?string $key = null, ?array $acknowledged = null): void
    {
        $this->send('POST', '/invoices/'.$shipment['invoice'].'/credit-note-adjustments', [
            'reason' => $reason,
            'lines' => array_map(static fn (array $l): array => ['fulfilment_line_id' => $shipment['lines'][$l[0]], 'quantity' => $l[1]], $lines),
        ] + (null === $acknowledged ? [] : ['acknowledged_return_ids' => $acknowledged]), ['Idempotency-Key' => $key ?? Uuid::v4()->toRfc4122()]);
    }
}
