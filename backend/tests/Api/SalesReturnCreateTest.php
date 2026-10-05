<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Modules\UserManagement\Domain\DefaultRoles;
use App\SharedKernel\ValueObject\AuditEvent;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Uid\Uuid;

/**
 * **`CreateSalesReturn` and `GetSalesReturn` — sales step 7, commit R2** (2026-10-01), built from
 * `docs/session13/output9-sales-8-design.md` §4.1–§4.3 under the owner's rulings of 2026-09-30
 * (`output9-sales-8-ruling.md`) and `SEC`'s (`output9-sales-8-sec.md`; L5-1 decided (b) in `-l5-1-sec.md`).
 *
 * Every shipment is written THROUGH THE API (`InvoiceFixtures::shipped()`), every invoice issued and paid through it.
 * The races — return ∥ issue on one shipment, and return ∥ fulfilment over two shared levels — are in
 * `PurchaseOrderRowLockTest`, beside the holder harness; the constraints are `SalesReturnSchemaTest`.
 */
final class SalesReturnCreateTest extends PurchaseOrderTestCase
{
    use InvoiceFixtures;

    /** The operations under `/sales-returns`. `CreateSalesReturn` hangs off the fulfilment and is pinned with it. */
    public const PINNED_SALES_RETURN_OPERATIONS = [
        // Sales step 7 R3 (declared first on its own resource class, so listed first).
        'ListSalesReturns' => ['method' => 'GET', 'path' => '/sales-returns', 'idem' => false],
        'GetSalesReturn' => ['method' => 'GET', 'path' => '/sales-returns/{id}', 'idem' => false],
        // G-29 / G-30 part 3 C1 (2026-10-05): a decision about the return's units, `credit_note.issue`, branch 5.
        'CreateAdjustmentDecision' => ['method' => 'POST', 'path' => '/sales-returns/{id}/adjustment-decisions', 'idem' => true],
    ];

    /**
     * **Measured at R3** (`SEC` L5-1 FINAL item 10; design :246-248): the status a caller without `invoice.view` gets for
     * `?credit_blocked_by_payment=`. A parameter error (422 `validation-failed`, code `not_permitted`), not a 403 — see
     * `SalesReturnCollectionProvider`'s docblock for why a provider-thrown 403 would bypass E-1's audit and denial subject.
     */
    private const CREDIT_BLOCKED_FILTER_REFUSAL = 422;

    /**
     * `SEC` L5-1 §1.3 — the literal key lists, from design :218-229 and §14 item 25; NEVER derived from a response.
     * If the built body differs, that is a design change for `SEC`, not an edit here.
     */
    private const RETURN_KEYS_WITHOUT_INVOICE_VIEW = [
        'created_at', 'credit_target', 'deducts', 'fulfilment', 'id', 'invoice', 'lines',
        'reason', 'recorded_by', 'returned_at', 'sales_return_number',
    ];

    // `SEC`'s §1.3 amendment (2026-10-01, on the R2 builder's stop-and-ask): option (1), the line carries its `id`.
    private const RETURN_LINE_KEYS = [
        'fulfilment_line', 'id', 'inventory_item', 'quantity', 'quantity_credited', 'storage_location',
    ];

    #[Test]
    public function theSalesReturnPathsDeclareExactlyThePinnedOperations(): void
    {
        $declared = [];
        foreach (TransactionBoundaryTest::contractOperations() as $name => $operation) {
            if (str_starts_with((string) $operation->getUriTemplate(), '/sales-returns')) {
                $declared[$name] = ['method' => $operation->getMethod(), 'path' => $operation->getUriTemplate()];
            }
        }

        self::assertSame(
            array_map(static fn (array $pin): array => ['method' => $pin['method'], 'path' => $pin['path']], self::PINNED_SALES_RETURN_OPERATIONS),
            $declared,
        );
    }

