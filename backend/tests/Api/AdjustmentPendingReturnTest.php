<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Modules\UserManagement\Domain\DefaultRoles;
use App\SharedKernel\ValueObject\AuditEvent;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Uid\Uuid;

/**
 * **`IssueAdjustmentCreditNote` against the returns still waiting for a credit — G-29 / G-30 part 3, C2** (2026-10-06),
 * built from `docs/session13/g29-g30-identity-design.md` §4.2 (steps 10–12), §4.3 and §7's tests 2b, 10, 11, 14, 15,
 * under the owner's ruling of 2026-10-05 and `SEC`'s S-1…S-7.
 *
 * Step 10 holds an adjustment to `credited(l) + pending(l) + q ≤ billed(l)` (closing G-29) and its refusal copy names the
 * CAUSE (J-2, FR-6); step 11 demands that every pending return on an adjusted line be NAMED in `acknowledged_return_ids`
 * (an owner-marker act: naming is a `distinct` assertion) and step 12 writes one `distinct` decision per open return line
 * with its event.
 *
 * Everything goes through the API except where a test says it seeds a row as the owner (the G-15 shape). A refused request
 * closes the shared entity manager, so each method lays its fixtures before its first refusal and at most one refusal
 * precedes a success.
 */
final class AdjustmentPendingReturnTest extends PurchaseOrderTestCase
{
    use InvoiceFixtures;
    use CreditFixtures;
    use CreditNoteFixtures;

