<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Modules\Customers\Application\CustomerWriter;
use App\Modules\Customers\Application\SharedNameSwitch;
use App\SharedKernel\ValueObject\AuditEvent;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Uid\Uuid;

/**
 * **BK-47 — the shared customer-name refusal** (`docs/session13/bk47-design.md` Revision 1 — FINAL R1-3 / R1-4, as corrected
 * by `bk47-final-red.md`; `RESIDUAL_GAPS.md` G-48). `CreateCustomer`, `UpdateCustomer` (a name change beyond capitals and
 * outer white space) and `RestoreCustomer` refuse 409 `customer.name_is_shared` when another customer in the workspace,
 * live or archived, has the same name under `lower(btrim(name, CustomerWriter::NAME_TRIM_CHARACTERS))`, unless the request
 * carries the query parameter `?allow_shared_name=true`. Names stay non-unique (`s34-ruling.md` D-2): the switch always
 * gets through, apart from races. A code collision is answered first, so the name 409 is offered only when the resend
 * would succeed.
 *
 * **R, the recorder assertion** (`bk47-red.md` H-1): every refusal is also asserted to have issued NO `INSERT INTO customer`
 * / `UPDATE customer`. The recorder logs a statement before it runs, so a write that is attempted and then fails 23505, or
 * runs and is rolled back by a later refusal, is still seen; at the HTTP level both look the same as a check that ran
 * first. **R-control** (final RED L-1): T-1, T-7a, T-8a and T-10c end by sending the request that succeeds inside a second
 * window and asserting the same pattern matches exactly one statement, so the assertion above them cannot be vacuous.
 *
 * **T-6 pins the comparison's recorded limits** (N-D): a no-break space, a doubled inner space, and ß against SS are NOT
 * the same name. Widening the comparison is a decision; this pin makes it one.
 */
final class CustomerSharedNameTest extends PurchaseOrderTestCase
{
    /** The customer write any refused request must not have issued. */
    private const CUSTOMER_WRITE = '/\b(INSERT\s+INTO|UPDATE)\s+customer\b/i';

    private const HOLDER_DEADLINE_SECONDS = 10.0;

    /** T-1 — a create beside a live namesake, no switch: 409, the literal rule and sentence, no row, no trail row (+R). */
    #[Test]
    public function aCreateBesideALiveNamesakeIsRefused(): void
    {
        $this->seedCustomer(self::TENANT_A, 'Acme Ltd');

        $this->refusedWithoutAWrite('T-1', fn () => $this->create('C-NEW', 'Acme Ltd'));

        self::assertSame(409, $this->httpStatus(), $this->responseContent());
        self::assertSame('customer.name_is_shared', $this->json()['rule']);
        self::assertSame(CustomerWriter::NAME_SHARED_ON_CREATE, $this->json()['detail']);
        self::assertSame('Another customer, live or archived, already has this name, so this customer was not created. Two customers can share a name: to create it anyway, send the request again with allow_shared_name=true.', $this->json()['detail']);
        self::assertSame(1, $this->namedCount('Acme Ltd'));
        self::assertSame([], $this->auditRowsOf(AuditEvent::CUSTOMER_CREATED), 'A refused create wrote a trail row.');

        // R-control: the same request with the switch issues exactly one INSERT, so the R above was not vacuous.
        $this->recorder()->start();
        $this->create('C-NEW', 'Acme Ltd', '?allow_shared_name=true');
        $this->recorder()->stop();
        self::assertSame(201, $this->httpStatus(), $this->responseContent());
        self::assertCount(1, $this->recorder()->matching(self::CUSTOMER_WRITE), 'R-control: the recorder did not see the create\'s INSERT.');
    }

    /** T-2 — with `?allow_shared_name=true` the create goes ahead: two live customers of one name, one trail row. */
    #[Test]
    public function theSwitchLetsACreateShareAName(): void
    {
        $this->seedCustomer(self::TENANT_A, 'Acme Ltd');

        $this->create('C-NEW', 'Acme Ltd', '?allow_shared_name=true');

        self::assertSame(201, $this->httpStatus(), $this->responseContent());
        self::assertSame(2, $this->namedCount('Acme Ltd'));
        self::assertCount(1, $this->auditRowsOf(AuditEvent::CUSTOMER_CREATED));
    }

