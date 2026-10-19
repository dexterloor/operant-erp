<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Modules\Customers\Application\CustomerContactWriter;
use App\Modules\Customers\Application\CustomerWriter;
use App\Modules\UserManagement\Domain\DefaultRoles;
use App\Platform\Http\Idempotency\IdempotencyKeyGuard;
use App\SharedKernel\ValueObject\AuditEvent;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Uid\Uuid;

/**
 * **Customers slice 2 C2 — `DeleteCustomerContact`** (`docs/session13/customers-s2-design.md` Revision 2 R2.2's prescribed
 * delete shape and R2.4 C2; owner rulings `s35-ruling.md` Q-1 (a), Q-2 yes, Q-4 accept the late-retry gap; SEC
 * `customers-s2-sec-arch.md` S-3, S-4, S-6).
 *
 * The order is parent lock → **unlocked** pair read (404) → archived 409 → `DELETE … WHERE id AND customer_id` → 0 rows 404 /
 * 1 row `customer_contact.deleted {"customer_id"}` and 204, no body. A repeat is the same 404 as an unknown id (S-6.5).
 *
 * **Races assert where the request waited** (final RED F-H1; `CustomerContactWriteTest`'s helper). Under Q-1 (a) every
 * contact write queues on the CUSTOMER row first, so a second delete of the same contact waits there and is answered 404 at
 * the pair read: **the delete's rowcount check is a backstop a test cannot reach today** — mutation M2-6 is a REVIEW CHECK
 * (`ARCH` A-3), not evidence. Likewise the identical retry held behind a delete (R1.3's test, moved here from C1) waits on
 * the customer and proves the parent lock, not the key-read lock.
 */
final class CustomerContactDeleteTest extends PurchaseOrderTestCase
{
    private const HOLDER_DEADLINE_SECONDS = 10.0;

    private const BODY = ['name' => 'Ada Lovelace', 'email' => 'ada@example.test', 'phone' => '+44 20 7946 0000', 'is_primary' => false];

