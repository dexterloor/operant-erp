<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Modules\UserManagement\Domain\DefaultRoles;
use App\SharedKernel\ValueObject\AuditEvent;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Uid\Uuid;

/**
 * **`PayInvoice` meets the returns — sales step 7 C1** (design §4.6; owner ruling Q-P, restricted (α); `SEC` final
 * position items 1, 2 and 4; `output9-sales-8-c0-amendments.md` A-1 row 7 and FINAL item 11). Three changes to a
 * shipped operation, approved with step 7: the order lock before the invoice lock (races: `PurchaseOrderRowLockTest`),
 * `invoice.has_no_uncredited_return` with its (α) exit, and `invoice.paid`'s new metadata key.
 */
final class InvoicePaymentReturnsTest extends PurchaseOrderTestCase
{
    use InvoiceFixtures;
    use CreditFixtures;
    use CreditNoteFixtures;

    /**
     * An invoice whose shipment has a creditable return cannot be paid until it is credited — or named. The refusal
     * lists the ids only to a caller who can read returns (`SEC` item 1): `finance` sees them; a composed `invoice.pay`
     * holder without `sales_return.view` is told only that such returns exist.
     */
    #[Test]
    public function anUncreditedReturnStopsThePaymentAndOnlyAReturnReaderIsToldWhich(): void
    {
        $i = $this->invoicedShipment('PR-A', [['4', '10.0000', '2']]);
        $this->actAsRole(DefaultRoles::OWNER);
        $return = $this->returnOf($i, [[0, '1']]);

        $this->actAsRole(DefaultRoles::FINANCE);
        $this->send('POST', '/invoices/'.$i['invoice'].'/payment', []);
        self::assertSame([409, 'invoice.has_no_uncredited_return'], [$this->httpStatus(), $this->json()['rule'] ?? null]);
        self::assertStringContainsString($return, (string) $this->json()['detail']);

        $this->actAs(self::TENANT_A, ['invoice.pay']);
        $this->send('POST', '/invoices/'.$i['invoice'].'/payment', []);
        self::assertSame([409, 'invoice.has_no_uncredited_return'], [$this->httpStatus(), $this->json()['rule'] ?? null]);
        self::assertStringNotContainsString($return, (string) $this->json()['detail'], 'A caller who cannot read returns was told which.');
        self::assertStringNotContainsString('RET-', (string) $this->json()['detail']);
        self::assertSame([['status' => 'issued']], $this->fetchAsOwnerForTenant(self::TENANT_A, 'SELECT status FROM invoice WHERE id = ?::uuid', [$i['invoice']]));
    }