    /** @return iterable<string, array{string, string}> stored name => sent name */
    public static function sameNames(): iterable
    {
        yield 'lower case' => ['Acme Ltd', 'acme ltd'];
        yield 'upper case' => ['Acme Ltd', 'ACME LTD'];
        yield 'outer space and tab' => ['Acme Ltd', " Acme Ltd\t"];
        yield 'trailing line feed' => ['Acme Ltd', "Acme Ltd\n"];
        yield 'vertical tab and carriage return' => ['Acme Ltd', "\x0BAcme Ltd\r"];
        // M-2 (measured 2026-11-24 and again at build): the database's `lower('İ')` is `i`, PHP's `mb_strtolower('İ')` is
        // `i` + U+0307. Both directions are one name to the database; the first is the row that tells a PHP-side
        // comparison (mutation M20) from the SQL one.
        yield 'dotted capital I sent, ASCII stored' => ['Istanbul Ltd', 'İstanbul Ltd'];
        yield 'dotted capital I stored, ASCII sent' => ['İstanbul Ltd', 'istanbul ltd'];
        // Option A, P-1 (c): the STORED side is normalised too (the generated column), not only the sent one.
        yield 'padded name stored, tab' => [" Acme Ltd\t", 'acme ltd'];
        yield 'padded name stored, line feed' => ["Acme Ltd\n", 'acme ltd'];
    }

    /** T-3 — capitals and the trim set's outer characters do not make a new name (+R on each). */
    #[Test]
    #[DataProvider('sameNames')]
    public function capitalsAndOuterWhiteSpaceCount(string $stored, string $sent): void
    {
        $this->seedCustomer(self::TENANT_A, $stored);

        $this->refusedWithoutAWrite('T-3', fn () => $this->create('C-NEW', $sent));

        self::assertSame(409, $this->httpStatus(), $this->responseContent());
        self::assertSame('customer.name_is_shared', $this->json()['rule']);
    }

    /** T-4 — an archived namesake counts, on each of the three operations (+R on each). */
    #[Test]
    public function anArchivedNamesakeCountsOnEveryOperation(): void
    {
        $this->archived('Acme Ltd');

        // (a) create
        $this->refusedWithoutAWrite('T-4a', fn () => $this->create('C-NEW', 'Acme Ltd'));
        self::assertSame(409, $this->httpStatus(), $this->responseContent());
        self::assertSame(CustomerWriter::NAME_SHARED_ON_CREATE, $this->json()['detail']);

        // (b) rename onto the archived namesake's name
        $other = $this->seedCustomer(self::TENANT_A, 'Other');
        $this->refusedWithoutAWrite('T-4b', fn () => $this->update($other, ['name' => 'Acme Ltd']));
        self::assertSame(409, $this->httpStatus(), $this->responseContent());
        self::assertSame(CustomerWriter::NAME_SHARED_ON_UPDATE, $this->json()['detail']);
        self::assertSame('Other', $this->stored($other)['name']);

        // (c) restore of archived A beside ARCHIVED B of the same name
        $a = $this->archived('Shared Archived');
        $this->archived('Shared Archived');
        $this->refusedWithoutAWrite('T-4c', fn () => $this->restore($a));
        self::assertSame(409, $this->httpStatus(), $this->responseContent());
        self::assertSame(CustomerWriter::NAME_SHARED_ON_RESTORE, $this->json()['detail']);
        self::assertNotNull($this->stored($a)['deleted_at'], 'A refused restore restored the customer.');
    }

    /** T-5 — another workspace's namesake does not count (RLS scopes the check). */
    #[Test]
    public function anotherWorkspacesNamesakeDoesNotCount(): void
    {
        $this->seedCustomer(self::TENANT_B, 'Acme Ltd');

        $this->create('C-NEW', 'Acme Ltd');

        self::assertSame(201, $this->httpStatus(), $this->responseContent());
    }

