<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Modules\UserManagement\Domain\DefaultRoles;
use App\SharedKernel\ValueObject\AuditEvent;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Uid\Uuid;

/**
 * **`PayInvoice`, and `paid_by` on the invoice — sales step 6, C5** (design §19 C5; OI-2, owner ruling #2 of
 * 2026-09-14; `SEC` §2 (Q7) and §5.1).
 *
 * Every invoice here is issued THROUGH THE API on a shipment written through the API (`InvoiceFixtures`); only the
 * states no operation can reach yet (`void`, until C7) are seeded as the owner. The pay ∥ pay race is in
 * `PurchaseOrderRowLockTest`, beside the holder harness; the CHECK quadrants are in `InvoiceSchemaTest`; the grant is
 * `GrantFootprintTest::theCallersCanUpdateOnlyTheInvoiceColumnsTheyWrite`.
 */
final class InvoicePaymentTest extends PurchaseOrderTestCase
{
    use InvoiceFixtures;

    /**
     * The shape and the effects: 200 with the invoice in its new state, `paid_by` = the caller as `{id,
     * display_name}` and nothing more, the stored `paid_by_user_id` the token subject (read back as the owner), and the
     * `invoice.paid` row cell by cell with `metadata` EXACTLY `{"paid_at": <the value written>}`.
     */
    #[Test]
    public function aFinanceUserRecordsThePaymentAndIsNamedAsItsRecorder(): void
    {
        $i = $this->issuedInvoice('PAY-A');
        $payer = $this->actAsRole(DefaultRoles::FINANCE);

        $this->pay($i['invoice'], ['paid_at' => '2099-01-01T00:00:00Z']);
        self::assertSame(422, $this->httpStatus(), 'Fixture: a far-future date must be refused before anything else.');

        $this->pay($i['invoice']);

        self::assertSame(200, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        $body = $this->json();
        self::assertSame('paid', $body['status']);
        self::assertNotNull($body['paid_at']);
        self::assertSame(['id' => $payer, 'display_name' => 'Fixture Actor'], $body['paid_by'],
            'paid_by is not the caller as {id, display_name}.');

        self::assertSame([['p' => $payer, 'status' => 'paid']], $this->fetchAsOwnerForTenant(self::TENANT_A,
            'SELECT paid_by_user_id::text AS p, status FROM invoice WHERE id = ?::uuid', [$i['invoice']]));

        self::assertSame([[
            'event_type' => AuditEvent::INVOICE_PAID,
            'actor_user_id' => $payer,
            'actor_label' => 'Fixture Actor',
            'subject_type' => 'invoice',
            'subject_id' => $i['invoice'],
            'ip_address' => null,
            'permission_code' => null,
        ]], $this->fetchAsOwnerForTenant(self::TENANT_A, <<<'SQL'
            SELECT event_type, actor_user_id::text AS actor_user_id, actor_label, subject_type,
                   subject_id::text AS subject_id, ip_address::text AS ip_address, permission_code
              FROM audit_log WHERE event_type = ?
            SQL, [AuditEvent::INVOICE_PAID]));
        $metadata = json_decode((string) $this->fetchAsOwnerForTenant(self::TENANT_A,
            'SELECT metadata::text AS m FROM audit_log WHERE event_type = ?', [AuditEvent::INVOICE_PAID])[0]['m'], true);
        self::assertSame(['paid_at' => $body['paid_at']], $metadata,
            'invoice.paid must carry exactly {"paid_at": <the value written>} (SEC §5.1).');

        // GetInvoice and ListInvoices show the same recorder.
        $this->send('GET', '/invoices/'.$i['invoice']);
        self::assertSame($body, $this->json());
        $this->send('GET', '/invoices?status=paid');
        self::assertSame($body['paid_by'], $this->json()['items'][0]['paid_by']);
    }

    /**
     * **RED-3 F-1: pay immediately after issue, with no `paid_at`.** The default is the DATABASE clock's `now()`, the
     * same clock `issued_at` was read from, so it can never land before the invoice. A PHP clock running behind the
     * database's (the Colima `+2.03 s` trap, mirrored) would default to an instant before `issued_at`, and
     * `invoice_paid_not_before_issue` would answer 23514 as a 500 — or the domain check would refuse a legal payment.
     */
    #[Test]
    public function aPaymentRecordedTheMomentAfterIssueIsAccepted(): void
    {
        $i = $this->issuedInvoice('PAY-B');
        $this->actAsRole(DefaultRoles::FINANCE);

        $this->pay($i['invoice']);

        self::assertSame(200, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        self::assertSame([['ok' => true]], $this->fetchAsOwnerForTenant(self::TENANT_A,
            'SELECT paid_at >= issued_at AS ok FROM invoice WHERE id = ?::uuid', [$i['invoice']]));
    }

    /**
     * **M-11, mechanised: the SET list of the paying `UPDATE invoice`, read from the statements the request really
     * issued** — exactly `status`, `paid_at`, `paid_by_user_id`, `updated_at`, the four columns
     * `Version20260928000400` grants. `GrantFootprintTest::theCallersCanUpdateOnlyTheInvoiceColumnsTheyWrite` holds the
     * grant to its hand list; this holds the writer to the same list, so neither can drift from the other unseen.
     */
    #[Test]
    public function thePaymentSetsExactlyTheFourGrantedColumns(): void
    {
        $i = $this->issuedInvoice('PAY-SET');
        $this->actAsRole(DefaultRoles::FINANCE);

        $this->recorder()->start();
        $this->pay($i['invoice']);
        $this->recorder()->stop();
        self::assertSame(200, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());

        $updates = $this->recorder()->matching('/^\s*UPDATE\s+invoice\b/i');
        self::assertCount(1, $updates, 'Expected exactly one UPDATE of invoice.');
        preg_match_all('/(\w+)\s*=\s*\?/', (string) preg_replace('/\bWHERE\b.*$/is', '', $updates[0]), $m);
        $set = $m[1];
        sort($set);
        self::assertSame(['paid_at', 'paid_by_user_id', 'status', 'updated_at'], $set, 'The paying UPDATE: '.$updates[0]);
    }

    /** `guard:invoice.status`: a repeat finds the invoice `paid` and is refused by §8.3's matrix, writing nothing. */
    #[Test]
    public function aSecondPaymentIsRefusedByTheStatusGuard(): void
    {
        $i = $this->issuedInvoice('PAY-C');
        $this->actAsRole(DefaultRoles::FINANCE);
        $this->pay($i['invoice']);
        self::assertSame(200, $this->httpStatus());

        $this->pay($i['invoice']);

        self::assertSame(409, $this->httpStatus());
        self::assertSame('urn:erp:problem:business-rule-violated', $this->json()['type']);
        self::assertSame('invoice.status_transition_is_legal', $this->json()['rule']);
        self::assertSame(1, $this->countAsOwnerForTenant(self::TENANT_A,
            'SELECT count(*) FROM audit_log WHERE event_type = ?', [AuditEvent::INVOICE_PAID]), 'The refused repeat wrote a trail row.');
    }

    /** A void invoice is not payable (§8.3: `issued` or `overdue`). Seeded void as the owner — `VoidInvoice` is C7's. */
    #[Test]
    public function aVoidInvoiceCannotBePaid(): void
    {
        $i = $this->issuedInvoice('PAY-D');
        $this->executeAsOwnerForTenant(self::TENANT_A, "UPDATE invoice SET status = 'void' WHERE id = ?::uuid", [$i['invoice']]);
        $this->actAsRole(DefaultRoles::FINANCE);

        $this->pay($i['invoice']);

        self::assertSame(409, $this->httpStatus());
        self::assertSame('invoice.status_transition_is_legal', $this->json()['rule']);
    }

    /**
     * An invoice that READS overdue (derived, owner ruling Q2) is stored `issued`, and is payable — the ordinary way a
     * late invoice is settled.
     */
    #[Test]
    public function anOverdueInvoiceIsPaid(): void
    {
        $i = $this->issuedInvoice('PAY-E');
        $this->executeAsOwnerForTenant(self::TENANT_A,
            "UPDATE invoice SET issued_at = issued_at - interval '40 days', due_at = due_at - interval '40 days' WHERE id = ?::uuid", [$i['invoice']]);
        $this->actAsRole(DefaultRoles::FINANCE);
        $this->send('GET', '/invoices/'.$i['invoice']);
        self::assertSame('overdue', $this->json()['status'], 'Fixture: the invoice does not read overdue.');

        $this->pay($i['invoice']);

        self::assertSame(200, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        self::assertSame('paid', $this->json()['status']);
    }

    /**
     * `paid_at` on the database clock: more than a minute ahead is 422 `in_the_future`; inside the minute it is
     * clamped to the database's now; a well-formed instant after the issue is stored as sent.
     */
    #[Test]
    public function aPaymentDateIsBoundedByTheDatabaseClock(): void
    {
        $i = $this->issuedInvoice('PAY-F');
        $this->actAsRole(DefaultRoles::FINANCE);
        $dbNow = new \DateTimeImmutable((string) $this->fetchAsOwnerForTenant(self::TENANT_A, 'SELECT now()::text AS n')[0]['n']);

        $this->pay($i['invoice'], ['paid_at' => $dbNow->modify('+5 minutes')->format(\DATE_RFC3339)]);
        self::assertSame(422, $this->httpStatus());
        self::assertSame('/paid_at', $this->json()['errors'][0]['pointer']);
        self::assertSame('in_the_future', $this->json()['errors'][0]['code']);

        $this->pay($i['invoice'], ['paid_at' => $dbNow->modify('+30 seconds')->format(\DATE_RFC3339)]);
        self::assertSame(200, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        self::assertSame([['clamped' => true]], $this->fetchAsOwnerForTenant(self::TENANT_A,
            'SELECT paid_at <= now() AS clamped FROM invoice WHERE id = ?::uuid', [$i['invoice']]),
            'A paid_at inside the skew window was stored in the future rather than clamped.');

        $j = $this->issuedInvoice('PAY-G');
        $this->executeAsOwnerForTenant(self::TENANT_A,
            "UPDATE invoice SET issued_at = issued_at - interval '10 days', due_at = due_at - interval '10 days' WHERE id = ?::uuid", [$j['invoice']]);
        $this->actAsRole(DefaultRoles::FINANCE);
        $sent = $dbNow->modify('-2 days')->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.000P');
        $this->pay($j['invoice'], ['paid_at' => $sent]);
        self::assertSame(200, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        self::assertSame($sent, $this->json()['paid_at'], 'A back-dated payment inside the invoice\'s life was not stored as sent.');
    }

    /**
     * **RED R-10: a payment dated before the invoice existed is refused in domain terms**
     * (`invoice.paid_after_issue`), before `invoice_paid_not_before_issue` could answer 23514 as a 500. Nothing changes.
     */
    #[Test]
    public function aPaymentDatedBeforeTheInvoiceIsRefused(): void
    {
        $i = $this->issuedInvoice('PAY-H');
        $this->actAsRole(DefaultRoles::FINANCE);
        $issuedAt = new \DateTimeImmutable((string) $this->fetchAsOwnerForTenant(self::TENANT_A,
            'SELECT issued_at::text AS i FROM invoice WHERE id = ?::uuid', [$i['invoice']])[0]['i']);

        $this->pay($i['invoice'], ['paid_at' => $issuedAt->modify('-1 second')->format(\DATE_RFC3339)]);

        self::assertSame(409, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        self::assertSame('invoice.paid_after_issue', $this->json()['rule']);
        self::assertSame([['status' => 'issued', 'p' => null]], $this->fetchAsOwnerForTenant(self::TENANT_A,
            'SELECT status, paid_at::text AS p FROM invoice WHERE id = ?::uuid', [$i['invoice']]));
    }

    /** The body: a malformed `paid_at` is 422 `not_a_timestamp`; a field naming the payer is refused, never ignored (API-9). */
    #[Test]
    public function theBodyAcceptsOnlyAWellFormedPaymentDate(): void
    {
        $i = $this->issuedInvoice('PAY-I');
        $this->actAsRole(DefaultRoles::FINANCE);

        $this->pay($i['invoice'], ['paid_at' => '2026-02-30T00:00:00Z']);
        self::assertSame(422, $this->httpStatus());
        self::assertSame('not_a_timestamp', $this->json()['errors'][0]['code']);

        $this->pay($i['invoice'], ['paid_by' => (string) $this->actorId]);
        self::assertSame(422, $this->httpStatus());
        self::assertSame('urn:erp:problem:validation-failed', $this->json()['type']);
        self::assertSame([['status' => 'issued']], $this->fetchAsOwnerForTenant(self::TENANT_A,
            'SELECT status FROM invoice WHERE id = ?::uuid', [$i['invoice']]));
    }

    /**
     * **M-16: the recorder is read by an UNFILTERED join, so a user deleted since is still named** — the document
     * keeps naming what it named (`SEC` §2). The payer is soft-deleted as the owner; a colleague reads the invoice.
     */
    #[Test]
    public function aRecorderDeletedSinceIsStillNamed(): void
    {
        $i = $this->issuedInvoice('PAY-J');
        $payer = $this->actAsRole(DefaultRoles::FINANCE);
        $this->pay($i['invoice']);
        self::assertSame(200, $this->httpStatus());
        $this->executeAsOwnerForTenant(self::TENANT_A,
            "UPDATE app_user SET deleted_at = now(), is_active = false, display_name = 'Former Clerk' WHERE id = ?::uuid", [$payer]);

        $this->actAsRole(DefaultRoles::VIEWER);
        $this->send('GET', '/invoices/'.$i['invoice']);

        self::assertSame(200, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        self::assertSame(['id' => $payer, 'display_name' => 'Former Clerk'], $this->json()['paid_by']);
    }

    /** E-1: without `invoice.pay` every id is the same 403, and nothing is read or written. */
    #[Test]
    public function withoutThePayCodeEveryInvoiceIsTheSameRefusal(): void
    {
        $i = $this->issuedInvoice('PAY-K');
        $this->actAsRole(DefaultRoles::SALES_REP);

        $this->pay($i['invoice']);
        self::assertSame(403, $this->httpStatus());
        $known = (string) $this->client()->getResponse()->getContent();
        $this->pay(Uuid::v4()->toRfc4122());
        self::assertSame(403, $this->httpStatus());
        self::assertSame($known, (string) $this->client()->getResponse()->getContent());
        self::assertSame([['status' => 'issued']], $this->fetchAsOwnerForTenant(self::TENANT_A,
            'SELECT status FROM invoice WHERE id = ?::uuid', [$i['invoice']]));
    }

    /** E-1 on the read: `paid_by` is gated on `invoice.view` with the rest of the invoice (`SEC` §2). */
    #[Test]
    public function withoutTheViewCodeTheInvoiceAndItsRecorderAreNotRead(): void
    {
        $i = $this->issuedInvoice('PAY-L');
        $this->actAsRole(DefaultRoles::FINANCE);
        $this->pay($i['invoice']);
        $this->actAs(self::TENANT_A, ['invoice.pay']);

        $this->send('GET', '/invoices/'.$i['invoice']);

        self::assertSame(403, $this->httpStatus());
        self::assertStringNotContainsString('paid_by', (string) $this->client()->getResponse()->getContent());
    }

    /** E-2 / ST-5: another workspace's invoice and a random id are the same 404 for a caller holding the code. */
    #[Test]
    public function anotherWorkspacesInvoiceIsNotFound(): void
    {
        $theirs = $this->issuedInvoice('PAY-M', self::TENANT_B);
        $this->actAsRole(DefaultRoles::FINANCE);

        $this->pay($theirs['invoice']);
        self::assertSame(404, $this->httpStatus());
        $foreign = (string) $this->client()->getResponse()->getContent();
        $this->pay(Uuid::v4()->toRfc4122());
        self::assertSame(404, $this->httpStatus());
        self::assertSame($foreign, (string) $this->client()->getResponse()->getContent());
        self::assertSame([['status' => 'issued']], $this->fetchAsOwnerForTenant(self::TENANT_B,
            'SELECT status FROM invoice WHERE id = ?::uuid', [$theirs['invoice']]));
    }

    /** @param array<string, string>|null $body */
    private function pay(string $invoiceId, ?array $body = []): void
    {
        $this->send('POST', '/invoices/'.$invoiceId.'/payment', $body);
    }
}
