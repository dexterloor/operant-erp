<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Modules\Customers\Application\CustomerContactWriter;
use App\Modules\Customers\Application\CustomerWriter;
use App\Modules\UserManagement\Domain\DefaultRoles;
use App\Platform\Http\Idempotency\IdempotencyKeyGuard;
use App\Platform\Http\Idempotency\IdempotencyKeyHash;
use App\Platform\Http\Idempotency\RequestDigest;
use App\SharedKernel\ValueObject\AuditEvent;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Uid\Uuid;

/**
 * **Customers slice 2 C1 — `CreateCustomerContact`, `UpdateCustomerContact`** (`docs/session13/customers-s2-design.md`
 * Revision 2 R2.2 / R2.3 / R2.4 C1; owner rulings `s35-ruling.md` Q-1 (a) one primary, marking another moves it; Q-2 yes,
 * record contact changes; SEC `customers-s2-sec-arch.md` S-1, S-4, S-6). {@see CustomerAddressWriteTest}'s shape.
 *
 * Each refusal's literal sentence is pinned once here (UX TO CONFIRM every one). Phones carry letters or punctuation (CD-3).
 *
 * **The race tests assert WHERE the request waited** (final RED F-H1): the holder reports the waiter's `pg_locks` rows at
 * the moment it saw it, and a row-lock wait is a `tuple` lock on the expected relation plus an ungranted `transactionid` on
 * the holder's xid; a wait at a unique index holds no tuple lock. Under Q-1 (a) every contact write queues on the CUSTOMER
 * row first, so the two-primaries races (M1-5's carriers) and the archive races assert a wait on `customer`; the
 * cross-customer insert race (M1-1's carrier) waits at `customer_contact_idem_unique` instead.
 *
 * **Review checks, not evidence, under Q-1 (a)** (`ARCH` A-3, F-H1 option ii): M1-14 (drop `FOR KEY SHARE` on the key
 * read) and M1-15 (demote before the INSERT) change nothing a request can observe, because the parent lock serialises
 * every contact write of one customer first. They are recorded in the build progress note, not tested.
 */
final class CustomerContactWriteTest extends PurchaseOrderTestCase
{
    private const HOLDER_DEADLINE_SECONDS = 10.0;

    private const BODY = ['name' => 'Ada Lovelace', 'email' => 'ada@example.test', 'phone' => '+44 20 7946 0000', 'is_primary' => false];