    /** @return iterable<string, array{string, string}> stored name => sent name */
    public static function differentNames(): iterable
    {
        yield 'a trailing no-break space' => ['Acme Ltd', "Acme Ltd\u{00A0}"];
        yield 'a doubled inner space' => ['Acme Ltd', 'Acme  Ltd'];
        yield 'sharp s against SS' => ['STRASSE', 'Straße'];
    }

    /**
     * T-6 — **the recorded limits of the comparison (N-D, G-48), pinned**: these are NOT one name. Widening the comparison
     * (Unicode white space, inner white space, case folding beyond the database's `lower()`) is a decision, and this pin
     * makes it one.
     */
    #[Test]
    #[DataProvider('differentNames')]
    public function theComparisonsLimitsArePinned(string $stored, string $sent): void
    {
        $this->seedCustomer(self::TENANT_A, $stored);

        $this->create('C-NEW', $sent);

        self::assertSame(201, $this->httpStatus(), $this->responseContent());
    }

    /** T-7 — update: a rename onto a namesake is refused; the switch, a case- or outer-space-only rename and a PATCH without `name` are not. */
    #[Test]
    public function aRenameOntoANamesakeIsRefusedUnlessAllowed(): void
    {
        $this->seedCustomer(self::TENANT_A, 'Acme Ltd');
        $other = $this->seedCustomer(self::TENANT_A, 'Other');

        // (a) no switch (+R)
        $this->refusedWithoutAWrite('T-7a', fn () => $this->update($other, ['name' => 'Acme Ltd']));
        self::assertSame(409, $this->httpStatus(), $this->responseContent());
        self::assertSame('customer.name_is_shared', $this->json()['rule']);
        self::assertSame(CustomerWriter::NAME_SHARED_ON_UPDATE, $this->json()['detail']);
        self::assertSame('Another customer, live or archived, already has this name, so the change was not saved. Two customers can share a name: to save it anyway, send the request again with allow_shared_name=true.', $this->json()['detail']);
        self::assertSame('Other', $this->stored($other)['name']);
        self::assertSame([], $this->auditRowsOf(AuditEvent::CUSTOMER_UPDATED), 'A refused rename wrote a trail row.');

        // (b) with the switch — also the R-control: exactly one UPDATE customer
        $this->recorder()->start();
        $this->update($other, ['name' => 'Acme Ltd'], '?allow_shared_name=true');
        $this->recorder()->stop();
        self::assertSame(200, $this->httpStatus(), $this->responseContent());
        self::assertSame('Acme Ltd', $this->stored($other)['name']);
        self::assertCount(1, $this->recorder()->matching(self::CUSTOMER_WRITE), 'R-control: the recorder did not see the rename\'s UPDATE.');

        // (c) case-only rename of a second `Acme Ltd` (seeded by owner SQL) beside a namesake: written, audited, unchecked
        $twin = $this->seedCustomer(self::TENANT_A, 'Acme Ltd');
        $this->update($twin, ['name' => 'ACME LTD']);
        self::assertSame(200, $this->httpStatus(), $this->responseContent());
        self::assertSame('ACME LTD', $this->stored($twin)['name']);
        $rows = array_values(array_filter($this->auditRowsOf(AuditEvent::CUSTOMER_UPDATED), static fn (array $r): bool => $r['subject_id'] === $twin));
        self::assertCount(1, $rows);
        self::assertSame(['fields' => ['name']], json_decode($rows[0]['metadata'], true));

        // (d) outer-space-only rename of the same customer: written, unchecked
        $this->update($twin, ['name' => ' Acme Ltd ']);
        self::assertSame(200, $this->httpStatus(), $this->responseContent());
        self::assertSame(' Acme Ltd ', $this->stored($twin)['name']);

        // (e) a PATCH without `name` on that namesake: unchecked
        $this->update($twin, ['payment_terms_days' => 45]);
        self::assertSame(200, $this->httpStatus(), $this->responseContent());
    }

