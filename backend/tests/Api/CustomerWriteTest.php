<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Modules\Customers\Application\Contract\PaymentTerms;
use App\Modules\UserManagement\Domain\DefaultRoles;
use App\SharedKernel\ValueObject\AuditEvent;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Uid\Uuid;

/**
 * **Customers slice 1 C2 — `CreateCustomer`, `UpdateCustomer`** (`docs/session13/customers-s1-design.md` §4; copy
 * `customers-s1-ux.md` §2 as amended by final RED L-1).
 *
 * Each refusal's literal sentence is pinned once here (house rule, `roles-s4-ux-copy.md` §5.5). The terms bound is pinned
 * twice on purpose: by the literals 0/3650 accepted and −1/3651 refused (the same four values `InvoiceIssueTest` uses, so a
 * drift between customer and invoice reddens both), and by edges derived from {@see PaymentTerms} (so a hard-coded copy of
 * the number in the writer reddens when the constant moves — design mutation C2-1b).
 *
 * **The trail** (`docs/session13/customers-s1-g35-judge.md` FINAL item 7, `customers-s1-g35-final-red.md` F-2;
 * `RESIDUAL_GAPS.md` G-35): one `customer.created` per create and one `customer.updated` per CHANGING update, each read
 * back as the owner cell by cell — event, actor, label, subject pair, no IP, no permission code, the exact `metadata` —
 * and no row on any refusal or on a no-op. The race test is a real second connection holding the row.
 */
final class CustomerWriteTest extends PurchaseOrderTestCase
{
    use InvoiceFixtures;

    private const HOLDER_DEADLINE_SECONDS = 10.0;

