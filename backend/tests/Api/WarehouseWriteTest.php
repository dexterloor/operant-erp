<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Modules\AuditLog\Infrastructure\WarehouseAuditWriter;
use App\Modules\Customers\Application\CustomerWriter;
use App\Modules\Purchasing\Infrastructure\Entity\PurchaseOrder;
use App\Modules\UserManagement\Domain\DefaultRoles;
use App\Modules\Warehouses\Application\WarehouseWriter;
use App\SharedKernel\ValueObject\AuditEvent;
use Doctrine\DBAL\Driver\AbstractException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Uid\Uuid;

/**
 * **Warehouses slice 1 C2 — `CreateWarehouse`, `UpdateWarehouse`** (`docs/session13/warehouses-s1-design.md` §3 and FINAL
 * item 3; final RED `warehouses-s1-final-red.md` M-4). `SupplierWriteTest`'s shape.
 *
 * Each refusal's literal sentence is pinned once here. The warehouse copy is a **placeholder** (`UX` owes it); the two
 * sentences shared with customers are held equal to `CustomerWriter`'s constants (one sentence per code across the house).
 *
 * **One live unique** (`warehouse_tenant_code_live`), on create AND update; **the name is not unique** (no index).
 *
 * **The trail** (`warehouse.created` / `warehouse.updated`): one row per create and per CHANGING update, read back cell by
 * cell, no row on any refusal or no-op; **values are recorded** (owner Q-W2 (a)) over an explicit allowlist that a test
 * pins by literal (final RED M-4). The race test is a real second connection holding the row. `.restored` came with
 * `RestoreWarehouse` (wh-loc-delete C1, `WarehouseRestoreTest`); `.deleted` comes with `DeleteWarehouse` (C4).
 */
final class WarehouseWriteTest extends PurchaseOrderTestCase
{
    private const HOLDER_DEADLINE_SECONDS = 10.0;