    /** T-8 — restore beside a live namesake (replaces the flipped `CustomerRestoreTest` pin, E-1). */
    #[Test]
    public function aRestoreBesideALiveNamesakeIsRefusedUnlessAllowed(): void
    {
        $a = $this->archived('Shared Name');
        $this->seedCustomer(self::TENANT_A, 'Shared Name');

        // (a) no switch (+R)
        $this->refusedWithoutAWrite('T-8a', fn () => $this->restore($a));
        self::assertSame(409, $this->httpStatus(), $this->responseContent());
        self::assertSame('customer.name_is_shared', $this->json()['rule']);
        self::assertSame(CustomerWriter::NAME_SHARED_ON_RESTORE, $this->json()['detail']);
        self::assertSame('Another customer, live or archived, has this customer\'s name, so it was not restored. Two customers can share a name: to restore it anyway, send the request again with allow_shared_name=true.', $this->json()['detail']);
        self::assertNotNull($this->stored($a)['deleted_at']);
        self::assertSame([], $this->auditRowsOf(AuditEvent::CUSTOMER_RESTORED), 'A refused restore wrote a trail row.');

        // (b) with the switch — also the R-control
        $this->recorder()->start();
        $this->restore($a, '?allow_shared_name=true');
        $this->recorder()->stop();
        self::assertSame(200, $this->httpStatus(), $this->responseContent());
        self::assertSame(2, $this->countAsOwnerForTenant(self::TENANT_A, "SELECT count(*) FROM customer WHERE name = 'Shared Name' AND deleted_at IS NULL"));
        self::assertCount(1, $this->recorder()->matching(self::CUSTOMER_WRITE), 'R-control: the recorder did not see the restore\'s UPDATE.');
    }

    /** T-9 — the own-id exclusion: a customer never meets itself (a restore with no other namesake; a create of a fresh name). */
    #[Test]
    public function aCustomerNeverMeetsItsOwnName(): void
    {
        $a = $this->archived('Only One');

        $this->restore($a);
        self::assertSame(200, $this->httpStatus(), $this->responseContent());

        $this->create('C-FRESH', 'Nobody Has This');
        self::assertSame(201, $this->httpStatus(), $this->responseContent());
    }

    /** T-10a — order, switch OFF: a code collision is answered before a shared name, on every operation (+R on each). */
    #[Test]
    public function aCodeCollisionIsAnsweredBeforeASharedName(): void
    {
        $holder = $this->seedCustomer(self::TENANT_A, 'Namesake');
        $takenCode = $this->stored($holder)['code'];

        // create
        $this->refusedWithoutAWrite('T-10a create, code', fn () => $this->create($takenCode, 'Namesake'));
        self::assertSame([409, CustomerWriter::CODE_TAKEN_ON_CREATE], [$this->httpStatus(), $this->json()['detail']], $this->responseContent());
        $this->refusedWithoutAWrite('T-10a create, name', fn () => $this->create('C-FREE-1', 'Namesake'));
        self::assertSame([409, 'customer.name_is_shared'], [$this->httpStatus(), $this->json()['rule']], $this->responseContent());

        // update
        $target = $this->seedCustomer(self::TENANT_A, 'Target');
        $this->refusedWithoutAWrite('T-10a update, code', fn () => $this->update($target, ['code' => $takenCode, 'name' => 'Namesake']));
        self::assertSame([409, CustomerWriter::CODE_TAKEN_ON_UPDATE], [$this->httpStatus(), $this->json()['detail']], $this->responseContent());
        $this->refusedWithoutAWrite('T-10a update, name', fn () => $this->update($target, ['code' => 'C-FREE-2', 'name' => 'Namesake']));
        self::assertSame([409, 'customer.name_is_shared'], [$this->httpStatus(), $this->json()['rule']], $this->responseContent());

        // restore: an archived customer whose code the live holder now has AND whose name the holder shares
        $archived = $this->archivedSharingCodeAndName($holder);
        $this->refusedWithoutAWrite('T-10a restore, code', fn () => $this->restore($archived));
        self::assertSame([409, CustomerWriter::CODE_TAKEN_ON_RESTORE], [$this->httpStatus(), $this->json()['detail']], $this->responseContent());
        $this->insertAsOwnerForTenant(self::TENANT_A, "UPDATE customer SET code = 'C-MOVED' WHERE id = ?::uuid", [$holder]);
        $this->refusedWithoutAWrite('T-10a restore, name', fn () => $this->restore($archived));
        self::assertSame([409, 'customer.name_is_shared'], [$this->httpStatus(), $this->json()['rule']], $this->responseContent());
    }