    /** The 201 body is `GetCustomer`'s, read back; omitted terms take the column default (30), never a PHP copy. */
    #[Test]
    public function aSalesRepCreatesACustomer(): void
    {
        $this->actAs(self::TENANT_A, DefaultRoles::codesFor(DefaultRoles::SALES_REP));

        $this->create(['code' => 'C-ACME', 'name' => 'Acme Retail', 'country_code' => 'DE', 'currency_code' => 'EUR']);

        self::assertSame(201, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        $body = $this->json();
        self::assertTrue(Uuid::isValid($body['id']));
        self::assertSame(['id' => $body['id'], 'code' => 'C-ACME', 'name' => 'Acme Retail', 'country_code' => 'DE', 'currency_code' => 'EUR',
            'payment_terms_days' => 30, 'archived_at' => null], $body);

        $this->send('GET', '/customers/'.$body['id']);
        self::assertSame($body, $this->json(), 'The 201 body is not GetCustomer\'s.');
    }

    /** API-14: the header is demanded (428 without it) — and then unused: a repeat is refused by `code`, never replayed. */
    #[Test]
    public function aRepeatedCreateIsRefusedNeverReplayed(): void
    {
        $body = ['code' => 'C-DUP', 'name' => 'First', 'country_code' => 'GB', 'currency_code' => 'GBP'];
        $this->send('POST', '/customers', $body);
        self::assertSame(428, $this->httpStatus());

        $key = Uuid::v4()->toRfc4122();
        $this->send('POST', '/customers', $body, ['Idempotency-Key' => $key]);
        self::assertSame(201, $this->httpStatus());

        $this->send('POST', '/customers', $body, ['Idempotency-Key' => $key]);
        self::assertSame(409, $this->httpStatus(), 'A repeat with the same key was replayed, not refused.');
        self::assertSame('customer.code_is_unique_per_workspace', $this->json()['rule']);
        self::assertSame('Another customer already uses this code. Choose a different code.', $this->json()['detail']);
        self::assertSame(1, $this->countAsOwnerForTenant(self::TENANT_A, "SELECT count(*) FROM customer WHERE code = 'C-DUP'"));
    }

    /** The unique is per workspace and among live rows: another workspace, or an archived customer, frees the code. */
    #[Test]
    public function aCodeIsUniqueAmongLiveCustomersOfOneWorkspace(): void
    {
        $archived = $this->seedCustomer(self::TENANT_A, 'Old');
        $this->insertAsOwnerForTenant(self::TENANT_A, "UPDATE customer SET code = 'C-FREE', deleted_at = now() WHERE id = ?::uuid", [$archived]);
        $this->insertAsOwnerForTenant(self::TENANT_B, "UPDATE customer SET code = 'C-FREE' WHERE id = ?::uuid", [$this->seedCustomer(self::TENANT_B, 'Theirs')]);

        $this->create(['code' => 'C-FREE', 'name' => 'New', 'country_code' => 'GB', 'currency_code' => 'GBP']);

        self::assertSame(201, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
    }

    /** @return iterable<string, array{mixed, int}> */
    public static function terms(): iterable
    {
        yield 'zero (due on the invoice date)' => [0, 201];
        yield 'ten years' => [3650, 201];
        yield 'negative' => [-1, 422];
        yield 'beyond ten years' => [3651, 422];
        yield 'a decimal' => [30.5, 422];
        yield 'a numeric string' => ['30', 422];
        yield 'a boolean' => [true, 422];
    }

    /** The literal edges, the same four `InvoiceIssueTest` uses; one code, `out_of_range`, one sentence. */
    #[Test]
    #[DataProvider('terms')]
    public function paymentTermsAreAWholeNumberOfDaysFromZeroToTenYears(mixed $terms, int $status): void
    {
        $this->create(['code' => 'C-T'.bin2hex(random_bytes(3)), 'name' => 'Terms', 'country_code' => 'GB', 'currency_code' => 'GBP', 'payment_terms_days' => $terms]);

        self::assertSame($status, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        if (201 === $status) {
            self::assertSame($terms, $this->json()['payment_terms_days']);

            return;
        }
        self::assertSame([['pointer' => '/payment_terms_days', 'code' => 'out_of_range',
            'message' => 'Payment terms must be a whole number of days from 0 to 3650.']], $this->fieldErrors());
    }

    /** Edges derived from the ONE source: a writer carrying its own copy of the number reddens when the constant moves. */
    #[Test]
    public function theTermsBoundIsTheSharedConstant(): void
    {
        $this->create(['code' => 'C-MAX', 'name' => 'Max', 'country_code' => 'GB', 'currency_code' => 'GBP', 'payment_terms_days' => PaymentTerms::MAXIMUM_DAYS]);
        self::assertSame(201, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());

        $this->create(['code' => 'C-OVER', 'name' => 'Over', 'country_code' => 'GB', 'currency_code' => 'GBP', 'payment_terms_days' => PaymentTerms::MAXIMUM_DAYS + 1]);
        self::assertSame(422, $this->httpStatus());

        $this->create(['code' => 'C-MIN', 'name' => 'Min', 'country_code' => 'GB', 'currency_code' => 'GBP', 'payment_terms_days' => PaymentTerms::MINIMUM_DAYS]);
        self::assertSame(201, $this->httpStatus());
    }

    /** Required fields, blanks (stored as sent otherwise — no trim, final RED L-2), shapes and the four-country limit. */
    #[Test]
    public function aMalformedCreateIsRefusedFieldByField(): void
    {
        $this->create([]);
        self::assertSame(422, $this->httpStatus());
        self::assertSame([
            ['pointer' => '/code', 'code' => 'required', 'message' => 'This field is required.'],
            ['pointer' => '/name', 'code' => 'required', 'message' => 'This field is required.'],
            ['pointer' => '/country_code', 'code' => 'required', 'message' => 'This field is required.'],
            ['pointer' => '/currency_code', 'code' => 'required', 'message' => 'This field is required.'],
        ], $this->fieldErrors());

        $this->create(['code' => '  ', 'name' => '', 'country_code' => 'gb', 'currency_code' => 'GB']);
        self::assertSame([
            ['pointer' => '/code', 'code' => 'blank', 'message' => 'This field may not be blank.'],
            ['pointer' => '/name', 'code' => 'blank', 'message' => 'This field may not be blank.'],
            ['pointer' => '/country_code', 'code' => 'not_a_country_code', 'message' => 'Use the two-letter country code, for example GB.'],
            ['pointer' => '/currency_code', 'code' => 'not_a_currency_code', 'message' => 'Use the three-letter currency code, for example GBP.'],
        ], $this->fieldErrors());

        $this->create(['code' => 'C-FR', 'name' => 'Paris', 'country_code' => 'FR', 'currency_code' => 'CHF']);
        self::assertSame([
            ['pointer' => '/country_code', 'code' => 'unknown_reference', 'message' => 'This country isn\'t supported yet.'],
            ['pointer' => '/currency_code', 'code' => 'unknown_reference', 'message' => 'This currency isn\'t supported yet.'],
        ], $this->fieldErrors());

        self::assertSame(0, $this->countAsOwnerForTenant(self::TENANT_A, 'SELECT count(*) FROM customer'));
    }

    /** A value with surrounding spaces is stored as sent: the house refuses blanks and does not trim (`ItemWriter`). */
    #[Test]
    public function aValueIsStoredAsSent(): void
    {
        $this->create(['code' => 'C-SP ', 'name' => ' Spaced ', 'country_code' => 'GB', 'currency_code' => 'GBP']);

        self::assertSame(201, $this->httpStatus());
        self::assertSame(['C-SP ', ' Spaced '], [$this->json()['code'], $this->json()['name']]);
    }

    /** Each field is editable on a live customer; the rest stay as they were; the body is `GetCustomer`'s. */
    #[Test]
    public function aCustomerIsUpdatedFieldByField(): void
    {
        $id = $this->seedCustomer(self::TENANT_A, 'Before');
        $code = $this->fetchAsOwnerForTenant(self::TENANT_A, 'SELECT code FROM customer WHERE id = ?::uuid', [$id])[0]['code'];
        $this->actAs(self::TENANT_A, DefaultRoles::codesFor(DefaultRoles::SALES_REP));

        $this->update($id, ['name' => 'After', 'payment_terms_days' => 60]);
        self::assertSame(200, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        self::assertSame(['id' => $id, 'code' => $code, 'name' => 'After', 'country_code' => 'GB', 'currency_code' => 'EUR',
            'payment_terms_days' => 60, 'archived_at' => null], $this->json());

        $this->update($id, ['code' => 'C-NEW', 'country_code' => 'US', 'currency_code' => 'USD']);
        self::assertSame(['C-NEW', 'US', 'USD', 'After', 60], [$this->json()['code'], $this->json()['country_code'], $this->json()['currency_code'], $this->json()['name'], $this->json()['payment_terms_days']]);

        $this->update($id, []);
        self::assertSame(200, $this->httpStatus(), 'An empty PATCH is legal: nothing is written, the row is read back.');
    }

    /** Explicit `null` clears nothing: all five are NOT NULL. */
    #[Test]
    public function noFieldCanBeCleared(): void
    {
        $id = $this->seedCustomer(self::TENANT_A, 'Kept');

        $this->update($id, ['code' => null, 'name' => null, 'country_code' => null, 'currency_code' => null, 'payment_terms_days' => null]);

        self::assertSame(422, $this->httpStatus());
        self::assertSame(array_map(static fn (string $field): array => ['pointer' => '/'.$field, 'code' => 'not_nullable', 'message' => 'This field may not be cleared.'],
            ['code', 'name', 'country_code', 'currency_code', 'payment_terms_days']), $this->fieldErrors());

        $this->update($id, ['name' => ' ']);
        self::assertSame([['pointer' => '/name', 'code' => 'blank', 'message' => 'This field may not be blank.']], $this->fieldErrors());
    }

    /** A rename onto a live customer's code is refused, with the update's own sentence. */
    #[Test]
    public function aRenameOntoATakenCodeIsRefused(): void
    {
        $taken = $this->seedCustomer(self::TENANT_A, 'Holder');
        $code = $this->fetchAsOwnerForTenant(self::TENANT_A, 'SELECT code FROM customer WHERE id = ?::uuid', [$taken])[0]['code'];
        $id = $this->seedCustomer(self::TENANT_A, 'Renamer');

        $this->update($id, ['code' => $code]);

        self::assertSame(409, $this->httpStatus());
        self::assertSame('customer.code_is_unique_per_workspace', $this->json()['rule']);
        self::assertSame('Another customer already uses this code. Keep the current code or choose a different one.', $this->json()['detail']);
    }

    /**
     * ST-9: an archived customer is read-only (reachable only by SQL until `DeleteCustomer` ships, C5 C4b). The sentence
     * carries no "Restore it" pending `UX` (C5 R1.1 M-3): restore ships at C4a, but a `customer.edit` holder may lack
     * `customer.delete`, which restoring needs.
     */
    #[Test]
    public function anArchivedCustomerCannotBeChanged(): void
    {
        $id = $this->seedCustomer(self::TENANT_A, 'Closed');
        $this->insertAsOwnerForTenant(self::TENANT_A, 'UPDATE customer SET deleted_at = now() WHERE id = ?::uuid', [$id]);

        $this->update($id, ['name' => 'Reopened']);

        self::assertSame(409, $this->httpStatus());
        self::assertSame('customer.is_archived', $this->json()['rule']);
        self::assertSame('This customer is archived, so it can\'t be changed.', $this->json()['detail']);
        self::assertSame('Closed', $this->fetchAsOwnerForTenant(self::TENANT_A, 'SELECT name FROM customer WHERE id = ?::uuid', [$id])[0]['name']);
    }

    /** ST-5: a foreign, absent or malformed id is the same 404 as `GetCustomer`'s. */
    #[Test]
    public function anUpdateOfAnUnknownCustomerIsTheSame404(): void
    {
        $bodies = [];
        foreach ([$this->seedCustomer(self::TENANT_B, 'Theirs'), Uuid::v4()->toRfc4122(), 'not-a-uuid'] as $id) {
            $this->update($id, ['name' => 'Mine now']);
            self::assertSame(404, $this->httpStatus(), $id);
            $bodies[] = (string) $this->client()->getResponse()->getContent();
        }
        self::assertSame($bodies[0], $bodies[1]);
        self::assertSame('Theirs', $this->fetchAsOwnerForTenant(self::TENANT_B, "SELECT name FROM customer WHERE name = 'Theirs'")[0]['name']);
    }

    /**
     * Design §4.3: every field stays editable after documents exist — nothing reads `customer.currency_code` (orders and
     * invoices carry their own), and terms affect only invoices issued after the change (`due_at` is stored at issue).
     */
    #[Test]
    public function currencyAndTermsStayEditableWithAnIssuedInvoice(): void
    {
        $invoice = $this->issuedInvoice('CUST-W');
        $before = $this->fetchAsOwnerForTenant(self::TENANT_A, 'SELECT currency_code, due_at FROM invoice WHERE id = ?::uuid', [$invoice['invoice']])[0];
        $this->actAs(self::TENANT_A, DefaultRoles::codesFor(DefaultRoles::SALES_REP));

        $this->update($invoice['shipment']['customer'], ['currency_code' => 'USD', 'payment_terms_days' => 90, 'name' => 'Renamed Customer']);

        self::assertSame(200, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        self::assertSame($before, $this->fetchAsOwnerForTenant(self::TENANT_A, 'SELECT currency_code, due_at FROM invoice WHERE id = ?::uuid', [$invoice['invoice']])[0],
            'Changing the customer changed an issued invoice\'s currency or due date.');
    }

    /** Permissions: create needs `customer.create`, update `customer.edit`; `viewer` holds neither. */
    #[Test]
    public function aViewerCanNeitherCreateNorChangeACustomer(): void
    {
        $id = $this->seedCustomer(self::TENANT_A, 'Untouched');
        $this->actAs(self::TENANT_A, DefaultRoles::codesFor(DefaultRoles::VIEWER));

        $this->create(['code' => 'C-V', 'name' => 'V', 'country_code' => 'GB', 'currency_code' => 'GBP']);
        self::assertSame(403, $this->httpStatus());
        $this->update($id, ['name' => 'Touched']);
        self::assertSame(403, $this->httpStatus());
    }

    /** 7.1 — one `customer.created`, `{}`, naming the creator; the subject is the new customer. */
    #[Test]
    public function aCreatedCustomerWritesExactlyOneCustomerCreatedRow(): void
    {
        $this->actAs(self::TENANT_A, DefaultRoles::codesFor(DefaultRoles::SALES_REP));

        $this->create(['code' => 'C-AUD', 'name' => 'Audited', 'country_code' => 'GB', 'currency_code' => 'GBP', 'payment_terms_days' => 3650]);

        self::assertSame(201, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        self::assertSame([$this->businessRow(AuditEvent::CUSTOMER_CREATED, 'customer', $this->json()['id'])],
            $this->auditRowsOf(AuditEvent::CUSTOMER_CREATED));
        self::assertSame([], $this->auditRowsOf(AuditEvent::CUSTOMER_UPDATED), 'A create wrote an update row.');
    }

    /** 7.2 — a create refused for a taken code (409, the 23505 caught outside `run()`) or for its shape (422) writes no row. */
    #[Test]
    public function aCreateRefusedForATakenCodeWritesNoRow(): void
    {
        $this->create(['code' => 'C-ONCE', 'name' => 'First', 'country_code' => 'GB', 'currency_code' => 'GBP']);
        self::assertSame(201, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        self::assertCount(1, $this->auditRowsOf(AuditEvent::CUSTOMER_CREATED));

        $this->create(['code' => 'C-ONCE', 'name' => 'Second', 'country_code' => 'GB', 'currency_code' => 'GBP']);
        self::assertSame(409, $this->httpStatus());
        $this->create(['code' => 'C-BAD', 'name' => 'Bad', 'country_code' => 'FR', 'currency_code' => 'GBP']);
        self::assertSame(422, $this->httpStatus());
        $this->create(['code' => 'C-BAD', 'name' => 'Bad', 'country_code' => 'GB', 'currency_code' => 'GBP', 'payment_terms_days' => 3651]);
        self::assertSame(422, $this->httpStatus());

        self::assertCount(1, $this->auditRowsOf(AuditEvent::CUSTOMER_CREATED), 'A refused create wrote a customer.created row.');
    }

    /** 7.3 — all five submitted, one changed: the row names that one field; a second case names two, sorted. */
    #[Test]
    public function anUpdateWritesOneRowNamingOnlyTheChangedFields(): void
    {
        $id = $this->seedCustomer(self::TENANT_A, 'Stable');
        $stored = $this->stored($id);
        $this->actAs(self::TENANT_A, DefaultRoles::codesFor(DefaultRoles::SALES_REP));

        $this->update($id, ['payment_terms_days' => 3650] + $stored);
        self::assertSame(200, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        self::assertSame([$this->updatedRow($id, ['payment_terms_days'])], $this->auditRowsOf(AuditEvent::CUSTOMER_UPDATED));

        $this->update($id, ['name' => 'Renamed', 'code' => 'C-RENAMED']);
        self::assertSame(200, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        self::assertSame([$this->updatedRow($id, ['payment_terms_days']), $this->updatedRow($id, ['code', 'name'])],
            $this->auditRowsOf(AuditEvent::CUSTOMER_UPDATED));
        self::assertSame([], $this->auditRowsOf(AuditEvent::CUSTOMER_CREATED), 'An update wrote a create row.');
    }

    /** 7.4 — all five submitted equal to the stored values: no row, and no `UPDATE customer` statement is issued. */
    #[Test]
    public function aNoOpUpdateWritesNoRowAndNoStatement(): void
    {
        $id = $this->seedCustomer(self::TENANT_A, 'Unchanged');
        $stored = $this->stored($id);
        $before = $this->fetchAsOwnerForTenant(self::TENANT_A, 'SELECT updated_at::text AS updated_at FROM customer WHERE id = ?::uuid', [$id]);

        $this->recorder()->start();
        $this->update($id, $stored);
        $this->recorder()->stop();

        self::assertSame(200, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        self::assertSame(['id' => $id] + $stored + ['archived_at' => null], $this->json(),
            'A no-op update must answer the unchanged row.');
        self::assertSame([], $this->recorder()->matching('/\bUPDATE\s+customer\b/i'), 'A no-op update issued an UPDATE.');
        self::assertSame([], $this->auditRowsOf(AuditEvent::CUSTOMER_UPDATED), 'A no-op update wrote a customer.updated row.');
        self::assertSame($before, $this->fetchAsOwnerForTenant(self::TENANT_A, 'SELECT updated_at::text AS updated_at FROM customer WHERE id = ?::uuid', [$id]));
    }

    /** 7.5 — a rename onto a taken code (409), an archived customer (409) and an unsupported country (422) write no row. */
    #[Test]
    public function aRefusedUpdateWritesNoRow(): void
    {
        $holder = $this->seedCustomer(self::TENANT_A, 'Holder');
        $taken = $this->stored($holder)['code'];
        $id = $this->seedCustomer(self::TENANT_A, 'Renamer');
        $archived = $this->seedCustomer(self::TENANT_A, 'Archived');
        $this->insertAsOwnerForTenant(self::TENANT_A, 'UPDATE customer SET deleted_at = now() WHERE id = ?::uuid', [$archived]);

        $this->update($id, ['code' => $taken, 'name' => 'Also renamed']);
        self::assertSame(409, $this->httpStatus());
        $this->update($archived, ['name' => 'Reopened']);
        self::assertSame(409, $this->httpStatus());
        $this->update($id, ['country_code' => 'FR', 'name' => 'Also renamed']);
        self::assertSame(422, $this->httpStatus());

        self::assertSame([], $this->auditRowsOf(AuditEvent::CUSTOMER_UPDATED), 'A refused update wrote a customer.updated row.');
    }

    /** 7.6 — `metadata` is `{fields}` and nothing else, and no submitted value appears in it. */
    #[Test]
    public function theUpdatedRowCarriesNamesNeverValues(): void
    {
        $id = $this->seedCustomer(self::TENANT_A, 'Valued');

        $this->update($id, ['name' => 'Zebra Holdings Secret', 'payment_terms_days' => 3650, 'currency_code' => 'USD']);
        self::assertSame(200, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());

        $rows = $this->auditRowsOf(AuditEvent::CUSTOMER_UPDATED);
        self::assertCount(1, $rows);
        $metadata = json_decode($rows[0]['metadata'], true, flags: \JSON_THROW_ON_ERROR);
        self::assertSame(['fields'], array_keys($metadata), 'customer.updated carries a key other than fields.');
        self::assertSame(['currency_code', 'name', 'payment_terms_days'], $metadata['fields']);
        foreach (['3650', 'Zebra', 'USD', 'Valued'] as $value) {
            self::assertStringNotContainsString($value, $rows[0]['metadata'], 'A value reached customer.updated\'s metadata.');
        }
    }

    /**
     * 7.7 — the race the row lock exists for (judge C-3; final RED F-2). A second connection resets the terms to 3650 and
     * holds the row; this request sends `{payment_terms_days: 30, name}` against a row stored at 30. Locked, the request
     * waits, then diffs against 3650: both fields change, the row ends at 30, and the request's row names both — the reset
     * is undone AND attributed. Unlocked (mutation M-6), it diffs against the uncommitted 30, leaves the terms out of its
     * `SET`, and the row keeps 3650 with nobody's name on the reset.
     */
    #[Test]
    public function aTermsResetRacingATermsChangeIsAttributedToTheResetter(): void
    {
        $id = $this->seedCustomer(self::TENANT_A, 'Raced');
        self::assertSame(30, $this->stored($id)['payment_terms_days'], 'Fixture: the seeded terms must be 30, or M-6 cannot redden this test.');

        $holder = $this->raceAgainstAHolder(
            [['UPDATE customer SET payment_terms_days = 3650 WHERE id = ?::uuid', [$id]]],
            function () use ($id): void {
                $this->update($id, ['payment_terms_days' => 30, 'name' => 'Raced Renamed']);
            },
        );

        self::assertTrue($holder['waiter_seen'], 'The request never waited on the holder, so nothing here was a race: '.json_encode($holder));
        self::assertTrue($holder['committed'], 'The holder did not commit: '.json_encode($holder));
        self::assertSame(200, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        self::assertSame(30, $this->stored($id)['payment_terms_days'], 'The request did not re-apply its terms over the holder\'s reset.');
        self::assertSame([$this->updatedRow($id, ['name', 'payment_terms_days'])], $this->auditRowsOf(AuditEvent::CUSTOMER_UPDATED),
            'The request\'s customer.updated row does not name the terms it changed back.');
    }

    // -----------------------------------------------------------------------

    /** @param array<string, mixed> $body */
    private function create(array $body): void
    {
        $this->send('POST', '/customers', $body, ['Idempotency-Key' => Uuid::v4()->toRfc4122()]);
    }

    /** @param array<string, mixed> $body */
    private function update(string $id, array $body): void
    {
        $this->send('PATCH', '/customers/'.$id, $body);
    }

    /** @return list<array{pointer: string, code: string, message: string}> */
    private function fieldErrors(): array
    {
        return $this->json()['errors'];
    }

    /** @return array{code: string, name: string, country_code: string, currency_code: string, payment_terms_days: int} */
    private function stored(string $id): array
    {
        $row = $this->fetchAsOwnerForTenant(self::TENANT_A,
            'SELECT code, name, country_code, currency_code, payment_terms_days FROM customer WHERE id = ?::uuid', [$id])[0];
        $row['payment_terms_days'] = (int) $row['payment_terms_days'];

        return $row;
    }

    /**
     * The one `customer.updated` row a change must write: this actor, the fixture label, the subject pair, no IP, no
     * permission code, `metadata` exactly `{"fields": [...]}` as Postgres renders the jsonb.
     *
     * @param list<string> $fields
     *
     * @return array<string, ?string>
     */
    private function updatedRow(string $id, array $fields): array
    {
        return array_replace($this->businessRow(AuditEvent::CUSTOMER_UPDATED, 'customer', $id), [
            'metadata' => (string) $this->fetchAsOwnerForTenant(self::TENANT_A, 'SELECT ?::jsonb::text AS m',
                [json_encode(['fields' => $fields], \JSON_THROW_ON_ERROR)])[0]['m'],
        ]);
    }

    /**
     * `DepleteStockTest::raceAgainstAHolder`'s shape (final RED F-2: `PurchaseOrderRowLockTest`'s is private and its
     * `HOLD_TABLE` whitelist names no `customer`, which must NOT be added). The holder's own lock is on a random
     * `purchase_order` id, so it matches no row; the real lock is `HOLD_BEFORE`'s `UPDATE customer`, and the holder's
     * `pg_blocking_pids` wait sees a waiter on any lock it holds.
     *
     * @param list<array{0: string, 1: list<string>}> $before
     *
     * @return array{waiter_seen: bool, committed: bool, sqlstate: ?string, error: ?string}
     */
    private function raceAgainstAHolder(array $before, callable $request): array
    {
        $readyFile = tempnam(sys_get_temp_dir(), 'erp_customer_');
        file_put_contents($readyFile, '');

        $proc = proc_open(
            [PHP_BINARY, __DIR__.'/support/hold_order_lock.php'],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            null,
            [
                'APP_DIRECT_DATABASE_URL' => $_ENV['APP_DIRECT_DATABASE_URL'],
                'HOLD_TENANT' => self::TENANT_A,
                'HOLD_ORDER' => Uuid::v4()->toRfc4122(),
                'HOLD_TABLE' => 'purchase_order',
                'HOLD_BEFORE' => json_encode($before, \JSON_THROW_ON_ERROR),
                'HOLD_AFTER' => json_encode([], \JSON_THROW_ON_ERROR),
                'HOLD_READY_FILE' => $readyFile,
                'HOLD_DEADLINE_SECONDS' => (string) self::HOLDER_DEADLINE_SECONDS,
                'PATH' => (string) getenv('PATH'),
            ],
        );
        self::assertIsResource($proc);

        try {
            $deadline = microtime(true) + self::HOLDER_DEADLINE_SECONDS;
            while ('' === trim((string) file_get_contents($readyFile))) {
                if (microtime(true) > $deadline) {
                    self::fail('The holder never took the lock: '.stream_get_contents($pipes[2]));
                }
                usleep(1000);
            }
            if ('failed' === trim((string) file_get_contents($readyFile))) {
                self::fail('The holder failed before taking the lock: '.stream_get_contents($pipes[1]).stream_get_contents($pipes[2]));
            }

            $request();

            $out = (string) stream_get_contents($pipes[1]);
            $err = (string) stream_get_contents($pipes[2]);
        } finally {
            if (proc_get_status($proc)['running']) {
                proc_terminate($proc);
            }
            foreach ($pipes as $pipe) {
                if (\is_resource($pipe)) {
                    fclose($pipe);
                }
            }
            proc_close($proc);
            @unlink($readyFile);
        }

        $report = json_decode(trim($out), true);
        self::assertIsArray($report, 'The holder printed no report. stdout: '.$out.' stderr: '.$err);

        return $report;
    }
}