    /**
     * **The shape and the effects, for the ordinary case Q-L (a′) serves: a second warehouse person records a return
     * on a colleague's shipment before any invoice exists, so it DEDUCTS.** 201 with `Location` naming
     * `GetSalesReturn`, the body, stock raised by one positive `return` movement into the bin named, `recorded_by` the
     * caller, the trail row `{"reason", "deducts"}`, and the order untouched (Q-C).
     */
    #[Test]
    public function aSecondWarehousePersonRecordsAReturnBeforeInvoicingAndItDeducts(): void
    {
        $s = $this->shipment('RET-A', [['5', '10.0000', '4']]);
        $quarantine = $this->seedBinIn($s['warehouse'], 'Q-01', 'quarantine');
        $recorder = $this->actAsRole(DefaultRoles::WAREHOUSE_OPERATOR);

        $this->recordReturn($s['fulfilment'], 'damaged_in_transit', [
            ['fulfilment_line_id' => $s['fulfilment_lines'][0], 'quantity' => '1.5', 'storage_location_id' => $quarantine],
        ]);

        self::assertSame(201, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        $body = $this->json();
        self::assertSame('/api/v1/sales-returns/'.$body['id'], $this->client()->getResponse()->headers->get('Location'));
        self::assertMatchesRegularExpression('/^RET-[0-9]{6}$/', $body['sales_return_number']);
        self::assertSame('damaged_in_transit', $body['reason']);
        self::assertSame(['id' => $s['fulfilment'], 'fulfilment_number' => $s['fulfilment_number']], $body['fulfilment']);
        self::assertNull($body['invoice']);
        self::assertNull($body['credit_target'], 'A deducting return owes no credit.');
        self::assertTrue($body['deducts']);
        self::assertSame(['id' => $recorder, 'display_name' => 'Fixture Actor'], $body['recorded_by']);
        self::assertSame([[
            'id' => $body['lines'][0]['id'],
            'fulfilment_line' => [
                'id' => $s['fulfilment_lines'][0],
                'sales_order_line' => ['id' => $s['lines'][0], 'line_number' => 1],
                'inventory_item' => $s['items'][0],
                'storage_location' => ['id' => $s['bin'], 'code' => 'A-01'],
            ],
            'inventory_item' => $s['items'][0],
            'quantity' => '1.500000',
            'storage_location' => ['id' => $quarantine, 'code' => 'Q-01'],
            'quantity_credited' => '0.000000',
        ]], $body['lines']);
        self::assertArrayNotHasKey('credit_blocked_by_payment', $body, 'warehouse_operator holds no invoice.view (M4-1).');

        self::assertSame([['q' => '1.500000', 'reason' => 'return', 'source_type' => 'sales_return', 'source_id' => $body['id']]],
            $this->fetchAsOwnerForTenant(self::TENANT_A,
                "SELECT quantity::text AS q, reason, source_type, source_id::text AS source_id FROM stock_movement WHERE reason = 'return'"));
        self::assertSame('1.500000', $this->onHand($quarantine));
        self::assertSame([['q' => '4.000000', 'status' => 'partially_fulfilled']], $this->fetchAsOwnerForTenant(self::TENANT_A,
            'SELECT sol.quantity_fulfilled::text AS q, so.status FROM sales_order_line sol JOIN sales_order so ON so.id = sol.sales_order_id WHERE sol.id = ?::uuid',
            [$s['lines'][0]]), 'Q-C: a return reopens nothing.');

        self::assertSame([[
            'event_type' => AuditEvent::SALES_RETURN_CREATED,
            'actor_user_id' => $recorder,
            'actor_label' => 'Fixture Actor',
            'subject_type' => 'sales_return',
            'subject_id' => $body['id'],
            'ip_address' => null,
            'permission_code' => null,
            'metadata' => '{"reason": "damaged_in_transit", "deducts": true}',
        ]], $this->auditRowsOf(AuditEvent::SALES_RETURN_CREATED));

        $this->send('GET', '/sales-returns/'.$body['id']);
        self::assertSame(200, $this->httpStatus());
        self::assertSame($body, $this->json());
    }

    /**
     * **Q-L (a′)'s classification, one case per condition** (`SEC` R2.2 item 1). Who records the return decides,
     * once, whether it lowers the next invoice; nothing is refused.
     *
     * @return iterable<string, array{string, bool}>
     */
    public static function recorders(): iterable
    {
        yield 'the shipper themselves (warehouse_operator)' => ['shipper', false];
        yield 'a second warehouse_operator' => ['colleague', true];
        yield 'admin (holds no fulfilment.create)' => ['admin', false];
        yield 'an owner who also shipped' => ['owner-shipper', true];
    }

    #[Test]
    #[DataProvider('recorders')]
    public function whoRecordsAReturnBeforeInvoicingDecidesWhetherItDeducts(string $recorder, bool $deducts): void
    {
        $shipperRole = 'owner-shipper' === $recorder ? DefaultRoles::OWNER : DefaultRoles::WAREHOUSE_OPERATOR;
        $s = $this->shipment('RET-'.strtoupper(substr(md5($recorder), 0, 4)), [['5', '10.0000', '4']], $shipperRole);

        match ($recorder) {
            'shipper', 'owner-shipper' => $this->actAsUser(self::TENANT_A, $s['shipper']),
            'colleague' => $this->actAsRole(DefaultRoles::WAREHOUSE_OPERATOR),
            'admin' => $this->actAsRole(DefaultRoles::ADMIN),
        };
        $this->recordReturn($s['fulfilment'], 'other', [
            ['fulfilment_line_id' => $s['fulfilment_lines'][0], 'quantity' => '1', 'storage_location_id' => $s['bin']],
        ]);

        self::assertSame(201, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        self::assertSame($deducts, $this->json()['deducts']);
        self::assertSame($deducts ? 'true' : 'false', $this->fetchAsOwnerForTenant(self::TENANT_A,
            "SELECT CASE WHEN deducts THEN 'true' ELSE 'false' END AS d FROM sales_return")[0]['d']);
    }

    /**
     * **Recorded after an invoice exists: stamped with it, never deducting, creditable against it** (design F-2; `SEC`
     * S-4: the invoice is a PC-15 reference served to every `sales_return.view` holder). Even an owner's return does
     * not deduct once an invoice exists — `sales_return_deducts_only_before_invoice`.
     */
    #[Test]
    public function aReturnRecordedAfterIssueIsStampedWithTheInvoiceAndCreditableAgainstIt(): void
    {
        $i = $this->issuedOn($this->shipment('RET-C', [['5', '10.0000', '4']]));
        $this->actAsRole(DefaultRoles::OWNER);

        $this->recordReturn($i['shipment']['fulfilment'], 'other', [
            ['fulfilment_line_id' => $i['shipment']['fulfilment_lines'][0], 'quantity' => '1', 'storage_location_id' => $i['shipment']['bin']],
        ]);

        self::assertSame(201, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        self::assertSame(['id' => $i['invoice'], 'invoice_number' => $i['number']], $this->json()['invoice']);
        self::assertSame(['id' => $i['invoice'], 'invoice_number' => $i['number']], $this->json()['credit_target']);
        self::assertFalse($this->json()['deducts']);
        self::assertFalse($this->json()['credit_blocked_by_payment'], 'An owner holds invoice.view, so the field is served.');
    }

    /**
     * **L5-1, decided (b) by `SEC` 2026-10-01 — verbatim from `output9-sales-8-l5-1-sec.md` §1.3.** Issue → pay →
     * return (a reason other than `recorded_in_error`, which a live invoice refuses): the return is stamped with the
     * paid invoice and is non-creditable for the paid reason alone. A `warehouse_operator` (no `invoice.view`) sees a
     * null `credit_target` and no `credit_blocked_by_payment`; `finance` sees `credit_blocked_by_payment: true`. The
     * key sets are pinned ABSOLUTELY (`RED-2` M-2) and relatively; the control is an unpaid invoice's return.
     */
    #[Test]
    public function aPaidInvoiceIsSignalledOnlyByANullCreditTargetToACallerWithoutInvoiceView(): void
    {
        $paid = $this->issuedOn($this->shipment('RET-PAID', [['5', '10.0000', '4']]));
        $this->actAsRole(DefaultRoles::FINANCE);
        $this->send('POST', '/invoices/'.$paid['invoice'].'/payment', []);
        self::assertSame(200, $this->httpStatus(), 'Fixture: the payment. '.(string) $this->client()->getResponse()->getContent());
        $paidReturnId = $this->returnedOne($paid['shipment']);

        $unpaid = $this->issuedOn($this->shipment('RET-OPEN', [['5', '10.0000', '4']]));
        $unpaidReturnId = $this->returnedOne($unpaid['shipment']);
        $unpaidInvoiceId = $unpaid['invoice'];

        $this->actAsRole(DefaultRoles::WAREHOUSE_OPERATOR);
        $this->send('GET', '/sales-returns/'.$paidReturnId);
        self::assertSame(200, $this->httpStatus());
        $op = $this->json();
        $this->actAsRole(DefaultRoles::FINANCE);
        $this->send('GET', '/sales-returns/'.$paidReturnId);
        self::assertSame(200, $this->httpStatus());
        $fin = $this->json();

        self::assertNull($op['credit_target']);                                         // L5-1 (b)
        // RED-2 M-2: absolute pins (a key added to BOTH bodies goes red here)
        $keys = array_keys($op);
        sort($keys);
        self::assertSame(self::RETURN_KEYS_WITHOUT_INVOICE_VIEW, $keys);
        $lineKeys = array_keys($op['lines'][0]);
        sort($lineKeys);
        self::assertSame(self::RETURN_LINE_KEYS, $lineKeys);
        // relative pin kept as a cheap second check
        self::assertSame(
            array_values(array_diff(array_keys($fin), ['credit_blocked_by_payment', 'adjustment_decision_owed'])),
            array_keys($op),
        );
        self::assertTrue($fin['credit_blocked_by_payment']);                            // positive control
        self::assertSame(['id', 'invoice_number'], array_keys($op['invoice']));         // PC-15 ref only
        self::assertSame('0.000000', $op['lines'][0]['quantity_credited']);             // RED-2 L-1: literal scale

        $this->actAsRole(DefaultRoles::WAREHOUSE_OPERATOR);
        $this->send('GET', '/sales-returns/'.$unpaidReturnId);
        $unpaidBody = $this->json();
        self::assertSame($unpaidInvoiceId, $unpaidBody['credit_target']['id']);         // positive control
    }

    /**
     * **L5-1, decided (b) — R3 half, verbatim from `output9-sales-8-l5-1-sec.md` §1.3.** Same fixture as the R2 test:
     * the paid invoice's return is in the unfiltered list (seeded, not vacuous), absent from `creditable=true`, and the
     * unpaid one's present; the `credit_blocked_by_payment` filter is refused for the operator and served to finance.
     */
    #[Test]
    public function theCreditableQueueExcludesAPaidInvoicesReturnAndNothingMoreIsProjected(): void
    {
        $paid = $this->issuedOn($this->shipment('RQ-PAID', [['5', '10.0000', '4']]));
        $this->actAsRole(DefaultRoles::FINANCE);
        $this->send('POST', '/invoices/'.$paid['invoice'].'/payment', []);
        self::assertSame(200, $this->httpStatus(), 'Fixture: the payment. '.(string) $this->client()->getResponse()->getContent());
        $paidReturnId = $this->returnedOne($paid['shipment']);
        $unpaidReturnId = $this->returnedOne($this->issuedOn($this->shipment('RQ-OPEN', [['5', '10.0000', '4']]))['shipment']);

        $this->actAsRole(DefaultRoles::WAREHOUSE_OPERATOR);
        $all = $this->idsOf('/sales-returns');
        self::assertContains($paidReturnId, $all);                                      // seeded, not vacuous
        $creditable = $this->idsOf('/sales-returns?creditable=true');
        self::assertNotContains($paidReturnId, $creditable);
        self::assertContains($unpaidReturnId, $creditable);

        $this->send('GET', '/sales-returns?credit_blocked_by_payment=true');
        self::assertSame(self::CREDIT_BLOCKED_FILTER_REFUSAL, $this->httpStatus());       // measured and recorded at R3 (design :246-248)
        self::assertSame([['pointer' => '?credit_blocked_by_payment', 'code' => 'not_permitted']], array_map(
            static fn (array $e): array => ['pointer' => $e['pointer'], 'code' => $e['code']], $this->json()['errors']));
        $this->actAsRole(DefaultRoles::FINANCE);
        $blocked = $this->idsOf('/sales-returns?credit_blocked_by_payment=true');
        self::assertContains($paidReturnId, $blocked);
        self::assertNotContains($unpaidReturnId, $blocked, 'Control: an unpaid invoice\'s return is not blocked by payment.');
    }

    /**
     * **The register's other filters (design §4.2; R3):** `deducts` — `finance`'s reconciliation list, REQUIRED in R3
     * (`RED-4` L4-4) — `fulfilment_id`, `invoice_id` (the stamp) and `q`; each list row is `GetSalesReturn`'s document; an
     * unknown id is an empty page; and the page walks newest first by number with a working cursor.
     */
    #[Test]
    public function theRegisterFiltersAndPagesNewestFirst(): void
    {
        $s = $this->shipment('RQ-FILT', [['5', '10.0000', '4']]);
        $this->actAsRole(DefaultRoles::WAREHOUSE_OPERATOR);
        $this->recordReturn($s['fulfilment'], 'other', [
            ['fulfilment_line_id' => $s['fulfilment_lines'][0], 'quantity' => '1', 'storage_location_id' => $s['bin']],
        ]);
        $deducting = $this->json()['id'];
        $i = $this->issuedOn($s);
        $this->actAsRole(DefaultRoles::OWNER);
        $this->recordReturn($s['fulfilment'], 'other', [
            ['fulfilment_line_id' => $s['fulfilment_lines'][0], 'quantity' => '1', 'storage_location_id' => $s['bin']],
        ]);
        $stamped = $this->json()['id'];
        $this->send('GET', '/sales-returns/'.$stamped);
        $stampedDocument = $this->json();
        $other = $this->returnedOne($this->shipment('RQ-OTHER', [['5', '10.0000', '4']]));

        $this->actAsRole(DefaultRoles::FINANCE);
        // `$other` was recorded by an owner before any invoice, so it deducts too (Q-L (a′)).
        self::assertSame([$other, $deducting], $this->idsOf('/sales-returns?deducts=true'));
        self::assertSame([$stamped], $this->idsOf('/sales-returns?deducts=false'));
        self::assertSame([$stamped, $deducting], $this->idsOf('/sales-returns?fulfilment_id='.$s['fulfilment']));
        self::assertSame([$stamped], $this->idsOf('/sales-returns?invoice_id='.$i['invoice']));
        self::assertSame([], $this->idsOf('/sales-returns?fulfilment_id='.Uuid::v4()->toRfc4122()));
        self::assertSame([$other, $stamped, $deducting], $this->idsOf('/sales-returns'), 'Not newest first by number.');

        $this->send('GET', '/sales-returns?invoice_id='.$i['invoice']);
        self::assertSame([$stampedDocument], $this->json()['items'], 'A list row is not GetSalesReturn\'s document.');
        $this->send('GET', '/sales-returns?q='.substr($stampedDocument['sales_return_number'], 0, 10));
        self::assertContains($stamped, array_column($this->json()['items'], 'id'));

        $this->send('GET', '/sales-returns?limit=2');
        self::assertSame([$other, $stamped], array_column($this->json()['items'], 'id'));
        $this->send('GET', '/sales-returns?limit=2&after='.urlencode((string) $this->json()['next_cursor']));
        self::assertSame([$deducting], array_column($this->json()['items'], 'id'));
        self::assertNull($this->json()['next_cursor']);

        $this->send('GET', '/sales-returns?creditable=maybe');
        self::assertSame(422, $this->httpStatus());
    }

    /**
     * **OI-2 R3: a shipment cannot be called a mistake once it is invoiced** (`sales_return.recorded_in_error_before_invoice`).
     * R-14: the refusal says the shipment is invoiced; the NUMBER only to an `invoice.view` holder.
     *
     * @return iterable<string, array{string, bool}>
     */
    public static function errorRecorders(): iterable
    {
        yield 'an owner (holds invoice.view)' => [DefaultRoles::OWNER, true];
        yield 'a warehouse_operator (no invoice.view)' => [DefaultRoles::WAREHOUSE_OPERATOR, false];
    }

    #[Test]
    #[DataProvider('errorRecorders')]
    public function aShipmentAlreadyInvoicedCannotBeRecordedInError(string $role, bool $seesNumber): void
    {
        $i = $this->issuedOn($this->shipment('RET-E'.($seesNumber ? 'V' : 'N'), [['5', '10.0000', '4']]));
        $this->actAsRole($role);

        $this->recordReturn($i['shipment']['fulfilment'], 'recorded_in_error', [
            ['fulfilment_line_id' => $i['shipment']['fulfilment_lines'][0], 'quantity' => '4'],
        ]);

        self::assertSame(409, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        self::assertSame('sales_return.recorded_in_error_before_invoice', $this->json()['rule']);
        $raw = (string) $this->client()->getResponse()->getContent();
        $seesNumber
            ? self::assertStringContainsString($i['number'], $raw)
            : self::assertStringNotContainsString($i['number'], $raw, 'R-14: the number reached a caller without invoice.view.');
        self::assertSame(0, $this->countAsOwnerForTenant(self::TENANT_A, 'SELECT count(*) FROM sales_return'));
    }

    /**
     * **A shipment recorded in error goes back to the bin it left from (Q-M)**: the body's bin may be omitted or equal
     * to it; a different bin is refused; and if that bin has since been closed, it cannot be recorded (G-27).
     */
    #[Test]
    public function aShipmentRecordedInErrorGoesBackToItsOwnBin(): void
    {
        $s = $this->shipment('RET-ERR', [['5', '10.0000', '4']]);
        $other = $this->seedBinIn($s['warehouse'], 'B-02');
        $this->actAsRole(DefaultRoles::OWNER);

        $this->recordReturn($s['fulfilment'], 'recorded_in_error', [
            ['fulfilment_line_id' => $s['fulfilment_lines'][0], 'quantity' => '1', 'storage_location_id' => $other],
        ]);
        self::assertSame(409, $this->httpStatus());
        self::assertSame('sales_return.error_returns_to_its_bin', $this->json()['rule']);

        $this->executeAsOwnerForTenant(self::TENANT_A, 'UPDATE storage_location SET deleted_at = now() WHERE id = ?::uuid', [$s['bin']]);
        $this->recordReturn($s['fulfilment'], 'recorded_in_error', [['fulfilment_line_id' => $s['fulfilment_lines'][0], 'quantity' => '1']]);
        self::assertSame(409, $this->httpStatus());
        self::assertSame('sales_return.origin_bin_is_live', $this->json()['rule']);
        $this->executeAsOwnerForTenant(self::TENANT_A, 'UPDATE storage_location SET deleted_at = NULL WHERE id = ?::uuid', [$s['bin']]);

        $before = $this->onHand($s['bin']);
        $this->recordReturn($s['fulfilment'], 'recorded_in_error', [['fulfilment_line_id' => $s['fulfilment_lines'][0], 'quantity' => '4']]);
        self::assertSame(201, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        self::assertSame(['id' => $s['bin'], 'code' => 'A-01'], $this->json()['lines'][0]['storage_location']);
        self::assertSame(bcadd($before, '4', 6), $this->onHand($s['bin']));
        self::assertSame(0, $this->countAsOwnerForTenant(self::TENANT_A, "SELECT count(*) FROM stock_movement WHERE reason = 'return' AND storage_location_id <> ?::uuid", [$s['bin']]));
    }

    /** **The per-line bound (V2-S-9's second half): what comes back never exceeds what shipped**, summed over returns. */
    #[Test]
    public function moreCannotComeBackThanShippedAcrossReturns(): void
    {
        $s = $this->shipment('RET-QTY', [['5', '10.0000', '4']]);
        $this->actAsRole(DefaultRoles::OWNER);
        $line = ['fulfilment_line_id' => $s['fulfilment_lines'][0], 'storage_location_id' => $s['bin']];

        $this->recordReturn($s['fulfilment'], 'other', [$line + ['quantity' => '3']]);
        self::assertSame(201, $this->httpStatus());
        $this->recordReturn($s['fulfilment'], 'other', [$line + ['quantity' => '1.000001']]);
        self::assertSame(409, $this->httpStatus());
        self::assertSame('sales_return.quantity_within_shipped', $this->json()['rule']);
        self::assertSame('Order line 1 shipped 4 on this shipment and 3 has already come back, so 1.000001 more cannot be returned against it.', $this->json()['detail']);
        $this->recordReturn($s['fulfilment'], 'other', [$line + ['quantity' => '1']]);
        self::assertSame(201, $this->httpStatus(), 'The bound is inclusive: the last unit may come back.');
    }

    /** **V2-S-9's first half: a line of ANOTHER shipment is an unknown reference**, as is a bin of another warehouse. */
    #[Test]
    public function aLineOfAnotherShipmentOrABinOfAnotherWarehouseIsRefusedAtTheLine(): void
    {
        $s = $this->shipment('RET-V1', [['5', '10.0000', '4']]);
        $t = $this->shipment('RET-V2', [['5', '10.0000', '4']]);
        $this->actAsRole(DefaultRoles::OWNER);

        $this->recordReturn($s['fulfilment'], 'other', [
            ['fulfilment_line_id' => $t['fulfilment_lines'][0], 'quantity' => '1', 'storage_location_id' => $s['bin']],
        ]);
        self::assertSame(422, $this->httpStatus());
        self::assertSame([['pointer' => '/lines/0/fulfilment_line_id', 'code' => 'unknown_reference']], array_map(
            static fn (array $e): array => ['pointer' => $e['pointer'], 'code' => $e['code']], $this->json()['errors']));

        $this->recordReturn($s['fulfilment'], 'other', [
            ['fulfilment_line_id' => $s['fulfilment_lines'][0], 'quantity' => '1', 'storage_location_id' => $t['bin']],
        ]);
        self::assertSame(422, $this->httpStatus());
        self::assertSame([['pointer' => '/lines/0/storage_location_id', 'code' => 'storage_location.belongs_to_returning_warehouse']], array_map(
            static fn (array $e): array => ['pointer' => $e['pointer'], 'code' => $e['code']], $this->json()['errors']));
        self::assertSame(0, $this->countAsOwnerForTenant(self::TENANT_A, 'SELECT count(*) FROM sales_return'));
    }

    /** Goods cannot come back before they left. */
    #[Test]
    public function aReturnDatedBeforeTheShipmentIsRefused(): void
    {
        $s = $this->shipment('RET-DATE', [['5', '10.0000', '4']]);
        $this->actAsRole(DefaultRoles::OWNER);

        $this->recordReturn($s['fulfilment'], 'other', [
            ['fulfilment_line_id' => $s['fulfilment_lines'][0], 'quantity' => '1', 'storage_location_id' => $s['bin']],
        ], returnedAt: '2000-01-01T00:00:00Z');

        self::assertSame(409, $this->httpStatus());
        self::assertSame('sales_return.returned_after_shipment', $this->json()['rule']);
    }

    /**
     * **The retry is answered with the first return, and nothing applies twice** (design §13 item 4: `CreateFulfilment`'s
     * 201 with the same body); the same key with a different body is a conflict.
     */
    #[Test]
    public function aRetryIsAReplayAndADifferentBodyIsAConflict(): void
    {
        $s = $this->shipment('RET-IDEM', [['5', '10.0000', '4']]);
        $this->actAsRole(DefaultRoles::OWNER);
        $key = Uuid::v4()->toRfc4122();
        $lines = [['fulfilment_line_id' => $s['fulfilment_lines'][0], 'quantity' => '1', 'storage_location_id' => $s['bin']]];

        $this->recordReturn($s['fulfilment'], 'other', $lines, $key);
        self::assertSame(201, $this->httpStatus());
        $first = $this->json();
        $this->recordReturn($s['fulfilment'], 'other', $lines, $key);
        self::assertSame(201, $this->httpStatus());
        self::assertSame($first, $this->json());
        self::assertSame(1, $this->countAsOwnerForTenant(self::TENANT_A, 'SELECT count(*) FROM sales_return'));
        self::assertSame(1, $this->countAsOwnerForTenant(self::TENANT_A, "SELECT count(*) FROM stock_movement WHERE reason = 'return'"));
        self::assertCount(1, $this->auditRowsOf(AuditEvent::SALES_RETURN_CREATED), 'The replay wrote a second trail row.');

        $this->recordReturn($s['fulfilment'], 'damaged_in_transit', $lines, $key);
        self::assertSame(409, $this->httpStatus());
        self::assertSame(1, $this->countAsOwnerForTenant(self::TENANT_A, 'SELECT count(*) FROM sales_return'));
    }

    /**
     * **Q1 as refined by Q-L (a′) — an invoice bills what shipped, less ONLY the returns that deduct** (the
     * returned-deduction carrier, `OwedAmendmentTripwireTest`). Two lines shipped (4 × 10, 2 × 5 = 50). A second warehouse
     * person returns 1 of line 1 (deducts); the shipper returns 1 of line 2 (does not). The invoice bills
     * `50 − 10 = 40.0000`; the shipper's return is then creditable against it, the deducting one owes no credit.
     */
    #[Test]
    public function anInvoiceBillsWhatShippedLessOnlyTheReturnsThatDeduct(): void
    {
        $s = $this->shipment('RET-DED', [['5', '10.0000', '4'], ['3', '5.0000', '2']]);
        $this->actAsRole(DefaultRoles::WAREHOUSE_OPERATOR);
        $this->recordReturn($s['fulfilment'], 'other', [
            ['fulfilment_line_id' => $s['fulfilment_lines'][0], 'quantity' => '1', 'storage_location_id' => $s['bin']],
        ]);
        self::assertSame(201, $this->httpStatus());
        $deducting = $this->json()['id'];
        self::assertTrue($this->json()['deducts']);
        $this->actAsUser(self::TENANT_A, $s['shipper']);
        $this->recordReturn($s['fulfilment'], 'other', [
            ['fulfilment_line_id' => $s['fulfilment_lines'][1], 'quantity' => '1', 'storage_location_id' => $s['bin']],
        ]);
        self::assertSame(201, $this->httpStatus());
        $credited = $this->json()['id'];
        self::assertFalse($this->json()['deducts']);

        $i = $this->issuedOn($s);
        $this->actAsRole(DefaultRoles::OWNER);
        $this->send('GET', '/invoices/'.$i['invoice']);
        self::assertSame('40.0000', $this->json()['total_amount'], 'The invoice did not bill shipped less the DEDUCTING return only.');

        $this->send('GET', '/sales-returns/'.$credited);
        self::assertSame(['id' => $i['invoice'], 'invoice_number' => $i['number']], $this->json()['credit_target']);
        self::assertNull($this->json()['invoice'], 'Recorded before the invoice existed: no stamp.');
        $this->send('GET', '/sales-returns/'.$deducting);
        self::assertNull($this->json()['credit_target'], 'A return the invoice already deducted must not also be creditable.');
    }

    /**
     * **Q-G (1): a shipment wholly returned before billing is not invoiced** (`invoice.fulfilment_has_something_to_bill`,
     * keyed on QUANTITIES) — and **Q-G (2): a zero-priced shipment still issues a zero invoice**, as before step 7.
     */
    #[Test]
    public function aShipmentWhollyReturnedBeforeBillingIsNotInvoicedAndAFreeOneStillIs(): void
    {
        $s = $this->shipment('RET-ALL', [['5', '10.0000', '2']]);
        // Seeded first: a refused request closes the shared entity manager, and the fixtures seed through it.
        $free = $this->shipment('RET-FREE', [['5', '0.0000', '2']]);
        $this->actAsRole(DefaultRoles::WAREHOUSE_OPERATOR);
        $this->recordReturn($s['fulfilment'], 'other', [
            ['fulfilment_line_id' => $s['fulfilment_lines'][0], 'quantity' => '2', 'storage_location_id' => $s['bin']],
        ]);
        self::assertSame(201, $this->httpStatus());
        self::assertTrue($this->json()['deducts']);

        $this->actAsRole(DefaultRoles::SALES_REP);
        $this->issue($s['fulfilment']);
        self::assertSame(409, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        self::assertSame('invoice.fulfilment_has_something_to_bill', $this->json()['rule']);
        self::assertSame(0, $this->countAsOwnerForTenant(self::TENANT_A, 'SELECT count(*) FROM invoice'));

        $this->actAsRole(DefaultRoles::SALES_REP);
        $this->issue($free['fulfilment']);
        self::assertSame(201, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        self::assertSame('0.0000', $this->json()['total_amount']);
    }

    /** @return iterable<string, array{string}> */
    public static function rolesThatMayNotRecordReturns(): iterable
    {
        yield 'sales_rep' => [DefaultRoles::SALES_REP];
        yield 'finance (SoD-5\'s other half)' => [DefaultRoles::FINANCE];
        yield 'buyer' => [DefaultRoles::BUYER];
        yield 'viewer' => [DefaultRoles::VIEWER];
    }

    /** Q-F: `sales_return.create` is held by `warehouse_operator`, `admin` and `owner` only. */
    #[Test]
    #[DataProvider('rolesThatMayNotRecordReturns')]
    public function aRoleWithoutSalesReturnCreateIsRefused(string $role): void
    {
        $s = $this->shipment('RET-R'.strtoupper(substr(md5($role), 0, 4)), [['5', '10.0000', '4']]);
        $this->actAsRole($role);

        $this->recordReturn($s['fulfilment'], 'other', [
            ['fulfilment_line_id' => $s['fulfilment_lines'][0], 'quantity' => '1', 'storage_location_id' => $s['bin']],
        ]);

        self::assertSame(403, $this->httpStatus());
        self::assertSame('sales_return.create', $this->json()['required_permission']);
    }

    /** Q-B: `buyer` and `purchasing_manager` hold no `sales_return.view`. */
    #[Test]
    public function theTwoPurchasingRolesCannotReadAReturn(): void
    {
        $s = $this->shipment('RET-VIEW', [['5', '10.0000', '4']]);
        $id = $this->returnedOne($s);
        foreach ([DefaultRoles::BUYER, DefaultRoles::PURCHASING_MANAGER] as $role) {
            $this->actAsRole($role);
            $this->send('GET', '/sales-returns/'.$id);
            self::assertSame(403, $this->httpStatus(), $role);
        }
        foreach ([DefaultRoles::SALES_REP, DefaultRoles::FINANCE, DefaultRoles::VIEWER, DefaultRoles::WAREHOUSE_OPERATOR] as $role) {
            $this->actAsRole($role);
            $this->send('GET', '/sales-returns/'.$id);
            self::assertSame(200, $this->httpStatus(), $role);
        }
    }

    #[Test]
    public function aReturnOfAnotherWorkspaceIsNotFound(): void
    {
        $id = $this->returnedOne($this->shipment('RET-WS', [['5', '10.0000', '4']]));

        $this->actAs(self::TENANT_B);
        $this->send('GET', '/sales-returns/'.$id);
        self::assertSame(404, $this->httpStatus());
        $this->send('GET', '/sales-returns/'.Uuid::v4()->toRfc4122());
        self::assertSame(404, $this->httpStatus());
    }

    // -----------------------------------------------------------------------

    /**
     * `InvoiceFixtures::shipped()`, by a principal of `$shipperRole` (the shipper matters to Q-L (a′)), plus what a
     * return names: the shipped line ids, the bin, the warehouse, the items' display.
     *
     * @param list<array{0: string, 1: string, 2: string}> $lines
     *
     * @return array{order: string, customer: string, lines: list<string>, fulfilment: string, fulfilment_number: string, fulfilment_lines: list<string>, bin: string, warehouse: string, items: list<array{id: string, sku: string, name: string}>, shipper: string}
     */
    private function shipment(string $tag, array $lines, string $shipperRole = DefaultRoles::WAREHOUSE_OPERATOR): array
    {
        $s = $this->shippedBy($tag, $lines, $shipperRole);
        $this->send('GET', '/fulfilments/'.$s['fulfilment']);
        self::assertSame(200, $this->httpStatus(), 'Fixture: the shipment is not readable.');
        $body = $this->json();

        return $s + [
            'fulfilment_lines' => array_column($body['lines'], 'id'),
            'bin' => $body['lines'][0]['storage_location']['id'],
            'warehouse' => (string) $this->fetchAsOwnerForTenant(self::TENANT_A, 'SELECT warehouse_id::text AS w FROM fulfilment WHERE id = ?::uuid', [$s['fulfilment']])[0]['w'],
            'items' => array_column($body['lines'], 'inventory_item'),
        ];
    }

    /**
     * @param list<array{0: string, 1: string, 2: string}> $lines
     *
     * @return array{order: string, customer: string, lines: list<string>, fulfilment: string, fulfilment_number: string, shipper: string}
     */
    private function shippedBy(string $tag, array $lines, string $shipperRole): array
    {
        $s = $this->shipped($tag, $lines);
        // `shipped()` ships as a principal holding everything; re-attribute the shipment to a principal of the role
        // under test, as the owner (the column is the only thing Q-L (a′) reads).
        $shipper = $this->actAsRole($shipperRole);
        $this->executeAsOwnerForTenant(self::TENANT_A, 'UPDATE fulfilment SET shipped_by_user_id = ?::uuid WHERE id = ?::uuid', [$shipper, $s['fulfilment']]);

        return $s + ['shipper' => $shipper];
    }

    /** @return array{invoice: string, number: string, shipment: array<string, mixed>} */
    private function issuedOn(array $shipment): array
    {
        $this->actAsRole(DefaultRoles::SALES_REP);
        $this->issue($shipment['fulfilment']);
        self::assertSame(201, $this->httpStatus(), 'Fixture: the invoice. '.(string) $this->client()->getResponse()->getContent());

        return ['invoice' => $this->json()['id'], 'number' => $this->json()['invoice_number'], 'shipment' => $shipment];
    }

    /** One `other` unit of the shipment's first line, by an owner; returns its id. */
    private function returnedOne(array $shipment): string
    {
        $this->actAsRole(DefaultRoles::OWNER);
        $this->recordReturn($shipment['fulfilment'], 'other', [
            ['fulfilment_line_id' => $shipment['fulfilment_lines'][0], 'quantity' => '1', 'storage_location_id' => $shipment['bin']],
        ]);
        self::assertSame(201, $this->httpStatus(), 'Fixture: the return. '.(string) $this->client()->getResponse()->getContent());

        return $this->json()['id'];
    }

    /** @return list<string> the ids of a list page, in order */
    private function idsOf(string $path): array
    {
        $this->send('GET', $path);
        self::assertSame(200, $this->httpStatus(), $path.': '.(string) $this->client()->getResponse()->getContent());

        return array_column($this->json()['items'], 'id');
    }

    /** @param list<array<string, string>> $lines */
    private function recordReturn(string $fulfilmentId, string $reason, array $lines, ?string $key = null, ?string $returnedAt = null): void
    {
        $body = ['reason' => $reason, 'lines' => $lines];
        if (null !== $returnedAt) {
            $body['returned_at'] = $returnedAt;
        }
        $this->send('POST', '/fulfilments/'.$fulfilmentId.'/sales-returns', $body, ['Idempotency-Key' => $key ?? Uuid::v4()->toRfc4122()]);
    }

    private function seedBinIn(string $warehouseId, string $code, string $kind = 'bin'): string
    {
        $id = Uuid::v4()->toRfc4122();
        $this->insertAsOwnerForTenant(self::TENANT_A,
            'INSERT INTO storage_location (id, tenant_id, warehouse_id, code, kind) VALUES (?::uuid, ?::uuid, ?::uuid, ?, ?)',
            [$id, self::TENANT_A, $warehouseId, $code, $kind]);

        return $id;
    }

    private function onHand(string $binId): string
    {
        $rows = $this->fetchAsOwnerForTenant(self::TENANT_A,
            'SELECT COALESCE(SUM(qty_on_hand), 0)::numeric(19,6)::text AS q FROM stock_level WHERE storage_location_id = ?::uuid', [$binId]);

        return (string) $rows[0]['q'];
    }
}
