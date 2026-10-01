<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Modules\UserManagement\Domain\DefaultRoles;
use App\Modules\UserManagement\Domain\OwnerMarker;
use App\SharedKernel\ValueObject\AuditEvent;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Uid\Uuid;

/**
 * **`VoidInvoice` — sales step 6, C7** (design §19 C7; OI-2; the owner's Q4 *"Owner only"* as a HOLDING, ADR-008
 * A2.1a / A2.7; ruling (d) B′, `SEC` §15 and §17.1).
 *
 * Every invoice is issued, paid and reversed THROUGH THE API. The races (pay ∥ void, reverse ∥ void, void in flight ∥
 * re-issue) are in `PurchaseOrderRowLockTest`, beside the other invoice races.
 *
 * **Who may void is decided by the permission check alone** (`SEC` §9.1): `owner` holds `invoice.void` among the
 * default roles, `admin` does not since A2.1a, and a composed role conferring `invoice.void` voids a NEVER-PAID invoice
 * with no owner involvement — that is the delegation Q4 chose. **B′ is the one named exception:** an invoice whose
 * payment was ever reversed is voided only by an owner-marker holder (`OwnerPredicate`), never decided by a role name.
 *
 * The delegates here are composed roles (`actAs`/`seedPrincipal` name them `Fixture Role …`), holding `invoice.void`
 * with `invoice.pay` where the sequence needs it — exactly the seat `SEC` §12 found the residual in.
 */
final class InvoiceVoidTest extends PurchaseOrderTestCase
{
    use InvoiceFixtures;

    /** A composed void delegate's codes: no owner marker. */
    private const DELEGATE = ['invoice.view', 'invoice.pay', 'invoice.void'];

