<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Modules\Customers\Application\CustomerWriter;
use App\Modules\Purchasing\Infrastructure\Entity\PurchaseOrder;
use App\Modules\Suppliers\Application\Contract\SupplierPaymentTerms;
use App\Modules\Suppliers\Application\SupplierWriter;
use App\Modules\UserManagement\Domain\DefaultRoles;
use App\SharedKernel\ValueObject\AuditEvent;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Uid\Uuid;

/**
 * **Suppliers slice 1 C2 — `CreateSupplier`, `UpdateSupplier`** (`docs/session13/suppliers-s1-design.md` FINAL F-3 and §4;
 * owner ruling Q-S5/Q-S6, `suppliers-s1-ruling.md`; final RED M-A). `CustomerWriteTest`'s shape.
 *
 * Each refusal's literal sentence is pinned once here. The supplier copy is a **placeholder** on the customer sentences
 * (`UX` owes the supplier copy); the four sentences shared with customers are held equal to `CustomerWriter`'s constants
 * (one sentence per code across the house). The terms bound is pinned by the literals 0/3650 and −1/3651 and by edges
 * derived from {@see SupplierPaymentTerms}.
 *
 * **Two live uniques** (`supplier_tenant_code_live`, `supplier_tenant_name_live`), each answered by its own rule, on create
 * AND update (final RED L-1 of the first attack). **The name unique compares the name as stored** (LEAD 2026-10-12, owner
 * notice in the ruling): a case variant is accepted, and the refusal copy never claims otherwise.
 *
 * **The trail** (`supplier.created` / `supplier.updated`): one row per create and per CHANGING update, read back cell by
 * cell, no row on any refusal or no-op; the race test is a real second connection holding the row. **Only these two
 * supplier events exist** (final RED M-A): `.deleted`/`.restored` are minted with their writers later.
 */
final class SupplierWriteTest extends PurchaseOrderTestCase
{
    private const HOLDER_DEADLINE_SECONDS = 10.0;