    /** T-10b — order, switch OFF: an unsupported reference (422) is answered before a shared name (FR is unsupported, G-37). */
    #[Test]
    public function anUnsupportedReferenceIsAnsweredBeforeASharedName(): void
    {
        $this->seedCustomer(self::TENANT_A, 'Namesake');

        $this->send('POST', '/customers', ['code' => 'C-FR', 'name' => 'Namesake', 'country_code' => 'FR', 'currency_code' => 'EUR'], ['Idempotency-Key' => Uuid::v4()->toRfc4122()]);

        self::assertSame(422, $this->httpStatus(), $this->responseContent());
        self::assertSame([['pointer' => '/country_code', 'code' => 'unknown_reference', 'message' => CustomerWriter::COUNTRY_UNSUPPORTED]], $this->json()['errors']);
    }

    /**
     * T-10c — **the switch never skips the code check** (switch ON, +R): the three code collisions answer the code 409 AND
     * issue no customer write. Without the pre-check (M19), or with one the switch skips (M7), the write is attempted, fails
     * 23505 and answers the same 409 — only the recorder sees it.
     */
    #[Test]
    public function theSwitchNeverSkipsTheCodeCheck(): void
    {
        $holder = $this->seedCustomer(self::TENANT_A, 'Namesake');
        $target = $this->seedCustomer(self::TENANT_A, 'Target');
        $archived = $this->archivedSharingCodeAndName($holder);
        $takenCode = $this->stored($holder)['code'];

        $this->refusedWithoutAWrite('T-10c create', fn () => $this->create($takenCode, 'Namesake', '?allow_shared_name=true'));
        self::assertSame([409, CustomerWriter::CODE_TAKEN_ON_CREATE], [$this->httpStatus(), $this->json()['detail']], $this->responseContent());

        $this->refusedWithoutAWrite('T-10c update', fn () => $this->update($target, ['code' => $takenCode, 'name' => 'Namesake'], '?allow_shared_name=true'));
        self::assertSame([409, CustomerWriter::CODE_TAKEN_ON_UPDATE], [$this->httpStatus(), $this->json()['detail']], $this->responseContent());

        $this->refusedWithoutAWrite('T-10c restore', fn () => $this->restore($archived, '?allow_shared_name=true'));
        self::assertSame([409, CustomerWriter::CODE_TAKEN_ON_RESTORE], [$this->httpStatus(), $this->json()['detail']], $this->responseContent());

        // R-control: once the holder's code is moved by owner SQL, the same restore succeeds with exactly one UPDATE.
        $this->insertAsOwnerForTenant(self::TENANT_A, "UPDATE customer SET code = 'C-MOVED' WHERE id = ?::uuid", [$holder]);
        $this->recorder()->start();
        $this->restore($archived, '?allow_shared_name=true');
        $this->recorder()->stop();
        self::assertSame(200, $this->httpStatus(), $this->responseContent());
        self::assertCount(1, $this->recorder()->matching(self::CUSTOMER_WRITE), 'R-control: the recorder did not see the restore\'s UPDATE.');
    }

    /**
     * T-11 — **every caller is checked (β, SEC)**: a seat holding `customer.create` alone (no `customer.view`) is refused too,
     * and the body is the house business-rule shape and nothing else: no id, code or name of the other customer.
     */
    #[Test]
    public function aCreateOnlySeatIsCheckedAndLearnsOneBitOnly(): void
    {
        $other = $this->seedCustomer(self::TENANT_A, 'Acme Ltd');
        $otherCode = $this->stored($other)['code'];
        $this->actAs(self::TENANT_A, ['customer.create']);

        $this->create('C-NEW', 'Acme Ltd');

        self::assertSame(409, $this->httpStatus(), $this->responseContent());
        $keys = array_keys($this->json());
        sort($keys);
        self::assertSame(['detail', 'message', 'rule', 'status', 'title', 'type'], $keys, 'The refusal carries a member beyond the house business-rule shape.');
        self::assertStringNotContainsString($other, $this->responseContent(), 'The refusal names the other customer\'s id.');
        self::assertStringNotContainsString($otherCode, $this->responseContent(), 'The refusal names the other customer\'s code.');
    }