    /**
     * The owner voids an issued invoice: 200 with the invoice `void`; stored `status` `void`; the `invoice.voided` row
     * cell by cell with `metadata` exactly `{"reason"}`; `GetInvoice` answers the same body.
     */
    #[Test]
    public function theOwnerVoidsAnInvoiceIssuedInError(): void
    {
        $i = $this->issuedInvoice('VOID-A');
        $voider = $this->actAsRole(DefaultRoles::OWNER);

        $this->void($i['invoice'], 'Issued against the wrong customer.');

        self::assertSame(200, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        $body = $this->json();
        self::assertSame('void', $body['status']);
        self::assertSame($i['invoice'], $body['id']);
        self::assertNull($body['paid_at']);
        self::assertNull($body['paid_by']);
        self::assertArrayNotHasKey('payment_reversed_at', $body, 'B′\'s column is not on the wire (SEC §17.2).');

        self::assertSame([['status' => 'void']], $this->fetchAsOwnerForTenant(self::TENANT_A,
            'SELECT status FROM invoice WHERE id = ?::uuid', [$i['invoice']]));

        self::assertSame([[
            'event_type' => AuditEvent::INVOICE_VOIDED,
            'actor_user_id' => $voider,
            'actor_label' => 'Fixture Actor',
            'subject_type' => 'invoice',
            'subject_id' => $i['invoice'],
            'ip_address' => null,
            'permission_code' => null,
            'metadata' => '{"reason": "Issued against the wrong customer."}',
        ]], $this->fetchAsOwnerForTenant(self::TENANT_A, <<<'SQL'
            SELECT event_type, actor_user_id::text AS actor_user_id, actor_label, subject_type,
                   subject_id::text AS subject_id, ip_address::text AS ip_address, permission_code, metadata::text AS metadata
              FROM audit_log WHERE event_type = ?
            SQL, [AuditEvent::INVOICE_VOIDED]));

        $this->send('GET', '/invoices/'.$i['invoice']);
        self::assertSame(array_keys($this->json()), array_keys($body), 'PC-17: the void\'s 200 is not GetInvoice\'s shape.');
        self::assertSame($body, $this->json());
    }

    /**
     * **Q4 is a holding, not a runtime check** (`SEC` §9.1): a composed role conferring `invoice.void` and NOT the owner
     * marker voids a never-paid invoice → 200. A general owner check in the operation would turn this red.
     */
    #[Test]
    public function aComposedVoidDelegateVoidsANeverPaidInvoice(): void
    {
        $i = $this->issuedInvoice('VOID-B');
        $this->actAs(self::TENANT_A, ['invoice.view', 'invoice.void']);

        $this->void($i['invoice'], 'Delegated void.');

        self::assertSame(200, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        self::assertSame('void', $this->json()['status']);
    }

    /**
     * E-1, and A2.1a's consequence at the gate: `admin` (54 → 53, `invoice.void` removed by PC-14's derivation),
     * `finance` and `sales_rep` hold no `invoice.void`, so each gets the same 403 for a real invoice and a random id.
     *
     * @return iterable<string, array{string}>
     */
    public static function rolesWithoutTheVoidCode(): iterable
    {
        yield 'admin' => [DefaultRoles::ADMIN];
        yield 'finance' => [DefaultRoles::FINANCE];
        yield 'sales_rep' => [DefaultRoles::SALES_REP];
    }

    #[Test]
    #[DataProvider('rolesWithoutTheVoidCode')]
    public function noDefaultRoleButTheOwnerMayVoid(string $role): void
    {
        $i = $this->issuedInvoice('VOID-C');
        self::assertNotContains('invoice.void', DefaultRoles::codesFor($role), 'Fixture: '.$role.' holds invoice.void.');
        $this->actAsRole($role);

        $this->void($i['invoice'], 'x');
        self::assertSame(403, $this->httpStatus());
        $known = (string) $this->client()->getResponse()->getContent();
        $this->void(Uuid::v4()->toRfc4122(), 'x');
        self::assertSame(403, $this->httpStatus());
        self::assertSame($known, (string) $this->client()->getResponse()->getContent());
        $this->assertStatus($i['invoice'], 'issued');
    }

    /** The status guard: a paid invoice is un-paid first (reverse the payment); a void invoice is already void. */
    #[Test]
    public function aPaidInvoiceCannotBeVoided(): void
    {
        $i = $this->issuedInvoice('VOID-D');
        $this->actAsRole(DefaultRoles::FINANCE);
        $this->pay($i['invoice']);
        self::assertSame(200, $this->httpStatus());
        $this->actAsRole(DefaultRoles::OWNER);

        $this->void($i['invoice'], 'Too late.');

        self::assertSame(409, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        self::assertSame('invoice.status_transition_is_legal', $this->json()['rule']);
        $this->assertStatus($i['invoice'], 'paid');
        $this->assertNoVoidRow($i['invoice']);
    }

    /** The status guard speaks first: an unvoidable invoice is refused as such even with no reason given. */
    #[Test]
    public function theStatusGuardSpeaksBeforeTheReason(): void
    {
        $i = $this->issuedInvoice('VOID-E');
        $this->actAsRole(DefaultRoles::OWNER);
        $this->void($i['invoice'], 'First.');
        self::assertSame(200, $this->httpStatus());

        $this->send('POST', '/invoices/'.$i['invoice'].'/void', []);

        self::assertSame(409, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        self::assertSame('invoice.status_transition_is_legal', $this->json()['rule']);
    }

    /** `reason` is required (OI-2 R2): absent, blank or null → 422 `/reason` `required`; nothing changes. */
    #[Test]
    public function aVoidNeedsAReason(): void
    {
        $i = $this->issuedInvoice('VOID-F');
        $this->actAsRole(DefaultRoles::OWNER);

        foreach ([[], ['reason' => '   '], ['reason' => null]] as $body) {
            $this->send('POST', '/invoices/'.$i['invoice'].'/void', $body);
            self::assertSame(422, $this->httpStatus(), json_encode($body).' '.(string) $this->client()->getResponse()->getContent());
            self::assertSame('/reason', $this->json()['errors'][0]['pointer']);
            self::assertSame('required', $this->json()['errors'][0]['code']);
        }
        $this->assertStatus($i['invoice'], 'issued');
        $this->assertNoVoidRow($i['invoice']);
    }

    /**
     * **Void, then re-issue the same shipment** → 201 under a DIFFERENT number: the void releases
     * `invoice_tenant_fulfilment_not_void`, and the voided invoice's number is never reissued (D-A as amended).
     */
    #[Test]
    public function aVoidedInvoicesShipmentCanBeInvoicedAgainUnderANewNumber(): void
    {
        $i = $this->issuedInvoice('VOID-G');
        $this->actAsRole(DefaultRoles::OWNER);
        $this->void($i['invoice'], 'Wrong price.');
        self::assertSame(200, $this->httpStatus());

        $this->actAsRole(DefaultRoles::SALES_REP);
        $this->issue($i['shipment']['fulfilment']);

        self::assertSame(201, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        self::assertNotSame($i['number'], $this->json()['invoice_number'], 'The voided invoice\'s number was reissued.');
        self::assertNotSame($i['invoice'], $this->json()['id']);
        self::assertSame([['status' => 'void'], ['status' => 'issued']], $this->fetchAsOwnerForTenant(self::TENANT_A,
            'SELECT status FROM invoice WHERE fulfilment_id = ?::uuid ORDER BY invoice_number', [$i['shipment']['fulfilment']]));
    }

    /**
     * **B′, the plain case:** P records the payment, delegate F (not the recorder) reverses it, then F voids → 409
     * `invoice.is_not_payment_reversed`, in `SEC` §17.1's words; the invoice unchanged and no `invoice.voided` row.
     */
    #[Test]
    public function aDelegateCannotVoidAnInvoiceWhosePaymentTheyReversed(): void
    {
        $i = $this->issuedInvoice('BP-A');
        $payer = $this->user(DefaultRoles::codesFor(DefaultRoles::FINANCE));
        $f = $this->user(self::DELEGATE);

        $this->as($payer, $i['invoice'], 'payment', []);
        $this->as($f, $i['invoice'], 'payment-reversal', ['reason' => 'Bounced.']);
        self::assertSame(200, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());

        $this->as($f, $i['invoice'], 'void', ['reason' => 'Write it off.']);

        $this->assertRefusedByBPrime($i['invoice']);
    }

    /**
     * **The laundering sequence — the test that distinguishes B′ from B** (`SEC` §15): F reverses P's payment, F records
     * a bogus payment, the OWNER reverses that one (so the most recent reverser is not F), then F voids → still 409. B
     * keyed on "the most recent reverser" would let it through; B′ keys on the invoice's history.
     */
    #[Test]
    public function theLaunderingSequenceIsStillRefused(): void
    {
        $i = $this->issuedInvoice('BP-B');
        $payer = $this->user(DefaultRoles::codesFor(DefaultRoles::FINANCE));
        $f = $this->user(self::DELEGATE);
        $owner = $this->user(DefaultRoles::codesFor(DefaultRoles::OWNER));

        $this->as($payer, $i['invoice'], 'payment', []);
        self::assertSame(200, $this->httpStatus());
        $this->as($f, $i['invoice'], 'payment-reversal', ['reason' => 'Bounced.']);
        self::assertSame(200, $this->httpStatus());
        $this->as($f, $i['invoice'], 'payment', []);
        self::assertSame(200, $this->httpStatus(), 'F records a bogus payment. '.(string) $this->client()->getResponse()->getContent());
        $this->as($owner, $i['invoice'], 'payment-reversal', ['reason' => 'That payment never arrived.']);
        self::assertSame(200, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());

        $this->as($f, $i['invoice'], 'void', ['reason' => 'Write it off.']);

        $this->assertRefusedByBPrime($i['invoice']);
    }

    /** **Two delegates:** G reverses P's payment, F voids → 409. Collusion between delegates is refused too (`SEC` §15). */
    #[Test]
    public function aSecondDelegateCannotVoidAReversedInvoice(): void
    {
        $i = $this->issuedInvoice('BP-C');
        $payer = $this->user(DefaultRoles::codesFor(DefaultRoles::FINANCE));
        $g = $this->user(self::DELEGATE);
        $f = $this->user(self::DELEGATE);

        $this->as($payer, $i['invoice'], 'payment', []);
        $this->as($g, $i['invoice'], 'payment-reversal', ['reason' => 'Bounced.']);
        self::assertSame(200, $this->httpStatus());

        $this->as($f, $i['invoice'], 'void', ['reason' => 'Write it off.']);

        $this->assertRefusedByBPrime($i['invoice']);
    }

    /**
     * **B′'s exemption is the owner MARKER, by the one predicate** — the voider of a reversed invoice holds, besides
     * `invoice.void`, a role that is: the seeded owner's whole set → 200; a co-owner (the marker, under another name) →
     * 200; a role NAMED `owner` without the marker → 409. A name is never the answer. A `#[DataProvider]`, never a loop:
     * a refused request closes the shared entity manager.
     *
     * @return iterable<string, array{string, list<string>, int}>
     */
    public static function voiderShapes(): iterable
    {
        yield 'the owner' => ['owner-seat', DefaultRoles::codesFor(DefaultRoles::OWNER), 200];
        yield 'a co-owner holding the marker under another name' => ['co-owner', ['invoice.view', 'invoice.void', OwnerMarker::CODE], 200];
        yield 'a role named owner without the marker' => [DefaultRoles::OWNER, ['invoice.view', 'invoice.void'], 409];
    }

    /** @param list<string> $codes */
    #[Test]
    #[DataProvider('voiderShapes')]
    public function onlyAnOwnerMarkerHolderMayVoidAReversedInvoice(string $roleName, array $codes, int $expected): void
    {
        $i = $this->issuedInvoice('BP-D');
        $payer = $this->user(DefaultRoles::codesFor(DefaultRoles::FINANCE));
        $reverser = $this->user(DefaultRoles::codesFor(DefaultRoles::FINANCE));
        $this->as($payer, $i['invoice'], 'payment', []);
        $this->as($reverser, $i['invoice'], 'payment-reversal', ['reason' => 'Bounced.']);
        self::assertSame(200, $this->httpStatus());

        $voider = $this->user([]);
        $this->grantRoleWithCodes(self::TENANT_A, $voider, $roleName, $codes);
        $this->as($voider, $i['invoice'], 'void', ['reason' => 'Withdrawn.']);

        if (409 === $expected) {
            $this->assertRefusedByBPrime($i['invoice']);

            return;
        }
        self::assertSame(200, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        self::assertSame('void', $this->json()['status']);
    }

    /**
     * **M-11, mechanised:** the void issues exactly one `UPDATE invoice`, setting exactly `status` and `updated_at` —
     * both column-granted since C5, so C7 needs no grant.
     */
    #[Test]
    public function theVoidSetsExactlyTheGrantedColumns(): void
    {
        $i = $this->issuedInvoice('VOID-SET');
        $this->actAsRole(DefaultRoles::OWNER);

        $this->recorder()->start();
        $this->void($i['invoice'], 'Set list.');
        $this->recorder()->stop();
        self::assertSame(200, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());

        $sets = [];
        foreach ($this->recorder()->matching('/^\s*UPDATE\s+invoice\b/i') as $update) {
            preg_match_all('/(\w+)\s*=\s*\?/', (string) preg_replace('/\bWHERE\b.*$/is', '', $update), $m);
            $set = $m[1];
            sort($set);
            $sets[] = $set;
        }
        self::assertSame([['status', 'updated_at']], $sets);
    }

    private function void(string $invoiceId, string $reason): void
    {
        $this->send('POST', '/invoices/'.$invoiceId.'/void', ['reason' => $reason]);
    }

    private function pay(string $invoiceId): void
    {
        $this->send('POST', '/invoices/'.$invoiceId.'/payment', []);
    }

    /**
     * A principal of tenant A holding `$codes` through one composed role, WITHOUT making it the rig's actor.
     *
     * @param list<string> $codes
     */
    private function user(array $codes): string
    {
        return $this->seedPrincipal(self::TENANT_A, $codes);
    }

    /**
     * POST `/invoices/{$invoiceId}/$action` as `$userId`, with that user's own token — the rig's actor is not switched.
     *
     * @param array<string, mixed> $body
     */
    private function as(string $userId, string $invoiceId, string $action, array $body): void
    {
        $this->send('POST', '/invoices/'.$invoiceId.'/'.$action, $body,
            ['Authorization' => 'Bearer '.$this->tokenFor(self::TENANT_A, $userId)]);
    }

    private function assertRefusedByBPrime(string $invoiceId): void
    {
        self::assertSame(409, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        self::assertSame('urn:erp:problem:business-rule-violated', $this->json()['type']);
        self::assertSame('invoice.is_not_payment_reversed', $this->json()['rule']);
        self::assertSame('A payment on this invoice was reversed, so only an owner can void it.', $this->json()['message']);
        $this->assertStatus($invoiceId, 'issued');
        $this->assertNoVoidRow($invoiceId);
    }

    private function assertStatus(string $invoiceId, string $status): void
    {
        self::assertSame([['status' => $status]], $this->fetchAsOwnerForTenant(self::TENANT_A,
            'SELECT status FROM invoice WHERE id = ?::uuid', [$invoiceId]), 'The invoice\'s stored status is not '.$status.'.');
    }

    private function assertNoVoidRow(string $invoiceId): void
    {
        self::assertSame(0, $this->countAsOwnerForTenant(self::TENANT_A,
            'SELECT count(*) FROM audit_log WHERE event_type = ? AND subject_id = ?::uuid', [AuditEvent::INVOICE_VOIDED, $invoiceId]),
            'A refused void wrote a trail row.');
    }
}