    /** 201, the contact as the body, `Location` naming the row-level URI §4.2 declares (no `GetCustomerContact`). */
    #[Test]
    public function aSalesRepAddsAContactToACustomer(): void
    {
        $customer = $this->seedCustomer(self::TENANT_A, 'Contact Owner');
        $this->actAs(self::TENANT_A, DefaultRoles::codesFor(DefaultRoles::SALES_REP));

        $this->create($customer, self::BODY);

        self::assertSame(201, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        $body = $this->json();
        self::assertTrue(Uuid::isValid($body['id']));
        self::assertSame(['id' => $body['id']] + self::BODY, $body);
        self::assertSame('/api/v1/customers/'.$customer.'/contacts/'.$body['id'], $this->client()->getResponse()->headers->get('Location'));

        $stored = $this->fetchAsOwnerForTenant(self::TENANT_A,
            'SELECT customer_id::text AS c, idempotency_key ~ \'^[0-9a-f]{64}$\' AS hashed, request_digest ~ \'^[0-9a-f]{64}$\' AS digested FROM customer_contact WHERE id = ?::uuid', [$body['id']])[0];
        self::assertSame(['c' => $customer, 'hashed' => true, 'digested' => true], $stored, 'The key is stored hashed, with a digest.');
    }

    /** Only `name` is required: a name-only contact is valid, `email`/`phone` null and not primary (design §6). */
    #[Test]
    public function aNameOnlyContactIsValid(): void
    {
        $customer = $this->seedCustomer(self::TENANT_A, 'Name Only');

        $this->create($customer, ['name' => ' Maria at reception ']);

        self::assertSame(201, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        self::assertSame(['name' => ' Maria at reception ', 'email' => null, 'phone' => null, 'is_primary' => false],
            array_diff_key($this->json(), ['id' => true]), 'Defaults applied; the name stored as sent (no trim).');
    }

    /** Shape, field by field (design §6, §8). */
    #[Test]
    public function aMalformedContactIsRefusedFieldByField(): void
    {
        $customer = $this->seedCustomer(self::TENANT_A, 'Strict Contact');

        $this->create($customer, []);
        self::assertSame(422, $this->httpStatus());
        self::assertSame([['pointer' => '/name', 'code' => 'required', 'message' => 'This field is required.']], $this->fieldErrors());

        $this->create($customer, ['name' => ' ', 'email' => 'no-at-sign', 'phone' => '  ', 'is_primary' => 'yes']);
        self::assertSame(422, $this->httpStatus());
        self::assertSame([
            ['pointer' => '/name', 'code' => 'blank', 'message' => 'This field may not be blank.'],
            ['pointer' => '/email', 'code' => 'not_an_email', 'message' => 'Enter an email address like name@example.com.'],
            ['pointer' => '/phone', 'code' => 'blank', 'message' => 'This field may not be blank.'],
            ['pointer' => '/is_primary', 'code' => 'not_a_boolean', 'message' => 'This field must be true or false.'],
        ], $this->fieldErrors());

        foreach (['a@b@c', '@example.test', 'ada@', str_repeat('a', 250).'@b.cd'] as $email) {
            $this->create($customer, ['name' => 'Ada', 'email' => $email]);
            self::assertSame([['pointer' => '/email', 'code' => 'not_an_email', 'message' => CustomerContactWriter::NOT_AN_EMAIL]], $this->fieldErrors(), $email);
        }

        $this->create($customer, ['name' => 'Ada', 'phone' => str_repeat('1-', 25).'1']);
        self::assertSame([['pointer' => '/phone', 'code' => 'too_long', 'message' => 'A phone number can be at most 50 characters.']], $this->fieldErrors());

        $this->create($customer, ['name' => 'Ada', 'is_primary' => null]);
        self::assertSame([['pointer' => '/is_primary', 'code' => 'not_nullable', 'message' => 'This field may not be cleared.']], $this->fieldErrors());

        self::assertSame(0, $this->countAsOwnerForTenant(self::TENANT_A, 'SELECT count(*) FROM customer_contact'));
    }

    /** SEC S-6.4: a 422 names the pointer and the code, never the submitted value — an e-mail is personal data. */
    #[Test]
    public function aMalformedEmailIsNeverEchoed(): void
    {
        $customer = $this->seedCustomer(self::TENANT_A, 'No Echo');
        $marker = 'MARKER'.bin2hex(random_bytes(6));

        $this->create($customer, ['name' => 'Ada', 'email' => $marker.'-not-an-email', 'phone' => ' ']);

        self::assertSame(422, $this->httpStatus());
        self::assertStringNotContainsString($marker, (string) $this->client()->getResponse()->getContent());
    }

    /**
     * ST-5 and SEC S-6.1: an unknown, malformed or another workspace's customer is the same 404, nothing is written — and a
     * parent 404 and a pair 404 (another customer's contact under this path) are the same body.
     */
    #[Test]
    public function everyNotFoundIsTheSameBody(): void
    {
        $bodies = [];
        foreach ([$this->seedCustomer(self::TENANT_B, 'Theirs'), Uuid::v4()->toRfc4122(), 'not-a-uuid'] as $customer) {
            $this->create($customer, self::BODY);
            self::assertSame(404, $this->httpStatus(), $customer);
            $bodies[] = (string) $this->client()->getResponse()->getContent();
        }
        self::assertSame($bodies[0], $bodies[1]);
        self::assertSame(0, $this->countAsOwnerForTenant(self::TENANT_B, 'SELECT count(*) FROM customer_contact'));

        $mine = $this->seedCustomer(self::TENANT_A, 'Pair Mine');
        $other = $this->seedCustomer(self::TENANT_A, 'Pair Other');
        $contact = $this->seedCustomerContact(self::TENANT_A, $other);
        $this->update($mine, $contact, ['name' => 'Hijacked']);
        self::assertSame(404, $this->httpStatus());
        self::assertSame($bodies[0], (string) $this->client()->getResponse()->getContent(), 'A pair 404 differs from a parent 404.');
        $this->update(Uuid::v4()->toRfc4122(), $contact, ['name' => 'Hijacked']);
        self::assertSame($bodies[0], (string) $this->client()->getResponse()->getContent(), 'A parent 404 on update differs.');
    }

    /** ST-9: an archived customer gets no new contact. */
    #[Test]
    public function anArchivedCustomerGetsNoNewContact(): void
    {
        $customer = $this->archivedCustomer('Closed For Contacts');

        $this->create($customer, self::BODY);

        self::assertSame(409, $this->httpStatus());
        self::assertSame('customer.is_archived', $this->json()['rule']);
        self::assertSame('This customer is archived, so no contact can be added to it until it\'s restored.', $this->json()['detail']);
        self::assertSame(0, $this->countAsOwnerForTenant(self::TENANT_A, 'SELECT count(*) FROM customer_contact WHERE customer_id = ?::uuid', [$customer]));
    }

    /** The same key and body replay the stored contact (same id, one row); another body is 409; no header is 428. */
    #[Test]
    public function aRetryReplaysAndAnotherBodyUnderTheKeyConflicts(): void
    {
        $customer = $this->seedCustomer(self::TENANT_A, 'Retried Contact');
        $key = Uuid::v4()->toRfc4122();

        $this->create($customer, self::BODY, $key);
        self::assertSame(201, $this->httpStatus());
        $first = $this->json();

        $this->create($customer, self::BODY, $key);
        self::assertSame(201, $this->httpStatus());
        self::assertSame($first, $this->json(), 'A retry was not handed the stored contact.');

        $this->create($customer, ['phone' => 'ext. 99'] + self::BODY, $key);
        self::assertSame(409, $this->httpStatus(), 'A different body under a used key was not refused.');
        self::assertSame('urn:erp:problem:idempotency-key-conflict', $this->json()['type']);

        $this->send('POST', '/customers/'.$customer.'/contacts', self::BODY);
        self::assertSame(428, $this->httpStatus(), 'The header is demanded.');

        self::assertSame(1, $this->countAsOwnerForTenant(self::TENANT_A, 'SELECT count(*) FROM customer_contact WHERE customer_id = ?::uuid', [$customer]));
    }

    /** Absent optional fields digest as their defaults: `{name}` and `{name, email: null, is_primary: false}` are one request. */
    #[Test]
    public function absentOptionalFieldsAreTheSameRequestAsTheirDefaults(): void
    {
        $customer = $this->seedCustomer(self::TENANT_A, 'Defaulted');
        $key = Uuid::v4()->toRfc4122();

        $this->create($customer, ['name' => 'Ada'], $key);
        self::assertSame(201, $this->httpStatus());
        $first = $this->json();

        $this->create($customer, ['name' => 'Ada', 'email' => null, 'is_primary' => false], $key);
        self::assertSame(201, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        self::assertSame($first, $this->json());
    }

    /**
     * The digest carries the PATH's customer id (M1-4): the same key and body sent to another customer is a conflict, never a
     * replay — and the 409 carries nothing of the stored row (SEC S-6.3).
     */
    #[Test]
    public function theSameKeyOnAnotherCustomerConflictsAndDisclosesNothing(): void
    {
        $first = $this->seedCustomer(self::TENANT_A, 'First Contact Keyed');
        $second = $this->seedCustomer(self::TENANT_A, 'Second Contact Keyed');
        $key = Uuid::v4()->toRfc4122();

        $this->create($first, self::BODY, $key);
        self::assertSame(201, $this->httpStatus());
        $stored = $this->json()['id'];

        $this->create($second, self::BODY, $key);
        self::assertSame(409, $this->httpStatus(), 'The same key on another customer was replayed or created, not refused.');
        self::assertSame('urn:erp:problem:idempotency-key-conflict', $this->json()['type']);
        $raw = (string) $this->client()->getResponse()->getContent();
        foreach ([$stored, $first, 'Ada', 'ada@example.test'] as $leak) {
            self::assertStringNotContainsString($leak, $raw, 'The conflict disclosed something of the stored row.');
        }
        self::assertSame(0, $this->countAsOwnerForTenant(self::TENANT_A, 'SELECT count(*) FROM customer_contact WHERE customer_id = ?::uuid', [$second]));
    }

    /** The key is read BEFORE the archived guard (M1-3): a stored key replays 201 on a since-archived customer. */
    #[Test]
    public function aRetryAfterTheCustomerWasArchivedStillReplays(): void
    {
        $customer = $this->seedCustomer(self::TENANT_A, 'Contact Archived After');
        $key = Uuid::v4()->toRfc4122();
        $this->create($customer, self::BODY, $key);
        self::assertSame(201, $this->httpStatus());
        $first = $this->json();

        $this->insertAsOwnerForTenant(self::TENANT_A, 'UPDATE customer SET deleted_at = now() WHERE id = ?::uuid', [$customer]);

        $this->create($customer, self::BODY, $key);
        self::assertSame(201, $this->httpStatus(), 'A retry of a create that succeeded was refused because the customer was archived since.');
        self::assertSame($first, $this->json());

        $this->create($customer, self::BODY);
        self::assertSame(409, $this->httpStatus());
        self::assertSame('customer.is_archived', $this->json()['rule']);
    }

    /** Owner Q-1 (a): adding a primary contact moves the mark — the old primary is un-marked in the same request. */
    #[Test]
    public function addingAPrimaryContactMovesTheMark(): void
    {
        $customer = $this->seedCustomer(self::TENANT_A, 'Primary Moves');
        $old = $this->seedCustomerContact(self::TENANT_A, $customer, 'Old Primary', null, null, true);
        $other = $this->seedCustomerContact(self::TENANT_A, $this->seedCustomer(self::TENANT_A, 'Other Primary'), 'Elsewhere', null, null, true);

        $this->create($customer, ['is_primary' => true] + self::BODY);

        self::assertSame(201, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        self::assertTrue($this->json()['is_primary']);
        self::assertSame([$this->json()['id']], $this->primariesOf($customer), 'Exactly the new contact is primary.');
        self::assertFalse($this->storedContact($old)['is_primary']);
        self::assertTrue($this->storedContact($other)['is_primary'], 'Another customer\'s primary was un-marked.');
    }

    /** Each field is editable; `email`/`phone` clear with `null`; `name`/`is_primary` refuse it; an empty PATCH is legal. */
    #[Test]
    public function aContactIsChangedFieldByField(): void
    {
        $customer = $this->seedCustomer(self::TENANT_A, 'Recontacted');
        $contact = $this->seedCustomerContact(self::TENANT_A, $customer);
        $this->actAs(self::TENANT_A, DefaultRoles::codesFor(DefaultRoles::SALES_REP));

        $this->update($customer, $contact, ['name' => 'Ada King', 'email' => 'ada.king@example.test']);
        self::assertSame(200, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        self::assertSame(['id' => $contact, 'name' => 'Ada King', 'email' => 'ada.king@example.test', 'phone' => '+44 20 7946 0000', 'is_primary' => false], $this->json());

        $this->update($customer, $contact, ['email' => null, 'phone' => null]);
        self::assertSame(200, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        self::assertSame([null, null], [$this->json()['email'], $this->json()['phone']], 'email and phone clear with null.');

        $this->update($customer, $contact, ['name' => null, 'is_primary' => null]);
        self::assertSame(422, $this->httpStatus());
        self::assertSame(array_map(static fn (string $f): array => ['pointer' => '/'.$f, 'code' => 'not_nullable', 'message' => 'This field may not be cleared.'],
            ['name', 'is_primary']), $this->fieldErrors());

        $this->update($customer, $contact, []);
        self::assertSame(200, $this->httpStatus(), 'An empty PATCH is legal: nothing is written, the row is read back.');
    }

    /** Marking a contact primary moves the mark; un-marking the primary leaves none (nothing is promoted). */
    #[Test]
    public function markingAndUnmarkingThePrimary(): void
    {
        $customer = $this->seedCustomer(self::TENANT_A, 'Marked');
        $first = $this->seedCustomerContact(self::TENANT_A, $customer, 'First', null, null, true);
        $second = $this->seedCustomerContact(self::TENANT_A, $customer, 'Second');

        $this->update($customer, $second, ['is_primary' => true, 'name' => 'Second Renamed']);
        self::assertSame(200, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        self::assertSame([$second], $this->primariesOf($customer));

        $this->update($customer, $second, ['is_primary' => false]);
        self::assertSame(200, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        self::assertSame([], $this->primariesOf($customer), 'Un-marking the primary promoted another contact.');
        self::assertFalse($this->storedContact($first)['is_primary']);
    }

    /** ST-9: an archived customer's contact cannot be changed (and the pair is read first: another's contact is the 404). */
    #[Test]
    public function anArchivedCustomersContactCannotBeChanged(): void
    {
        $customer = $this->seedCustomer(self::TENANT_A, 'Archived Contact Owner');
        $contact = $this->seedCustomerContact(self::TENANT_A, $customer);
        $this->insertAsOwnerForTenant(self::TENANT_A, 'UPDATE customer SET deleted_at = now() WHERE id = ?::uuid', [$customer]);

        $this->update($customer, $contact, ['name' => 'Reopened']);

        self::assertSame(409, $this->httpStatus());
        self::assertSame('customer.is_archived', $this->json()['rule']);
        self::assertSame('This customer is archived, so its contacts can\'t be changed until it\'s restored.', $this->json()['detail']);
        self::assertSame('Ada Lovelace', $this->storedContact($contact)['name']);
    }

    /** `viewer` holds `customer.view`, not `customer.edit`: it reads contacts but can neither add nor change one. */
    #[Test]
    public function aViewerCanNeitherAddNorChangeAContact(): void
    {
        $customer = $this->seedCustomer(self::TENANT_A, 'Viewed Contacts');
        $contact = $this->seedCustomerContact(self::TENANT_A, $customer);
        $this->actAs(self::TENANT_A, DefaultRoles::codesFor(DefaultRoles::VIEWER));

        $this->create($customer, self::BODY);
        self::assertSame(403, $this->httpStatus());
        $this->update($customer, $contact, ['name' => 'Viewer Edit']);
        self::assertSame(403, $this->httpStatus());
        $this->send('GET', '/customers/'.$customer.'/contacts');
        self::assertSame(200, $this->httpStatus());
    }

    // ---- the trail (owner Q-2) ---------------------------------------------------------------------------------------------

    /** One `customer_contact.created`, `{}`, naming the creator; the subject is the new contact. */
    #[Test]
    public function aCreatedContactWritesExactlyOneCustomerContactCreatedRow(): void
    {
        $customer = $this->seedCustomer(self::TENANT_A, 'Audited Contact');
        $this->actAs(self::TENANT_A, DefaultRoles::codesFor(DefaultRoles::SALES_REP));

        $this->create($customer, ['is_primary' => true] + self::BODY);

        self::assertSame(201, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        self::assertSame([$this->businessRow(AuditEvent::CUSTOMER_CONTACT_CREATED, 'customer_contact', $this->json()['id'])],
            $this->auditRowsOf(AuditEvent::CUSTOMER_CONTACT_CREATED));
        self::assertSame([], $this->auditRowsOf(AuditEvent::CUSTOMER_CONTACT_UPDATED), 'A create with nothing to demote wrote an update row.');
        self::assertSame([], $this->auditRowsOf(AuditEvent::CUSTOMER_UPDATED), 'A contact create wrote a customer.updated row.');
    }

    /** A replay inserts nothing, so it writes nothing; every refusal writes no row either. */
    #[Test]
    public function aReplayedOrRefusedContactCreateWritesNoRow(): void
    {
        $customer = $this->seedCustomer(self::TENANT_A, 'Replayed Contact');
        $key = Uuid::v4()->toRfc4122();

        $this->create($customer, self::BODY, $key);
        self::assertSame(201, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        self::assertCount(1, $this->auditRowsOf(AuditEvent::CUSTOMER_CONTACT_CREATED));

        $this->create($customer, self::BODY, $key);
        self::assertSame(201, $this->httpStatus(), 'Fixture: the same key and body must replay.');
        $this->create($customer, ['phone' => 'ext. 2'] + self::BODY, $key);
        self::assertSame(409, $this->httpStatus());
        $this->create($this->archivedCustomer('Archived For Contact Trail'), self::BODY);
        self::assertSame(409, $this->httpStatus());
        $this->create($customer, ['email' => 'nope'] + self::BODY);
        self::assertSame(422, $this->httpStatus());
        $this->create(Uuid::v4()->toRfc4122(), self::BODY);
        self::assertSame(404, $this->httpStatus());

        self::assertCount(1, $this->auditRowsOf(AuditEvent::CUSTOMER_CONTACT_CREATED), 'A replayed or refused create wrote a customer_contact.created row.');
        self::assertSame([], $this->auditRowsOf(AuditEvent::CUSTOMER_CONTACT_UPDATED));
    }

    /** Several submitted, one changed: the row names that one field; a second case names two, sorted. */
    #[Test]
    public function aContactUpdateWritesOneRowNamingOnlyTheChangedFields(): void
    {
        $customer = $this->seedCustomer(self::TENANT_A, 'Stable Contact');
        $contact = $this->seedCustomerContact(self::TENANT_A, $customer);
        $stored = $this->storedContact($contact);
        $this->actAs(self::TENANT_A, DefaultRoles::codesFor(DefaultRoles::SALES_REP));

        $this->update($customer, $contact, ['phone' => 'ext. 7'] + $stored);
        self::assertSame(200, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        self::assertSame([$this->contactUpdatedRow($contact, ['phone'])], $this->auditRowsOf(AuditEvent::CUSTOMER_CONTACT_UPDATED));

        $this->update($customer, $contact, ['name' => 'Ada King', 'email' => null, 'is_primary' => false]);
        self::assertSame(200, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        self::assertSame([$this->contactUpdatedRow($contact, ['phone']), $this->contactUpdatedRow($contact, ['email', 'name'])],
            $this->auditRowsOf(AuditEvent::CUSTOMER_CONTACT_UPDATED));
        self::assertSame([], $this->auditRowsOf(AuditEvent::CUSTOMER_CONTACT_CREATED), 'An update wrote a create row.');
    }

    /** All submitted equal to the stored values, and an empty PATCH: no row, no UPDATE statement, `updated_at` unmoved. */
    #[Test]
    public function aNoOpContactUpdateWritesNoRowAndNoStatement(): void
    {
        $customer = $this->seedCustomer(self::TENANT_A, 'Unchanged Contact');
        $contact = $this->seedCustomerContact(self::TENANT_A, $customer, 'Unchanged', null, null, true);
        $stored = $this->storedContact($contact);
        $before = $this->fetchAsOwnerForTenant(self::TENANT_A, 'SELECT updated_at::text AS updated_at FROM customer_contact WHERE id = ?::uuid', [$contact]);

        foreach ([$stored, []] as $body) {
            $this->recorder()->start();
            $this->update($customer, $contact, $body);
            $this->recorder()->stop();

            self::assertSame(200, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
            self::assertSame(['id' => $contact] + $stored, $this->json(), 'A no-op update must answer the unchanged row.');
            self::assertSame([], $this->recorder()->matching('/\bUPDATE\s+customer_contact\b/i'), 'A no-op update issued an UPDATE.');
        }
        self::assertSame([], $this->auditRowsOf(AuditEvent::CUSTOMER_CONTACT_UPDATED), 'A no-op update wrote a customer_contact.updated row.');
        self::assertSame($before, $this->fetchAsOwnerForTenant(self::TENANT_A, 'SELECT updated_at::text AS updated_at FROM customer_contact WHERE id = ?::uuid', [$contact]));
    }

    /** Another customer's contact (404), an archived customer (409) and a malformed body (422) write no row. */
    #[Test]
    public function aRefusedContactUpdateWritesNoRow(): void
    {
        $mine = $this->seedCustomer(self::TENANT_A, 'Refused Contact Mine');
        $contact = $this->seedCustomerContact(self::TENANT_A, $mine);
        $other = $this->seedCustomer(self::TENANT_A, 'Refused Contact Other');
        $archived = $this->seedCustomer(self::TENANT_A, 'Refused Contact Archived');
        $archivedContact = $this->seedCustomerContact(self::TENANT_A, $archived);
        $this->insertAsOwnerForTenant(self::TENANT_A, 'UPDATE customer SET deleted_at = now() WHERE id = ?::uuid', [$archived]);

        $this->update($other, $contact, ['name' => 'Hijacked']);
        self::assertSame(404, $this->httpStatus());
        $this->update($archived, $archivedContact, ['name' => 'Reopened']);
        self::assertSame(409, $this->httpStatus());
        $this->update($mine, $contact, ['name' => 'Fine', 'email' => 'not-an-email']);
        self::assertSame(422, $this->httpStatus());

        self::assertSame([], $this->auditRowsOf(AuditEvent::CUSTOMER_CONTACT_UPDATED), 'A refused update wrote a customer_contact.updated row.');
    }

    /** `metadata` is `{fields}` and nothing else, and no submitted or stored value appears in it. */
    #[Test]
    public function theContactUpdatedRowCarriesNamesNeverValues(): void
    {
        $customer = $this->seedCustomer(self::TENANT_A, 'Valued Contact');
        $contact = $this->seedCustomerContact(self::TENANT_A, $customer);

        $this->update($customer, $contact, ['name' => 'Zebra Secret', 'email' => 'zebra@secret.test', 'phone' => 'ext. 777', 'is_primary' => true]);
        self::assertSame(200, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());

        $rows = $this->auditRowsOf(AuditEvent::CUSTOMER_CONTACT_UPDATED);
        self::assertCount(1, $rows);
        $metadata = json_decode($rows[0]['metadata'], true, flags: \JSON_THROW_ON_ERROR);
        self::assertSame(['fields'], array_keys($metadata), 'customer_contact.updated carries a key other than fields.');
        self::assertSame(['email', 'is_primary', 'name', 'phone'], $metadata['fields']);
        foreach (['Zebra', 'Secret', 'secret.test', '777', 'Ada', 'Lovelace', 'example.test', '7946', 'true'] as $value) {
            self::assertStringNotContainsString($value, $rows[0]['metadata'], 'A value reached customer_contact.updated\'s metadata.');
        }
    }

    /**
     * A contact un-marked because another was marked primary gets its OWN `customer_contact.updated {"fields":["is_primary"]}`
     * (owner Q-1 (a); the trail must explain why a row changed that nobody PATCHed) — on create and on update (M1-7).
     */
    #[Test]
    public function aDemotedContactGetsItsOwnUpdatedRow(): void
    {
        $customer = $this->seedCustomer(self::TENANT_A, 'Demotions');
        $first = $this->seedCustomerContact(self::TENANT_A, $customer, 'First Primary', null, null, true);
        $this->actAs(self::TENANT_A, DefaultRoles::codesFor(DefaultRoles::SALES_REP));

        $this->create($customer, ['is_primary' => true] + self::BODY);
        self::assertSame(201, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        $created = $this->json()['id'];
        self::assertSame([$this->contactUpdatedRow($first, ['is_primary'])], $this->auditRowsOf(AuditEvent::CUSTOMER_CONTACT_UPDATED),
            'The demoted contact has no row of its own.');
        self::assertSame([$this->businessRow(AuditEvent::CUSTOMER_CONTACT_CREATED, 'customer_contact', $created)],
            $this->auditRowsOf(AuditEvent::CUSTOMER_CONTACT_CREATED), 'The new primary got more than its create row.');

        $this->update($customer, $first, ['is_primary' => true, 'phone' => 'ext. 1']);
        self::assertSame(200, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        self::assertSame(self::canonical([
            $this->contactUpdatedRow($first, ['is_primary']),
            $this->contactUpdatedRow($first, ['is_primary', 'phone']),
            $this->contactUpdatedRow($created, ['is_primary']),
        ]), self::canonical($this->auditRowsOf(AuditEvent::CUSTOMER_CONTACT_UPDATED)));
        self::assertSame([$first], $this->primariesOf($customer));
    }

    // ---- races (F-H1: the wait location is asserted) -------------------------------------------------------------------------

    /**
     * **M1-5's carrier — two requests making contacts primary at once leave exactly one** (owner Q-1 (a); `ARCH` A-1). The
     * holder runs the writer's OWN statements for a create-as-primary — {@see CustomerContactWriter::PARENT_LOCK}, `INSERT`,
     * `SET_PRIMARY` — and holds. The request (create with `is_primary: true`) must wait on the CUSTOMER row; when the holder
     * commits, it demotes the holder's contact and answers 201 with exactly one primary. Downgrade the parent lock to
     * `FOR KEY SHARE` and the request passes the parent, waits instead at `customer_contact_one_primary` on the holder's
     * uncommitted row, and meets a 23505 mid-`UPDATE` when it commits — a 500, and a wait in the wrong place.
     */
    #[Test]
    public function twoRequestsMakingContactsPrimaryAtOnceLeaveExactlyOne(): void
    {
        $customer = $this->seedCustomer(self::TENANT_A, 'Raced Primaries');
        $this->actAs(self::TENANT_A, DefaultRoles::codesFor(DefaultRoles::SALES_REP));
        $holderContact = Uuid::v4()->toRfc4122();

        $holder = $this->raceAgainstAHolder(
            $this->aPrimaryAddedByTheWritersOwnStatements($holderContact, $customer),
            fn () => $this->create($customer, ['name' => 'Raced', 'is_primary' => true]),
        );

        $this->assertWaitedOnTheRowOf('customer', $holder);
        self::assertSame(201, $this->httpStatus(), 'The second primary was not answered 201: '.(string) $this->client()->getResponse()->getContent());
        self::assertSame([$this->json()['id']], $this->primariesOf($customer), 'Not exactly one primary after two raced.');
        self::assertFalse($this->storedContact($holderContact)['is_primary'], 'The holder\'s primary was not demoted.');
    }

    /** M1-5's update-side carrier: a PATCH making a contact primary while a primary is being added waits on the customer, then moves the mark. */
    #[Test]
    public function aContactMarkedPrimaryWhileAnotherIsBeingAddedAsPrimaryWaitsThenMovesTheMark(): void
    {
        $customer = $this->seedCustomer(self::TENANT_A, 'Raced Mark');
        $contact = $this->seedCustomerContact(self::TENANT_A, $customer, 'Patched');
        $holderContact = Uuid::v4()->toRfc4122();

        $holder = $this->raceAgainstAHolder(
            $this->aPrimaryAddedByTheWritersOwnStatements($holderContact, $customer),
            fn () => $this->update($customer, $contact, ['is_primary' => true]),
        );

        $this->assertWaitedOnTheRowOf('customer', $holder);
        self::assertSame(200, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        self::assertSame([$contact], $this->primariesOf($customer));
    }

    /** Delete first ∥ `CreateCustomerContact`: the contact parent lock conflicts with `FOR UPDATE`, so it waits and is refused. */
    #[Test]
    public function aContactAddedWhileTheCustomerIsBeingArchivedIsRefused(): void
    {
        $customer = $this->seedCustomer(self::TENANT_A, 'Archiving Under Contact Add');

        $holder = $this->raceAgainstAHolder($this->theDeletesOwnStatements($customer), fn () => $this->create($customer, self::BODY));

        $this->assertWaitedOnTheRowOf('customer', $holder);
        self::assertSame(409, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        self::assertSame(CustomerContactWriter::ARCHIVED_ON_CREATE, $this->json()['detail']);
        self::assertSame(0, $this->countAsOwnerForTenant(self::TENANT_A, 'SELECT count(*) FROM customer_contact WHERE customer_id = ?::uuid', [$customer]));
    }

    /** Delete first ∥ `UpdateCustomerContact`: it waits on the customer and the contact is unchanged. */
    #[Test]
    public function aContactChangedWhileTheCustomerIsBeingArchivedIsRefused(): void
    {
        $customer = $this->seedCustomer(self::TENANT_A, 'Archiving Under Contact Change');
        $contact = $this->seedCustomerContact(self::TENANT_A, $customer);

        $holder = $this->raceAgainstAHolder($this->theDeletesOwnStatements($customer), fn () => $this->update($customer, $contact, ['name' => 'Changed']));

        $this->assertWaitedOnTheRowOf('customer', $holder);
        self::assertSame(409, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        self::assertSame(CustomerContactWriter::ARCHIVED_ON_UPDATE, $this->json()['detail']);
        self::assertSame('Ada Lovelace', $this->storedContact($contact)['name']);
    }

    /**
     * An identical retry racing its own first attempt: under Q-1 (a) the retry waits at the CUSTOMER (the first attempt
     * holds the parent lock, the writer's own statements), then its key read finds the committed row and replays it — 201,
     * the same contact, one row, no audit row of its own.
     */
    #[Test]
    public function anIdenticalRetryRacingItsFirstAttemptWaitsOnTheCustomerAndReplays(): void
    {
        $customer = $this->seedCustomer(self::TENANT_A, 'Raced Identical');
        $key = Uuid::v4()->toRfc4122();
        $winner = Uuid::v4()->toRfc4122();

        $holder = $this->raceAgainstAHolder(
            [[CustomerContactWriter::PARENT_LOCK, [$customer]], $this->twinInserted($winner, $customer, self::BODY, $key)],
            fn () => $this->create($customer, self::BODY, $key),
        );

        $this->assertWaitedOnTheRowOf('customer', $holder);
        self::assertSame(201, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        self::assertSame(['id' => $winner] + self::BODY, $this->json());
        self::assertSame('/api/v1/customers/'.$customer.'/contacts/'.$winner, $this->client()->getResponse()->headers->get('Location'));
        self::assertSame(1, $this->countAsOwnerForTenant(self::TENANT_A, 'SELECT count(*) FROM customer_contact WHERE idempotency_key = ?', [IdempotencyKeyHash::of($key)]));
        self::assertSame([], $this->auditRowsOf(AuditEvent::CUSTOMER_CONTACT_CREATED), 'The retry wrote a create row for a contact it did not insert.');
    }

    /**
     * **M1-1 / M1-2's carrier — the one insert race the parent lock does not serialise:** the SAME key on ANOTHER customer.
     * The holder adds customer X's contact under the key (the writer's own statements) and holds; the request sends the key
     * to customer Y: its parent lock is Y's, its key read sees nothing uncommitted, so it waits at
     * `customer_contact_idem_unique` (no tuple lock — the wait-location assertion says so). When the holder commits, its
     * `ON CONFLICT … DO NOTHING` inserts nothing and the in-transaction key read answers 409 (the digest carries the
     * customer). A plain INSERT (M1-1) turns this into a 23505 → 500; a wrong conflict target (M1-2) is a 42P10 on every create.
     */
    #[Test]
    public function theSameKeyRacedOnAnotherCustomerConflictsAtTheUnique(): void
    {
        $x = $this->seedCustomer(self::TENANT_A, 'Raced Key X');
        $y = $this->seedCustomer(self::TENANT_A, 'Raced Key Y');
        $key = Uuid::v4()->toRfc4122();
        $winner = Uuid::v4()->toRfc4122();

        $holder = $this->raceAgainstAHolder(
            [[CustomerContactWriter::PARENT_LOCK, [$x]], $this->twinInserted($winner, $x, self::BODY, $key)],
            fn () => $this->create($y, self::BODY, $key),
        );

        self::assertTrue($holder['waiter_seen'], 'The create never waited on the holder: '.json_encode($holder));
        self::assertTrue($holder['committed'], 'The holder did not commit: '.json_encode($holder));
        self::assertSame([], array_values(array_filter($holder['waiter_locks'], static fn (array $l): bool => 'tuple' === $l['locktype'])),
            'The request waited on a ROW, not at the unique: '.json_encode($holder));
        self::assertContains(['locktype' => 'transactionid', 'relation' => null, 'mode' => 'ShareLock', 'granted' => false, 'xid' => $holder['holder_xid']],
            $holder['waiter_locks'], 'The request did not wait on the holder\'s transaction: '.json_encode($holder));
        self::assertSame(409, $this->httpStatus(), 'The raced same key on another customer was not refused: '.(string) $this->client()->getResponse()->getContent());
        self::assertSame('urn:erp:problem:idempotency-key-conflict', $this->json()['type']);
        self::assertSame(0, $this->countAsOwnerForTenant(self::TENANT_A, 'SELECT count(*) FROM customer_contact WHERE customer_id = ?::uuid', [$y]));
        self::assertSame([], $this->auditRowsOf(AuditEvent::CUSTOMER_CONTACT_CREATED));
    }

    // -----------------------------------------------------------------------

    private function archivedCustomer(string $name): string
    {
        $customer = $this->seedCustomer(self::TENANT_A, $name);
        $this->insertAsOwnerForTenant(self::TENANT_A, 'UPDATE customer SET deleted_at = now() WHERE id = ?::uuid', [$customer]);

        return $customer;
    }

    /** @param array<string, mixed> $body */
    private function create(string $customer, array $body, ?string $key = null): void
    {
        $this->send('POST', '/customers/'.$customer.'/contacts', $body, [IdempotencyKeyGuard::HEADER => $key ?? Uuid::v4()->toRfc4122()]);
    }

    /** @param array<string, mixed> $body */
    private function update(string $customer, string $contact, array $body): void
    {
        $this->send('PATCH', '/customers/'.$customer.'/contacts/'.$contact, $body);
    }

    /** @return list<array{pointer: string, code: string, message: string}> */
    private function fieldErrors(): array
    {
        return $this->json()['errors'];
    }

    /** @return array{name: string, email: ?string, phone: ?string, is_primary: bool} */
    private function storedContact(string $contact): array
    {
        return $this->fetchAsOwnerForTenant(self::TENANT_A, 'SELECT name, email, phone, is_primary FROM customer_contact WHERE id = ?::uuid', [$contact])[0];
    }

    /** @return list<string> */
    private function primariesOf(string $customer): array
    {
        return array_column($this->fetchAsOwnerForTenant(self::TENANT_A,
            'SELECT id::text AS id FROM customer_contact WHERE customer_id = ?::uuid AND is_primary ORDER BY id', [$customer]), 'id');
    }

    /**
     * @param list<string> $fields
     *
     * @return array<string, ?string>
     */
    private function contactUpdatedRow(string $contact, array $fields): array
    {
        return array_replace($this->businessRow(AuditEvent::CUSTOMER_CONTACT_UPDATED, 'customer_contact', $contact), [
            'metadata' => (string) $this->fetchAsOwnerForTenant(self::TENANT_A, 'SELECT ?::jsonb::text AS m',
                [json_encode(['fields' => $fields], \JSON_THROW_ON_ERROR)])[0]['m'],
        ]);
    }

    /**
     * Rows written in one transaction share `occurred_at`, so `auditRowsOf()`'s order among them is the random id's: compare
     * as a multiset, both sides sorted by (subject, metadata).
     *
     * @param list<array<string, ?string>> $rows
     *
     * @return list<array<string, ?string>>
     */
    private static function canonical(array $rows): array
    {
        usort($rows, static fn (array $a, array $b): int => [(string) $a['subject_id'], (string) $a['metadata']] <=> [(string) $b['subject_id'], (string) $b['metadata']]);

        return $rows;
    }

    /**
     * What `CreateCustomerContact` writes for a create-as-primary, by THE WRITER'S constants: parent lock, INSERT (never
     * primary), SET_PRIMARY. The holder writes no audit row.
     *
     * @return list<array{0: string, 1: list<mixed>}>
     */
    private function aPrimaryAddedByTheWritersOwnStatements(string $id, string $customer): array
    {
        return [
            [CustomerContactWriter::PARENT_LOCK, [$customer]],
            $this->twinInserted($id, $customer, ['name' => 'Holder Primary', 'email' => null, 'phone' => null, 'is_primary' => true], Uuid::v4()->toRfc4122()),
            [CustomerContactWriter::SET_PRIMARY, [$id, $customer]],
        ];
    }

    /**
     * The writer's own INSERT, with the key's hash and the request's digest (`RequestDigest::of(CreateCustomerContact, …)`
     * over the path's customer id and the four fields, the writer's own rule).
     *
     * @param array<string, mixed> $body
     *
     * @return array{0: string, 1: list<mixed>}
     */
    private function twinInserted(string $id, string $customer, array $body, string $key): array
    {
        $body += ['email' => null, 'phone' => null, 'is_primary' => false];
        $digest = RequestDigest::of(CustomerContactWriter::CREATE_OPERATION, ['customer_id' => RequestDigest::uuid($customer)] + $body);

        return [CustomerContactWriter::INSERT, [$id, self::TENANT_A, $customer, $body['name'], $body['email'], $body['phone'], IdempotencyKeyHash::of($key), $digest]];
    }

    /**
     * The delete's own two statements — `CustomerWriter`'s constants, never a transcription (`CustomerDeleteTest`'s).
     *
     * @return list<array{0: string, 1: list<string>}>
     */
    private function theDeletesOwnStatements(string $customer): array
    {
        return [[CustomerWriter::DELETE_LOCK, [$customer]], [CustomerWriter::ARCHIVE, [$customer]]];
    }

    /**
     * F-H1: the request waited, and on a ROW of `$relation` — a `tuple` lock there and an ungranted `transactionid` on the
     * holder's xid, read off `pg_locks` by the holder when it saw the waiter.
     *
     * @param array<string, mixed> $holder
     */
    private function assertWaitedOnTheRowOf(string $relation, array $holder): void
    {
        self::assertTrue($holder['waiter_seen'], 'The request never waited on the holder, so nothing here was a race: '.json_encode($holder));
        self::assertTrue($holder['committed'], 'The holder did not commit: '.json_encode($holder));
        $tuples = array_values(array_unique(array_column(array_filter($holder['waiter_locks'], static fn (array $l): bool => 'tuple' === $l['locktype']), 'relation')));
        self::assertSame([$relation], $tuples, 'The request did not wait on a row of '.$relation.': '.json_encode($holder));
        self::assertContains(['locktype' => 'transactionid', 'relation' => null, 'mode' => 'ShareLock', 'granted' => false, 'xid' => $holder['holder_xid']],
            $holder['waiter_locks'], 'The request did not wait on the holder\'s transaction: '.json_encode($holder));
    }

    /**
     * `CustomerAddressWriteTest::raceAgainstAHolder`'s shape (the holder's own `HOLD_TABLE` lock is on a random
     * `purchase_order` id, matching no row; the real locks are `HOLD_BEFORE`'s).
     *
     * @param list<array{0: string, 1: list<mixed>}> $before
     *
     * @return array{waiter_seen: bool, committed: bool, sqlstate: ?string, error: ?string, waiter_locks: list<array<string, mixed>>, holder_xid: ?string}
     */
    private function raceAgainstAHolder(array $before, callable $request): array
    {
        $readyFile = tempnam(sys_get_temp_dir(), 'erp_customer_contact_');
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