    /**
     * **Test 2b, cause (ii) — G-29's own case** (design §4.3 row 5): billed 2, a return of 1 waiting, no adjustment yet. The
     * owner's adjustment of 2 would take the room the return needs and strand it for good. Refused
     * `credit_note.adjustment_within_pending_returns` with the copy that offers the way out — "credit the return instead" /
     * "adjust at most 1" — and NEVER "record that…" (there is no adjustment, so a decision would be a dead end). Adjusting 1
     * naming the return is accepted and finance's credit of 1 then completes the line: no orphan.
     */
    #[Test]
    public function anAdjustmentCannotTakeTheRoomOfAPendingReturn(): void
    {
        $i = $this->invoicedShipment('PEND-G29', [['2', '10.0000', '2']]);
        $this->actAsRole(DefaultRoles::OWNER);
        $r = $this->returnOf($i, [[0, '1']]);

        $this->adjust($i, '2', 'short_shipped');
        self::assertSame([409, 'credit_note.adjustment_within_pending_returns'], [$this->httpStatus(), $this->json()['rule'] ?? null]);
        $detail = (string) $this->json()['detail'];
        self::assertStringContainsString('credit the return instead', $detail);
        self::assertStringContainsString('adjust at most 1', $detail);
        self::assertStringContainsString($this->returnNumber($r), $detail);
        self::assertStringNotContainsString('Record that', $detail, 'Cause (ii) has no adjustment to record against (J-2).');
        self::assertSame(0, $this->countAsOwnerForTenant(self::TENANT_A, 'SELECT count(*) FROM credit_note'));

        $this->adjust($i, '1', 'short_shipped', [$r]);
        self::assertSame(201, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        $this->actAsRole(DefaultRoles::FINANCE);
        $this->credit($i, $r, [[0, '1']]);
        self::assertSame(201, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        $this->send('GET', '/invoices/'.$i['invoice']);
        self::assertTrue($this->json()['credited_in_full'], 'The line is credited in full, with no orphaned return.');
    }

    /**
     * **Test 2b, cause (i) and test 10's A-1 row** (§4.3 row 7): billed 3; an adjustment of 2 kept; a return of 2 (its pair
     * with the adjustment is impossible: `0 + 0 + 2 + 2 > 3`, at least 1 unit is the same). A further adjustment of 1 is
     * refused with the "record that" copy naming "at least 1" (FR-4) and never "credit the return"; finance records `same`
     * 2 and the adjustment of 1 is then accepted (`2 + 0 + 1 ≤ 3`).
     */
    #[Test]
    public function anAdjustmentOverlappingAPendingReturnIsToldToRecordThat(): void
    {
        $i = $this->invoicedShipment('PEND-A1', [['3', '10.0000', '3']]);
        $this->actAsRole(DefaultRoles::OWNER);
        [, $c1] = $this->adjustAndRead($i, '2', 'damaged_goods_kept');
        [$r, $rl] = $this->returned($i, '2');

        $this->adjust($i, '1', 'short_shipped');
        self::assertSame([409, 'credit_note.adjustment_within_pending_returns'], [$this->httpStatus(), $this->json()['rule'] ?? null]);
        $detail = (string) $this->json()['detail'];
        self::assertStringContainsString('at least 1 units that CN-', $detail);
        self::assertStringContainsString('Record that, then adjust only what remains', $detail);
        self::assertStringNotContainsString('credit the return', $detail, 'Cause (i) never says "credit the return" (M-3).');

        $this->actAsRole(DefaultRoles::FINANCE);
        $this->decide($r, ['kind' => 'same', 'sales_return_line_id' => $rl, 'credit_note_line_id' => $c1, 'quantity' => '2']);
        self::assertSame(200, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        $this->actAsRole(DefaultRoles::OWNER);
        $this->adjust($i, '1', 'short_shipped');
        self::assertSame(201, $this->httpStatus(), 'A-1 correct end state. '.(string) $this->client()->getResponse()->getContent());
    }

    /**
     * **Test 2b, cause (i′) (FR-6):** billed 10; an adjustment of 3 kept; a return of 3 (the pair is undecided but NOT
     * impossible: `0 + 0 + 3 + 3 ≤ 10`); an adjustment of 5 would overrun the room. The copy says the return MAY have brought
     * back units the adjustment credited, and to record that first — not that it did.
     */
    #[Test]
    public function anUndecidedButPossiblePairIsToldToRecordItFirst(): void
    {
        $i = $this->invoicedShipment('PEND-IP', [['10', '10.0000', '10']]);
        $this->actAsRole(DefaultRoles::OWNER);
        $this->adjustAndRead($i, '3', 'damaged_goods_kept');
        $this->returned($i, '3');

        $this->adjust($i, '5', 'short_shipped');
        self::assertSame([409, 'credit_note.adjustment_within_pending_returns'], [$this->httpStatus(), $this->json()['rule'] ?? null]);
        $detail = (string) $this->json()['detail'];
        self::assertStringContainsString('may have brought back units', $detail);
        self::assertStringContainsString('If so, record that first', $detail);
        self::assertStringNotContainsString('at least', $detail);
    }

    /** **Test 2b, cause (ii), room ≤ 0 (FR-6):** billed 2, a return of 2, an adjustment of 1 — nothing can be adjusted while it waits. */
    #[Test]
    public function nothingCanBeAdjustedWhileAReturnTakesTheWholeLine(): void
    {
        $i = $this->invoicedShipment('PEND-ZERO', [['2', '10.0000', '2']]);
        $this->actAsRole(DefaultRoles::OWNER);
        $r = $this->returnOf($i, [[0, '2']]);

        $this->adjust($i, '1', 'short_shipped');
        self::assertSame([409, 'credit_note.adjustment_within_pending_returns'], [$this->httpStatus(), $this->json()['rule'] ?? null]);
        self::assertStringContainsString('Nothing on this line can be adjusted while '.$this->returnNumber($r).' waits', (string) $this->json()['detail']);
    }

    /**
     * **Test 2b, a pending `recorded_in_error` return (FR-6):** recorded in error before the invoice, by the shipper (so it
     * does not deduct), then invoiced — a creditable return of a shipment that did not happen. Cause (ii) says only "credit
     * it first": naming it would fail at step 11.
     */
    #[Test]
    public function aPendingErrorReturnIsToldToBeCreditedFirst(): void
    {
        $i = $this->invoicedShipment('PEND-ERR2', [['2', '10.0000', '2']], issue: false);
        $error = $this->returnOf($i, [[0, '2']], 'recorded_in_error');
        $i = $this->issueOn($i);
        $this->actAsRole(DefaultRoles::OWNER);

        $this->adjust($i, '1', 'short_shipped');
        self::assertSame([409, 'credit_note.adjustment_within_pending_returns'], [$this->httpStatus(), $this->json()['rule'] ?? null]);
        self::assertSame('Credit '.$this->returnNumber($error).' first.', $this->json()['detail']);
    }

    /**
     * **Test 2b, the FR-8 row (M15):** a DEDUCTING pre-invoice return already left the bill, so it is not "pending" and
     * must not shrink the room. Billed = shipped 4 − deducted 1 = 3; adjusting up to `billed − credited` = 3 is accepted.
     * Counting deducting returns in `pending` would refuse it.
     */
    #[Test]
    public function aDeductingPreInvoiceReturnIsNotPending(): void
    {
        $i = $this->invoicedShipment('PEND-DED', [['4', '10.0000', '4']], issue: false);
        $this->actAsRole(DefaultRoles::OWNER);
        $this->returnOf($i, [[0, '1']]);
        self::assertTrue((bool) $this->json()['deducts'], 'Fixture: the owner\'s pre-invoice return deducts.');
        $i = $this->issueOn($i);
        $this->actAsRole(DefaultRoles::OWNER);

        $this->adjust($i, '3', 'short_shipped');
        self::assertSame(201, $this->httpStatus(), 'A deducting return was counted as pending. '.(string) $this->client()->getResponse()->getContent());
    }

    /**
     * **Test 10, A-3 (§4.3 row 8):** billed 2; a return of 1; an adjustment of 1 naming it (accepted); a further adjustment
     * of 1 is `1 + 1 + 1 > 2` and refused.
     */
    #[Test]
    public function aSecondAdjustmentCannotTakeTheRoomOfAReturnAlreadyNamed(): void
    {
        $i = $this->invoicedShipment('PEND-A3', [['2', '10.0000', '2']]);
        $this->actAsRole(DefaultRoles::OWNER);
        $r = $this->returnOf($i, [[0, '1']]);
        $this->adjust($i, '1', 'damaged_goods_kept', [$r]);
        self::assertSame(201, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());

        $this->adjust($i, '1', 'damaged_goods_kept', [$r]);
        self::assertSame([409, 'credit_note.adjustment_within_pending_returns'], [$this->httpStatus(), $this->json()['rule'] ?? null]);
    }

    /**
     * **Test 11, the acknowledgement and its hygiene:** naming the pending return writes one `distinct` row against the new
     * adjustment line, recorded by the caller, and one `credit_note.return_decided` event whose subject is the NEW credit note
     * (SEC F-S1). A named return that is no longer pending (credited meanwhile) is ignored (stale) and writes nothing.
     */
    #[Test]
    public function namingAPendingReturnWritesItsDistinctRowAndEvent(): void
    {
        $i = $this->invoicedShipment('PEND-ACK', [['10', '10.0000', '10']]);
        $owner = $this->actAsRole(DefaultRoles::OWNER);
        [$r, $rl] = $this->returned($i, '2');
        $stale = $this->returnOf($i, [[0, '1']]);
        $this->actAsRole(DefaultRoles::FINANCE);
        $this->credit($i, $stale, [[0, '1']]);
        self::assertSame(201, $this->httpStatus(), 'Fixture: the other return is credited, so it is stale. '.(string) $this->client()->getResponse()->getContent());
        $this->actAsUser(self::TENANT_A, $owner);

        $this->adjust($i, '2', 'short_shipped', [$r, $stale]);
        self::assertSame(201, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        $note = (string) $this->json()['id'];
        $line = (string) $this->json()['lines'][0]['id'];
        self::assertSame([['kind' => 'distinct', 'return_line' => $rl, 'adjustment_line' => $line, 'recorded_by' => $owner, 'round' => 1, 'key' => null]],
            $this->fetchAsOwnerForTenant(self::TENANT_A, <<<'SQL'
                SELECT kind, sales_return_line_id::text AS return_line, credit_note_line_id::text AS adjustment_line,
                       recorded_by_user_id::text AS recorded_by, decision_round::int AS round, idempotency_key AS key
                  FROM adjustment_decision
                SQL), 'Exactly one `distinct` row, for the pending return and not the stale one; no key columns.');
        self::assertSame([['event_type' => AuditEvent::CREDIT_NOTE_RETURN_DECIDED, 'subject_type' => 'credit_note', 'subject_id' => $note,
            'actor' => $owner, 'ip' => null, 'metadata' => '{"kind": "distinct", "credit_note_line_id": "'.$line.'", "sales_return_line_id": "'.$rl.'", "adjustment_decision_id": "'
                .$this->fetchAsOwnerForTenant(self::TENANT_A, 'SELECT id::text AS id FROM adjustment_decision')[0]['id'].'"}']],
            $this->fetchAsOwnerForTenant(self::TENANT_A, <<<'SQL'
                SELECT event_type, subject_type, subject_id::text AS subject_id, actor_user_id::text AS actor, ip_address::text AS ip, metadata::text AS metadata
                  FROM audit_log WHERE event_type = ?
                SQL, [AuditEvent::CREDIT_NOTE_RETURN_DECIDED]));
        self::assertSame(['distinct'], array_column($this->json()['lines'][0]['return_decisions'], 'kind'), 'The 201 carries the decision to an invoice.view holder.');
    }

    /** **Test 11:** an id that is no return of this invoice's shipment is 422 on its pointer (an `unknown_reference`). */
    #[Test]
    public function anIdThatIsNoReturnOfThisShipmentIsRefusedOnItsPointer(): void
    {
        $i = $this->invoicedShipment('PEND-UNK', [['10', '10.0000', '10']]);
        $this->actAsRole(DefaultRoles::OWNER);

        $this->adjust($i, '1', 'short_shipped', [Uuid::v4()->toRfc4122()]);
        self::assertSame(422, $this->httpStatus());
        self::assertSame(['/acknowledged_return_ids/0', 'unknown_reference'], [$this->json()['errors'][0]['pointer'], $this->json()['errors'][0]['code']]);
        self::assertSame(0, $this->countAsOwnerForTenant(self::TENANT_A, 'SELECT count(*) FROM credit_note'));
    }

    /**
     * **Test 11, numbers only to a `sales_return.view` holder (T-12 / the F11 form):** a delegate holding `credit_note.adjust`
     * and `invoice.view` but not `sales_return.view` is told a return is waiting without its number or id.
     */
    #[Test]
    public function returnNumbersAreOnlyForASalesReturnViewer(): void
    {
        $i = $this->invoicedShipment('PEND-NUM', [['10', '10.0000', '10']]);
        $this->actAsRole(DefaultRoles::OWNER);
        $r = $this->returnOf($i, [[0, '2']]);
        $this->actAs(self::TENANT_A, ['credit_note.adjust', 'invoice.view']);

        $this->adjust($i, '1', 'short_shipped');
        self::assertSame([409, 'credit_note.has_no_pending_return'], [$this->httpStatus(), $this->json()['rule'] ?? null]);
        $detail = (string) $this->json()['detail'];
        self::assertStringNotContainsString($this->returnNumber($r), $detail);
        self::assertStringNotContainsString($r, $detail);
    }

    /**
     * **Test 15 (M-7 / M18):** a pending `recorded_in_error` return cannot be named — paying or adjusting past it bills a
     * shipment that did not happen. Billed 4 leaves room (`0 + 2 + 1 ≤ 4`), so step 10 passes and step 11's guard is the
     * refusal. Without the guard the naming would be accepted and a `distinct` stored for a shipment that never happened.
     */
    #[Test]
    public function aPendingErrorReturnCannotBeNamed(): void
    {
        $i = $this->invoicedShipment('PEND-ERR', [['4', '10.0000', '4']], issue: false);
        $error = $this->returnOf($i, [[0, '2']], 'recorded_in_error');
        $i = $this->issueOn($i);
        $this->actAsRole(DefaultRoles::OWNER);

        $this->adjust($i, '1', 'short_shipped', [$error]);
        self::assertSame([409, 'credit_note.error_return_cannot_be_acknowledged'], [$this->httpStatus(), $this->json()['rule'] ?? null]);
        self::assertSame(0, $this->countAsOwnerForTenant(self::TENANT_A, 'SELECT count(*) FROM adjustment_decision'));
        self::assertSame(0, $this->countAsOwnerForTenant(self::TENANT_A, 'SELECT count(*) FROM credit_note'));
    }

    /**
     * **Test 19's adjust-path half (design §4.2 step 11; SEC S-2):** naming a pending return is a `distinct` assertion, so it
     * needs the owner marker. A delegate holding `credit_note.adjust` (with `invoice.view` and `sales_return.view`) who
     * names the return is refused `adjustment_decision.distinct_needs_the_owner`; the same adjustment with no pending return
     * is accepted. Mutation M16's twin for this path: drop the marker check on naming → the delegate's refusal is red.
     */
    #[Test]
    public function aDelegateWithoutTheMarkerCannotNameAPendingReturn(): void
    {
        $i = $this->invoicedShipment('PEND-DEL', [['10', '10.0000', '10']]);
        $this->actAsRole(DefaultRoles::OWNER);
        $r = $this->returnOf($i, [[0, '2']]);
        $this->actAs(self::TENANT_A, ['credit_note.adjust', 'invoice.view', 'sales_return.view']);

        $this->adjust($i, '1', 'short_shipped', [$r]);
        self::assertSame([409, 'adjustment_decision.distinct_needs_the_owner'], [$this->httpStatus(), $this->json()['rule'] ?? null]);
        self::assertSame(0, $this->countAsOwnerForTenant(self::TENANT_A, 'SELECT count(*) FROM credit_note'));
    }

    /**
     * **Test 14 (M13):** 2a — the invoice's order, its fulfilment's order and the locked order agree, else the invoice was
     * written outside the application (G-15) and the lock protected the wrong row: 500-class, nothing credited. The forgery
     * is seeded as the owner (an invoice whose `sales_order_id` names another order).
     */
    #[Test]
    public function anInvoiceWhoseOrderDisagreesWithItsFulfilmentIsNotAdjusted(): void
    {
        $i = $this->invoicedShipment('PEND-2A', [['10', '10.0000', '10']]);
        $other = $this->seedSalesOrder(self::TENANT_A, self::freshNumber('SO'));
        $this->executeAsOwnerForTenant(self::TENANT_A, 'UPDATE invoice SET sales_order_id = ?::uuid WHERE id = ?::uuid', [$other, $i['invoice']]);
        $this->actAsRole(DefaultRoles::OWNER);

        $this->adjust($i, '1', 'short_shipped');
        $status = $this->httpStatus();
        self::assertSame(500, $status, 'A forged invoice was adjusted.');
        self::assertSame(0, $this->countAsOwnerForTenant(self::TENANT_A, 'SELECT count(*) FROM credit_note'), 'Nothing was credited.');
    }

    /**
     * `IssueAdjustmentCreditNote` on line 0 as the current actor.
     *
     * @param array{invoice: string, lines: list<string>} $shipment
     * @param list<string>|null                          $acknowledged
     */
    private function adjust(array $shipment, string $quantity, string $reason, ?array $acknowledged = null): void
    {
        $this->send('POST', '/invoices/'.$shipment['invoice'].'/credit-note-adjustments', [
            'reason' => $reason,
            'lines' => [['fulfilment_line_id' => $shipment['lines'][0], 'quantity' => $quantity]],
        ] + (null === $acknowledged ? [] : ['acknowledged_return_ids' => $acknowledged]), ['Idempotency-Key' => Uuid::v4()->toRfc4122()]);
    }

    /**
     * An accepted adjustment of line 0; returns `[credit note id, its line id]`.
     *
     * @param array{invoice: string, lines: list<string>} $shipment
     *
     * @return array{0: string, 1: string}
     */
    private function adjustAndRead(array $shipment, string $quantity, string $reason): array
    {
        $this->adjust($shipment, $quantity, $reason);
        self::assertSame(201, $this->httpStatus(), 'Fixture: the adjustment. '.(string) $this->client()->getResponse()->getContent());

        return [(string) $this->json()['id'], (string) $this->json()['lines'][0]['id']];
    }

    /**
     * A return of `$quantity` of line 0 as the current actor; returns `[return id, its line id]`.
     *
     * @param array{fulfilment: string, lines: list<string>, bin: string} $shipment
     *
     * @return array{0: string, 1: string}
     */
    private function returned(array $shipment, string $quantity): array
    {
        $id = $this->returnOf($shipment, [[0, $quantity]]);

        return [$id, (string) $this->json()['lines'][0]['id']];
    }

    /** @param array<string, mixed> $body */
    private function decide(string $returnId, array $body): void
    {
        $this->send('POST', '/sales-returns/'.$returnId.'/adjustment-decisions', $body, ['Idempotency-Key' => Uuid::v4()->toRfc4122()]);
    }

    private function returnNumber(string $returnId): string
    {
        return (string) $this->fetchAsOwnerForTenant(self::TENANT_A, 'SELECT sales_return_number FROM sales_return WHERE id = ?::uuid', [$returnId])[0]['sales_return_number'];
    }
}
