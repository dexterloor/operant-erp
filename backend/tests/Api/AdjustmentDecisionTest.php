<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Modules\UserManagement\Domain\DefaultRoles;
use App\SharedKernel\ValueObject\AuditEvent;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Uid\Uuid;

/**
 * **`CreateAdjustmentDecision` — G-29 / G-30 part 3, C1** (2026-10-05), built from
 * `docs/session13/g29-g30-identity-design.md` §3, §2.4 and §5 under the owner's ruling of 2026-10-05
 * (`session21-owner-ruling.md` §1: D1 (a), D1b (s-iii), D2 (b), D3 no, D4 yes) and `SEC`'s S-1…S-7 (`g29-g30-sec.md`).
 *
 * A return line that falls on an order line already carrying a credit WITHOUT a return (an adjustment,
 * `IssueAdjustmentCreditNote`) is decided: `same` (finance or owner: "q of these returned units are units that
 * adjustment already credited"), `distinct` (owner marker only: "different goods"), or an owner's `reversal` of one row.
 * Decided units leave the return's room through one named term (`sameOnReturnLine`). The decision members are served
 * only to an `invoice.view` holder.
 *
 * Every shipment, invoice, return, credit and decision goes THROUGH THE API except where a test says it seeds a row as
 * the owner (the G-15 shape). A refused request closes the shared entity manager, so each method lays its fixtures before
 * its first refusal and at most one refusal precedes a success.
 */
final class AdjustmentDecisionTest extends PurchaseOrderTestCase
{
    use InvoiceFixtures;
    use CreditFixtures;
    use CreditNoteFixtures;

