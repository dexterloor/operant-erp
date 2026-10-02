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
     * Bounds: an adjustment is held to what the line billed (`credit_note.quantity_within_billed`), and it counts in
     * every credited term (amendments A-8: `CREDITED_ON_FULFILMENT_LINE` covers adjustments) — so once the kept goods
     * are credited in full, a return of the same line is no longer creditable, through the queue AND the write.
     */
    #[Test]
    public function anAdjustmentIsBoundByWhatWasBilledAndShrinksWhatAReturnCanBeCredited(): void
    {
        $i = $this->invoicedShipment('ADJ-BND', [['4', '10.0000', '2']]);
        $this->actAsRole(DefaultRoles::OWNER);
        $return = $this->returnOf($i, [[0, '1']]);

        $this->send('GET', '/sales-returns?creditable=true');
        self::assertContains($return, array_column($this->json()['items'], 'id'), 'Fixture: the return starts creditable.');

        $this->adjust($i, [[0, '2']], 'short_shipped');
        self::assertSame(201, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        $this->send('GET', '/invoices/'.$i['invoice']);
        self::assertTrue($this->json()['credited_in_full']);
        $this->send('GET', '/sales-returns?creditable=true');
        self::assertNotContains($return, array_column($this->json()['items'], 'id'), 'An adjustment did not count against the return\'s creditable quantity.');

        $this->adjust($i, [[0, '0.5']], 'short_shipped');
        self::assertSame([409, 'credit_note.quantity_within_billed'], [$this->httpStatus(), $this->json()['rule'] ?? null]);
        $this->credit($i, $return, [[0, '1']]);
        self::assertSame([409, 'credit_note.quantity_within_billed'], [$this->httpStatus(), $this->json()['rule'] ?? null]);
        self::assertSame(1, $this->countAsOwnerForTenant(self::TENANT_A, 'SELECT count(*) FROM credit_note'));
    }

    /** @return iterable<string, array{string, string, bool}> reason, the return's reason, whether the return comes first */
    public static function adjustedUnitsReturned(): iterable
    {
        // G-30 path (i): damaged units the customer said they would keep, sent back anyway.
        yield 'damaged_goods_kept, adjusted then returned' => ['damaged_goods_kept', 'damaged_in_transit', false];
        yield 'damaged_goods_kept, returned then adjusted' => ['damaged_goods_kept', 'damaged_in_transit', true];
        // G-30 paths (ii)/(iii): units claimed not received that turn up and come back.
        yield 'short_shipped, adjusted then returned' => ['short_shipped', 'other', false];
        yield 'short_shipped, returned then adjusted' => ['short_shipped', 'other', true];
    }

    /**
     * **⚠ THIS PINS CURRENT, KNOWN-WRONG BEHAVIOUR — `RESIDUAL_GAPS.md` G-30 (Medium), case J-1 of
     * `docs/session13/l4-judge.md`.** Billed 10 on one shipped line. An owner credits 2 as an adjustment; the customer's
     * return of 2 on the same line is recorded (after it, or before it); finance credits the return's 2. All three are
     * answered 201 and the line carries **4** units of credit for **2** units the record can show at the customer's door
     * — because nothing records WHICH units an adjustment covered, so the return stays creditable up to
     * `billed − credited` whether or not its units are the adjusted ones. Every credit stays within the line's billing
     * (`credit_note.quantity_within_billed`), so no quantity bound can tell this from two different sets of units.
     *
     * LEAD ran this case by a throwaway probe on 2026-10-02 (Move 423); this is that probe made permanent, in both
     * orders and under both reasons, so G-30's closing commit cannot change the behaviour without seeing it.
     * **G-30's closing commit (the shared identity design with G-29) must REWRITE this test to the decided behaviour —
     * refused without the paperwork holder's decision, settled with it — never delete it.** No request here is refused,
     * so one test per provider row never closes the shared entity manager.
     */
    #[Test]
    #[DataProvider('adjustedUnitsReturned')]
    public function aReturnOfAdjustedUnitsIsCreditedAgainToday(string $reason, string $returnReason, bool $returnFirst): void
    {
        $i = $this->invoicedShipment('ADJ-G30-'.$reason.($returnFirst ? '-R' : '-A'), [['10', '10.0000', '10']]);
        $this->actAsRole(DefaultRoles::OWNER);

        if ($returnFirst) {
            $return = $this->returnOf($i, [[0, '2']], $returnReason);
            $this->adjust($i, [[0, '2']], $reason);
            self::assertSame(201, $this->httpStatus(), 'The adjustment. '.(string) $this->client()->getResponse()->getContent());
        } else {
            $this->adjust($i, [[0, '2']], $reason);
            self::assertSame(201, $this->httpStatus(), 'The adjustment. '.(string) $this->client()->getResponse()->getContent());
            $return = $this->returnOf($i, [[0, '2']], $returnReason);
        }

        $this->send('GET', '/sales-returns?creditable=true');
        self::assertContains($return, array_column($this->json()['items'], 'id'),
            'G-30 today: a return of units an adjustment may already have credited is still offered for credit.');

        $this->actAsRole(DefaultRoles::FINANCE);
        $this->credit($i, $return, [[0, '2']]);
        self::assertSame(201, $this->httpStatus(), 'G-30 today: the return\'s credit is accepted. '.(string) $this->client()->getResponse()->getContent());

        self::assertSame([['notes' => 2, 'quantity' => '4.000000', 'amount' => '40.0000']], $this->fetchAsOwnerForTenant(self::TENANT_A, <<<'SQL'
            SELECT count(DISTINCT cnl.credit_note_id) AS notes, sum(cnl.quantity)::text AS quantity, sum(cnl.amount)::text AS amount
              FROM credit_note_line cnl WHERE cnl.fulfilment_line_id = ?::uuid
            SQL, [$i['lines'][0]]), 'G-30 today: 4 units credited on the line for 2 units returned or adjusted.');
        $this->send('GET', '/invoices/'.$i['invoice']);
        self::assertSame(['40.0000', '60.0000'], [$this->json()['amount_credited'], $this->json()['amount_due']]);
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
        // ⚠ G-29 / G-30 (`RESIDUAL_GAPS.md`): this provider is the ONLY mechanical trigger of both gaps' "any change giving
        // `credit_note.adjust` to a default role other than `owner`". If a role here starts answering 201, do not drop it
        // from the list to get green: G-29 (an adjustment can use up a pending return's room) and G-30 (adjusted units
        // returned are credited twice) become reachable by that role, and their shared identity design is owed first.
        // A NEW default role holding the code is not caught here; a composed role is caught by OwedAmendmentTripwireTest
        // (CreateRole / UpdateRole); a grant written by SQL (G-10) by nothing.
        foreach ([DefaultRoles::ADMIN, DefaultRoles::FINANCE, DefaultRoles::SALES_REP, DefaultRoles::WAREHOUSE_OPERATOR, DefaultRoles::VIEWER, DefaultRoles::BUYER, DefaultRoles::PURCHASING_MANAGER] as $role) {
            yield $role => [$role];
        }
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
     */
    private function adjust(array $shipment, array $lines, string $reason, ?string $key = null): void
    {
        $this->send('POST', '/invoices/'.$shipment['invoice'].'/credit-note-adjustments', [
            'reason' => $reason,
            'lines' => array_map(static fn (array $l): array => ['fulfilment_line_id' => $shipment['lines'][$l[0]], 'quantity' => $l[1]], $lines),
        ], ['Idempotency-Key' => $key ?? Uuid::v4()->toRfc4122()]);
    }
}