    /** @return iterable<string, array{string}> */
    public static function malformedSwitches(): iterable
    {
        yield 'yes' => ['allow_shared_name=yes'];
        yield 'empty' => ['allow_shared_name='];
        yield 'two' => ['allow_shared_name=2'];
        yield 'the array form' => ['allow_shared_name[]=1'];
    }

    /** T-13a — a malformed switch is one 422 at `?allow_shared_name`, on each of the three operations (+R). */
    #[Test]
    #[DataProvider('malformedSwitches')]
    public function aMalformedSwitchIsRefusedOnEveryOperation(string $query): void
    {
        $expected = [['pointer' => '?allow_shared_name', 'code' => 'not_a_boolean', 'message' => 'allow_shared_name must be true or false.']];
        $target = $this->seedCustomer(self::TENANT_A, 'Target');
        $archived = $this->archived('Archived One');

        foreach ([
            'create' => fn () => $this->create('C-NEW', 'Fresh Name', '?'.$query),
            'update' => fn () => $this->update($target, ['name' => 'Renamed'], '?'.$query),
            'restore' => fn () => $this->restore($archived, '?'.$query),
        ] as $operation => $send) {
            $this->refusedWithoutAWrite('T-13a '.$operation, $send);
            self::assertSame(422, $this->httpStatus(), $operation.': '.$this->responseContent());
            self::assertSame('urn:erp:problem:validation-failed', $this->json()['type'], $operation);
            self::assertSame($expected, $this->json()['errors'], $operation);
        }
        self::assertSame(SharedNameSwitch::NOT_A_BOOLEAN, $expected[0]['message']);
    }

    /** T-13b — **one 422**: the body's errors first, in `refuseMalformed`'s field order, then the switch's (`bk47-red.md` L-4). */
    #[Test]
    public function aMalformedSwitchJoinsTheBodysErrors(): void
    {
        $this->send('POST', '/customers?allow_shared_name=yes', ['code' => 'C-NEW', 'name' => ' ', 'country_code' => 'GB', 'currency_code' => 'GBP'], ['Idempotency-Key' => Uuid::v4()->toRfc4122()]);

        self::assertSame(422, $this->httpStatus(), $this->responseContent());
        self::assertSame([
            ['pointer' => '/name', 'code' => 'blank', 'message' => 'This field may not be blank.'],
            ['pointer' => '?allow_shared_name', 'code' => 'not_a_boolean', 'message' => 'allow_shared_name must be true or false.'],
        ], $this->json()['errors']);
    }

    /** T-13c — `1` means on and `0` means off. */
    #[Test]
    public function oneIsOnAndZeroIsOff(): void
    {
        $this->seedCustomer(self::TENANT_A, 'Acme Ltd');

        $this->create('C-ZERO', 'Acme Ltd', '?allow_shared_name=0');
        self::assertSame(409, $this->httpStatus(), $this->responseContent());

        $this->create('C-ONE', 'Acme Ltd', '?allow_shared_name=1');
        self::assertSame(201, $this->httpStatus(), $this->responseContent());
    }

    /**
     * T-14 — **acceptance of the declared value, driven here** (`QueryParameterContractTest` half 3 drives lists only;
     * final RED L-3: hand-written requests, the recipes are not reused). No namesake present: `false` and `0` answer each
     * operation's success status.
     */
    #[Test]
    public function theSwitchIsAcceptedWhenOffOnEveryOperation(): void
    {
        foreach (['false', '0'] as $i => $value) {
            $this->create('C-T14-'.$i, 'Accepted Create '.$i, '?allow_shared_name='.$value);
            self::assertSame(201, $this->httpStatus(), 'create '.$value.': '.$this->responseContent());

            $target = $this->seedCustomer(self::TENANT_A, 'Accepted Target '.$i);
            $this->update($target, ['name' => 'Accepted Rename '.$i], '?allow_shared_name='.$value);
            self::assertSame(200, $this->httpStatus(), 'update '.$value.': '.$this->responseContent());

            $archived = $this->archived('Accepted Restore '.$i);
            $this->restore($archived, '?allow_shared_name='.$value);
            self::assertSame(200, $this->httpStatus(), 'restore '.$value.': '.$this->responseContent());
        }
    }

