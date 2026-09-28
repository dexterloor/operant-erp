<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Modules\UserManagement\Domain\DefaultRoles;
use App\Modules\UserManagement\Domain\OwnerMarker;
use App\Modules\UserManagement\Domain\PermissionCatalogue;
use App\SharedKernel\ValueObject\AuditEvent;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Uid\Uuid;

/**
 * **`ReverseInvoicePayment` — sales step 6, C6** (design §19 C6; OI-2; ADR-008 A2.3/A2.3a; `SEC` Q8 — the one owner
 * predicate — and §15, B′).
 *
 * Every invoice is issued and paid THROUGH THE API; the reverse ∥ reverse race is in `PurchaseOrderRowLockTest`. The
 * owner exemption is decided by `OwnerPredicate` over the request's permission set, so the owner shapes here are the
 * same five `PurchaseOrderOwnerExemptionTest` runs for DSoD-1, against `invoice.pay` (`SEC` §3.4, last paragraph).
 */
final class InvoicePaymentReversalTest extends PurchaseOrderTestCase
{
    use InvoiceFixtures;

    /**
     * The ordinary path, two people: P records, Q (another `finance` user) reverses. 200 with the invoice `issued` again,
     * `paid_at` and `paid_by` null; stored `payment_reversed_at` set (never on the wire); the `invoice.payment_reversed`
     * row cell by cell with `metadata` exactly `{"reason"}`; and the History holds both rows — the reversal clears the
     * field, not the trail (A2.3a).
     */
    #[Test]
    public function aColleagueReversesAPaymentRecordedInError(): void
    {
        [$i, $payer] = $this->paidInvoice('REV-A');
        $reverser = $this->actAsRole(DefaultRoles::FINANCE);

        $this->reverse($i['invoice'], 'Posted against the wrong customer.');

        self::assertSame(200, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        $body = $this->json();
        self::assertSame('issued', $body['status']);
        self::assertNull($body['paid_at']);
        self::assertNull($body['paid_by']);
        self::assertArrayNotHasKey('payment_reversed_at', $body, 'B′\'s column is not on the wire (SEC §17.2).');

        self::assertSame([['status' => 'issued', 'paid_at' => null, 'paid_by' => null, 'reversed' => true]],
            $this->fetchAsOwnerForTenant(self::TENANT_A,
                'SELECT status, paid_at::text AS paid_at, paid_by_user_id::text AS paid_by, payment_reversed_at IS NOT NULL AS reversed FROM invoice WHERE id = ?::uuid',
                [$i['invoice']]));

        self::assertSame([[
            'event_type' => AuditEvent::INVOICE_PAYMENT_REVERSED,
            'actor_user_id' => $reverser,
            'actor_label' => 'Fixture Actor',
            'subject_type' => 'invoice',
            'subject_id' => $i['invoice'],
            'ip_address' => null,
            'permission_code' => null,
            'metadata' => '{"reason": "Posted against the wrong customer."}',
        ]], $this->fetchAsOwnerForTenant(self::TENANT_A, <<<'SQL'
            SELECT event_type, actor_user_id::text AS actor_user_id, actor_label, subject_type,
                   subject_id::text AS subject_id, ip_address::text AS ip_address, permission_code, metadata::text AS metadata
              FROM audit_log WHERE event_type = ?
            SQL, [AuditEvent::INVOICE_PAYMENT_REVERSED]));

        self::assertSame([
            ['e' => AuditEvent::INVOICE_PAID, 'a' => $payer],
            ['e' => AuditEvent::INVOICE_PAYMENT_REVERSED, 'a' => $reverser],
        ], $this->fetchAsOwnerForTenant(self::TENANT_A,
            "SELECT event_type AS e, actor_user_id::text AS a FROM audit_log WHERE subject_id = ?::uuid AND event_type LIKE 'invoice.pa%' ORDER BY occurred_at, event_type",
            [$i['invoice']]), 'The History does not hold both the payment and its reversal.');

        $this->send('GET', '/invoices/'.$i['invoice']);
        self::assertSame($body, $this->json());
    }

    /** A2.3: the recorder may not reverse their own payment. Refused in the recorder's words (ST-4e); nothing changes. */
    #[Test]
    public function theRecorderCannotReverseTheirOwnPayment(): void
    {
        [$i] = $this->paidInvoice('REV-B');

        $this->reverse($i['invoice'], 'Mine, in error.');

        self::assertSame(409, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        self::assertSame('urn:erp:problem:business-rule-violated', $this->json()['type']);
        self::assertSame('invoice.reverser_is_not_the_recorder', $this->json()['rule']);
        self::assertSame('You recorded this payment. It needs a different person to reverse it.', $this->json()['message']);
        $this->assertStillPaid($i['invoice']);
    }

    /**
     * **The owner shapes (`SEC` §3.4, against `invoice.pay`)** — the recorder reverses their own payment holding, besides
     * `finance`, a role that is: NAMED `owner` without the marker → refused; a co-owner (the marker, under another name)
     * → exempt; every catalogue code, derived → exempt. A name is never the answer. A `#[DataProvider]`, never a loop:
     * a refused request closes the shared entity manager.
     *
     * @return iterable<string, array{string, list<string>, int}>
     */
    public static function ownerShapes(): iterable
    {
        yield 'a role named owner without the marker' => [DefaultRoles::OWNER, ['invoice.view', 'invoice.pay'], 409];
        yield 'a co-owner holding the marker under another name' => ['co-owner', [OwnerMarker::CODE], 200];
        yield 'every catalogue code, derived' => ['everything', PermissionCatalogue::codes(), 200];
    }

    /** @param list<string> $codes */
    #[Test]
    #[DataProvider('ownerShapes')]
    public function onlyAnOwnerMarkerHolderMayReverseTheirOwnPayment(string $roleName, array $codes, int $expected): void
    {
        [$i] = $this->paidInvoice('REV-C');
        $this->grantRoleWithCodes(self::TENANT_A, (string) $this->actorId, $roleName, $codes);

        $this->reverse($i['invoice'], 'Own payment.');

        self::assertSame($expected, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        if (409 === $expected) {
            self::assertSame('invoice.reverser_is_not_the_recorder', $this->json()['rule']);
            $this->assertStillPaid($i['invoice']);
        }
    }

    /** The owner who recorded the payment reverses it: a one-person workspace records and corrects its own payments. */
    #[Test]
    public function theOwnerMayReverseTheirOwnPayment(): void
    {
        $i = $this->issuedInvoice('REV-D');
        $this->actAs(self::TENANT_A, DefaultRoles::codesFor(DefaultRoles::OWNER));
        $this->pay($i['invoice']);
        self::assertSame(200, $this->httpStatus());

        $this->reverse($i['invoice'], 'Owner corrects own entry.');

        self::assertSame(200, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        self::assertSame('issued', $this->json()['status']);
    }

    /**
     * **B′'s column is set ONCE (`COALESCE`)**: pay (P), reverse (Q), pay again (P), reverse again (R) — the second
     * reversal must not move `payment_reversed_at`. The column means "first reversed at"; a later value would hide the
     * first reversal from C7's owner-only void.
     */
    #[Test]
    public function aSecondReversalKeepsTheFirstReversalTime(): void
    {
        [$i, $payer] = $this->paidInvoice('REV-E');
        $this->actAsRole(DefaultRoles::FINANCE);
        $this->reverse($i['invoice'], 'First.');
        self::assertSame(200, $this->httpStatus());
        $first = $this->reversedAt($i['invoice']);
        self::assertNotNull($first);

        $this->actAsRole(DefaultRoles::FINANCE);
        $this->pay($i['invoice']);
        self::assertSame(200, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        $this->actAsRole(DefaultRoles::FINANCE);
        $this->reverse($i['invoice'], 'Second.');
        self::assertSame(200, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());

        self::assertSame($first, $this->reversedAt($i['invoice']), 'A second reversal moved payment_reversed_at (B′ needs "first reversed at").');
        self::assertSame(2, $this->countAsOwnerForTenant(self::TENANT_A,
            'SELECT count(*) FROM audit_log WHERE event_type = ? AND subject_id = ?::uuid', [AuditEvent::INVOICE_PAYMENT_REVERSED, $i['invoice']]));
    }

    /**
     * **RED-3 F-1: issue, pay and reverse inside one second**, then read back as the owner that `payment_reversed_at >=
     * issued_at`. The value comes from the database's `now()`; from a PHP clock behind the database's (the Colima
     * `+2.03 s` trap, mirrored) it would land before `issued_at` and `invoice_payment_reversed_not_before_issue` would
     * answer 23514 as a 500.
     */
    #[Test]
    public function issueAndPayAndReverseInsideOneSecondIsAccepted(): void
    {
        [$i] = $this->paidInvoice('REV-F');
        $this->actAsRole(DefaultRoles::FINANCE);

        $this->reverse($i['invoice'], 'Immediately.');

        self::assertSame(200, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        self::assertSame([['ok' => true]], $this->fetchAsOwnerForTenant(self::TENANT_A,
            'SELECT payment_reversed_at >= issued_at AS ok FROM invoice WHERE id = ?::uuid', [$i['invoice']]));
    }

    /** The status guard speaks first: an unpaid invoice is refused as not paid, even with no reason given. */
    #[Test]
    public function anUnpaidInvoiceHasNoPaymentToReverse(): void
    {
        $i = $this->issuedInvoice('REV-G');
        $this->actAsRole(DefaultRoles::FINANCE);

        $this->send('POST', '/invoices/'.$i['invoice'].'/payment-reversal', []);

        self::assertSame(409, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        self::assertSame('invoice.status_transition_is_legal', $this->json()['rule']);
        self::assertSame([['r' => false]], $this->fetchAsOwnerForTenant(self::TENANT_A,
            'SELECT payment_reversed_at IS NOT NULL AS r FROM invoice WHERE id = ?::uuid', [$i['invoice']]));
    }

    /** `reason` is required (OI-2): absent or blank on a paid invoice → 422 `/reason` `required`; nothing changes. */
    #[Test]
    public function aReversalNeedsAReason(): void
    {
        [$i] = $this->paidInvoice('REV-H');
        $this->actAsRole(DefaultRoles::FINANCE);

        foreach ([[], ['reason' => '   '], ['reason' => null]] as $body) {
            $this->send('POST', '/invoices/'.$i['invoice'].'/payment-reversal', $body);
            self::assertSame(422, $this->httpStatus(), json_encode($body).' '.(string) $this->client()->getResponse()->getContent());
            self::assertSame('/reason', $this->json()['errors'][0]['pointer']);
            self::assertSame('required', $this->json()['errors'][0]['code']);
        }
        $this->assertStillPaid($i['invoice']);
    }

    /** E-1: without `invoice.pay` every id is the same 403 — `sales_rep` issues invoices and cannot touch payments. */
    #[Test]
    public function withoutThePayCodeEveryInvoiceIsTheSameRefusal(): void
    {
        [$i] = $this->paidInvoice('REV-I');
        $this->actAsRole(DefaultRoles::SALES_REP);

        $this->reverse($i['invoice'], 'x');
        self::assertSame(403, $this->httpStatus());
        $known = (string) $this->client()->getResponse()->getContent();
        $this->reverse(Uuid::v4()->toRfc4122(), 'x');
        self::assertSame(403, $this->httpStatus());
        self::assertSame($known, (string) $this->client()->getResponse()->getContent());
        $this->assertStillPaid($i['invoice']);
    }

    /**
     * **M-11, mechanised:** the reversal issues exactly two `UPDATE invoice` statements — the ORM's, setting exactly
     * `paid_at`, `paid_by_user_id`, `status` and `updated_at`, and B′'s, setting exactly `payment_reversed_at` — the five
     * columns `erp_app` is granted UPDATE on and no others.
     */
    #[Test]
    public function theReversalSetsExactlyTheGrantedColumns(): void
    {
        [$i] = $this->paidInvoice('REV-SET');
        $this->actAsRole(DefaultRoles::FINANCE);

        $this->recorder()->start();
        $this->reverse($i['invoice'], 'Set list.');
        $this->recorder()->stop();
        self::assertSame(200, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());

        $sets = [];
        foreach ($this->recorder()->matching('/^\s*UPDATE\s+invoice\b/i') as $update) {
            preg_match_all('/(\w+)\s*=\s*(?:\?|COALESCE)/', (string) preg_replace('/\bWHERE\b.*$/is', '', $update), $m);
            $set = $m[1];
            sort($set);
            $sets[] = $set;
        }
        self::assertSame([['paid_at', 'paid_by_user_id', 'status', 'updated_at'], ['payment_reversed_at']], $sets);
    }

    /**
     * An issued invoice paid through the API by one `finance` user, who is left as the actor.
     *
     * @return array{0: array{invoice: string, number: string, issuer: string, shipment: array<string, mixed>}, 1: string}
     */
    private function paidInvoice(string $tag): array
    {
        $i = $this->issuedInvoice($tag);
        $payer = $this->actAsRole(DefaultRoles::FINANCE);
        $this->pay($i['invoice']);
        self::assertSame(200, $this->httpStatus(), 'Fixture: the payment was refused. '.(string) $this->client()->getResponse()->getContent());

        return [$i, $payer];
    }

    private function pay(string $invoiceId): void
    {
        $this->send('POST', '/invoices/'.$invoiceId.'/payment', []);
    }

    private function reverse(string $invoiceId, string $reason): void
    {
        $this->send('POST', '/invoices/'.$invoiceId.'/payment-reversal', ['reason' => $reason]);
    }

    private function reversedAt(string $invoiceId): ?string
    {
        return $this->fetchAsOwnerForTenant(self::TENANT_A,
            'SELECT payment_reversed_at::text AS r FROM invoice WHERE id = ?::uuid', [$invoiceId])[0]['r'];
    }

    private function assertStillPaid(string $invoiceId): void
    {
        self::assertSame([['status' => 'paid', 'r' => false]], $this->fetchAsOwnerForTenant(self::TENANT_A,
            'SELECT status, payment_reversed_at IS NOT NULL AS r FROM invoice WHERE id = ?::uuid', [$invoiceId]),
            'A refused reversal changed the invoice.');
    }
}