    /** Test 4 (design §7): `same` is bounded by the return line's open units (credited ones included, M-8) and by the adjustment's. */
    #[Test]
    public function aSameDecisionIsBoundByTheReturnAndByTheAdjustment(): void
    {
        $i = $this->invoicedShipment('AD-4', [['10', '10.0000', '10']]);
        $this->actAsRole(DefaultRoles::OWNER);
        [, $c1] = $this->adjustment($i, '2', 'damaged_goods_kept');
        [$r, $rl] = $this->returned($i, '5');
        // The credited unit is SEEDED as the owner (the G-15 shape): through the API, C2's step 8c refuses a credit while the
        // pair is undecided, and the pair cannot be decided `same` AND credited first — this pins the TERM (a credited unit
        // counts against the return line's room, M-8), not a reachable sequence.
        $this->seedCreditNote($i['invoice'], '10.0000', [[$i['lines'][0], '1', '10.0000', $rl]], $r);
        $this->actAsRole(DefaultRoles::FINANCE);

        $this->decide($r, ['kind' => 'same', 'sales_return_line_id' => $rl, 'credit_note_line_id' => $c1, 'quantity' => '1']);
        self::assertSame(200, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        self::assertSame(['1.000000', '1.000000'], [$this->json()['lines'][0]['quantity_credited'], $this->json()['lines'][0]['quantity_covered_by_adjustment']]);
        $this->send('GET', '/sales-returns?creditable=true');
        self::assertContains($r, array_column($this->json()['items'], 'id'), 'A partial `same` leaves the rest creditable.');

        // Open on the return line: 5 − 1 credited − 1 same = 3; open on the adjustment: 2 − 1 = 1.
        $this->decide($r, ['kind' => 'same', 'sales_return_line_id' => $rl, 'credit_note_line_id' => $c1, 'quantity' => '4']);
        self::assertSame([409, 'adjustment_decision.within_returned'], [$this->httpStatus(), $this->json()['rule'] ?? null],
            'The credited unit did not count against the return line (M-8).');
        $this->decide($r, ['kind' => 'same', 'sales_return_line_id' => $rl, 'credit_note_line_id' => $c1, 'quantity' => '2']);
        self::assertSame([409, 'adjustment_decision.within_adjusted'], [$this->httpStatus(), $this->json()['rule'] ?? null]);
        self::assertSame(1, $this->countAsOwnerForTenant(self::TENANT_A, 'SELECT count(*) FROM adjustment_decision'));
    }

    /**
     * Test 1's own-method row (FR-7, M2): after `same` 2 on a return of 2, a credit of the 2 is refused — the units are
     * out of the return line's room — and the shipped message names the `same` units and the adjustment, for an
     * `invoice.view` holder only (`SEC` §5: the whole clause is a decision member).
     */
    #[Test]
    public function aCreditCannotPassUnitsRecordedAsAlreadyCredited(): void
    {
        $i = $this->invoicedShipment('AD-1F', [['10', '10.0000', '10']]);
        $this->actAsRole(DefaultRoles::OWNER);
        [$cn] = $this->adjustment($i, '2', 'damaged_goods_kept');
        $c1 = $this->json()['lines'][0]['id'];
        $number = $this->json()['credit_note_number'];
        [$r, $rl] = $this->returned($i, '2');
        $this->actAsRole(DefaultRoles::FINANCE);
        $this->decide($r, ['kind' => 'same', 'sales_return_line_id' => $rl, 'credit_note_line_id' => $c1, 'quantity' => '2']);
        self::assertSame(200, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        $this->credit($i, $r, [[0, '2']]);
        self::assertSame([409, 'credit_note.quantity_within_returned'], [$this->httpStatus(), $this->json()['rule'] ?? null]);
        self::assertStringContainsString('and 2 were recorded as already credited without a return ('.$number.')', (string) $this->json()['detail']);
        unset($cn);
    }

    /** Test 5: the adjustment line must be an adjustment's, on this invoice, on the same shipped line; the return line this return's. */
    #[Test]
    public function aDecisionNamesOnlyAnAdjustmentOnTheSameShippedLine(): void
    {
        $i = $this->invoicedShipment('AD-5', [['10', '10.0000', '10'], ['10', '10.0000', '10']]);
        $other = $this->invoicedShipment('AD-5B', [['10', '10.0000', '10']]);
        $this->actAsRole(DefaultRoles::OWNER);
        [, $c1] = $this->adjustment($i, '2', 'damaged_goods_kept');
        [, $foreign] = $this->adjustment($other, '1', 'short_shipped');
        $r = $this->returnOf($i, [[0, '2'], [1, '2']]);
        $lines = array_column($this->json()['lines'], 'id');
        [$otherReturn, $otherLine] = $this->returned($other, '1');
        $this->credit($i, $r, [[1, '1']]);
        self::assertSame(201, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        $returnCredit = $this->json()['lines'][0]['id'];

        foreach ([
            [$lines[1], $c1, '/credit_note_line_id', 'not_the_same_shipped_line'],
            [$lines[1], $returnCredit, '/credit_note_line_id', 'not_an_adjustment'],
            [$lines[0], $foreign, '/credit_note_line_id', 'unknown_reference'],
            [$otherLine, $c1, '/sales_return_line_id', 'not_on_the_return'],
        ] as [$returnLine, $adjustmentLine, $pointer, $code]) {
            $this->decide($r, ['kind' => 'same', 'sales_return_line_id' => $returnLine, 'credit_note_line_id' => $adjustmentLine, 'quantity' => '1']);
            self::assertSame(422, $this->httpStatus(), $code.': '.(string) $this->client()->getResponse()->getContent());
            self::assertSame([$pointer, $code], [$this->json()['errors'][0]['pointer'], $this->json()['errors'][0]['code']]);
        }
        unset($otherReturn);
        self::assertSame(0, $this->countAsOwnerForTenant(self::TENANT_A, 'SELECT count(*) FROM adjustment_decision'));
    }

    /** Test 6: a deducting return, and a return on a shipment with no live invoice, are not decided against any adjustment. */
    #[Test]
    public function aDecisionOnAReturnNotStampedForThisInvoiceIsRefused(): void
    {
        $s = $this->invoicedShipment('AD-6', [['10', '10.0000', '10']], false);
        $this->actAsRole(DefaultRoles::OWNER);
        $deducting = $this->returnOf($s, [[0, '1']]);
        $deductingLine = $this->json()['lines'][0]['id'];
        $uninvoiced = $this->invoicedShipment('AD-6B', [['10', '10.0000', '10']], false);
        $i = $this->issueOn($s);
        $this->actAsRole(DefaultRoles::OWNER);
        [, $c1] = $this->adjustment($i, '1', 'damaged_goods_kept');
        [$noInvoice, $noInvoiceLine] = $this->returned($uninvoiced, '1');

        $this->decide($deducting, ['kind' => 'same', 'sales_return_line_id' => $deductingLine, 'credit_note_line_id' => $c1, 'quantity' => '1']);
        self::assertSame([409, 'adjustment_decision.return_is_against_this_invoice'], [$this->httpStatus(), $this->json()['rule'] ?? null]);
        $this->decide($noInvoice, ['kind' => 'same', 'sales_return_line_id' => $noInvoiceLine, 'credit_note_line_id' => $c1, 'quantity' => '1']);
        self::assertSame([409, 'adjustment_decision.return_is_against_this_invoice'], [$this->httpStatus(), $this->json()['rule'] ?? null]);
        self::assertSame(0, $this->countAsOwnerForTenant(self::TENANT_A, 'SELECT count(*) FROM adjustment_decision'));
    }

    /** Test 7 (API-14 / D-B): the same key and body replay the return as it now reads; another body under the key is 409. */
    #[Test]
    public function aRetryOfADecisionIsAReplayAndADifferentBodyIsAConflict(): void
    {
        $i = $this->invoicedShipment('AD-7', [['10', '10.0000', '10']]);
        $this->actAsRole(DefaultRoles::OWNER);
        [, $c1] = $this->adjustment($i, '2', 'damaged_goods_kept');
        [$r, $rl] = $this->returned($i, '2');
        $key = Uuid::v4()->toRfc4122();

        $body = ['kind' => 'same', 'sales_return_line_id' => $rl, 'credit_note_line_id' => $c1, 'quantity' => '1'];
        $this->decide($r, $body, $key);
        self::assertSame(200, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        $first = $this->json();
        $this->decide($r, $body + [], $key);
        self::assertSame(200, $this->httpStatus());
        self::assertSame($first, $this->json(), 'A replay answers the return as it now reads, unchanged here.');
        self::assertNull($this->client()->getResponse()->headers->get('Location'), 'Branch 5: no Location.');
        $this->decide($r, ['quantity' => '2'] + $body, $key);
        self::assertSame(409, $this->httpStatus());
        self::assertStringContainsString('idempotency-key-conflict', (string) $this->json()['type']);
        self::assertSame(1, $this->countAsOwnerForTenant(self::TENANT_A, 'SELECT count(*) FROM adjustment_decision'));
        self::assertCount(1, $this->auditRowsOf(AuditEvent::CREDIT_NOTE_RETURN_DECIDED), 'A replay wrote a second trail row.');
    }

    /**
     * Test 8 (M-4, J-8, J-9, FR-13; `SEC` S-5, S-7, F-S1): the decision members are an `invoice.view` holder's alone —
     * present for finance at literal scale 6, ABSENT for `warehouse_operator` (even in its own `CreateSalesReturn` 201);
     * `return_decisions` on the adjustment in `GetCreditNote`; the review filters refused for the warehouse and exact for
     * finance; and the trail row, cell by cell, filed under the ADJUSTMENT's credit note.
     */
    #[Test]
    public function theDecisionsAreServedOnlyToInvoiceReaders(): void
    {
        $i = $this->invoicedShipment('AD-8', [['10', '10.0000', '10']]);
        $this->actAsRole(DefaultRoles::OWNER);
        [$cn, $c1] = $this->adjustment($i, '3', 'damaged_goods_kept');
        [$r, $rl] = $this->returned($i, '2');
        [$untouched] = $this->returned($i, '1');
        $finance = $this->actAsRole(DefaultRoles::FINANCE);
        $this->decide($r, ['kind' => 'same', 'sales_return_line_id' => $rl, 'credit_note_line_id' => $c1, 'quantity' => '2']);
        self::assertSame(200, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        $decided = $this->json();
        $line = $decided['lines'][0];
        self::assertSame(['0.000000', '2.000000'], [$line['quantity_credited'], $line['quantity_covered_by_adjustment']],
            'Decided units are served apart from quantity_credited, at scale 6 (J-9: WireScaleTest never sees them).');
        self::assertCount(1, $line['adjustment_decisions']);
        $decision = $line['adjustment_decisions'][0];
        self::assertSame(['id', 'credit_note', 'kind', 'quantity', 'reverses_decision_id', 'recorded_by', 'recorded_at'], array_keys($decision));
        self::assertSame([$cn, 'same', '2.000000', null, ['id' => $finance, 'display_name' => 'Fixture Actor']],
            [$decision['credit_note']['id'], $decision['kind'], $decision['quantity'], $decision['reverses_decision_id'], $decision['recorded_by']]);
        self::assertFalse($decided['adjustment_decision_owed']);

        $this->send('GET', '/credit-notes/'.$cn);
        $returnDecision = $this->json()['lines'][0]['return_decisions'][0];
        self::assertSame(['id', 'sales_return', 'kind', 'quantity', 'reverses_decision_id', 'recorded_by', 'recorded_at'], array_keys($returnDecision));
        self::assertSame([$decision['id'], $r, 'same', '2.000000'], [$returnDecision['id'], $returnDecision['sales_return']['id'],
            $returnDecision['kind'], $returnDecision['quantity']]);

        // The owner's review list (s-iii).
        $this->send('GET', '/sales-returns?adjustment_decided=same');
        self::assertSame([$r], array_column($this->json()['items'], 'id'));
        $this->send('GET', '/sales-returns?adjustment_decided=same&decided_since='.rawurlencode('2999-01-01T00:00:00Z'));
        self::assertSame([], array_column($this->json()['items'], 'id'), 'A decided_since after the decision excludes it.');
        $this->send('GET', '/sales-returns?adjustment_decided=same&decided_since='.rawurlencode($decision['recorded_at']));
        self::assertSame([$r], array_column($this->json()['items'], 'id'));

        // The trail (SEC F-S1): business, subject the ADJUSTMENT's credit note, ids-only metadata, no IP.
        self::assertSame([[
            'event_type' => AuditEvent::CREDIT_NOTE_RETURN_DECIDED, 'actor_user_id' => $finance, 'actor_label' => 'Fixture Actor',
            'subject_type' => 'credit_note', 'subject_id' => $cn, 'ip_address' => null, 'permission_code' => null,
            'metadata' => ['adjustment_decision_id' => $decision['id'], 'credit_note_line_id' => $c1, 'kind' => 'same',
                'quantity' => '2.000000', 'sales_return_line_id' => $rl],
        ]], array_map(static function (array $row): array {
            $metadata = json_decode($row['metadata'], true, 512, \JSON_THROW_ON_ERROR);
            ksort($metadata);
            $row['metadata'] = $metadata;

            return $row;
        }, $this->auditRowsOf(AuditEvent::CREDIT_NOTE_RETURN_DECIDED)), 'Ids-only metadata, keys exactly these (jsonb orders them).');
        self::assertSame('credit_note', AuditEvent::SUBJECT_TYPES[AuditEvent::CREDIT_NOTE_RETURN_DECIDED], 'SEC F-S1: never the return.');

        // `warehouse_operator`: sales_return.view, no invoice.view — every decision member ABSENT, filters refused.
        $this->actAsRole(DefaultRoles::WAREHOUSE_OPERATOR);
        $this->send('GET', '/sales-returns/'.$r);
        self::assertArrayNotHasKey('adjustment_decision_owed', $this->json());
        self::assertArrayNotHasKey('quantity_covered_by_adjustment', $this->json()['lines'][0]);
        self::assertArrayNotHasKey('adjustment_decisions', $this->json()['lines'][0]);
        $this->returnOf($i, [[0, '1']]);
        self::assertArrayNotHasKey('adjustment_decisions', $this->json()['lines'][0], 'FR-13: the warehouse\'s own CreateSalesReturn 201 leaked a decision member.');
        self::assertArrayNotHasKey('adjustment_decision_owed', $this->json());
        foreach (['adjustment_decided=same', 'adjustment_decision_owed=true', 'adjustment_decided=same&decided_since=2026-01-01T00:00:00Z'] as $query) {
            $this->send('GET', '/sales-returns?'.$query);
            self::assertSame(422, $this->httpStatus(), $query);
            self::assertSame('not_permitted', $this->json()['errors'][0]['code'], $query);
        }
        unset($untouched);
    }

    /** Test 8, the filter's own shape: `decided_since` without `adjustment_decided` is refused. */
    #[Test]
    public function decidedSinceStandsOnlyWithAdjustmentDecided(): void
    {
        $this->actAsRole(DefaultRoles::FINANCE);
        $this->send('GET', '/sales-returns?decided_since=2026-01-01T00:00:00Z');
        self::assertSame(422, $this->httpStatus());
        self::assertSame(['?decided_since', 'needs_adjustment_decided'], [$this->json()['errors'][0]['pointer'], $this->json()['errors'][0]['code']]);
    }

    /**
     * Test 9 (§4.3 row 6; M-8): a return on a line an adjustment used up has no room, so it is not creditable — and the
     * decision queue still finds it. An owner's `distinct` is impossible there; finance's `same` takes it off the queue,
     * and a later return on the same line is queued again.
     */
    #[Test]
    public function adjustmentDecisionOwedFindsAReturnWithNoRoomLeft(): void
    {
        $i = $this->invoicedShipment('AD-9', [['10', '10.0000', '10']]);
        $this->actAsRole(DefaultRoles::OWNER);
        [, $c1] = $this->adjustment($i, '10', 'short_shipped');
        [$r, $rl] = $this->returned($i, '2');
        $this->send('GET', '/sales-returns/'.$r);
        self::assertTrue($this->json()['adjustment_decision_owed']);
        self::assertNull($this->json()['credit_target'], 'No room: not creditable.');
        $this->send('GET', '/sales-returns?adjustment_decision_owed=true');
        self::assertSame([$r], array_column($this->json()['items'], 'id'));

        $this->decide($r, ['kind' => 'distinct', 'sales_return_line_id' => $rl, 'credit_note_line_id' => $c1]);
        self::assertSame([409, 'adjustment_decision.distinct_is_impossible'], [$this->httpStatus(), $this->json()['rule'] ?? null]);
        self::assertStringContainsString('at least 2 of these returned units', (string) $this->json()['detail']);

        $this->actAsRole(DefaultRoles::FINANCE);
        $this->decide($r, ['kind' => 'same', 'sales_return_line_id' => $rl, 'credit_note_line_id' => $c1, 'quantity' => '2']);
        self::assertSame(200, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        self::assertFalse($this->json()['adjustment_decision_owed']);
        $this->actAsRole(DefaultRoles::OWNER);
        [$later] = $this->returned($i, '1');
        $this->send('GET', '/sales-returns?adjustment_decision_owed=true');
        self::assertSame([$later], array_column($this->json()['items'], 'id'), 'A later return on the same line is queued again.');
    }

    /**
     * Test 16 (M-3, J-1, FR-4, FR-9): a `distinct` the arithmetic proves false is refused, naming at least {o}; the joint
     * form refuses a second `distinct` that is possible alone. The A-1 numbers, and billed 10 / A = 4 / R = 7.
     *
     * @return iterable<string, array{string, string, string, string}> billed, adjusted, returned, the proven overlap
     */
    public static function impossibleDistincts(): iterable
    {
        yield 'A-1: billed 3, kept 2, returned 2' => ['3', '2', '2', '1'];
        yield 'FR-4: billed 10, short 4, returned 7' => ['10', '4', '7', '1'];
    }

    #[Test]
    #[DataProvider('impossibleDistincts')]
    public function anImpossibleDistinctIsRefused(string $billed, string $adjusted, string $returned, string $overlap): void
    {
        $i = $this->invoicedShipment('AD-16-'.$billed.$adjusted, [[$billed, '10.0000', $billed]]);
        $this->actAsRole(DefaultRoles::OWNER);
        [, $c1] = $this->adjustment($i, $adjusted, 'short_shipped');
        [$r, $rl] = $this->returned($i, $returned);

        $this->decide($r, ['kind' => 'distinct', 'sales_return_line_id' => $rl, 'credit_note_line_id' => $c1]);
        self::assertSame([409, 'adjustment_decision.distinct_is_impossible'], [$this->httpStatus(), $this->json()['rule'] ?? null]);
        self::assertStringContainsString('at least '.$overlap.' of these returned units', (string) $this->json()['detail'], 'FR-4: {o} is provenOverlap.');
        self::assertSame(0, $this->countAsOwnerForTenant(self::TENANT_A, 'SELECT count(*) FROM adjustment_decision'));
    }

    /** FR-9: billed 10, A1 = 5 short, A2 = 3 kept, R = 4 — `distinct` (R, A1) is possible; then (R, A2) is jointly not. */
    #[Test]
    public function twoDistinctsThatCannotBothBeTrueAreRefused(): void
    {
        $i = $this->invoicedShipment('AD-16J', [['10', '10.0000', '10']]);
        $this->actAsRole(DefaultRoles::OWNER);
        [, $a1] = $this->adjustment($i, '5', 'short_shipped');
        [, $a2] = $this->adjustment($i, '3', 'damaged_goods_kept');
        [$r, $rl] = $this->returned($i, '4');

        $this->decide($r, ['kind' => 'distinct', 'sales_return_line_id' => $rl, 'credit_note_line_id' => $a1]);
        self::assertSame(200, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        $this->decide($r, ['kind' => 'distinct', 'sales_return_line_id' => $rl, 'credit_note_line_id' => $a2]);
        self::assertSame([409, 'adjustment_decision.distinct_is_impossible'], [$this->httpStatus(), $this->json()['rule'] ?? null]);
        self::assertStringContainsString('at least 2 of these returned units', (string) $this->json()['detail'], 'The joint excess: 0 + 0 + 4 + 5 + 3 − 10.');
    }

    /**
     * Test 17 (M-8) and `SEC` R-1: two adjustments and two returns on one line, partial `same` across them; a `distinct`
     * decides its pair once; and a reversal's cascade files each trail row under ITS OWN adjustment's credit note.
     */
    #[Test]
    public function severalAdjustmentsAndReturnsOnOneLine(): void
    {
        $i = $this->invoicedShipment('AD-17', [['10', '10.0000', '10']]);
        $owner = $this->actAsRole(DefaultRoles::OWNER);
        [$cn1, $c1] = $this->adjustment($i, '2', 'damaged_goods_kept');
        [$cn2, $c2] = $this->adjustment($i, '3', 'short_shipped');
        [$r1, $rl1] = $this->returned($i, '2');
        [$r2, $rl2] = $this->returned($i, '3');
        $this->decide($r1, ['kind' => 'same', 'sales_return_line_id' => $rl1, 'credit_note_line_id' => $c1, 'quantity' => '1']);
        self::assertSame(200, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        $same = $this->json()['lines'][0]['adjustment_decisions'][0]['id'];
        $this->decide($r2, ['kind' => 'same', 'sales_return_line_id' => $rl2, 'credit_note_line_id' => $c2, 'quantity' => '2']);
        self::assertSame(200, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        // 0 + 3 + 1 + 1 = 5 ≤ 10.
        $this->decide($r1, ['kind' => 'distinct', 'sales_return_line_id' => $rl1, 'credit_note_line_id' => $c2]);
        self::assertSame(200, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        $distinct = array_values(array_filter($this->json()['lines'][0]['adjustment_decisions'], static fn (array $d): bool => 'distinct' === $d['kind']))[0]['id'];

        // Reverse the `same` on (R1, c1): the cascade reverses the live `distinct` on (R1, c2), whose note is CN2.
        $this->decide($r1, ['kind' => 'reversal', 'reverses_decision_id' => $same]);
        self::assertSame(200, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        $rows = $this->fetchAsOwnerForTenant(self::TENANT_A, <<<'SQL'
            SELECT ad.reverses_id::text AS reverses, a.subject_id::text AS subject
              FROM adjustment_decision ad
              JOIN audit_log a ON a.event_type = ? AND (a.metadata->>'adjustment_decision_id')::uuid = ad.id
             WHERE ad.kind = 'reversal'
             ORDER BY 1
            SQL, [AuditEvent::CREDIT_NOTE_RETURN_DECIDED]);
        $expected = [['reverses' => $same, 'subject' => $cn1], ['reverses' => $distinct, 'subject' => $cn2]];
        usort($expected, static fn (array $a, array $b): int => $a['reverses'] <=> $b['reverses']);
        self::assertSame($expected, $rows, 'SEC R-1: each row\'s subject is its OWN adjustment line\'s note.');
        $this->send('GET', '/sales-returns/'.$r1);
        self::assertTrue($this->json()['adjustment_decision_owed'], 'Both pairs of R1 are undecided again.');
        unset($owner, $r2);

        $this->decide($r1, ['kind' => 'distinct', 'sales_return_line_id' => $rl1, 'credit_note_line_id' => $c2]);
        self::assertSame(200, $this->httpStatus(), 'FR-2: the reopened pair is decided again, at round 2. '.(string) $this->client()->getResponse()->getContent());
        self::assertSame([['round' => 1], ['round' => 2]], $this->fetchAsOwnerForTenant(self::TENANT_A,
            "SELECT decision_round AS round FROM adjustment_decision WHERE kind = 'distinct' ORDER BY decision_round"));
        $this->decide($r1, ['kind' => 'distinct', 'sales_return_line_id' => $rl1, 'credit_note_line_id' => $c2]);
        self::assertSame([409, 'adjustment_decision.already_decided'], [$this->httpStatus(), $this->json()['rule'] ?? null]);
    }

    /** Test 18 (M-8): reversing a payment after a `same` does not make the return creditable again. */
    #[Test]
    public function aPaymentReversalAfterADecisionLeavesTheReturnDecided(): void
    {
        $i = $this->invoicedShipment('AD-18', [['10', '10.0000', '10']]);
        $this->actAsRole(DefaultRoles::OWNER);
        [, $c1] = $this->adjustment($i, '2', 'damaged_goods_kept');
        [$r, $rl] = $this->returned($i, '2');
        $this->actAsRole(DefaultRoles::FINANCE);
        $this->decide($r, ['kind' => 'same', 'sales_return_line_id' => $rl, 'credit_note_line_id' => $c1, 'quantity' => '2']);
        self::assertSame(200, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        $this->send('POST', '/invoices/'.$i['invoice'].'/payment', []);
        self::assertSame(200, $this->httpStatus(), 'Nothing to acknowledge: the decided return owes no credit. '.(string) $this->client()->getResponse()->getContent());
        $this->actAsRole(DefaultRoles::OWNER);
        $this->send('POST', '/invoices/'.$i['invoice'].'/payment-reversal', ['reason' => 'Bounced.']);
        self::assertSame(200, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        $this->send('GET', '/sales-returns/'.$r);
        self::assertNull($this->json()['credit_target']);
        self::assertFalse($this->json()['adjustment_decision_owed']);
    }

    /** @return iterable<string, array{string}> */
    public static function rolesWithoutTheMarker(): iterable
    {
        foreach ([DefaultRoles::ADMIN, DefaultRoles::FINANCE, DefaultRoles::SALES_REP, DefaultRoles::WAREHOUSE_OPERATOR, DefaultRoles::VIEWER, DefaultRoles::BUYER, DefaultRoles::PURCHASING_MANAGER] as $role) {
            yield $role => [$role];
        }
    }

    /**
     * Test 19, FR-10 (D1 (a)): every default role but `owner` is refused a `distinct` — 403 (no `credit_note.issue`) or
     * 409 `distinct_needs_the_owner`. This provider is G-NEW's marker trigger: a default role that starts answering 200
     * here has been given the owner marker.
     */
    #[Test]
    #[DataProvider('rolesWithoutTheMarker')]
    public function onlyAnOwnerRecordsDifferentGoods(string $role): void
    {
        $i = $this->invoicedShipment('AD-19-'.$role, [['10', '10.0000', '10']]);
        $this->actAsRole(DefaultRoles::OWNER);
        [, $c1] = $this->adjustment($i, '2', 'damaged_goods_kept');
        [$r, $rl] = $this->returned($i, '2');
        $this->actAsRole($role);
        $this->decide($r, ['kind' => 'distinct', 'sales_return_line_id' => $rl, 'credit_note_line_id' => $c1]);
        $answer = [$this->httpStatus(), $this->json()['rule'] ?? null];
        self::assertContains($answer, [[403, null], [409, 'adjustment_decision.distinct_needs_the_owner']],
            $role.' recorded different goods without the owner marker (D1 (a)).');
        self::assertSame(0, $this->countAsOwnerForTenant(self::TENANT_A, 'SELECT count(*) FROM adjustment_decision'));
    }

    /** Test 19: the owner records `distinct`, and so does a co-owner holding the marker under another role (the house precedent). */
    #[Test]
    public function anOwnerOrACoOwnerRecordsDifferentGoods(): void
    {
        $i = $this->invoicedShipment('AD-19-OWN', [['10', '10.0000', '10']]);
        $this->actAsRole(DefaultRoles::OWNER);
        [, $c1] = $this->adjustment($i, '2', 'damaged_goods_kept');
        [$r1, $rl1] = $this->returned($i, '2');
        [$r2, $rl2] = $this->returned($i, '2');
        $this->decide($r1, ['kind' => 'distinct', 'sales_return_line_id' => $rl1, 'credit_note_line_id' => $c1]);
        self::assertSame(200, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        $this->actAs(self::TENANT_A, ['credit_note.issue', 'tenant_settings.edit']);
        $this->decide($r2, ['kind' => 'distinct', 'sales_return_line_id' => $rl2, 'credit_note_line_id' => $c1]);
        self::assertSame(200, $this->httpStatus(), 'A co-owner holds the marker under another name. '.(string) $this->client()->getResponse()->getContent());
        self::assertArrayNotHasKey('adjustment_decisions', $this->json()['lines'][0], 'No invoice.view: the members are absent from its own 200.');
    }

    /** D2 (b): only an owner undoes a decision — finance, who may record the `same`, may not reverse it. */
    #[Test]
    public function onlyAnOwnerReversesADecision(): void
    {
        $i = $this->invoicedShipment('AD-REV-SEAT', [['10', '10.0000', '10']]);
        $this->actAsRole(DefaultRoles::OWNER);
        [, $c1] = $this->adjustment($i, '2', 'damaged_goods_kept');
        [$r, $rl] = $this->returned($i, '2');
        $this->actAsRole(DefaultRoles::FINANCE);
        $this->decide($r, ['kind' => 'same', 'sales_return_line_id' => $rl, 'credit_note_line_id' => $c1, 'quantity' => '2']);
        self::assertSame(200, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        $same = $this->json()['lines'][0]['adjustment_decisions'][0]['id'];
        $this->decide($r, ['kind' => 'reversal', 'reverses_decision_id' => $same]);
        self::assertSame([409, 'adjustment_decision.reversal_needs_the_owner'], [$this->httpStatus(), $this->json()['rule'] ?? null]);
        self::assertSame(1, $this->countAsOwnerForTenant(self::TENANT_A, 'SELECT count(*) FROM adjustment_decision'));
    }

    /**
     * J-8 / `SEC` S-7 (PC-17 hold-test): a holder of `credit_note.issue` WITHOUT `invoice.view` gets the credit write's 201
     * without `return_decisions`; an `invoice.view` holder's `GetCreditNote` of an adjustment carries them.
     */
    #[Test]
    public function aCreditWritesBodyOmitsTheDecisionsWithoutInvoiceView(): void
    {
        $i = $this->invoicedShipment('AD-J8', [['10', '10.0000', '10']]);
        $this->actAsRole(DefaultRoles::OWNER);
        [$cn, $c1] = $this->adjustment($i, '2', 'damaged_goods_kept');
        [$r, $rl] = $this->returned($i, '3');
        $this->decide($r, ['kind' => 'same', 'sales_return_line_id' => $rl, 'credit_note_line_id' => $c1, 'quantity' => '2']);
        self::assertSame(200, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        $this->send('GET', '/credit-notes/'.$cn);
        self::assertCount(1, $this->json()['lines'][0]['return_decisions'], 'Positive control: the adjustment carries its decision.');

        $this->actAs(self::TENANT_A, ['credit_note.issue']);
        $this->credit($i, $r, [[0, '1']]);
        self::assertSame(201, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        self::assertArrayNotHasKey('return_decisions', $this->json()['lines'][0], 'J-8: the 201 leaked a decision member.');
    }

    /**
     * Test 23 (J-7, FR-1, FR-2): `same` on a pair carrying a live `distinct` is refused; reversing a reversal is refused;
     * a row is reversed once.
     */
    #[Test]
    public function aSameIsRefusedBesideALiveDistinctAndAReversalIsFinal(): void
    {
        $i = $this->invoicedShipment('AD-23', [['10', '10.0000', '10']]);
        $this->actAsRole(DefaultRoles::OWNER);
        [, $c1] = $this->adjustment($i, '2', 'damaged_goods_kept');
        [$r, $rl] = $this->returned($i, '4');
        $this->decide($r, ['kind' => 'distinct', 'sales_return_line_id' => $rl, 'credit_note_line_id' => $c1]);
        self::assertSame(200, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        $distinct = $this->json()['lines'][0]['adjustment_decisions'][0]['id'];
        $this->decide($r, ['kind' => 'reversal', 'reverses_decision_id' => $distinct]);
        self::assertSame(200, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        $reversal = array_values(array_filter($this->json()['lines'][0]['adjustment_decisions'], static fn (array $d): bool => 'reversal' === $d['kind']))[0];
        self::assertSame($distinct, $reversal['reverses_decision_id']);
        $this->decide($r, ['kind' => 'distinct', 'sales_return_line_id' => $rl, 'credit_note_line_id' => $c1]);
        self::assertSame(200, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());

        $this->actAsRole(DefaultRoles::FINANCE);
        $this->decide($r, ['kind' => 'same', 'sales_return_line_id' => $rl, 'credit_note_line_id' => $c1, 'quantity' => '1']);
        self::assertSame([409, 'adjustment_decision.already_decided'], [$this->httpStatus(), $this->json()['rule'] ?? null], 'J-7.');
        $this->actAsRole(DefaultRoles::OWNER);
        $this->decide($r, ['kind' => 'reversal', 'reverses_decision_id' => $reversal['id']]);
        self::assertSame([409, 'adjustment_decision.reverses_a_decision'], [$this->httpStatus(), $this->json()['rule'] ?? null]);
        $this->decide($r, ['kind' => 'reversal', 'reverses_decision_id' => $distinct]);
        self::assertSame([409, 'adjustment_decision.already_reversed'], [$this->httpStatus(), $this->json()['rule'] ?? null]);
    }

    /** Test 23 (J-7): `same` first and then `distinct` for the remaining open units is the legitimate mixed case. */
    #[Test]
    public function aSameThenADistinctForTheRestIsAccepted(): void
    {
        $i = $this->invoicedShipment('AD-23B', [['10', '10.0000', '10']]);
        $this->actAsRole(DefaultRoles::OWNER);
        [, $c1] = $this->adjustment($i, '2', 'damaged_goods_kept');
        [$r, $rl] = $this->returned($i, '4');
        $this->decide($r, ['kind' => 'same', 'sales_return_line_id' => $rl, 'credit_note_line_id' => $c1, 'quantity' => '1']);
        self::assertSame(200, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        $this->decide($r, ['kind' => 'distinct', 'sales_return_line_id' => $rl, 'credit_note_line_id' => $c1]);
        self::assertSame(200, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        self::assertFalse($this->json()['adjustment_decision_owed']);
        self::assertSame('1.000000', $this->json()['lines'][0]['quantity_covered_by_adjustment']);
        $this->actAsRole(DefaultRoles::FINANCE);
        $this->credit($i, $r, [[0, '3']]);
        self::assertSame(201, $this->httpStatus(), 'The three open, different units are owed. '.(string) $this->client()->getResponse()->getContent());
    }

    /**
     * Test 23b S1 (RED d1d4 F-1): reversing a `same` that a later return credit relied on is refused until the owner
     * names that return as different goods; nothing is written.
     */
    #[Test]
    public function aReversalCannotUnmaskACreditThatReliedOnIt(): void
    {
        [$i, $r1, $rl1, $c1, $same, $r2] = $this->s1();
        $this->actAsRole(DefaultRoles::OWNER);
        $this->decide($r1, ['kind' => 'reversal', 'reverses_decision_id' => $same]);
        self::assertSame([409, 'adjustment_decision.reversal_unmasks_a_credited_return'], [$this->httpStatus(), $this->json()['rule'] ?? null]);
        $detail = (string) $this->json()['detail'];
        self::assertStringContainsString($this->returnNumber($r2), $detail, 'The owner (sales_return.view) is told which return.');
        self::assertStringContainsString('may be short by up to 2 units', $detail, 'Final RED R-2: not over, may be short.');
        self::assertSame(1, $this->countAsOwnerForTenant(self::TENANT_A, 'SELECT count(*) FROM adjustment_decision'), 'Nothing was written.');
        unset($i, $rl1, $c1);
    }

    /** Test 23b S1, the accepted half: naming R2 writes the reversal and a `distinct` record row (R2, c1); R1 is then decided and credited. */
    #[Test]
    public function aReversalNamingTheCreditedReturnRecordsItAsDifferent(): void
    {
        [$i, $r1, $rl1, $c1, $same, $r2] = $this->s1();
        $this->actAsRole(DefaultRoles::OWNER);
        $this->decide($r1, ['kind' => 'reversal', 'reverses_decision_id' => $same, 'acknowledged_return_ids' => [$r2]]);
        self::assertSame(200, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        self::assertSame([['kind' => 'distinct', 'return' => $r2], ['kind' => 'reversal', 'return' => $r1], ['kind' => 'same', 'return' => $r1]],
            $this->fetchAsOwnerForTenant(self::TENANT_A, <<<'SQL'
                SELECT ad.kind, rl.sales_return_id::text AS return FROM adjustment_decision ad
                  JOIN sales_return_line rl ON rl.id = ad.sales_return_line_id ORDER BY ad.kind
                SQL));
        $this->decide($r1, ['kind' => 'distinct', 'sales_return_line_id' => $rl1, 'credit_note_line_id' => $c1]);
        self::assertSame(200, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        $this->actAsRole(DefaultRoles::FINANCE);
        $this->credit($i, $r1, [[0, '2']]);
        self::assertSame(201, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
    }

    /** Test 23b S2 (final RED R-1): a return holding a live `same` with the adjustment is still listed. */
    #[Test]
    public function aReturnWithItsOwnSameIsStillListed(): void
    {
        $i = $this->invoicedShipment('AD-S2', [['10', '10.0000', '10']]);
        $this->actAsRole(DefaultRoles::OWNER);
        [, $c1] = $this->adjustment($i, '3', 'damaged_goods_kept');
        [$r2, $rl2] = $this->returned($i, '3');
        $this->actAsRole(DefaultRoles::FINANCE);
        $this->decide($r2, ['kind' => 'same', 'sales_return_line_id' => $rl2, 'credit_note_line_id' => $c1, 'quantity' => '1']);
        self::assertSame(200, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        $this->actAsRole(DefaultRoles::OWNER);
        [$r1, $rl1] = $this->returned($i, '2');
        $this->actAsRole(DefaultRoles::FINANCE);
        $this->decide($r1, ['kind' => 'same', 'sales_return_line_id' => $rl1, 'credit_note_line_id' => $c1, 'quantity' => '2']);
        self::assertSame(200, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        $same = $this->json()['lines'][0]['adjustment_decisions'][0]['id'];
        $this->credit($i, $r2, [[0, '2']]);
        self::assertSame(201, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());

        $this->actAsRole(DefaultRoles::OWNER);
        $this->decide($r1, ['kind' => 'reversal', 'reverses_decision_id' => $same]);
        self::assertSame([409, 'adjustment_decision.reversal_unmasks_a_credited_return'], [$this->httpStatus(), $this->json()['rule'] ?? null]);
        self::assertStringContainsString($this->returnNumber($r2), (string) $this->json()['detail']);
    }

    /**
     * Test 23b S3 (final RED R-3): the reversed line itself is not listed, so the reversal is accepted — and the
     * invariant R-3 protects holds: no impossible `distinct` is stored, and one recorded afterwards is refused.
     * *(Design §17: final RED expected a refusal; under R-3's own exclusion of `rl` none is listed. Both keep M-3.)*
     */
    #[Test]
    public function reversingAForcedSameStoresNoImpossibleDistinct(): void
    {
        $i = $this->invoicedShipment('AD-S3', [['3', '10.0000', '3']]);
        $this->actAsRole(DefaultRoles::OWNER);
        [, $c1] = $this->adjustment($i, '2', 'damaged_goods_kept');
        [$r1, $rl1] = $this->returned($i, '3');
        $this->decide($r1, ['kind' => 'same', 'sales_return_line_id' => $rl1, 'credit_note_line_id' => $c1, 'quantity' => '2']);
        self::assertSame(200, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        $same = $this->json()['lines'][0]['adjustment_decisions'][0]['id'];
        $this->credit($i, $r1, [[0, '1']]);
        self::assertSame(201, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());

        $this->decide($r1, ['kind' => 'reversal', 'reverses_decision_id' => $same]);
        self::assertSame(200, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        self::assertSame(0, $this->countAsOwnerForTenant(self::TENANT_A, "SELECT count(*) FROM adjustment_decision WHERE kind = 'distinct'"));
        $this->decide($r1, ['kind' => 'distinct', 'sales_return_line_id' => $rl1, 'credit_note_line_id' => $c1]);
        self::assertSame([409, 'adjustment_decision.distinct_is_impossible'], [$this->httpStatus(), $this->json()['rule'] ?? null], '1 + 0 + 2 + 2 = 5 > 3.');
    }

    /**
     * Test 23b S3's record-row variant (final RED R-3; M33): a listed line WITH open units is a real `distinct`, so §3 step
     * 8 runs on its record row and an impossible one refuses the whole reversal. **R3's credit is SEEDED AS THE OWNER
     * (the G-15 shape)**: through the API the state is unreachable once C2's step 8c exists (crediting R3 needs its pair
     * decided first, and the pair's `distinct` is itself impossible), so this pins the check, not a reachable path.
     */
    #[Test]
    public function anImpossibleRecordRowRefusesTheReversal(): void
    {
        $i = $this->invoicedShipment('AD-S3R', [['10', '10.0000', '10']]);
        $this->actAsRole(DefaultRoles::OWNER);
        [, $c1] = $this->adjustment($i, '5', 'damaged_goods_kept');
        [$r1, $rl1] = $this->returned($i, '2');
        [$r3, $rl3] = $this->returned($i, '8');
        $this->decide($r1, ['kind' => 'same', 'sales_return_line_id' => $rl1, 'credit_note_line_id' => $c1, 'quantity' => '2']);
        self::assertSame(200, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        $same = $this->json()['lines'][0]['adjustment_decisions'][0]['id'];
        $this->decide($r3, ['kind' => 'same', 'sales_return_line_id' => $rl3, 'credit_note_line_id' => $c1, 'quantity' => '1']);
        self::assertSame(200, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        $this->seedCreditNote($i['invoice'], '10.0000', [[$i['lines'][0], '1', '10.0000', $rl3]], $r3);

        // After the reversal: Cr 1 + Same 1 + open(R3) 6 + open(c1) 4 = 12 > 10.
        $this->decide($r1, ['kind' => 'reversal', 'reverses_decision_id' => $same, 'acknowledged_return_ids' => [$r3]]);
        self::assertSame([409, 'adjustment_decision.distinct_is_impossible'], [$this->httpStatus(), $this->json()['rule'] ?? null]);
        self::assertSame(2, $this->countAsOwnerForTenant(self::TENANT_A, 'SELECT count(*) FROM adjustment_decision'), 'Nothing was written.');
    }

    /** Test 23b S4 (final RED R-2): refused without naming R2; the total stays 3 for 4 owed — short, never over. */
    #[Test]
    public function aRefusedReversalLeavesTheCustomerShortNeverOver(): void
    {
        $i = $this->invoicedShipment('AD-S4', [['10', '10.0000', '10']]);
        $this->actAsRole(DefaultRoles::OWNER);
        [, $c1] = $this->adjustment($i, '2', 'damaged_goods_kept');
        [$r1, $rl1] = $this->returned($i, '2');
        [$r2] = $this->returned($i, '1');
        $this->actAsRole(DefaultRoles::FINANCE);
        $this->decide($r1, ['kind' => 'same', 'sales_return_line_id' => $rl1, 'credit_note_line_id' => $c1, 'quantity' => '2']);
        self::assertSame(200, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        $same = $this->json()['lines'][0]['adjustment_decisions'][0]['id'];
        $this->credit($i, $r2, [[0, '1']]);
        self::assertSame(201, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());

        $this->actAsRole(DefaultRoles::OWNER);
        $this->decide($r1, ['kind' => 'reversal', 'reverses_decision_id' => $same]);
        self::assertSame([409, 'adjustment_decision.reversal_unmasks_a_credited_return'], [$this->httpStatus(), $this->json()['rule'] ?? null]);
        self::assertStringContainsString('the total credited is not over; it may be short by up to 2 units', (string) $this->json()['detail']);
        self::assertSame('3.000000', $this->fetchAsOwnerForTenant(self::TENANT_A,
            'SELECT sum(quantity)::numeric(19,6)::text AS q FROM credit_note_line WHERE fulfilment_line_id = ?::uuid', [$i['lines'][0]])[0]['q']);
    }

    /**
     * Test 23b, the positive control: a return credited on a note numbered BEFORE the adjustment's is not listed.
     * And M30's case (final RED R-11): a credit numbered AFTER the adjustment whose transaction STARTED before the `same`
     * — `created_at` earlier, number later — IS listed. That state is the race's end state; it is **seeded as the owner**
     * (an explicit earlier `created_at`), not raced.
     */
    #[Test]
    public function theListIsOrderedByCreditNoteNumberNotByTime(): void
    {
        $i = $this->invoicedShipment('AD-NUM', [['10', '10.0000', '10']]);
        $this->actAsRole(DefaultRoles::OWNER);
        [$r0] = $this->returned($i, '1');
        $this->credit($i, $r0, [[0, '1']]);
        self::assertSame(201, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        [, $c1] = $this->adjustment($i, '2', 'damaged_goods_kept');
        [$r1, $rl1] = $this->returned($i, '2');
        $this->decide($r1, ['kind' => 'same', 'sales_return_line_id' => $rl1, 'credit_note_line_id' => $c1, 'quantity' => '2']);
        self::assertSame(200, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        $same = $this->json()['lines'][0]['adjustment_decisions'][0]['id'];
        [$r2, $rl2] = $this->returned($i, '1');
        $note = $this->seedCreditNote($i['invoice'], '10.0000', [[$i['lines'][0], '1', '10.0000', $rl2]], $r2);
        $this->executeAsOwnerForTenant(self::TENANT_A, "UPDATE credit_note SET created_at = now() - interval '1 hour', issued_at = now() - interval '1 hour' WHERE id = ?::uuid", [$note]);

        $this->decide($r1, ['kind' => 'reversal', 'reverses_decision_id' => $same]);
        self::assertSame([409, 'adjustment_decision.reversal_unmasks_a_credited_return'], [$this->httpStatus(), $this->json()['rule'] ?? null],
            'A credit numbered after the adjustment but timestamped before the same was not listed (M30).');
        $detail = (string) $this->json()['detail'];
        self::assertStringContainsString($this->returnNumber($r2), $detail);
        self::assertStringNotContainsString($this->returnNumber($r0), $detail, 'A credit numbered before the adjustment is never listed.');
    }

    /**
     * **Test 3 (G-29 / G-30 part 3 C2):** a decided return leaves BOTH queues, and `PayInvoice`'s Q-P stops demanding it.
     * Adjust, return, finance pays naming the return (Q-P) — the return is now in `credit_blocked_by_payment` (G-23's
     * queue); finance records `same` (allowed on a paid invoice, §3 step 9) and the return is out of both queues with
     * `credit_target` null.
     */
    #[Test]
    public function aDecidedReturnLeavesBothQueues(): void
    {
        $i = $this->invoicedShipment('AD-3', [['10', '10.0000', '10']]);
        $this->actAsRole(DefaultRoles::OWNER);
        [, $c1] = $this->adjustment($i, '2', 'damaged_goods_kept');
        [$r, $rl] = $this->returned($i, '2');
        $this->actAsRole(DefaultRoles::FINANCE);

        $this->send('POST', '/invoices/'.$i['invoice'].'/payment', ['acknowledged_return_ids' => [$r]]);
        self::assertSame(200, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        $this->send('GET', '/sales-returns?credit_blocked_by_payment=true');
        self::assertContains($r, array_column($this->json()['items'], 'id'), 'Paid past: the return waits in G-23\'s queue.');

        $this->decide($r, ['kind' => 'same', 'sales_return_line_id' => $rl, 'credit_note_line_id' => $c1, 'quantity' => '2']);
        self::assertSame(200, $this->httpStatus(), 'A `same` is allowed on a paid invoice. '.(string) $this->client()->getResponse()->getContent());
        self::assertNull($this->json()['credit_target']);
        self::assertFalse($this->json()['credit_blocked_by_payment']);
        $this->send('GET', '/sales-returns?credit_blocked_by_payment=true');
        self::assertNotContains($r, array_column($this->json()['items'], 'id'), 'A decided return left G-23\'s queue.');
        $this->send('GET', '/sales-returns?creditable=true');
        self::assertNotContains($r, array_column($this->json()['items'], 'id'));
    }

    /**
     * **Test 3's `PayInvoice` sentence, finance (RED d1d4 F-2; M31):** the refusal before the acknowledgement says a
     * decision is owed on the return — by number to a caller holding `invoice.view` AND `sales_return.view`.
     */
    #[Test]
    public function payInvoiceTellsFinanceADecisionIsOwed(): void
    {
        $i = $this->owedReturnOnAnUnpaidInvoice('AD-3F');
        $this->actAsRole(DefaultRoles::FINANCE);

        $this->send('POST', '/invoices/'.$i['invoice'].'/payment', []);
        self::assertSame([409, 'invoice.has_no_uncredited_return'], [$this->httpStatus(), $this->json()['rule'] ?? null]);
        self::assertStringContainsString('A decision is owed on '.$i['number_of_return'].' (already credited, or, by an owner, different goods)', (string) $this->json()['detail']);
        self::assertStringContainsString('marks the invoice for good', (string) $this->json()['detail']);
    }

    /** **Test 3, `actAs(["invoice.pay"])` (the shipped precedent):** no `invoice.view`, so no decision sentence and no return number. */
    #[Test]
    public function payInvoiceSaysNothingOfDecisionsWithoutInvoiceView(): void
    {
        $i = $this->owedReturnOnAnUnpaidInvoice('AD-3P');
        $this->actAs(self::TENANT_A, ['invoice.pay']);

        $this->send('POST', '/invoices/'.$i['invoice'].'/payment', []);
        self::assertSame([409, 'invoice.has_no_uncredited_return'], [$this->httpStatus(), $this->json()['rule'] ?? null]);
        self::assertStringNotContainsString('A decision is owed', (string) $this->json()['detail']);
        self::assertStringNotContainsString('RET-', (string) $this->json()['detail']);
    }

    /**
     * **Test 3, `["invoice.pay", "invoice.view"]` (final RED R-4; M35):** the sentence is there, but the return's number is
     * a `sales_return.view` holder's too, so it reads "on one of these returns" and carries no `RET-`.
     */
    #[Test]
    public function payInvoiceNamesNoReturnToAnInvoiceViewerWhoCannotViewReturns(): void
    {
        $i = $this->owedReturnOnAnUnpaidInvoice('AD-3V');
        $this->actAs(self::TENANT_A, ['invoice.pay', 'invoice.view']);

        $this->send('POST', '/invoices/'.$i['invoice'].'/payment', []);
        self::assertSame([409, 'invoice.has_no_uncredited_return'], [$this->httpStatus(), $this->json()['rule'] ?? null]);
        self::assertStringContainsString('A decision is owed on one of these returns', (string) $this->json()['detail']);
        self::assertStringNotContainsString('RET-', (string) $this->json()['detail']);
    }

    /**
     * **Test 22 (J-13; M21):** two returns on one shipped line, the first credited, NO adjustment. The second return's credit
     * is not refused with `adjustment_decision_owed`: a return credit's line can never form a pair, because the adjustment
     * filter is in `undecidedPair`'s own text.
     */
    #[Test]
    public function aReturnCreditsLineNeverFormsAPair(): void
    {
        $i = $this->invoicedShipment('AD-22', [['10', '10.0000', '10']]);
        $this->actAsRole(DefaultRoles::OWNER);
        [$r1] = $this->returned($i, '2');
        [$r2] = $this->returned($i, '2');
        $this->actAsRole(DefaultRoles::FINANCE);
        $this->credit($i, $r1, [[0, '2']]);
        self::assertSame(201, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());

        $this->credit($i, $r2, [[0, '2']]);
        self::assertSame(201, $this->httpStatus(), 'The first return\'s credit line formed a pair with the second return. '.(string) $this->client()->getResponse()->getContent());
    }

    /**
     * **Test 23, FR-1's sequence and FR-2 (Q-2 (b); M24):** billed 10; A1 = 1 kept; R = 3; finance `same` 1 on (R, A1); an
     * owner adjusts A2 = 1 NAMING R (a `distinct` on (R, A2)); R is credited 2; the owner reverses the `same`. The cascade
     * reverses the (R, A2) `distinct` too — so `adjustment_decision_owed` is true while EITHER pair is undecided: after
     * recording `distinct` on (R, A1) it is STILL true, and only a second `distinct`, on (R, A2) in decision round 2 (FR-2),
     * clears it.
     */
    #[Test]
    public function aReversalReopensEveryDistinctOnEitherLine(): void
    {
        $i = $this->invoicedShipment('AD-FR1', [['10', '10.0000', '10']]);
        $owner = $this->actAsRole(DefaultRoles::OWNER);
        [, $a1] = $this->adjustment($i, '1', 'damaged_goods_kept');
        [$r, $rl] = $this->returned($i, '3');
        $this->actAsRole(DefaultRoles::FINANCE);
        $this->decide($r, ['kind' => 'same', 'sales_return_line_id' => $rl, 'credit_note_line_id' => $a1, 'quantity' => '1']);
        self::assertSame(200, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        $same = $this->json()['lines'][0]['adjustment_decisions'][0]['id'];
        $this->actAsUser(self::TENANT_A, $owner);
        $this->send('POST', '/invoices/'.$i['invoice'].'/credit-note-adjustments', [
            'reason' => 'short_shipped', 'lines' => [['fulfilment_line_id' => $i['lines'][0], 'quantity' => '1']], 'acknowledged_return_ids' => [$r],
        ], ['Idempotency-Key' => Uuid::v4()->toRfc4122()]);
        self::assertSame(201, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        $a2 = (string) $this->json()['lines'][0]['id'];
        $this->actAsRole(DefaultRoles::FINANCE);
        $this->credit($i, $r, [[0, '2']]);
        self::assertSame(201, $this->httpStatus(), 'The decided pairs let the return be credited. '.(string) $this->client()->getResponse()->getContent());

        $this->actAsUser(self::TENANT_A, $owner);
        $this->decide($r, ['kind' => 'reversal', 'reverses_decision_id' => $same]);
        self::assertSame(200, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        self::assertTrue($this->json()['adjustment_decision_owed']);
        $this->decide($r, ['kind' => 'distinct', 'sales_return_line_id' => $rl, 'credit_note_line_id' => $a1]);
        self::assertSame(200, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        self::assertTrue($this->json()['adjustment_decision_owed'], 'The (R, A2) `distinct` was not reopened by the reversal (FR-1).');
        $this->decide($r, ['kind' => 'distinct', 'sales_return_line_id' => $rl, 'credit_note_line_id' => $a2]);
        self::assertSame(200, $this->httpStatus(), 'FR-2: the reopened pair accepts a new `distinct`. '.(string) $this->client()->getResponse()->getContent());
        self::assertFalse($this->json()['adjustment_decision_owed']);
        self::assertSame([2], array_map('intval', array_column($this->fetchAsOwnerForTenant(self::TENANT_A, <<<'SQL'
            SELECT decision_round FROM adjustment_decision WHERE kind = 'distinct' AND credit_note_line_id = ?::uuid ORDER BY decision_round DESC LIMIT 1
            SQL, [$a2]), 'decision_round')));
        $this->credit($i, $r, [[0, '1']]);
        self::assertSame(201, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
    }

    /**
     * **Test 16, 8c's copy is the PAIR's, not the line's (J-1; M23)** — the R1/R2 case: billed 5; CN-1 2 kept; R1 returns
     * the 2 kept units; R2 returns 2 different units; finance credits R2. The line-wide excess is `2 + 2 + 2 > 5`, but R2's
     * own pair with CN-1 is `0 + 0 + 2 + 2 ≤ 5`, so nothing is PROVEN to overlap: the copy is the neutral one and never says
     * "at least".
     */
    #[Test]
    public function theDecisionOwedCopyIsNeutralWhereThePairIsNotImpossible(): void
    {
        $i = $this->invoicedShipment('AD-16N', [['5', '10.0000', '5']]);
        $this->actAsRole(DefaultRoles::OWNER);
        $this->adjustment($i, '2', 'damaged_goods_kept');
        $this->returned($i, '2');
        [$r2] = $this->returned($i, '2');
        $this->actAsRole(DefaultRoles::FINANCE);

        $this->credit($i, $r2, [[0, '2']]);
        self::assertSame([409, 'credit_note.adjustment_decision_owed'], [$this->httpStatus(), $this->json()['rule'] ?? null]);
        $detail = (string) $this->json()['detail'];
        self::assertStringNotContainsString('at least', $detail, 'A pair that is not impossible was told it overlaps (a line-wide sum, M23).');
        self::assertStringContainsString('also has 2 credited without a return (CN-', $detail);
        self::assertStringContainsString('record whether its goods are those units', $detail);
    }

    /** @return iterable<string, array{string, string, string, string}> billed, adjusted, returned, the proven overlap (FR-4) */
    public static function provenOverlaps(): iterable
    {
        yield 'A-1: billed 3, adjusted 2, returned 2' => ['3', '2', '2', '1'];
        yield 'billed 10, adjusted 4 short_shipped, returned 7' => ['10', '4', '7', '1'];
    }

    /**
     * **Test 16, FR-4 (M25; the 8c half of the refusal tests):** where the PAIR is impossible the copy says "at least {o}" of
     * the returned units are units the adjustment already credited, with `{o}` = `provenOverlap`, never the returned quantity.
     */
    #[Test]
    #[DataProvider('provenOverlaps')]
    public function theDecisionOwedCopyNamesTheProvenOverlap(string $billed, string $adjusted, string $returned, string $overlap): void
    {
        $i = $this->invoicedShipment('AD-16P-'.$billed.$adjusted.$returned, [[$billed, '10.0000', $billed]]);
        $this->actAsRole(DefaultRoles::OWNER);
        $this->adjustment($i, $adjusted, '10' === $billed ? 'short_shipped' : 'damaged_goods_kept');
        [$r] = $this->returned($i, $returned);
        $this->actAsRole(DefaultRoles::FINANCE);

        // A credit of ONE unit: the whole return would be refused first by the billed bound (step 7), before 8c is reached.
        $this->credit($i, $r, [[0, '1']]);
        self::assertSame([409, 'credit_note.adjustment_decision_owed'], [$this->httpStatus(), $this->json()['rule'] ?? null]);
        self::assertStringContainsString(
            \sprintf('at least %s of these %s returned units are units CN-', $overlap, $returned),
            (string) $this->json()['detail'],
            'The copy did not name the proven overlap (FR-4: provenOverlap, not the returned or adjusted quantity).',
        );
        self::assertStringContainsString(\sprintf('Record at least %s as already credited', $overlap), (string) $this->json()['detail']);
    }

    /**
     * An unpaid invoice with an adjustment of 2 kept and an undecided return of 2.
     *
     * @return array{invoice: string, number_of_return: string}
     */
    private function owedReturnOnAnUnpaidInvoice(string $tag): array
    {
        $i = $this->invoicedShipment($tag, [['10', '10.0000', '10']]);
        $this->actAsRole(DefaultRoles::OWNER);
        $this->adjustment($i, '2', 'damaged_goods_kept');
        [$r] = $this->returned($i, '2');

        return ['invoice' => $i['invoice'], 'number_of_return' => $this->returnNumber($r)];
    }

    /**
     * S1's fixture: billed 10; c1 2 `damaged_goods_kept`; R1 2; finance `same` 2 on (R1, c1); R2 3 credited 3 (no pair:
     * c1 has no open units).
     *
     * @return array{0: array{invoice: string, number: string, fulfilment: string, order: string, lines: list<string>, bin: string}, 1: string, 2: string, 3: string, 4: string, 5: string}
     */
    private function s1(): array
    {
        $i = $this->invoicedShipment('AD-S1-'.substr(Uuid::v4()->toRfc4122(), 0, 6), [['10', '10.0000', '10']]);
        $this->actAsRole(DefaultRoles::OWNER);
        [, $c1] = $this->adjustment($i, '2', 'damaged_goods_kept');
        [$r1, $rl1] = $this->returned($i, '2');
        [$r2] = $this->returned($i, '3');
        $this->actAsRole(DefaultRoles::FINANCE);
        $this->decide($r1, ['kind' => 'same', 'sales_return_line_id' => $rl1, 'credit_note_line_id' => $c1, 'quantity' => '2']);
        self::assertSame(200, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        $same = $this->json()['lines'][0]['adjustment_decisions'][0]['id'];
        $this->credit($i, $r2, [[0, '3']]);
        self::assertSame(201, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());

        return [$i, $r1, $rl1, $c1, $same, $r2];
    }

    /**
     * `IssueAdjustmentCreditNote` on line 0 as the current actor; returns `[credit note id, its line id]`.
     *
     * @param array{invoice: string, lines: list<string>} $shipment
     *
     * @return array{0: string, 1: string}
     */
    private function adjustment(array $shipment, string $quantity, string $reason): array
    {
        $this->send('POST', '/invoices/'.$shipment['invoice'].'/credit-note-adjustments', [
            'reason' => $reason,
            'lines' => [['fulfilment_line_id' => $shipment['lines'][0], 'quantity' => $quantity]],
        ], ['Idempotency-Key' => Uuid::v4()->toRfc4122()]);
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
    private function decide(string $returnId, array $body, ?string $key = null): void
    {
        $this->send('POST', '/sales-returns/'.$returnId.'/adjustment-decisions', $body, ['Idempotency-Key' => $key ?? Uuid::v4()->toRfc4122()]);
    }

    private function returnNumber(string $returnId): string
    {
        return (string) $this->fetchAsOwnerForTenant(self::TENANT_A, 'SELECT sales_return_number FROM sales_return WHERE id = ?::uuid', [$returnId])[0]['sales_return_number'];
    }
}