    /**
     * T-15 — **the 23505 backstop survives the pre-check**: a second connection inserts a customer with the code and holds
     * its transaction open; the request's pre-check (READ COMMITTED) cannot see it and passes, its INSERT then waits at the
     * unique index, the holder commits, and the 23505 is still the code 409 — never a 500.
     */
    #[Test]
    public function aCodeTakenAfterThePreCheckIsStillTheCode409(): void
    {
        $holder = $this->raceAgainstAHolder(
            [[
                "INSERT INTO customer (id, tenant_id, code, name, country_code, currency_code) VALUES (?::uuid, ?::uuid, 'C-RACED', 'Holder Name', 'GB', 'GBP')",
                [Uuid::v4()->toRfc4122(), self::TENANT_A],
            ]],
            function (): void {
                $this->create('C-RACED', 'Request Name');
            },
        );

        self::assertTrue($holder['waiter_seen'], 'The request never waited on the holder, so nothing here was a race: '.json_encode($holder));
        self::assertTrue($holder['committed'], 'The holder did not commit: '.json_encode($holder));
        self::assertSame(409, $this->httpStatus(), $this->responseContent());
        self::assertSame('customer.code_is_unique_per_workspace', $this->json()['rule']);
        self::assertSame(CustomerWriter::CODE_TAKEN_ON_CREATE, $this->json()['detail']);
        self::assertSame(0, $this->namedCount('Request Name'));
    }

    /**
     * **P-1 (a) — one normalisation, two copies** (option A, `bk47-option-a-design.md` Revision 1 item 5): the trim set is
     * the PHP constant {@see CustomerWriter::NAME_TRIM_CHARACTERS} (bound in every statement) AND the `chr()` list in
     * `Version20261125000100`'s generation expression. The catalogue's expression, as PostgreSQL deparses it, must equal
     * the one built from the constant's characters in order. *Mutations:* drop or add a `chr()` in the migration, or drop a
     * character from the constant → red.
     */
    #[Test]
    public function theStoredNormalisationIsTheConstantsTrimSet(): void
    {
        $characters = mb_str_split(CustomerWriter::NAME_TRIM_CHARACTERS);
        $set = ' ' === $characters[0] ? "' '::text" : 'chr('.mb_ord($characters[0]).')';
        foreach (\array_slice($characters, 1) as $character) {
            $set = '('.$set.' || chr('.mb_ord($character).'))';
        }

        $expression = $this->fetchAsOwnerForTenant(self::TENANT_A, <<<'SQL'
            SELECT pg_get_expr(d.adbin, d.adrelid) AS expression
              FROM pg_attrdef d JOIN pg_attribute a ON a.attrelid = d.adrelid AND a.attnum = d.adnum
             WHERE d.adrelid = 'customer'::regclass AND a.attname = 'name_normalised' AND a.attgenerated = 's'
            SQL)[0]['expression'] ?? null;

        self::assertSame('lower(btrim(name, '.$set.'))', $expression,
            'customer.name_normalised is not lower(btrim(name)) over exactly CustomerWriter::NAME_TRIM_CHARACTERS.');
    }