    /** The 201 body is `GetWarehouse`'s, read back. `admin` holds `warehouse.create`. */
    #[Test]
    public function anAdminCreatesAWarehouse(): void
    {
        $this->actAs(self::TENANT_A, DefaultRoles::codesFor(DefaultRoles::ADMIN));

        $this->create(['code' => 'W-MAIN', 'name' => 'Main Site', 'country_code' => 'GB']);

        self::assertSame(201, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        $body = $this->json();
        self::assertTrue(Uuid::isValid($body['id']));
        self::assertSame(['id' => $body['id'], 'code' => 'W-MAIN', 'name' => 'Main Site', 'country_code' => 'GB', 'archived_at' => null], $body);

        $this->send('GET', '/warehouses/'.$body['id']);
        self::assertSame($body, $this->json(), 'The 201 body is not GetWarehouse\'s.');
    }

    /** API-14: the header is demanded (428 without it) — and then unused: a repeat is refused by `code`, never replayed. */
    #[Test]
    public function aRepeatedCreateIsRefusedNeverReplayed(): void
    {
        $body = ['code' => 'W-DUP', 'name' => 'First', 'country_code' => 'GB'];
        $this->send('POST', '/warehouses', $body);
        self::assertSame(428, $this->httpStatus());

        $key = Uuid::v4()->toRfc4122();
        $this->send('POST', '/warehouses', $body, ['Idempotency-Key' => $key]);
        self::assertSame(201, $this->httpStatus());

        $this->send('POST', '/warehouses', $body, ['Idempotency-Key' => $key]);
        self::assertSame(409, $this->httpStatus(), 'A repeat with the same key was replayed, not refused.');
        self::assertSame('warehouse.code_is_unique_per_workspace', $this->json()['rule']);
        self::assertSame('Another warehouse already uses this code. Choose a different code.', $this->json()['detail']);
        self::assertSame(1, $this->countAsOwnerForTenant(self::TENANT_A, "SELECT count(*) FROM warehouse WHERE code = 'W-DUP'"));
    }

    /** No name unique exists (design §3.1, MEASURED): two live warehouses may share a name. */
    #[Test]
    public function twoWarehousesMayShareAName(): void
    {
        $this->create(['code' => 'W-N1', 'name' => 'Riverside', 'country_code' => 'GB']);
        self::assertSame(201, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        $this->create(['code' => 'W-N2', 'name' => 'Riverside', 'country_code' => 'GB']);
        self::assertSame(201, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
    }

    /** The code is unique per workspace and among live rows: another workspace, or an archived warehouse, frees it. */
    #[Test]
    public function aCodeIsUniqueAmongLiveWarehousesOfOneWorkspace(): void
    {
        $archived = $this->seedWarehouse(self::TENANT_A, 'Freed');
        $this->insertAsOwnerForTenant(self::TENANT_A, "UPDATE warehouse SET code = 'W-FREE', deleted_at = now() WHERE id = ?::uuid", [$archived]);
        $this->insertAsOwnerForTenant(self::TENANT_B, "UPDATE warehouse SET code = 'W-THEIRS' WHERE id = ?::uuid", [$this->seedWarehouse(self::TENANT_B, 'Theirs')]);

        $this->create(['code' => 'W-FREE', 'name' => 'Freed Again', 'country_code' => 'GB']);
        self::assertSame(201, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        $this->create(['code' => 'W-THEIRS', 'name' => 'Mine', 'country_code' => 'GB']);
        self::assertSame(201, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
    }

    /** Required fields, blanks (stored as sent otherwise — no trim), the shape and the four-country limit. */
    #[Test]
    public function aMalformedCreateIsRefusedFieldByField(): void
    {
        $before = $this->countAsOwnerForTenant(self::TENANT_A, 'SELECT count(*) FROM warehouse');

        $this->create([]);
        self::assertSame(422, $this->httpStatus());
        self::assertSame([
            ['pointer' => '/code', 'code' => 'required', 'message' => 'This field is required.'],
            ['pointer' => '/name', 'code' => 'required', 'message' => 'This field is required.'],
            ['pointer' => '/country_code', 'code' => 'required', 'message' => 'This field is required.'],
        ], $this->fieldErrors());

        $this->create(['code' => '  ', 'name' => '', 'country_code' => 'gb']);
        self::assertSame([
            ['pointer' => '/code', 'code' => 'blank', 'message' => 'This field may not be blank.'],
            ['pointer' => '/name', 'code' => 'blank', 'message' => 'This field may not be blank.'],
            ['pointer' => '/country_code', 'code' => 'not_a_country_code', 'message' => 'Use the two-letter country code, for example GB.'],
        ], $this->fieldErrors());

        $this->create(['code' => 'W-ZZ', 'name' => 'Nowhere', 'country_code' => 'ZZ']);
        self::assertSame(422, $this->httpStatus());
        self::assertSame([['pointer' => '/country_code', 'code' => 'unknown_reference', 'message' => 'This country isn\'t supported yet.']],
            $this->fieldErrors());

        self::assertSame($before, $this->countAsOwnerForTenant(self::TENANT_A, 'SELECT count(*) FROM warehouse'));
    }

    /** One sentence per code across the house: the warehouse writer's copies are the customer writer's. */
    #[Test]
    public function theSharedRefusalSentencesAreTheCustomerWritersOwn(): void
    {
        self::assertSame(
            [CustomerWriter::COUNTRY_UNSUPPORTED, CustomerWriter::COUNTRY_SHAPE],
            [WarehouseWriter::COUNTRY_UNSUPPORTED, WarehouseWriter::COUNTRY_SHAPE],
        );
    }

    /**
     * Q-W4: `address_line`, `city`, `postal_code` and `is_active` are columns, not fields — sent, they are refused as
     * undeclared (API-9), on create and on update. `RESIDUAL_GAPS.md` G-36.
     */
    #[Test]
    public function theUnwritableColumnsAreRefusedAsUndeclaredFields(): void
    {
        $before = $this->countAsOwnerForTenant(self::TENANT_A, 'SELECT count(*) FROM warehouse');
        foreach (['address_line' => '1 High St', 'city' => 'Leeds', 'postal_code' => 'LS1', 'is_active' => false] as $field => $value) {
            $this->create(['code' => 'W-COL', 'name' => 'Columns', 'country_code' => 'GB', $field => $value]);
            self::assertSame(422, $this->httpStatus(), $field.': '.$this->client()->getResponse()->getContent());
            self::assertSame([['pointer' => '/'.$field, 'code' => 'undeclared_field', 'message' => 'The field "'.$field.'" is not accepted by this operation.']],
                $this->fieldErrors());
        }
        self::assertSame($before, $this->countAsOwnerForTenant(self::TENANT_A, 'SELECT count(*) FROM warehouse'));

        $id = $this->seedWarehouse(self::TENANT_A, 'Column Target');
        foreach (['is_active' => false, 'address_line' => '1 High St'] as $field => $value) {
            $this->update($id, [$field => $value]);
            self::assertSame(422, $this->httpStatus());
            self::assertSame('undeclared_field', $this->fieldErrors()[0]['code']);
        }
        self::assertSame(['a' => 'true', 'address_line' => ''], $this->fetchAsOwnerForTenant(self::TENANT_A,
            'SELECT is_active::text AS a, address_line FROM warehouse WHERE id = ?::uuid', [$id])[0], 'A refused update changed a column.');
    }

    /** A value with surrounding spaces is stored as sent: the house refuses blanks and does not trim (`ItemWriter`). */
    #[Test]
    public function aValueIsStoredAsSent(): void
    {
        $this->create(['code' => 'W-SP ', 'name' => ' Spaced ', 'country_code' => 'GB']);

        self::assertSame(201, $this->httpStatus());
        self::assertSame(['W-SP ', ' Spaced '], [$this->json()['code'], $this->json()['name']]);
    }

    /** Each field is editable on a live warehouse by a `warehouse_operator` (`warehouse.edit`); the body is `GetWarehouse`'s. */
    #[Test]
    public function aWarehouseIsUpdatedFieldByField(): void
    {
        $id = $this->seedWarehouse(self::TENANT_A, 'Before');
        $code = $this->stored($id)['code'];
        $this->actAs(self::TENANT_A, DefaultRoles::codesFor(DefaultRoles::WAREHOUSE_OPERATOR));

        $this->update($id, ['name' => 'After']);
        self::assertSame(200, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        self::assertSame(['id' => $id, 'code' => $code, 'name' => 'After', 'country_code' => 'DE', 'archived_at' => null], $this->json());

        $this->update($id, ['code' => 'W-NEW', 'country_code' => 'US']);
        self::assertSame(['W-NEW', 'US', 'After'], [$this->json()['code'], $this->json()['country_code'], $this->json()['name']]);

        $this->update($id, []);
        self::assertSame(200, $this->httpStatus(), 'An empty PATCH is legal: nothing is written, the row is read back.');
    }

    /** Explicit `null` clears nothing: all three are NOT NULL. */
    #[Test]
    public function noFieldCanBeCleared(): void
    {
        $id = $this->seedWarehouse(self::TENANT_A, 'Kept');

        $this->update($id, ['code' => null, 'name' => null, 'country_code' => null]);

        self::assertSame(422, $this->httpStatus());
        self::assertSame(array_map(static fn (string $field): array => ['pointer' => '/'.$field, 'code' => 'not_nullable', 'message' => 'This field may not be cleared.'],
            ['code', 'name', 'country_code']), $this->fieldErrors());

        $this->update($id, ['name' => ' ']);
        self::assertSame([['pointer' => '/name', 'code' => 'blank', 'message' => 'This field may not be blank.']], $this->fieldErrors());
        self::assertSame('Kept', $this->stored($id)['name']);
    }

    /** A recode onto a live warehouse's code is refused, with the update's own sentence. */
    #[Test]
    public function aRecodeOntoATakenCodeIsRefused(): void
    {
        $taken = $this->stored($this->seedWarehouse(self::TENANT_A, 'Code Holder'))['code'];
        $id = $this->seedWarehouse(self::TENANT_A, 'Recoder');

        $this->update($id, ['code' => $taken]);

        self::assertSame(409, $this->httpStatus());
        self::assertSame('warehouse.code_is_unique_per_workspace', $this->json()['rule']);
        self::assertSame('Another warehouse already uses this code. Keep the current code or choose a different one.', $this->json()['detail']);
    }

    /**
     * Design §3.5 D2: only `"warehouse_tenant_code_live"` (quoted) is answered as the code rule; a 23505 naming any other
     * constraint is not ours and is rethrown. No second warehouse unique is reachable over HTTP today (the id is minted), so
     * the translation is driven directly with the driver's own message shape.
     */
    #[Test]
    public function aUniqueViolationOnAnotherConstraintIsRethrownNotAnsweredAsTheCode(): void
    {
        $translated = new \ReflectionMethod(WarehouseWriter::class, 'translated');
        $violation = static fn (string $constraint): UniqueConstraintViolationException => new UniqueConstraintViolationException(
            new class(\sprintf('SQLSTATE[23505]: Unique violation: 7 ERROR:  duplicate key value violates unique constraint "%s"', $constraint), '23505') extends AbstractException {},
            null,
        );

        $ours = $translated->invoke(null, $violation('warehouse_tenant_code_live'), WarehouseWriter::CODE_TAKEN_ON_CREATE);
        self::assertNotInstanceOf(UniqueConstraintViolationException::class, $ours, 'Control: the code unique was not translated.');

        foreach (['warehouse_pkey', 'warehouse_tenant_code_live_shadow', 'storage_location_tenant_warehouse_code_live'] as $other) {
            $e = $violation($other);
            self::assertSame($e, $translated->invoke(null, $e, WarehouseWriter::CODE_TAKEN_ON_CREATE), $other.' was answered as the code rule.');
        }
    }

    /** ST-9: an archived warehouse is read-only until it is restored (archived by owner SQL here; `DeleteWarehouse` since C4). Passive copy (UX R-3). */
    #[Test]
    public function anArchivedWarehouseCannotBeChanged(): void
    {
        $id = $this->seedWarehouse(self::TENANT_A, 'Closed');
        $this->insertAsOwnerForTenant(self::TENANT_A, 'UPDATE warehouse SET deleted_at = now() WHERE id = ?::uuid', [$id]);

        $this->update($id, ['name' => 'Reopened']);

        self::assertSame(409, $this->httpStatus());
        self::assertSame('warehouse.is_archived', $this->json()['rule']);
        self::assertSame('This warehouse is archived, so it can\'t be changed until it\'s restored.', $this->json()['detail']);
        self::assertSame('Closed', $this->stored($id)['name']);
    }

    /** ST-5: a foreign, absent or malformed id is the same 404 as `GetWarehouse`'s. */
    #[Test]
    public function anUpdateOfAnUnknownWarehouseIsTheSame404(): void
    {
        $bodies = [];
        foreach ([$this->seedWarehouse(self::TENANT_B, 'Theirs'), Uuid::v4()->toRfc4122(), 'not-a-uuid'] as $id) {
            $this->update($id, ['name' => 'Mine now']);
            self::assertSame(404, $this->httpStatus(), $id);
            $bodies[] = (string) $this->client()->getResponse()->getContent();
        }
        self::assertSame($bodies[0], $bodies[1]);
        self::assertSame(1, $this->countAsOwnerForTenant(self::TENANT_B, "SELECT count(*) FROM warehouse WHERE name = 'Theirs'"));
    }

    /** Permissions: create needs `warehouse.create` (owner, admin), update `warehouse.edit`; `viewer` holds neither. */
    #[Test]
    public function onlyHoldersMayCreateOrChangeAWarehouse(): void
    {
        $id = $this->seedWarehouse(self::TENANT_A, 'Untouched');

        $this->actAs(self::TENANT_A, DefaultRoles::codesFor(DefaultRoles::VIEWER));
        $this->create(['code' => 'W-V', 'name' => 'V', 'country_code' => 'GB']);
        self::assertSame(403, $this->httpStatus());
        $this->update($id, ['name' => 'Touched']);
        self::assertSame(403, $this->httpStatus());

        // `warehouse_operator` holds `warehouse.edit` but not `warehouse.create` (§1.3).
        $this->actAs(self::TENANT_A, DefaultRoles::codesFor(DefaultRoles::WAREHOUSE_OPERATOR));
        $this->create(['code' => 'W-OP', 'name' => 'Op', 'country_code' => 'GB']);
        self::assertSame(403, $this->httpStatus());

        self::assertSame('Untouched', $this->stored($id)['name']);
        self::assertSame(0, $this->countAsOwnerForTenant(self::TENANT_A, "SELECT count(*) FROM warehouse WHERE code IN ('W-V', 'W-OP')"));
    }

    /**
     * Design §3.6 / `RESIDUAL_GAPS.md` G-43 half (i), accepted (Q-W3 (a)): a rename **relabels** an issued purchase order,
     * which reads the warehouse's name through a live join — and the old name survives on the trail (`from`). Pinned so a
     * snapshot added later is a decision, not a surprise.
     */
    #[Test]
    public function aRenameRelabelsAnIssuedOrderAndTheOldNameIsOnTheTrail(): void
    {
        $warehouse = $this->seedWarehouse(self::TENANT_A, 'Old Site Name');
        $order = $this->seedOrder(self::TENANT_A, 'PO-000778', $this->seedSupplier(self::TENANT_A, 'Relabel Supplier'), $warehouse,
            status: PurchaseOrder::STATUS_ORDERED);

        $this->update($warehouse, ['name' => 'New Site Name']);
        self::assertSame(200, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());

        $this->send('GET', '/purchase-orders/'.$order);
        self::assertSame(200, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        self::assertSame(['id' => $warehouse, 'name' => 'New Site Name'], $this->json()['warehouse'], 'G-43 (i): the order no longer reads the live name.');

        self::assertSame([$this->updatedRow($warehouse, ['name' => 'Old Site Name'], ['name' => 'New Site Name'])],
            $this->auditRowsOf(AuditEvent::WAREHOUSE_UPDATED), 'The old name is not recoverable from the trail.');
    }

    /**
     * All four warehouse events are minted — in the code catalogue AND the live CHECK: `.created`, `.updated`, `.restored`
     * (wh-loc-delete C1, `RestoreWarehouse`) and `.deleted` (C4, `DeleteWarehouse`). The pair is complete.
     */
    #[Test]
    public function onlyTheWarehouseEventsWithAWriterAreMinted(): void
    {
        $definition = (string) $this->fetchAsOwnerForTenant(self::TENANT_A,
            "SELECT pg_get_constraintdef(oid) AS d FROM pg_constraint WHERE conname = 'audit_log_event_type_values'")[0]['d'];
        self::assertSame(1, preg_match_all("/'supplier\\.created'/", $definition), 'Control: the live catalogue was not read.');

        preg_match_all("/'(warehouse\\.[a-z_]+)'/", $definition, $live);
        $code = array_values(array_filter(AuditEvent::ALL, static fn (string $event): bool => str_starts_with($event, 'warehouse.')));
        sort($code);
        $liveMembers = $live[1];
        sort($liveMembers);

        self::assertSame(['warehouse.created', 'warehouse.deleted', 'warehouse.restored', 'warehouse.updated'], $code, 'AuditEvent mints a warehouse event without its writer.');
        self::assertSame(['warehouse.created', 'warehouse.deleted', 'warehouse.restored', 'warehouse.updated'], $liveMembers, 'The live CHECK admits a warehouse event without its writer.');
    }

    /** One `warehouse.created` naming the creator, carrying the three values as stored; the subject is the new warehouse. */
    #[Test]
    public function aCreatedWarehouseWritesExactlyOneRowWithItsValues(): void
    {
        $this->actAs(self::TENANT_A, DefaultRoles::codesFor(DefaultRoles::ADMIN));

        $this->create(['code' => 'W-AUD', 'name' => 'Audited Site', 'country_code' => 'US']);

        self::assertSame(201, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        self::assertSame([array_replace($this->businessRow(AuditEvent::WAREHOUSE_CREATED, 'warehouse', $this->json()['id']), [
            'metadata' => $this->jsonb(['values' => ['code' => 'W-AUD', 'name' => 'Audited Site', 'country_code' => 'US']]),
        ])], $this->auditRowsOf(AuditEvent::WAREHOUSE_CREATED));
        self::assertSame([], $this->auditRowsOf(AuditEvent::WAREHOUSE_UPDATED), 'A create wrote an update row.');
    }

    /** A create refused for a taken code (409, caught outside `run()`) or for its shape (422) writes no row. */
    #[Test]
    public function aCreateRefusedWritesNoRow(): void
    {
        $this->create(['code' => 'W-ONCE', 'name' => 'Once', 'country_code' => 'GB']);
        self::assertSame(201, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        self::assertCount(1, $this->auditRowsOf(AuditEvent::WAREHOUSE_CREATED));

        $this->create(['code' => 'W-ONCE', 'name' => 'Twice', 'country_code' => 'GB']);
        self::assertSame(409, $this->httpStatus());
        $this->create(['code' => 'W-BAD', 'name' => 'Bad', 'country_code' => 'ZZ']);
        self::assertSame(422, $this->httpStatus());

        self::assertCount(1, $this->auditRowsOf(AuditEvent::WAREHOUSE_CREATED), 'A refused create wrote a warehouse.created row.');
    }

    /** All three submitted, one changed: the row names that one field with its old and new value; a second names two. */
    #[Test]
    public function anUpdateWritesOneRowWithTheOldAndNewValuesOfOnlyTheChangedFields(): void
    {
        $id = $this->seedWarehouse(self::TENANT_A, 'Stable');
        $stored = $this->stored($id);
        $this->actAs(self::TENANT_A, DefaultRoles::codesFor(DefaultRoles::WAREHOUSE_OPERATOR));

        $this->update($id, ['country_code' => 'GB'] + $stored);
        self::assertSame(200, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        $first = $this->updatedRow($id, ['country_code' => 'DE'], ['country_code' => 'GB']);
        self::assertSame([$first], $this->auditRowsOf(AuditEvent::WAREHOUSE_UPDATED));

        $this->update($id, ['name' => 'Renamed Stable', 'code' => 'W-RENAMED']);
        self::assertSame(200, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        self::assertSame([$first, $this->updatedRow($id, ['code' => $stored['code'], 'name' => 'Stable'], ['code' => 'W-RENAMED', 'name' => 'Renamed Stable'])],
            $this->auditRowsOf(AuditEvent::WAREHOUSE_UPDATED));
        self::assertSame([], $this->auditRowsOf(AuditEvent::WAREHOUSE_CREATED), 'An update wrote a create row.');
    }

    /** All three submitted equal to the stored values: no row, no `UPDATE warehouse` statement, `updated_at` unmoved. */
    #[Test]
    public function aNoOpUpdateWritesNoRowAndNoStatement(): void
    {
        $id = $this->seedWarehouse(self::TENANT_A, 'Unchanged');
        $stored = $this->stored($id);
        $before = $this->fetchAsOwnerForTenant(self::TENANT_A, 'SELECT updated_at::text AS updated_at FROM warehouse WHERE id = ?::uuid', [$id]);

        $this->recorder()->start();
        $this->update($id, $stored);
        $this->recorder()->stop();

        self::assertSame(200, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        self::assertSame(['id' => $id] + $stored + ['archived_at' => null], $this->json(), 'A no-op update must answer the unchanged row.');
        self::assertSame([], $this->recorder()->matching('/\bUPDATE\s+warehouse\b/i'), 'A no-op update issued an UPDATE.');
        self::assertSame([], $this->auditRowsOf(AuditEvent::WAREHOUSE_UPDATED), 'A no-op update wrote a warehouse.updated row.');
        self::assertSame($before, $this->fetchAsOwnerForTenant(self::TENANT_A, 'SELECT updated_at::text AS updated_at FROM warehouse WHERE id = ?::uuid', [$id]));
    }

    /** A recode onto a taken code (409), an archived warehouse (409) and an unsupported country (422) write no row. */
    #[Test]
    public function aRefusedUpdateWritesNoRow(): void
    {
        $taken = $this->stored($this->seedWarehouse(self::TENANT_A, 'Holder'))['code'];
        $id = $this->seedWarehouse(self::TENANT_A, 'Renamer');
        $archived = $this->seedWarehouse(self::TENANT_A, 'Archived');
        $this->insertAsOwnerForTenant(self::TENANT_A, 'UPDATE warehouse SET deleted_at = now() WHERE id = ?::uuid', [$archived]);

        $this->update($id, ['code' => $taken, 'name' => 'Also renamed']);
        self::assertSame(409, $this->httpStatus());
        $this->update($archived, ['name' => 'Reopened']);
        self::assertSame(409, $this->httpStatus());
        $this->update($id, ['country_code' => 'ZZ', 'name' => 'Also renamed']);
        self::assertSame(422, $this->httpStatus());

        self::assertSame([], $this->auditRowsOf(AuditEvent::WAREHOUSE_UPDATED), 'A refused update wrote a warehouse.updated row.');
        self::assertSame('Renamer', $this->stored($id)['name'], 'A refused update changed the row.');
    }

    /**
     * **Final RED M-4 / design R-9 — the value allowlist is a test, not a docblock.** (1) The allowlist is pinned by
     * literal: widening it (a personal field inheriting the values rule) reddens here. (2) An update changing every
     * accepted field records `from`/`to` keyed exactly by the allowlist, and `.created`'s `values` likewise. (3) The writer
     * refuses a field off the allowlist rather than recording it.
     */
    #[Test]
    public function theRecordedValuesAreExactlyTheAllowlist(): void
    {
        self::assertSame(['code', 'country_code', 'name'], WarehouseAuditWriter::VALUE_FIELDS,
            'The warehouse value allowlist changed. A new field must not inherit the values rule silently (R-9): SEC decides.');

        $this->create(['code' => 'W-ALL', 'name' => 'All Fields', 'country_code' => 'GB']);
        self::assertSame(201, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        $id = $this->json()['id'];
        $this->update($id, ['code' => 'W-ALL-2', 'name' => 'All Fields 2', 'country_code' => 'US']);
        self::assertSame(200, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());

        $created = json_decode($this->auditRowsOf(AuditEvent::WAREHOUSE_CREATED)[0]['metadata'], true, flags: \JSON_THROW_ON_ERROR);
        self::assertSame(['values'], array_keys($created));
        $keys = array_keys($created['values']);
        sort($keys);
        self::assertSame(WarehouseAuditWriter::VALUE_FIELDS, $keys);

        $updated = json_decode($this->auditRowsOf(AuditEvent::WAREHOUSE_UPDATED)[0]['metadata'], true, flags: \JSON_THROW_ON_ERROR);
        $top = array_keys($updated);
        sort($top);
        self::assertSame(['fields', 'from', 'to'], $top, 'warehouse.updated carries a key other than fields/from/to.');
        self::assertSame(WarehouseAuditWriter::VALUE_FIELDS, $updated['fields']);
        foreach (['from', 'to'] as $side) {
            $sideKeys = array_keys($updated[$side]);
            sort($sideKeys);
            self::assertSame(WarehouseAuditWriter::VALUE_FIELDS, $sideKeys, $side.' is not keyed by the allowlist.');
        }

        $writer = self::getContainer()->get(WarehouseAuditWriter::class);
        \assert($writer instanceof WarehouseAuditWriter);
        try {
            $writer->recordUpdated(tenantId: self::TENANT_A, actorUserId: $this->actorId, actorLabel: 'Probe', warehouseId: $id,
                from: ['is_active' => 'true'], to: ['is_active' => 'false']);
            self::fail('WarehouseAuditWriter recorded the value of a field off its allowlist.');
        } catch (\LogicException $e) {
            self::assertStringContainsString('"is_active" is not on the warehouse value allowlist', $e->getMessage());
        }
        self::assertCount(1, $this->auditRowsOf(AuditEvent::WAREHOUSE_UPDATED), 'A refused off-allowlist value reached the trail.');
    }

    /**
     * The race the row lock exists for (`SupplierWriteTest`'s twin). A second connection renames the warehouse to
     * `Holder Name` and holds the row; this request sends `{code: new, name: <the seeded name>}`. Locked, the request waits,
     * then diffs against `Holder Name`: both fields change, the row ends at the seeded name, and the request's row records
     * `Holder Name` as the name it replaced. Unlocked, it diffs against the uncommitted seeded name, leaves the name out of
     * its `SET`, and the holder's rename survives with the request's row saying nothing about it.
     */
    #[Test]
    public function aRenameRacingAnUpdateIsRecordedWithTheValueItReplaced(): void
    {
        $id = $this->seedWarehouse(self::TENANT_A, 'Raced');
        $code = $this->stored($id)['code'];

        $holder = $this->raceAgainstAHolder(
            [["UPDATE warehouse SET name = 'Holder Name' WHERE id = ?::uuid", [$id]]],
            function () use ($id): void {
                $this->update($id, ['code' => 'W-RACED', 'name' => 'Raced']);
            },
        );

        self::assertTrue($holder['waiter_seen'], 'The request never waited on the holder, so nothing here was a race: '.json_encode($holder));
        self::assertTrue($holder['committed'], 'The holder did not commit: '.json_encode($holder));
        self::assertSame(200, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        self::assertSame('Raced', $this->stored($id)['name'], 'The request did not re-apply its name over the holder\'s rename.');
        self::assertSame([$this->updatedRow($id, ['code' => $code, 'name' => 'Holder Name'], ['code' => 'W-RACED', 'name' => 'Raced'])],
            $this->auditRowsOf(AuditEvent::WAREHOUSE_UPDATED),
            'The request\'s warehouse.updated row does not record the value it replaced.');
    }

    // -----------------------------------------------------------------------

    /** @param array<string, mixed> $body */
    private function create(array $body): void
    {
        $this->send('POST', '/warehouses', $body, ['Idempotency-Key' => Uuid::v4()->toRfc4122()]);
    }

    /** @param array<string, mixed> $body */
    private function update(string $id, array $body): void
    {
        $this->send('PATCH', '/warehouses/'.$id, $body);
    }

    /** @return list<array{pointer: string, code: string, message: string}> */
    private function fieldErrors(): array
    {
        return $this->json()['errors'];
    }

    /** @return array{code: string, name: string, country_code: string} */
    private function stored(string $id): array
    {
        return $this->fetchAsOwnerForTenant(self::TENANT_A, 'SELECT code, name, country_code FROM warehouse WHERE id = ?::uuid', [$id])[0];
    }

    /** The server's own `jsonb` text for a value, so key order is Postgres's, never PHP's. */
    private function jsonb(array $value): string
    {
        return (string) $this->fetchAsOwnerForTenant(self::TENANT_A, 'SELECT ?::jsonb::text AS m', [json_encode($value, \JSON_THROW_ON_ERROR)])[0]['m'];
    }

    /**
     * @param array<string, string> $from
     * @param array<string, string> $to
     *
     * @return array<string, ?string>
     */
    private function updatedRow(string $id, array $from, array $to): array
    {
        $fields = array_keys($from);
        sort($fields);

        return array_replace($this->businessRow(AuditEvent::WAREHOUSE_UPDATED, 'warehouse', $id), [
            'metadata' => $this->jsonb(['fields' => $fields, 'from' => $from, 'to' => $to]),
        ]);
    }

    /**
     * `SupplierWriteTest::raceAgainstAHolder`'s shape (the holder's `HOLD_TABLE` whitelist names no `warehouse`, which must
     * NOT be added): the holder's own lock is on a random `purchase_order` id, so it matches no row; the real lock is
     * `HOLD_BEFORE`'s `UPDATE warehouse`.
     *
     * @param list<array{0: string, 1: list<string>}> $before
     *
     * @return array{waiter_seen: bool, committed: bool, sqlstate: ?string, error: ?string}
     */
    private function raceAgainstAHolder(array $before, callable $request): array
    {
        $readyFile = tempnam(sys_get_temp_dir(), 'erp_warehouse_');
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
