<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Modules\Customers\Application\CustomerWriter;
use App\Modules\UserManagement\Domain\DefaultRoles;
use App\SharedKernel\ValueObject\AuditEvent;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Uid\Uuid;

/**
 * **C5 C4a — `RestoreCustomer`, `POST /customers/{id}/restoration`, `customer.delete`** (`docs/session13/c5-design.md`
 * §3.4 / §2.5; §4.2 `guard:customer.deleted_at`; `SupplierRestoreTest`'s twin, API-21 branch 5).
 *
 * 200 with `GetCustomer`'s body in its new state; a live customer → 409 `customer.is_not_archived` (the guard that makes
 * a replay safe); a foreign, absent or malformed id → the same 404; a live customer that took the archived one's code
 * meanwhile → 409 on the existing unique rule with a restore sentence (the 23505 translated outside the transaction). **No
 * name collision exists** — `customer_tenant_name_live` is not unique (measured C4a T-3), so a restore may leave two live
 * customers of one name, as create already can (design §3.4 notice). One `customer.restored` row, `{}`, on success and
 * none on any refusal. Archived customers are seeded by owner SQL (a fixture; `DeleteCustomer`, C5 C4b, archives through
 * the API — the round trip is `CustomerDeleteTest::anArchivedCustomerIsRestoredByTheOperationThatPairsWithIt`). Copy is `UX`'s final (`owed-ux-c5.md` FINAL (a1)).
 */