    /**
     * **P-1 (b) — the same, by behaviour, from a FIXED probe list** (never derived from the constant): each character
     * wrapped around a name, plus mixed case and the dotted capital İ. The stored column must equal the statement's own
     * normalisation of the same name; form feed (chr 12) and the no-break space (chr 160) must survive (G-48's bounds).
     */
    #[Test]
    public function theStoredNormalisationMatchesTheStatementsOnAFixedProbeList(): void
    {
        $probes = [' ', "\t", "\n", "\r", "\x0B", "\x0C", "\u{00A0}"];
        $names = array_map(static fn (string $c): string => $c.'Acme Ltd'.$c, $probes);
        $names[] = 'MiXeD Case Ltd';
        $names[] = 'İstanbul Ltd';
        foreach ($names as $name) {
            $this->seedCustomer(self::TENANT_A, $name);
        }

        $rows = $this->fetchAsOwnerForTenant(self::TENANT_A,
            'SELECT name, name_normalised, name_normalised IS NOT DISTINCT FROM lower(btrim(name, ?::text)) AS same FROM customer ORDER BY name',
            [CustomerWriter::NAME_TRIM_CHARACTERS]);
        self::assertCount(\count($names), $rows);
        foreach ($rows as $row) {
            self::assertTrue($row['same'], 'Stored and statement normalisation differ for '.json_encode($row['name']).': '.json_encode($row['name_normalised']));
        }
        $stored = array_column($rows, 'name_normalised', 'name');
        self::assertSame("\x0Cacme ltd\x0C", $stored["\x0CAcme Ltd\x0C"], 'A form feed was trimmed: the trim set grew.');
        self::assertSame("\u{00A0}acme ltd\u{00A0}", $stored["\u{00A0}Acme Ltd\u{00A0}"], 'A no-break space was trimmed: the trim set grew.');
        self::assertSame('acme ltd', $stored["\x0BAcme Ltd\x0B"], 'A vertical tab was not trimmed.');
    }

    // -----------------------------------------------------------------------

    /** R: the request `$send` makes issues no `INSERT INTO customer` / `UPDATE customer`, seen before it runs. */
    private function refusedWithoutAWrite(string $case, callable $send): void
    {
        $this->recorder()->start();
        $send();
        $this->recorder()->stop();

        self::assertSame([], $this->recorder()->matching(self::CUSTOMER_WRITE), $case.': a refused request issued a customer write.');
    }

    private function create(string $code, string $name, string $query = ''): void
    {
        $this->send('POST', '/customers'.$query, ['code' => $code, 'name' => $name, 'country_code' => 'GB', 'currency_code' => 'GBP'], ['Idempotency-Key' => Uuid::v4()->toRfc4122()]);
    }

    /** @param array<string, mixed> $body */
    private function update(string $id, array $body, string $query = ''): void
    {
        $this->send('PATCH', '/customers/'.$id.$query, $body);
    }

    private function restore(string $id, string $query = ''): void
    {
        $this->send('POST', '/customers/'.$id.'/restoration'.$query);
    }

    private function archived(string $name): string
    {
        $id = $this->seedCustomer(self::TENANT_A, $name);
        $this->insertAsOwnerForTenant(self::TENANT_A, 'UPDATE customer SET deleted_at = now() WHERE id = ?::uuid', [$id]);

        return $id;
    }

    /** An archived customer whose code `$holder` (live) now holds and whose name `$holder` shares. */
    private function archivedSharingCodeAndName(string $holder): string
    {
        $archived = $this->archived($this->stored($holder)['name']);
        $code = $this->stored($archived)['code'];
        $this->insertAsOwnerForTenant(self::TENANT_A, 'UPDATE customer SET code = ? WHERE id = ?::uuid', [$code, $holder]);

        return $archived;
    }

    private function namedCount(string $name): int
    {
        return $this->countAsOwnerForTenant(self::TENANT_A, 'SELECT count(*) FROM customer WHERE name = \''.str_replace('\'', '\'\'', $name).'\'');
    }

    /** @return array{code: string, name: string, deleted_at: ?string} */
    private function stored(string $id): array
    {
        return $this->fetchAsOwnerForTenant(self::TENANT_A, 'SELECT code, name, deleted_at FROM customer WHERE id = ?::uuid', [$id])[0];
    }

    /**
     * `CustomerWriteTest::raceAgainstAHolder`'s shape (`DepleteStockTest`'s): the holder's own lock is on a random
     * `purchase_order` id, so it matches no row; the real hold is `HOLD_BEFORE`'s uncommitted `INSERT INTO customer`, and the
     * request waits at the code's unique index on the holder's transaction, which `pg_blocking_pids` reports.
     *
     * @param list<array{0: string, 1: list<string>}> $before
     *
     * @return array{waiter_seen: bool, committed: bool, sqlstate: ?string, error: ?string}
     */
    private function raceAgainstAHolder(array $before, callable $request): array
    {
        $readyFile = tempnam(sys_get_temp_dir(), 'erp_customer_name_');
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