    /** 204, no body; the row is gone; the customer's other contacts are untouched. */
    #[Test]
    public function aSalesRepRemovesAContact(): void
    {
        $customer = $this->seedCustomer(self::TENANT_A, 'Contact Remover');
        $contact = $this->seedCustomerContact(self::TENANT_A, $customer);
        $kept = $this->seedCustomerContact(self::TENANT_A, $customer, 'Kept');
        $this->actAs(self::TENANT_A, DefaultRoles::codesFor(DefaultRoles::SALES_REP));

        $this->remove($customer, $contact);

        self::assertSame(204, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        self::assertSame('', (string) $this->client()->getResponse()->getContent(), 'A 204 carries no body.');
        self::assertSame([$kept], $this->contactsOf($customer));
    }

    /** Removing the primary leaves the customer with no primary; nothing is promoted (notice N-4). */
    #[Test]
    public function removingThePrimaryLeavesNoPrimary(): void
    {
        $customer = $this->seedCustomer(self::TENANT_A, 'Primary Removed');
        $primary = $this->seedCustomerContact(self::TENANT_A, $customer, 'Primary', null, null, true);
        $other = $this->seedCustomerContact(self::TENANT_A, $customer, 'Other');

        $this->remove($customer, $primary);

        self::assertSame(204, $this->httpStatus());
        self::assertSame([$other], $this->contactsOf($customer));
        self::assertSame(0, $this->countAsOwnerForTenant(self::TENANT_A, 'SELECT count(*) FROM customer_contact WHERE customer_id = ?::uuid AND is_primary', [$customer]),
            'Removing the primary promoted another contact.');
    }

    /**
     * ST-5 / SEC S-6.1 / S-6.5: a foreign, unknown or malformed customer, another customer's contact under this path (which
     * SURVIVES — M2-1), an unknown contact, and a repeat of a delete that succeeded all answer the SAME 404 body.
     */
    #[Test]
    public function everyNotFoundIsTheSameBodyAndAnotherCustomersContactSurvives(): void
    {
        $mine = $this->seedCustomer(self::TENANT_A, 'Delete Mine');
        $other = $this->seedCustomer(self::TENANT_A, 'Delete Other');
        $othersContact = $this->seedCustomerContact(self::TENANT_A, $other);
        $foreign = $this->seedCustomer(self::TENANT_B, 'Delete Foreign');
        $foreignContact = $this->seedCustomerContact(self::TENANT_B, $foreign);
        $gone = $this->seedCustomerContact(self::TENANT_A, $mine, 'Removed Once');
        $this->remove($mine, $gone);
        self::assertSame(204, $this->httpStatus(), 'Fixture: the first delete must succeed.');

        $bodies = [];
        foreach ([[$foreign, $foreignContact], [Uuid::v4()->toRfc4122(), $othersContact], ['not-a-uuid', $othersContact],
            [$mine, $othersContact], [$mine, Uuid::v4()->toRfc4122()], [$mine, 'not-a-uuid'], [$mine, $gone]] as [$customer, $contact]) {
            $this->remove($customer, $contact);
            self::assertSame(404, $this->httpStatus(), $customer.' / '.$contact);
            $bodies[] = (string) $this->client()->getResponse()->getContent();
        }
        self::assertCount(1, array_unique($bodies), 'The 404 bodies differ: '.implode(' | ', array_unique($bodies)));
        self::assertSame([$othersContact], $this->contactsOf($other), 'Another customer\'s contact was removed through this path.');
        self::assertSame(1, $this->countAsOwnerForTenant(self::TENANT_B, 'SELECT count(*) FROM customer_contact WHERE id = ?::uuid', [$foreignContact]));
    }

    /** ST-9: an archived customer's contact cannot be removed (M2-2). */
    #[Test]
    public function anArchivedCustomersContactCannotBeRemoved(): void
    {
        $customer = $this->seedCustomer(self::TENANT_A, 'Archived Contact Kept');
        $contact = $this->seedCustomerContact(self::TENANT_A, $customer);
        $this->insertAsOwnerForTenant(self::TENANT_A, 'UPDATE customer SET deleted_at = now() WHERE id = ?::uuid', [$customer]);

        $this->remove($customer, $contact);

        self::assertSame(409, $this->httpStatus());
        self::assertSame('customer.is_archived', $this->json()['rule']);
        self::assertSame('This customer is archived, so its contacts can\'t be removed until it\'s restored.', $this->json()['detail']);
        self::assertSame([$contact], $this->contactsOf($customer));
    }

    /** `viewer` holds `customer.view`, not `customer.edit`: it cannot remove a contact. */
    #[Test]
    public function aViewerCannotRemoveAContact(): void
    {
        $customer = $this->seedCustomer(self::TENANT_A, 'Viewer Delete');
        $contact = $this->seedCustomerContact(self::TENANT_A, $customer);
        $this->actAs(self::TENANT_A, DefaultRoles::codesFor(DefaultRoles::VIEWER));

        $this->remove($customer, $contact);

        self::assertSame(403, $this->httpStatus());
        self::assertSame([$contact], $this->contactsOf($customer));
    }

    // ---- the trail (owner Q-2) ---------------------------------------------------------------------------------------------

    /** One `customer_contact.deleted`, naming the remover, subject the removed contact, `{"customer_id"}` (SEC S-4). */
    #[Test]
    public function aRemovedContactWritesExactlyOneDeletedRowNamingItsCustomer(): void
    {
        $customer = $this->seedCustomer(self::TENANT_A, 'Audited Removal');
        $contact = $this->seedCustomerContact(self::TENANT_A, $customer);
        $this->actAs(self::TENANT_A, DefaultRoles::codesFor(DefaultRoles::SALES_REP));

        $this->remove($customer, $contact);

        self::assertSame(204, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        self::assertSame([array_replace($this->businessRow(AuditEvent::CUSTOMER_CONTACT_DELETED, 'customer_contact', $contact), [
            'metadata' => (string) $this->fetchAsOwnerForTenant(self::TENANT_A, 'SELECT ?::jsonb::text AS m',
                [json_encode(['customer_id' => $customer], \JSON_THROW_ON_ERROR)])[0]['m'],
        ])], $this->auditRowsOf(AuditEvent::CUSTOMER_CONTACT_DELETED));
        self::assertSame([], $this->auditRowsOf(AuditEvent::CUSTOMER_CONTACT_UPDATED), 'A delete wrote an update row.');
    }

    /** A 404 (unknown, another customer's, a repeat), a 409 (archived) and a 403 write no row. */
    #[Test]
    public function aRefusedOrRepeatedDeleteWritesNoRow(): void
    {
        $customer = $this->seedCustomer(self::TENANT_A, 'Refused Removal');
        $other = $this->seedCustomer(self::TENANT_A, 'Refused Removal Other');
        $othersContact = $this->seedCustomerContact(self::TENANT_A, $other);
        $archived = $this->seedCustomer(self::TENANT_A, 'Refused Removal Archived');
        $archivedContact = $this->seedCustomerContact(self::TENANT_A, $archived);
        $this->insertAsOwnerForTenant(self::TENANT_A, 'UPDATE customer SET deleted_at = now() WHERE id = ?::uuid', [$archived]);
        $contact = $this->seedCustomerContact(self::TENANT_A, $customer);

        $this->remove($customer, $contact);
        self::assertSame(204, $this->httpStatus(), 'Fixture: the first delete must succeed.');
        self::assertCount(1, $this->auditRowsOf(AuditEvent::CUSTOMER_CONTACT_DELETED));

        $this->remove($customer, $contact);
        self::assertSame(404, $this->httpStatus());
        $this->remove($customer, $othersContact);
        self::assertSame(404, $this->httpStatus());
        $this->remove($archived, $archivedContact);
        self::assertSame(409, $this->httpStatus());
        $this->actAs(self::TENANT_A, DefaultRoles::codesFor(DefaultRoles::VIEWER));
        $this->remove($other, $othersContact);
        self::assertSame(403, $this->httpStatus());

        self::assertCount(1, $this->auditRowsOf(AuditEvent::CUSTOMER_CONTACT_DELETED), 'A refused or repeated delete wrote a row.');
    }

    // ---- races (F-H1: the wait location is asserted) -------------------------------------------------------------------------

    /**
     * **R1.4's double delete.** The holder runs the delete's own statements ({@see CustomerContactWriter::PARENT_LOCK},
     * {@see CustomerContactWriter::DELETE}) and holds. The second delete must wait on the CUSTOMER row (owner Q-1 (a)), then
     * find no pair and answer 404, writing no row. Under (a) this test evidences the parent lock; the DELETE's rowcount check
     * behind it is a backstop (M2-6, a review check).
     */
    #[Test]
    public function aSecondConcurrentDeleteWaitsOnTheCustomerAndIsA404WritingNoSecondRow(): void
    {
        $customer = $this->seedCustomer(self::TENANT_A, 'Raced Delete');
        $contact = $this->seedCustomerContact(self::TENANT_A, $customer);

        $holder = $this->raceAgainstAHolder($this->theDeletesOwnStatements($customer, $contact), fn () => $this->remove($customer, $contact));

        $this->assertWaitedOnTheRowOf('customer', $holder);
        self::assertSame(404, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        self::assertSame([], $this->contactsOf($customer));
        self::assertSame([], $this->auditRowsOf(AuditEvent::CUSTOMER_CONTACT_DELETED), 'The second delete wrote a .deleted row for a row it did not remove.');
    }

    /** Archive first ∥ `DeleteCustomerContact`: it waits on the customer and is refused; the contact survives (M2-5). */
    #[Test]
    public function aContactRemovedWhileTheCustomerIsBeingArchivedIsRefused(): void
    {
        $customer = $this->seedCustomer(self::TENANT_A, 'Archiving Under Contact Removal');
        $contact = $this->seedCustomerContact(self::TENANT_A, $customer);

        $holder = $this->raceAgainstAHolder([[CustomerWriter::DELETE_LOCK, [$customer]], [CustomerWriter::ARCHIVE, [$customer]]],
            fn () => $this->remove($customer, $contact));

        $this->assertWaitedOnTheRowOf('customer', $holder);
        self::assertSame(409, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        self::assertSame(CustomerContactWriter::ARCHIVED_ON_DELETE, $this->json()['detail']);
        self::assertSame([$contact], $this->contactsOf($customer));
    }

    /**
     * **R1.3's race, moved from C1** (C1 deviation 1: no delete existed to hold). A contact is created with key K; the holder
     * runs its delete's own statements and holds; the identical retry of K's create is sent. Under owner Q-1 (a) the retry
     * waits on the CUSTOMER (the parent lock — this is what the test evidences, `ARCH` A-3), then finds no key and creates
     * afresh: 201 with a NEW id. Owner Q-4 accepted this as a gap (the late-retry re-create); option T would make it a 409.
     */
    #[Test]
    public function anIdenticalRetryHeldBehindADeleteWaitsOnTheCustomerAndCreatesAfresh(): void
    {
        $customer = $this->seedCustomer(self::TENANT_A, 'Raced Retry After Delete');
        $key = Uuid::v4()->toRfc4122();
        $this->create($customer, self::BODY, $key);
        self::assertSame(201, $this->httpStatus(), 'Fixture: the create must succeed.');
        $first = $this->json()['id'];

        $holder = $this->raceAgainstAHolder($this->theDeletesOwnStatements($customer, $first), fn () => $this->create($customer, self::BODY, $key));

        $this->assertWaitedOnTheRowOf('customer', $holder);
        self::assertSame(201, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        self::assertNotSame($first, $this->json()['id'], 'The retry replayed a contact that the delete removed.');
        self::assertSame([$this->json()['id']], $this->contactsOf($customer));
    }

    /**
     * **The late-retry gap's pin (owner Q-4 "accept", `RESIDUAL_GAPS.md` G-49).** A contact is created with key K and deleted;
     * a later retry of K's create (same body) is a FRESH create: 201, a NEW id, a second `customer_contact.created` row naming
     * the retrying actor — the key died with the row. Building the tombstone (option T) flips this to 409
     * `customer_contact.was_removed`: a decision that moves this pin, never a surprise.
     */
    #[Test]
    public function aRetryOfTheOriginalCreateAfterADeleteCreatesTheContactAgain(): void
    {
        $customer = $this->seedCustomer(self::TENANT_A, 'Late Retry');
        $key = Uuid::v4()->toRfc4122();
        $this->create($customer, self::BODY, $key);
        self::assertSame(201, $this->httpStatus());
        $first = $this->json()['id'];
        $this->remove($customer, $first);
        self::assertSame(204, $this->httpStatus());

        $this->create($customer, self::BODY, $key);

        self::assertSame(201, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        self::assertNotSame($first, $this->json()['id']);
        self::assertSame(['id' => $this->json()['id']] + self::BODY, $this->json());
        self::assertCount(2, $this->auditRowsOf(AuditEvent::CUSTOMER_CONTACT_CREATED), 'The re-create is not on the trail.');
    }

    // -----------------------------------------------------------------------

    private function remove(string $customer, string $contact): void
    {
        $this->send('DELETE', '/customers/'.$customer.'/contacts/'.$contact);
    }

    /** @param array<string, mixed> $body */
    private function create(string $customer, array $body, string $key): void
    {
        $this->send('POST', '/customers/'.$customer.'/contacts', $body, [IdempotencyKeyGuard::HEADER => $key]);
    }

    /** @return list<string> */
    private function contactsOf(string $customer): array
    {
        return array_column($this->fetchAsOwnerForTenant(self::TENANT_A,
            'SELECT id::text AS id FROM customer_contact WHERE customer_id = ?::uuid ORDER BY created_at, id', [$customer]), 'id');
    }

    /**
     * The contact delete's own statements, by THE WRITER'S constants. The holder writes no audit row.
     *
     * @return list<array{0: string, 1: list<string>}>
     */
    private function theDeletesOwnStatements(string $customer, string $contact): array
    {
        return [[CustomerContactWriter::PARENT_LOCK, [$customer]], [CustomerContactWriter::DELETE, [$contact, $customer]]];
    }

    /**
     * F-H1: the request waited, and on a ROW of `$relation` — a `tuple` lock there and an ungranted `transactionid` on the
     * holder's xid (`CustomerContactWriteTest`'s assertion).
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
     * `CustomerContactWriteTest::raceAgainstAHolder`'s shape.
     *
     * @param list<array{0: string, 1: list<mixed>}> $before
     *
     * @return array{waiter_seen: bool, committed: bool, sqlstate: ?string, error: ?string, waiter_locks: list<array<string, mixed>>, holder_xid: ?string}
     */
    private function raceAgainstAHolder(array $before, callable $request): array
    {
        $readyFile = tempnam(sys_get_temp_dir(), 'erp_customer_contact_delete_');
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