    /** The 201 body is `GetSupplier`'s, read back; omitted terms take the column default (30), never a PHP copy. */
    #[Test]
    public function aBuyerCreatesASupplier(): void
    {
        $this->actAs(self::TENANT_A, DefaultRoles::codesFor(DefaultRoles::BUYER));

        $this->create(['code' => 'S-ACME', 'name' => 'Acme Components', 'country_code' => 'DE', 'currency_code' => 'EUR']);

        self::assertSame(201, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        $body = $this->json();
        self::assertTrue(Uuid::isValid($body['id']));
        self::assertSame(['id' => $body['id'], 'code' => 'S-ACME', 'name' => 'Acme Components', 'country_code' => 'DE', 'currency_code' => 'EUR',
            'payment_terms_days' => 30, 'archived_at' => null], $body);

        $this->send('GET', '/suppliers/'.$body['id']);
        self::assertSame($body, $this->json(), 'The 201 body is not GetSupplier\'s.');
    }

    /** API-14: the header is demanded (428 without it) — and then unused: a repeat is refused by `code`, never replayed. */
    #[Test]
    public function aRepeatedCreateIsRefusedNeverReplayed(): void
    {
        $body = ['code' => 'S-DUP', 'name' => 'First', 'country_code' => 'GB', 'currency_code' => 'GBP'];
        $this->send('POST', '/suppliers', $body);
        self::assertSame(428, $this->httpStatus());

        $key = Uuid::v4()->toRfc4122();
        $this->send('POST', '/suppliers', $body, ['Idempotency-Key' => $key]);
        self::assertSame(201, $this->httpStatus());

        // An identical repeat is refused too, but by whichever live unique Postgres checks first (both collide; pinned in
        // `IdempotencyTest`); the name is changed here so the code's rule — the §4.2 `Idem` mechanism — is the one observed.
        $this->send('POST', '/suppliers', array_replace($body, ['name' => 'Second']), ['Idempotency-Key' => $key]);
        self::assertSame(409, $this->httpStatus(), 'A repeat with the same key was replayed, not refused.');
        self::assertSame('supplier.code_is_unique_per_workspace', $this->json()['rule']);
        self::assertSame('Another supplier already uses this code. Choose a different code.', $this->json()['detail']);
        self::assertSame(1, $this->countAsOwnerForTenant(self::TENANT_A, "SELECT count(*) FROM supplier WHERE code = 'S-DUP'"));
    }

    /** Design §4.7 D3b: a taken NAME with a fresh code is the name's own rule and sentence, never the code's. */
    #[Test]
    public function aNameTakenByALiveSupplierIsRefusedByItsOwnRule(): void
    {
        $this->create(['code' => 'S-ONE', 'name' => 'Globex', 'country_code' => 'GB', 'currency_code' => 'GBP']);
        self::assertSame(201, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());

        $this->create(['code' => 'S-TWO', 'name' => 'Globex', 'country_code' => 'GB', 'currency_code' => 'GBP']);

        self::assertSame(409, $this->httpStatus());
        self::assertSame('supplier.name_is_unique_per_workspace', $this->json()['rule']);
        self::assertSame('Another supplier already uses this name. Choose a different name.', $this->json()['detail']);
        self::assertSame(0, $this->countAsOwnerForTenant(self::TENANT_A, "SELECT count(*) FROM supplier WHERE code = 'S-TWO'"));
    }

    /**
     * The name unique is **case-sensitive** (`supplier_tenant_name_live` on `name` as stored; LEAD decision 2026-10-12, owner
     * notice): `GLOBEX` beside `Globex` is accepted. If `UX`+`DATA` move the unique to `lower(name)`, this test is the one
     * to change, with the 409 copy.
     */
    #[Test]
    public function aCaseVariantNameIsAcceptedAsItsOwnSupplier(): void
    {
        $this->create(['code' => 'S-LOW', 'name' => 'Globex', 'country_code' => 'GB', 'currency_code' => 'GBP']);
        self::assertSame(201, $this->httpStatus());

        $this->create(['code' => 'S-UP', 'name' => 'GLOBEX', 'country_code' => 'GB', 'currency_code' => 'GBP']);

        self::assertSame(201, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        self::assertSame(2, $this->countAsOwnerForTenant(self::TENANT_A, "SELECT count(*) FROM supplier WHERE lower(name) = 'globex'"));
    }

    /** Both uniques are per workspace and among live rows: another workspace, or an archived supplier, frees code and name. */
    #[Test]
    public function aCodeAndANameAreUniqueAmongLiveSuppliersOfOneWorkspace(): void
    {
        $archived = $this->seedSupplier(self::TENANT_A, 'Freed Name');
        $this->insertAsOwnerForTenant(self::TENANT_A, "UPDATE supplier SET code = 'S-FREE', deleted_at = now() WHERE id = ?::uuid", [$archived]);
        $this->insertAsOwnerForTenant(self::TENANT_B, "UPDATE supplier SET code = 'S-THEIRS' WHERE id = ?::uuid", [$this->seedSupplier(self::TENANT_B, 'Their Name')]);

        $this->create(['code' => 'S-FREE', 'name' => 'Freed Name', 'country_code' => 'GB', 'currency_code' => 'GBP']);
        self::assertSame(201, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        $this->create(['code' => 'S-THEIRS', 'name' => 'Their Name', 'country_code' => 'GB', 'currency_code' => 'GBP']);
        self::assertSame(201, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
    }

    /** @return iterable<string, array{mixed, int}> */
    public static function terms(): iterable
    {
        yield 'zero' => [0, 201];
        yield 'ten years' => [3650, 201];
        yield 'negative' => [-1, 422];
        yield 'beyond ten years' => [3651, 422];
        yield 'a decimal' => [30.5, 422];
        yield 'a numeric string' => ['30', 422];
        yield 'a boolean' => [true, 422];
    }

    /** The literal edges; one code, `out_of_range`, one sentence. */
    #[Test]
    #[DataProvider('terms')]
    public function paymentTermsAreAWholeNumberOfDaysFromZeroToTenYears(mixed $terms, int $status): void
    {
        $tag = bin2hex(random_bytes(3));
        $this->create(['code' => 'S-T'.$tag, 'name' => 'Terms '.$tag, 'country_code' => 'GB', 'currency_code' => 'GBP', 'payment_terms_days' => $terms]);

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
    public function theTermsBoundIsTheSupplierConstant(): void
    {
        $this->create(['code' => 'S-MAX', 'name' => 'Max', 'country_code' => 'GB', 'currency_code' => 'GBP', 'payment_terms_days' => SupplierPaymentTerms::MAXIMUM_DAYS]);
        self::assertSame(201, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());

        $this->create(['code' => 'S-OVER', 'name' => 'Over', 'country_code' => 'GB', 'currency_code' => 'GBP', 'payment_terms_days' => SupplierPaymentTerms::MAXIMUM_DAYS + 1]);
        self::assertSame(422, $this->httpStatus());

        $this->create(['code' => 'S-MIN', 'name' => 'Min', 'country_code' => 'GB', 'currency_code' => 'GBP', 'payment_terms_days' => SupplierPaymentTerms::MINIMUM_DAYS]);
        self::assertSame(201, $this->httpStatus());
    }

    /** Required fields, blanks (stored as sent otherwise — no trim), shapes and the four-country limit. */
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

        $this->create(['code' => 'S-FR', 'name' => 'Paris', 'country_code' => 'ZZ', 'currency_code' => 'ZZZ']);
        self::assertSame([
            ['pointer' => '/country_code', 'code' => 'unknown_reference', 'message' => 'This country isn\'t supported yet.'],
            ['pointer' => '/currency_code', 'code' => 'unknown_reference', 'message' => 'This currency isn\'t supported yet.'],
        ], $this->fieldErrors());

        self::assertSame(0, $this->countAsOwnerForTenant(self::TENANT_A, 'SELECT count(*) FROM supplier'));
    }

    /**
     * One sentence per code across the house (customers C3 D-5): the supplier writer's copies of the shared sentences are
     * the customer writer's. A literal duplicated across a module boundary (Suppliers may not import Customers) is held
     * here, where deptrac does not look.
     */
    #[Test]
    public function theSharedRefusalSentencesAreTheCustomerWritersOwn(): void
    {
        self::assertSame(
            [CustomerWriter::TERMS_OUT_OF_RANGE, CustomerWriter::COUNTRY_UNSUPPORTED, CustomerWriter::CURRENCY_UNSUPPORTED, CustomerWriter::COUNTRY_SHAPE, CustomerWriter::CURRENCY_SHAPE],
            [SupplierWriter::TERMS_OUT_OF_RANGE, SupplierWriter::COUNTRY_UNSUPPORTED, SupplierWriter::CURRENCY_UNSUPPORTED, SupplierWriter::COUNTRY_SHAPE, SupplierWriter::CURRENCY_SHAPE],
        );
    }

    /** Owner Q-S6: `email`, `phone` and `is_active` are columns, not fields — sent, they are refused as undeclared (API-9). */
    #[Test]
    public function theUnwritableColumnsAreRefusedAsUndeclaredFields(): void
    {
        foreach (['email' => 'a@example.com', 'phone' => '+44 20 0000 0000', 'is_active' => false] as $field => $value) {
            $this->create(['code' => 'S-COL', 'name' => 'Columns', 'country_code' => 'GB', 'currency_code' => 'GBP', $field => $value]);
            self::assertSame(422, $this->httpStatus(), $field.': '.$this->client()->getResponse()->getContent());
            self::assertSame([['pointer' => '/'.$field, 'code' => 'undeclared_field', 'message' => 'The field "'.$field.'" is not accepted by this operation.']],
                $this->fieldErrors());
        }
        self::assertSame(0, $this->countAsOwnerForTenant(self::TENANT_A, 'SELECT count(*) FROM supplier'));

        $id = $this->seedSupplier(self::TENANT_A, 'Column Target');
        $this->update($id, ['email' => 'a@example.com']);
        self::assertSame(422, $this->httpStatus());
        self::assertSame('undeclared_field', $this->fieldErrors()[0]['code']);
        self::assertNull($this->fetchAsOwnerForTenant(self::TENANT_A, 'SELECT email FROM supplier WHERE id = ?::uuid', [$id])[0]['email']);
    }

    /** A value with surrounding spaces is stored as sent: the house refuses blanks and does not trim (`ItemWriter`). */
    #[Test]
    public function aValueIsStoredAsSent(): void
    {
        $this->create(['code' => 'S-SP ', 'name' => ' Spaced ', 'country_code' => 'GB', 'currency_code' => 'GBP']);

        self::assertSame(201, $this->httpStatus());
        self::assertSame(['S-SP ', ' Spaced '], [$this->json()['code'], $this->json()['name']]);
    }

    /** Each field is editable on a live supplier; the rest stay as they were; the body is `GetSupplier`'s. */
    #[Test]
    public function aSupplierIsUpdatedFieldByField(): void
    {
        $id = $this->seedSupplier(self::TENANT_A, 'Before');
        $code = $this->stored($id)['code'];
        $this->actAs(self::TENANT_A, DefaultRoles::codesFor(DefaultRoles::PURCHASING_MANAGER));

        $this->update($id, ['name' => 'After', 'payment_terms_days' => 60]);
        self::assertSame(200, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        self::assertSame(['id' => $id, 'code' => $code, 'name' => 'After', 'country_code' => 'GB', 'currency_code' => 'EUR',
            'payment_terms_days' => 60, 'archived_at' => null], $this->json());

        $this->update($id, ['code' => 'S-NEW', 'country_code' => 'US', 'currency_code' => 'USD']);
        self::assertSame(['S-NEW', 'US', 'USD', 'After', 60], [$this->json()['code'], $this->json()['country_code'], $this->json()['currency_code'], $this->json()['name'], $this->json()['payment_terms_days']]);

        $this->update($id, []);
        self::assertSame(200, $this->httpStatus(), 'An empty PATCH is legal: nothing is written, the row is read back.');
    }

    /** Explicit `null` clears nothing: all five are NOT NULL. */
    #[Test]
    public function noFieldCanBeCleared(): void
    {
        $id = $this->seedSupplier(self::TENANT_A, 'Kept');

        $this->update($id, ['code' => null, 'name' => null, 'country_code' => null, 'currency_code' => null, 'payment_terms_days' => null]);

        self::assertSame(422, $this->httpStatus());
        self::assertSame(array_map(static fn (string $field): array => ['pointer' => '/'.$field, 'code' => 'not_nullable', 'message' => 'This field may not be cleared.'],
            ['code', 'name', 'country_code', 'currency_code', 'payment_terms_days']), $this->fieldErrors());

        $this->update($id, ['name' => ' ']);
        self::assertSame([['pointer' => '/name', 'code' => 'blank', 'message' => 'This field may not be blank.']], $this->fieldErrors());
    }

    /** A recode onto a live supplier's code is refused, with the update's own sentence. */
    #[Test]
    public function aRecodeOntoATakenCodeIsRefused(): void
    {
        $taken = $this->stored($this->seedSupplier(self::TENANT_A, 'Code Holder'))['code'];
        $id = $this->seedSupplier(self::TENANT_A, 'Recoder');

        $this->update($id, ['code' => $taken]);

        self::assertSame(409, $this->httpStatus());
        self::assertSame('supplier.code_is_unique_per_workspace', $this->json()['rule']);
        self::assertSame('Another supplier already uses this code. Keep the current code or choose a different one.', $this->json()['detail']);
    }

    /** A rename onto a live supplier's name is refused by the name's rule, with the update's own sentence. */
    #[Test]
    public function aRenameOntoATakenNameIsRefused(): void
    {
        $this->seedSupplier(self::TENANT_A, 'Name Holder');
        $id = $this->seedSupplier(self::TENANT_A, 'Renamer');

        $this->update($id, ['name' => 'Name Holder']);

        self::assertSame(409, $this->httpStatus());
        self::assertSame('supplier.name_is_unique_per_workspace', $this->json()['rule']);
        self::assertSame('Another supplier already uses this name. Keep the current name or choose a different one.', $this->json()['detail']);
        self::assertSame('Renamer', $this->stored($id)['name']);
    }

    /** ST-9: an archived supplier is read-only (reachable only by SQL until `DeleteSupplier` ships). No "Restore it". */
    #[Test]
    public function anArchivedSupplierCannotBeChanged(): void
    {
        $id = $this->seedSupplier(self::TENANT_A, 'Closed');
        $this->insertAsOwnerForTenant(self::TENANT_A, 'UPDATE supplier SET deleted_at = now() WHERE id = ?::uuid', [$id]);

        $this->update($id, ['name' => 'Reopened']);

        self::assertSame(409, $this->httpStatus());
        self::assertSame('supplier.is_archived', $this->json()['rule']);
        self::assertSame('This supplier is archived, so it can\'t be changed.', $this->json()['detail']);
        self::assertSame('Closed', $this->stored($id)['name']);
    }

    /** ST-5: a foreign, absent or malformed id is the same 404 as `GetSupplier`'s. */
    #[Test]
    public function anUpdateOfAnUnknownSupplierIsTheSame404(): void
    {
        $bodies = [];
        foreach ([$this->seedSupplier(self::TENANT_B, 'Theirs'), Uuid::v4()->toRfc4122(), 'not-a-uuid'] as $id) {
            $this->update($id, ['name' => 'Mine now']);
            self::assertSame(404, $this->httpStatus(), $id);
            $bodies[] = (string) $this->client()->getResponse()->getContent();
        }
        self::assertSame($bodies[0], $bodies[1]);
        self::assertSame(1, $this->countAsOwnerForTenant(self::TENANT_B, "SELECT count(*) FROM supplier WHERE name = 'Theirs'"));
    }

    /**
     * Design §4.3: every field stays editable with an issued purchase order — the order carries its own currency. A
     * rename **relabels** the order, which reads the supplier's name through a live join (`RESIDUAL_GAPS.md` G-40, G-38's
     * twin): pinned so a snapshot added later is a decision, not a surprise.
     */
    #[Test]
    public function anIssuedPurchaseOrderKeepsItsCurrencyAndShowsTheNewName(): void
    {
        $supplier = $this->seedSupplier(self::TENANT_A, 'Old Supplier Name');
        $order = $this->seedOrder(self::TENANT_A, 'PO-000777', $supplier, $this->seedWarehouse(self::TENANT_A, 'WH Rename'), status: PurchaseOrder::STATUS_ORDERED);

        $this->update($supplier, ['currency_code' => 'USD', 'payment_terms_days' => 90, 'name' => 'New Supplier Name']);
        self::assertSame(200, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());

        self::assertSame('EUR', $this->fetchAsOwnerForTenant(self::TENANT_A, 'SELECT currency_code FROM purchase_order WHERE id = ?::uuid', [$order])[0]['currency_code'],
            'Changing the supplier changed an issued order\'s currency.');
        $this->send('GET', '/purchase-orders/'.$order);
        self::assertSame(200, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        self::assertSame(['id' => $supplier, 'name' => 'New Supplier Name'], $this->json()['supplier'], 'G-40: the order no longer reads the live name.');
    }

    /** Permissions: create needs `supplier.create`, update `supplier.edit`; `viewer` holds neither. */
    #[Test]
    public function aViewerCanNeitherCreateNorChangeASupplier(): void
    {
        $id = $this->seedSupplier(self::TENANT_A, 'Untouched');
        $this->actAs(self::TENANT_A, DefaultRoles::codesFor(DefaultRoles::VIEWER));

        $this->create(['code' => 'S-V', 'name' => 'V', 'country_code' => 'GB', 'currency_code' => 'GBP']);
        self::assertSame(403, $this->httpStatus());
        $this->update($id, ['name' => 'Touched']);
        self::assertSame(403, $this->httpStatus());
        self::assertSame('Untouched', $this->stored($id)['name']);
    }

    /**
     * Final RED M-A: of the four supplier events only `.created` and `.updated` are minted — in the code catalogue AND in
     * the live CHECK. `.deleted`/`.restored` arrive with their writers (the later archived-view slice), never ahead of them.
     */
    #[Test]
    public function onlyTheCreateAndUpdateSupplierEventsAreMinted(): void
    {
        $definition = (string) $this->fetchAsOwnerForTenant(self::TENANT_A,
            "SELECT pg_get_constraintdef(oid) AS d FROM pg_constraint WHERE conname = 'audit_log_event_type_values'")[0]['d'];
        self::assertSame(1, preg_match_all("/'customer\\.created'/", $definition), 'Control: the live catalogue was not read.');

        preg_match_all("/'(supplier\\.[a-z_]+)'/", $definition, $live);
        $code = array_values(array_filter(AuditEvent::ALL, static fn (string $event): bool => str_starts_with($event, 'supplier.')));
        sort($code);
        $liveMembers = $live[1];
        sort($liveMembers);

        self::assertSame(['supplier.created', 'supplier.updated'], $code, 'AuditEvent mints a supplier event without its writer (M-A).');
        self::assertSame(['supplier.created', 'supplier.updated'], $liveMembers, 'The live CHECK admits a supplier event without its writer (M-A).');
    }

    /** One `supplier.created`, `{}`, naming the creator; the subject is the new supplier. */
    #[Test]
    public function aCreatedSupplierWritesExactlyOneSupplierCreatedRow(): void
    {
        $this->actAs(self::TENANT_A, DefaultRoles::codesFor(DefaultRoles::BUYER));

        $this->create(['code' => 'S-AUD', 'name' => 'Audited', 'country_code' => 'GB', 'currency_code' => 'GBP', 'payment_terms_days' => 3650]);

        self::assertSame(201, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        self::assertSame([$this->businessRow(AuditEvent::SUPPLIER_CREATED, 'supplier', $this->json()['id'])],
            $this->auditRowsOf(AuditEvent::SUPPLIER_CREATED));
        self::assertSame([], $this->auditRowsOf(AuditEvent::SUPPLIER_UPDATED), 'A create wrote an update row.');
    }

    /** A create refused for a taken code or name (409, caught outside `run()`) or for its shape (422) writes no row. */
    #[Test]
    public function aCreateRefusedForATakenCodeOrNameWritesNoRow(): void
    {
        $this->create(['code' => 'S-ONCE', 'name' => 'First Once', 'country_code' => 'GB', 'currency_code' => 'GBP']);
        self::assertSame(201, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        self::assertCount(1, $this->auditRowsOf(AuditEvent::SUPPLIER_CREATED));

        $this->create(['code' => 'S-ONCE', 'name' => 'Second', 'country_code' => 'GB', 'currency_code' => 'GBP']);
        self::assertSame(409, $this->httpStatus());
        $this->create(['code' => 'S-OTHER', 'name' => 'First Once', 'country_code' => 'GB', 'currency_code' => 'GBP']);
        self::assertSame(409, $this->httpStatus());
        $this->create(['code' => 'S-BAD', 'name' => 'Bad', 'country_code' => 'ZZ', 'currency_code' => 'GBP']);
        self::assertSame(422, $this->httpStatus());
        $this->create(['code' => 'S-BAD', 'name' => 'Bad', 'country_code' => 'GB', 'currency_code' => 'GBP', 'payment_terms_days' => 3651]);
        self::assertSame(422, $this->httpStatus());

        self::assertCount(1, $this->auditRowsOf(AuditEvent::SUPPLIER_CREATED), 'A refused create wrote a supplier.created row.');
    }

    /** All five submitted, one changed: the row names that one field; a second case names two, sorted. */
    #[Test]
    public function anUpdateWritesOneRowNamingOnlyTheChangedFields(): void
    {
        $id = $this->seedSupplier(self::TENANT_A, 'Stable');
        $stored = $this->stored($id);
        $this->actAs(self::TENANT_A, DefaultRoles::codesFor(DefaultRoles::BUYER));

        $this->update($id, ['payment_terms_days' => 3650] + $stored);
        self::assertSame(200, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        self::assertSame([$this->updatedRow($id, ['payment_terms_days'])], $this->auditRowsOf(AuditEvent::SUPPLIER_UPDATED));

        $this->update($id, ['name' => 'Renamed Stable', 'code' => 'S-RENAMED']);
        self::assertSame(200, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        self::assertSame([$this->updatedRow($id, ['payment_terms_days']), $this->updatedRow($id, ['code', 'name'])],
            $this->auditRowsOf(AuditEvent::SUPPLIER_UPDATED));
        self::assertSame([], $this->auditRowsOf(AuditEvent::SUPPLIER_CREATED), 'An update wrote a create row.');
    }

    /** All five submitted equal to the stored values: no row, and no `UPDATE supplier` statement is issued. */
    #[Test]
    public function aNoOpUpdateWritesNoRowAndNoStatement(): void
    {
        $id = $this->seedSupplier(self::TENANT_A, 'Unchanged');
        $stored = $this->stored($id);
        $before = $this->fetchAsOwnerForTenant(self::TENANT_A, 'SELECT updated_at::text AS updated_at FROM supplier WHERE id = ?::uuid', [$id]);

        $this->recorder()->start();
        $this->update($id, $stored);
        $this->recorder()->stop();

        self::assertSame(200, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        self::assertSame(['id' => $id] + $stored + ['archived_at' => null], $this->json(), 'A no-op update must answer the unchanged row.');
        self::assertSame([], $this->recorder()->matching('/\bUPDATE\s+supplier\b/i'), 'A no-op update issued an UPDATE.');
        self::assertSame([], $this->auditRowsOf(AuditEvent::SUPPLIER_UPDATED), 'A no-op update wrote a supplier.updated row.');
        self::assertSame($before, $this->fetchAsOwnerForTenant(self::TENANT_A, 'SELECT updated_at::text AS updated_at FROM supplier WHERE id = ?::uuid', [$id]));
    }

    /** A recode or rename onto a taken value (409), an archived supplier (409) and an unsupported country (422) write no row. */
    #[Test]
    public function aRefusedUpdateWritesNoRow(): void
    {
        $holder = $this->seedSupplier(self::TENANT_A, 'Holder');
        $taken = $this->stored($holder)['code'];
        $id = $this->seedSupplier(self::TENANT_A, 'Renamer');
        $archived = $this->seedSupplier(self::TENANT_A, 'Archived');
        $this->insertAsOwnerForTenant(self::TENANT_A, 'UPDATE supplier SET deleted_at = now() WHERE id = ?::uuid', [$archived]);

        $this->update($id, ['code' => $taken, 'payment_terms_days' => 90]);
        self::assertSame(409, $this->httpStatus());
        $this->update($id, ['name' => 'Holder', 'payment_terms_days' => 90]);
        self::assertSame(409, $this->httpStatus());
        $this->update($archived, ['name' => 'Reopened']);
        self::assertSame(409, $this->httpStatus());
        $this->update($id, ['country_code' => 'ZZ', 'name' => 'Also renamed']);
        self::assertSame(422, $this->httpStatus());

        self::assertSame([], $this->auditRowsOf(AuditEvent::SUPPLIER_UPDATED), 'A refused update wrote a supplier.updated row.');
        self::assertSame(30, $this->stored($id)['payment_terms_days'], 'A refused update changed the row.');
    }

    /** `metadata` is `{fields}` and nothing else, and no submitted value appears in it. */
    #[Test]
    public function theUpdatedRowCarriesNamesNeverValues(): void
    {
        $id = $this->seedSupplier(self::TENANT_A, 'Valued');

        $this->update($id, ['name' => 'Zebra Holdings Secret', 'payment_terms_days' => 3650, 'currency_code' => 'USD']);
        self::assertSame(200, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());

        $rows = $this->auditRowsOf(AuditEvent::SUPPLIER_UPDATED);
        self::assertCount(1, $rows);
        $metadata = json_decode($rows[0]['metadata'], true, flags: \JSON_THROW_ON_ERROR);
        self::assertSame(['fields'], array_keys($metadata), 'supplier.updated carries a key other than fields.');
        self::assertSame(['currency_code', 'name', 'payment_terms_days'], $metadata['fields']);
        foreach (['3650', 'Zebra', 'USD', 'Valued'] as $value) {
            self::assertStringNotContainsString($value, $rows[0]['metadata'], 'A value reached supplier.updated\'s metadata.');
        }
    }

    /**
     * The race the row lock exists for (`CustomerWriteTest` 7.7's twin). A second connection resets the terms to 3650 and
     * holds the row; this request sends `{payment_terms_days: 30, name}` against a row stored at 30. Locked, the request
     * waits, then diffs against 3650: both fields change, the row ends at 30, and the request's row names both. Unlocked,
     * it diffs against the uncommitted 30, leaves the terms out of its `SET`, and the row keeps 3650 with nobody's name on
     * the reset.
     */
    #[Test]
    public function aTermsResetRacingATermsChangeIsAttributedToTheResetter(): void
    {
        $id = $this->seedSupplier(self::TENANT_A, 'Raced');
        self::assertSame(30, $this->stored($id)['payment_terms_days'], 'Fixture: the seeded terms must be 30, or removing the lock cannot redden this test.');

        $holder = $this->raceAgainstAHolder(
            [['UPDATE supplier SET payment_terms_days = 3650 WHERE id = ?::uuid', [$id]]],
            function () use ($id): void {
                $this->update($id, ['payment_terms_days' => 30, 'name' => 'Raced Renamed']);
            },
        );

        self::assertTrue($holder['waiter_seen'], 'The request never waited on the holder, so nothing here was a race: '.json_encode($holder));
        self::assertTrue($holder['committed'], 'The holder did not commit: '.json_encode($holder));
        self::assertSame(200, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        self::assertSame(30, $this->stored($id)['payment_terms_days'], 'The request did not re-apply its terms over the holder\'s reset.');
        self::assertSame([$this->updatedRow($id, ['name', 'payment_terms_days'])], $this->auditRowsOf(AuditEvent::SUPPLIER_UPDATED),
            'The request\'s supplier.updated row does not name the terms it changed back.');
    }

    // -----------------------------------------------------------------------

    /** @param array<string, mixed> $body */
    private function create(array $body): void
    {
        $this->send('POST', '/suppliers', $body, ['Idempotency-Key' => Uuid::v4()->toRfc4122()]);
    }

    /** @param array<string, mixed> $body */
    private function update(string $id, array $body): void
    {
        $this->send('PATCH', '/suppliers/'.$id, $body);
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
            'SELECT code, name, country_code, currency_code, payment_terms_days FROM supplier WHERE id = ?::uuid', [$id])[0];
        $row['payment_terms_days'] = (int) $row['payment_terms_days'];

        return $row;
    }

    /**
     * @param list<string> $fields
     *
     * @return array<string, ?string>
     */
    private function updatedRow(string $id, array $fields): array
    {
        return array_replace($this->businessRow(AuditEvent::SUPPLIER_UPDATED, 'supplier', $id), [
            'metadata' => (string) $this->fetchAsOwnerForTenant(self::TENANT_A, 'SELECT ?::jsonb::text AS m',
                [json_encode(['fields' => $fields], \JSON_THROW_ON_ERROR)])[0]['m'],
        ]);
    }

    /**
     * `CustomerWriteTest::raceAgainstAHolder`'s shape (the holder's `HOLD_TABLE` whitelist names no `supplier`, which must NOT
     * be added): the holder's own lock is on a random `purchase_order` id, so it matches no row; the real lock is
     * `HOLD_BEFORE`'s `UPDATE supplier`.
     *
     * @param list<array{0: string, 1: list<string>}> $before
     *
     * @return array{waiter_seen: bool, committed: bool, sqlstate: ?string, error: ?string}
     */
    private function raceAgainstAHolder(array $before, callable $request): array
    {
        $readyFile = tempnam(sys_get_temp_dir(), 'erp_supplier_');
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