final class CustomerRestoreTest extends PurchaseOrderTestCase
{
    /** A sales rep (holds `customer.delete`) restores an archived customer: 200, the body is `GetCustomer`'s, `archived_at` null. */
    #[Test]
    public function aSalesRepRestoresAnArchivedCustomer(): void
    {
        $id = $this->archived('Returning Customer');
        $this->actAs(self::TENANT_A, DefaultRoles::codesFor(DefaultRoles::SALES_REP));

        $this->restore($id);

        self::assertSame(200, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        $body = $this->json();
        self::assertSame($id, $body['id']);
        self::assertNull($body['archived_at']);
        self::assertNull($this->stored($id)['deleted_at']);
        $this->send('GET', '/customers/'.$id);
        self::assertSame($this->json(), $body, 'The restore body is not GetCustomer\'s.');

        self::assertContains('Returning Customer', array_column($this->listed(''), 'name'), 'A restored customer is not back on the live list.');
        self::assertSame([], $this->listed('?archived=true'), 'A restored customer is still on the Archived view.');
    }

    /** The §4.2 guard: restoring a live customer is refused, so a replayed restore is told the first one committed. */
    #[Test]
    public function restoringALiveCustomerIsRefusedByTheGuard(): void
    {
        $id = $this->archived('Once Archived');

        $this->restore($id);
        self::assertSame(200, $this->httpStatus());
        $updatedAt = $this->stored($id)['updated_at'];

        $this->restore($id);
        self::assertSame(409, $this->httpStatus());
        self::assertSame('customer.is_not_archived', $this->json()['rule']);
        self::assertSame('This customer is not archived, so there is nothing to restore.', $this->json()['detail']);
        self::assertSame($updatedAt, $this->stored($id)['updated_at'], 'A refused restore wrote the row.');
        self::assertCount(1, $this->auditRowsOf(AuditEvent::CUSTOMER_RESTORED), 'A refused restore wrote a trail row.');
    }

    /** ST-5: a foreign, absent or malformed id is the same 404 as `GetCustomer`'s, and nothing is written. */
    #[Test]
    public function aRestoreOfAnUnknownCustomerIsTheSame404(): void
    {
        $theirs = $this->archived('Theirs', self::TENANT_B);
        $bodies = [];
        foreach ([$theirs, Uuid::v4()->toRfc4122(), 'not-a-uuid'] as $id) {
            $this->restore($id);
            self::assertSame(404, $this->httpStatus(), $id);
            $bodies[] = (string) $this->client()->getResponse()->getContent();
        }
        self::assertSame($bodies[0], $bodies[1]);
        self::assertSame(1, $this->countAsOwnerForTenant(self::TENANT_B, "SELECT count(*) FROM customer WHERE name = 'Theirs' AND deleted_at IS NOT NULL"));
    }

    /**
     * Mutation C4a-M10: the restore collision (design §3.4). While a customer was archived a live one took its code
     * (`customer_tenant_code_live` is partial on `deleted_at IS NULL`); the restore is refused on the existing rule with a
     * restore sentence, never a 500, and writes nothing.
     */
    #[Test]
    public function aRestoreOntoATakenCodeIsRefusedByItsOwnRule(): void
    {
        $id = $this->archived('Original Code Holder');
        $code = $this->stored($id)['code'];
        $taker = $this->seedCustomer(self::TENANT_A, 'Code Taker');
        $this->insertAsOwnerForTenant(self::TENANT_A, 'UPDATE customer SET code = ? WHERE id = ?::uuid', [$code, $taker]);

        $this->restore($id);
        self::assertSame(409, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        self::assertSame('customer.code_is_unique_per_workspace', $this->json()['rule']);
        self::assertSame(CustomerWriter::CODE_TAKEN_ON_RESTORE, $this->json()['detail']);
        self::assertSame('Another customer now uses this customer\'s code, so it can\'t be restored. It can be restored once that customer\'s code is changed.', $this->json()['detail']);
        self::assertNotNull($this->stored($id)['deleted_at']);
        self::assertSame([], $this->auditRowsOf(AuditEvent::CUSTOMER_RESTORED), 'A refused restore wrote a trail row.');
    }

    /**
     * **Customer names have no uniqueness rule** (design §3.4; `customer_tenant_name_live` is a plain index, C4a T-3): a
     * restore beside a live customer of the same name SUCCEEDS and leaves two live customers of one name, as create already
     * can. Pinned so a later name rule is a decision, not a surprise (supplier restore refuses this case; customer does not).
     */
    #[Test]
    public function aRestoreBesideALiveCustomerOfTheSameNameSucceeds(): void
    {
        $id = $this->archived('Shared Name');
        $this->seedCustomer(self::TENANT_A, 'Shared Name');

        $this->restore($id);

        self::assertSame(200, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        self::assertSame(2, $this->countAsOwnerForTenant(self::TENANT_A, "SELECT count(*) FROM customer WHERE name = 'Shared Name' AND deleted_at IS NULL"));
    }

    /** One `customer.restored`, `{}`, naming the restorer; the subject is the customer (business, PC-11 (4a), no IP). */
    #[Test]
    public function aRestoreWritesExactlyOneCustomerRestoredRow(): void
    {
        $id = $this->archived('Audited Return');
        $this->actAs(self::TENANT_A, DefaultRoles::codesFor(DefaultRoles::ADMIN));

        $this->restore($id);

        self::assertSame(200, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        self::assertSame([$this->businessRow(AuditEvent::CUSTOMER_RESTORED, 'customer', $id)], $this->auditRowsOf(AuditEvent::CUSTOMER_RESTORED));
        self::assertSame([], $this->auditRowsOf(AuditEvent::CUSTOMER_UPDATED), 'A restore wrote an update row.');
    }

    /** `customer.delete` gates the restore (§4.2): `viewer` and a holder of `customer.edit` alone are refused, and nothing moves. */
    #[Test]
    public function onlyAHolderOfCustomerDeleteMayRestore(): void
    {
        $id = $this->archived('Guarded');

        foreach ([DefaultRoles::codesFor(DefaultRoles::VIEWER), ['customer.view', 'customer.edit']] as $codes) {
            $this->actAs(self::TENANT_A, $codes);
            $this->restore($id);
            self::assertSame(403, $this->httpStatus());
        }
        self::assertNotNull($this->stored($id)['deleted_at']);
        self::assertSame([], $this->auditRowsOf(AuditEvent::CUSTOMER_RESTORED));
    }

    /** Archiving and restoring touch no child: the addresses come back unchanged (all children `hard`; design §4.1). */
    #[Test]
    public function aRestoredCustomerKeepsItsAddresses(): void
    {
        $id = $this->seedCustomer(self::TENANT_A, 'With Addresses');
        $address = $this->seedCustomerAddress(self::TENANT_A, $id);
        $before = $this->fetchAsOwnerForTenant(self::TENANT_A, 'SELECT * FROM customer_address WHERE id = ?::uuid', [$address]);
        $this->insertAsOwnerForTenant(self::TENANT_A, 'UPDATE customer SET deleted_at = now() WHERE id = ?::uuid', [$id]);

        $this->restore($id);

        self::assertSame(200, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        self::assertSame($before, $this->fetchAsOwnerForTenant(self::TENANT_A, 'SELECT * FROM customer_address WHERE id = ?::uuid', [$address]));
    }

    // -----------------------------------------------------------------------

    private function archived(string $name, string $tenantId = self::TENANT_A): string
    {
        $id = $this->seedCustomer($tenantId, $name);
        $this->insertAsOwnerForTenant($tenantId, 'UPDATE customer SET deleted_at = now() WHERE id = ?::uuid', [$id]);

        return $id;
    }

    private function restore(string $id): void
    {
        $this->send('POST', '/customers/'.$id.'/restoration');
    }

    /** @return list<array<string, mixed>> */
    private function listed(string $query): array
    {
        $this->send('GET', '/customers'.$query);
        self::assertSame(200, $this->httpStatus());

        return $this->json()['items'];
    }

    /** @return array{code: string, deleted_at: ?string, updated_at: string} */
    private function stored(string $id): array
    {
        return $this->fetchAsOwnerForTenant(self::TENANT_A, 'SELECT code, deleted_at, updated_at FROM customer WHERE id = ?::uuid', [$id])[0];
    }
}