    /**
     * **(α):** naming every uncredited creditable return pays past it, and `invoice.paid` records exactly the returns
     * paid past, sorted (`RED-5` L5-2) — an id that is no longer creditable (credited meanwhile) is ignored, and recorded
     * nowhere. Crediting the return first leaves nothing to name, and the metadata keeps exactly `{"paid_at"}`.
     */
    #[Test]
    public function namingTheReturnsPaysPastThemAndTheTrailSaysWhich(): void
    {
        $i = $this->invoicedShipment('PR-B', [['4', '10.0000', '2'], ['4', '5.0000', '2']]);
        $this->actAsRole(DefaultRoles::OWNER);
        $one = $this->returnOf($i, [[0, '1']]);
        $two = $this->returnOf($i, [[1, '1']]);
        $credited = $this->returnOf($i, [[1, '1']]);
        $this->actAsRole(DefaultRoles::FINANCE);
        $this->credit($i, $credited, [[1, '1']]);
        self::assertSame(201, $this->httpStatus(), 'Fixture: the credit. '.(string) $this->client()->getResponse()->getContent());

        $this->send('POST', '/invoices/'.$i['invoice'].'/payment', ['acknowledged_return_ids' => [$one]]);
        self::assertSame([409, 'invoice.has_no_uncredited_return'], [$this->httpStatus(), $this->json()['rule'] ?? null], 'Naming one of two paid past both.');

        $this->send('POST', '/invoices/'.$i['invoice'].'/payment', ['acknowledged_return_ids' => [$two, $credited, $one]]);
        self::assertSame(200, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());

        $expected = [$one, $two];
        sort($expected);
        self::assertSame(['paid_at' => $this->json()['paid_at'], 'acknowledged_return_ids' => $expected], json_decode((string) $this->fetchAsOwnerForTenant(self::TENANT_A,
            'SELECT metadata::text AS m FROM audit_log WHERE event_type = ?', [AuditEvent::INVOICE_PAID])[0]['m'], true));

        $plain = $this->invoicedShipment('PR-B2', [['4', '10.0000', '2']]);
        $this->actAsRole(DefaultRoles::OWNER);
        $r = $this->returnOf($plain, [[0, '1']]);
        $this->actAsRole(DefaultRoles::FINANCE);
        $this->credit($plain, $r, [[0, '1']]);
        $this->send('POST', '/invoices/'.$plain['invoice'].'/payment', []);
        self::assertSame(200, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        self::assertSame(['paid_at'], array_keys(json_decode((string) $this->fetchAsOwnerForTenant(self::TENANT_A,
            'SELECT metadata::text AS m FROM audit_log WHERE event_type = ? AND subject_id = ?::uuid', [AuditEvent::INVOICE_PAID, $plain['invoice']])[0]['m'], true)),
            'The key is not omitted when nothing was paid past.');
    }

    /** A `recorded_in_error` return can never be named: paying past it bills a shipment that did not happen. */
    #[Test]
    public function aShipmentRecordedInErrorCannotBePaidPast(): void
    {
        // Recorded in error BEFORE the invoice, by the shipper (so it does not deduct), then invoiced: creditable.
        $i = $this->invoicedShipment('PR-ERR', [['4', '10.0000', '2']], issue: false);
        $error = $this->returnOf($i, [[0, '2']], 'recorded_in_error');
        $i = $this->issueOn($i);
        $this->actAsRole(DefaultRoles::FINANCE);
        $this->send('GET', '/sales-returns/'.$error);
        self::assertSame($i['invoice'], $this->json()['credit_target']['id'] ?? null, 'Fixture: the error return is not creditable.');

        $this->send('POST', '/invoices/'.$i['invoice'].'/payment', ['acknowledged_return_ids' => [$error]]);
        self::assertSame([409, 'invoice.error_return_cannot_be_acknowledged'], [$this->httpStatus(), $this->json()['rule'] ?? null]);
    }

    /**
     * (α)'s 422: an id that is not a return of the invoice's shipment is refused on its pointer — but only AFTER the
     * status guard, so N-4 holds (below). A malformed id is a shape error, refused before anything is read.
     */
    #[Test]
    public function anIdThatIsNotAReturnOfThisShipmentIsRefusedOnItsPointer(): void
    {
        $i = $this->invoicedShipment('PR-UNK', [['4', '10.0000', '2']]);
        $other = $this->invoicedShipment('PR-UNK2', [['4', '10.0000', '2']]);
        $this->actAsRole(DefaultRoles::OWNER);
        $foreign = $this->returnOf($other, [[0, '1']]);

        $this->actAsRole(DefaultRoles::FINANCE);
        $this->send('POST', '/invoices/'.$i['invoice'].'/payment', ['acknowledged_return_ids' => [$foreign]]);
        self::assertSame(422, $this->httpStatus());
        self::assertSame(['/acknowledged_return_ids/0', 'unknown_reference'], [$this->json()['errors'][0]['pointer'], $this->json()['errors'][0]['code']]);
        $this->send('POST', '/invoices/'.$i['invoice'].'/payment', ['acknowledged_return_ids' => ['not-a-uuid']]);
        self::assertSame(['/acknowledged_return_ids/0', 'not_a_uuid'], [$this->json()['errors'][0]['pointer'], $this->json()['errors'][0]['code']]);
    }

    /**
     * **N-4 (FINAL item 11):** a retry of a COMPLETED payment is answered by the status refusal — even when a return was
     * recorded after the payment, and even when the retry carries an `acknowledged_return_ids` entry that is no return
     * of the shipment (so (α)'s 422 sits behind the status guard). Mutation (run): move the (α) block ahead of the status
     * guard → red.
     */
    #[Test]
    public function aRetryOfACompletedPaymentIsAnsweredByTheStatusGuard(): void
    {
        $i = $this->invoicedShipment('PR-N4', [['4', '10.0000', '2']]);
        $this->actAsRole(DefaultRoles::FINANCE);
        $this->send('POST', '/invoices/'.$i['invoice'].'/payment', []);
        self::assertSame(200, $this->httpStatus());
        $this->actAsRole(DefaultRoles::OWNER);
        $this->returnOf($i, [[0, '1']]);

        $this->actAsRole(DefaultRoles::FINANCE);
        $this->send('POST', '/invoices/'.$i['invoice'].'/payment', []);
        self::assertSame([409, 'invoice.status_transition_is_legal'], [$this->httpStatus(), $this->json()['rule'] ?? null]);
        $this->send('POST', '/invoices/'.$i['invoice'].'/payment', ['acknowledged_return_ids' => [Uuid::v4()->toRfc4122()]]);
        self::assertSame([409, 'invoice.status_transition_is_legal'], [$this->httpStatus(), $this->json()['rule'] ?? null],
            '(α)\'s 422 answered before the status guard.');
    }

    /**
     * Zero-priced and zero-telescope returns are not creditable, so they stop nothing: the free-goods `recorded_in_error`
     * case is closed (design §4.2) — an invoice of free goods with such a return is paid in the ordinary way.
     */
    #[Test]
    public function aReturnOfFreeGoodsDoesNotStopThePayment(): void
    {
        $i = $this->invoicedShipment('PR-FREE', [['4', '0', '2']], issue: false);
        $this->returnOf($i, [[0, '2']], 'recorded_in_error');
        $i = $this->issueOn($i);
        $this->actAsRole(DefaultRoles::FINANCE);
        $this->send('POST', '/invoices/'.$i['invoice'].'/payment', []);
        self::assertSame(200, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
    }
}
