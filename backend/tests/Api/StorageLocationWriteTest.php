<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Modules\AuditLog\Infrastructure\StorageLocationAuditWriter;
use App\Modules\Inventory\Application\Contract\DepleteStock;
use App\Modules\Inventory\Application\Contract\PickableStock;
use App\Modules\Inventory\Application\Contract\StockDepletion;
use App\Modules\Inventory\Application\Contract\StockDepletionLine;
use App\Modules\Inventory\Application\Contract\StockDepletionRefused;
use App\Modules\Sales\Infrastructure\Entity\SalesOrder;
use App\Modules\UserManagement\Domain\DefaultRoles;
use App\Modules\UserManagement\Domain\OwnerMarker;
use App\Modules\Warehouses\Application\Contract\LocationKinds;
use App\Modules\Warehouses\Application\StorageLocationWriter;
use App\SharedKernel\ValueObject\AuditEvent;
use Doctrine\DBAL\Driver\AbstractException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Uid\Uuid;

/**
 * **Warehouses slice 1 C3 — `CreateStorageLocation`, `UpdateStorageLocation`** (`docs/session13/warehouses-s1-design.md` §4
 * and FINAL item 4; final RED `warehouses-s1-final-red.md` §3 H-2, M-1, M-2, M-4, L-1, L-5). `WarehouseWriteTest`'s shape.
 *
 * Each refusal's literal sentence is pinned once here; the location copy is a **placeholder** (`UX` owes it).
 *
 * **The quarantine rule (owner Q-W1 (d)):** a hold (into `quarantine`) is always allowed; a release (out of `quarantine`)
 * of a location holding stock is the owner's alone — `admin` and `warehouse_operator` are refused 409
 * `storage_location.release_needs_owner` whatever kind they release to (L-1); an empty location is released by any editor.
 * **One rule (M-1):** whenever `kind` changes, `held_stock` is recorded, read in a statement after the row lock.
 *
 * **The races, two connections each** (a real second process holds the lock; `hold_order_lock.php` commits once it sees
 * this request wait):
 *  - (a) **a non-owner release waits for a receipt in flight into an empty quarantine location, then sees its stock and is
 *    refused** (M-2) — M3 (`FOR NO KEY UPDATE`), M10 (the `EXISTS` folded into the locking read) and M16 (no locking read)
 *    each turn it red; (a′) the same with the owner → 200 with `held_stock: true`;
 *  - (b) **a hold committed first makes a direct `DepleteStock` wait, then refuse** (H-2) — M4 (drop the depleter's
 *    `FOR KEY SHARE`) turns it red;
 *  - (c) **the same hold against `CreateFulfilment`** — the fulfilment line's foreign-key lock is today's control, so (c)
 *    stays green under M4 (a regression test, recorded).
 */
final class StorageLocationWriteTest extends PurchaseOrderTestCase
{
    private const HOLDER_DEADLINE_SECONDS = 10.0;

    // ---- create ------------------------------------------------------------

    /** `admin` holds `storage_location.create`. Kind omitted → the column default `bin`; 201 with `Location`. */
    #[Test]
    public function anAdminCreatesALocationWithTheDefaultKind(): void
    {
        $warehouse = $this->seedWarehouse(self::TENANT_A, 'Create Site');
        $this->actAs(self::TENANT_A, DefaultRoles::codesFor(DefaultRoles::ADMIN));

        $this->create($warehouse, ['code' => 'A-01']);

        self::assertSame(201, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        $body = $this->json();
        self::assertTrue(Uuid::isValid($body['id']));
        self::assertSame(['id' => $body['id'], 'code' => 'A-01', 'kind' => 'bin', 'archived_at' => null], $body);
        self::assertSame('/api/v1/warehouses/'.$warehouse.'/locations/'.$body['id'], $this->client()->getResponse()->headers->get('Location'));

        $this->send('GET', '/warehouses/'.$warehouse.'/locations');
        self::assertSame([$body], $this->json()['items'], 'The 201 body is not the listed item.');
    }

    /** Each of the five kinds is accepted; a sixth, and an explicit `null`, are refused before any row is written. */
    #[Test]
    public function eachOfTheFiveKindsIsAcceptedAndNothingElse(): void
    {
        $warehouse = $this->seedWarehouse(self::TENANT_A, 'Kinds Site');
        foreach (LocationKinds::ALL as $i => $kind) {
            $this->create($warehouse, ['code' => 'K-0'.$i, 'kind' => $kind]);
            self::assertSame(201, $this->httpStatus(), $kind.': '.$this->client()->getResponse()->getContent());
            self::assertSame($kind, $this->json()['kind']);
        }

        $this->create($warehouse, ['code' => 'K-X', 'kind' => 'pallet']);
        self::assertSame(422, $this->httpStatus());
        self::assertSame([['pointer' => '/kind', 'code' => 'not_a_location_kind', 'message' => 'Use one of: bin, shelf, zone, staging, quarantine.']], $this->fieldErrors());

        $this->create($warehouse, ['code' => 'K-N', 'kind' => null]);
        self::assertSame(422, $this->httpStatus());
        self::assertSame([['pointer' => '/kind', 'code' => 'not_nullable', 'message' => 'This field may not be cleared.']], $this->fieldErrors());

        self::assertSame(5, $this->countAsOwnerForTenant(self::TENANT_A, 'SELECT count(*) FROM storage_location WHERE warehouse_id = ?::uuid', [$warehouse]));
    }

    /**
     * Design §4.2: `LocationKinds::ALL` is the live `storage_location_kind_values` CHECK (server-derived), and its
     * never-pickable kind is `PickableStock`'s — two copies held equal, because `InventoryContract` may not import
     * `WarehousesContract` (mutation M8).
     */
    #[Test]
    public function theKindsAreTheLiveCheckAndTheNeverPickableKindIsInventorysOwn(): void
    {
        $definition = (string) $this->fetchAsOwnerForTenant(self::TENANT_A,
            "SELECT pg_get_constraintdef(oid) AS d FROM pg_constraint WHERE conname = 'storage_location_kind_values'")[0]['d'];
        preg_match_all("/'([a-z_]+)'/", $definition, $live);

        self::assertNotSame([], $live[1], 'Control: the live CHECK was not read.');
        self::assertSame($live[1], LocationKinds::ALL, 'LocationKinds::ALL is not the live storage_location_kind_values CHECK.');
        self::assertSame(PickableStock::NEVER_PICKABLE_KIND, LocationKinds::NEVER_PICKABLE,
            'The Warehouses and Inventory copies of the never-pickable kind disagree.');
    }

    /** Required, blank and the body's `warehouse_id` (the path's — API-9 undeclared). Nothing is written. */
    #[Test]
    public function aMalformedCreateIsRefused(): void
    {
        $warehouse = $this->seedWarehouse(self::TENANT_A, 'Malformed Site');

        $this->create($warehouse, []);
        self::assertSame(422, $this->httpStatus());
        self::assertSame([['pointer' => '/code', 'code' => 'required', 'message' => 'This field is required.']], $this->fieldErrors());

        $this->create($warehouse, ['code' => '  ']);
        self::assertSame([['pointer' => '/code', 'code' => 'blank', 'message' => 'This field may not be blank.']], $this->fieldErrors());

        $this->create($warehouse, ['code' => 'A-01', 'warehouse_id' => $this->seedWarehouse(self::TENANT_A, 'Other')]);
        self::assertSame(422, $this->httpStatus());
        self::assertSame([['pointer' => '/warehouse_id', 'code' => 'undeclared_field', 'message' => 'The field "warehouse_id" is not accepted by this operation.']],
            $this->fieldErrors());

        self::assertSame(0, $this->countAsOwnerForTenant(self::TENANT_A, 'SELECT count(*) FROM storage_location'));
    }

    /** API-14: the header is demanded (428 without it) — and then unused: a repeat is refused by `code`, never replayed. */
    #[Test]
    public function aRepeatedCreateIsRefusedNeverReplayed(): void
    {
        $warehouse = $this->seedWarehouse(self::TENANT_A, 'Repeat Site');
        $this->send('POST', '/warehouses/'.$warehouse.'/locations', ['code' => 'R-01']);
        self::assertSame(428, $this->httpStatus());

        $key = Uuid::v4()->toRfc4122();
        $this->send('POST', '/warehouses/'.$warehouse.'/locations', ['code' => 'R-01'], ['Idempotency-Key' => $key]);
        self::assertSame(201, $this->httpStatus());
        $this->send('POST', '/warehouses/'.$warehouse.'/locations', ['code' => 'R-01'], ['Idempotency-Key' => $key]);

        self::assertSame(409, $this->httpStatus(), 'A repeat with the same key was replayed, not refused.');
        self::assertSame('storage_location.code_is_unique_per_warehouse', $this->json()['rule']);
        self::assertSame('Another location in this warehouse already uses this code. Choose a different code.', $this->json()['detail']);
    }

    /** The code is unique per warehouse among live rows: another warehouse, or an archived location, frees it. */
    #[Test]
    public function aCodeIsUniqueAmongTheLiveLocationsOfOneWarehouse(): void
    {
        $warehouse = $this->seedWarehouse(self::TENANT_A, 'Unique Site');
        $other = $this->seedWarehouse(self::TENANT_A, 'Other Site');
        $this->seedStorageLocation(self::TENANT_A, $warehouse, 'U-01');
        $archived = $this->seedStorageLocation(self::TENANT_A, $warehouse, 'U-02');
        $this->insertAsOwnerForTenant(self::TENANT_A, 'UPDATE storage_location SET deleted_at = now() WHERE id = ?::uuid', [$archived]);

        $this->create($warehouse, ['code' => 'U-01']);
        self::assertSame(409, $this->httpStatus());
        $this->create($other, ['code' => 'U-01']);
        self::assertSame(201, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        $this->create($warehouse, ['code' => 'U-02']);
        self::assertSame(201, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
    }

    /** ST-5: a foreign, absent or malformed warehouse is the same 404, on create and update. */
    #[Test]
    public function anUnknownWarehouseIsTheSame404(): void
    {
        $bodies = [];
        foreach ([$this->seedWarehouse(self::TENANT_B, 'Theirs'), Uuid::v4()->toRfc4122(), 'not-a-uuid'] as $id) {
            $this->create($id, ['code' => 'Z-01']);
            self::assertSame(404, $this->httpStatus(), $id);
            $bodies[] = (string) $this->client()->getResponse()->getContent();
        }
        self::assertSame($bodies[0], $bodies[1]);
        self::assertSame(0, $this->countAsOwnerForTenant(self::TENANT_B, "SELECT count(*) FROM storage_location WHERE code = 'Z-01'"));
    }

    /** ST-9: an archived warehouse's locations are read-only — no add, no change (409 `warehouse.is_archived`). */
    #[Test]
    public function anArchivedWarehouseRefusesANewLocationAndAChange(): void
    {
        $warehouse = $this->seedWarehouse(self::TENANT_A, 'Closed Site');
        $location = $this->seedStorageLocation(self::TENANT_A, $warehouse, 'C-01');
        $this->insertAsOwnerForTenant(self::TENANT_A, 'UPDATE warehouse SET deleted_at = now() WHERE id = ?::uuid', [$warehouse]);

        $this->create($warehouse, ['code' => 'C-02']);
        self::assertSame(409, $this->httpStatus());
        self::assertSame('warehouse.is_archived', $this->json()['rule']);
        self::assertSame('This warehouse is archived, so no location can be added to it until it\'s restored.', $this->json()['detail']);

        $this->update($warehouse, $location, ['code' => 'C-03']);
        self::assertSame(409, $this->httpStatus());
        self::assertSame('warehouse.is_archived', $this->json()['rule']);
        self::assertSame('This warehouse is archived, so its locations can\'t be changed until it\'s restored.', $this->json()['detail']);

        self::assertSame(['C-01'], array_column($this->fetchAsOwnerForTenant(self::TENANT_A,
            'SELECT code FROM storage_location WHERE warehouse_id = ?::uuid', [$warehouse]), 'code'));
    }

    // ---- update ------------------------------------------------------------

    /** Each field is editable by a `warehouse_operator` (`storage_location.edit`); the body is the listed item. */
    #[Test]
    public function aLocationIsUpdatedFieldByField(): void
    {
        $warehouse = $this->seedWarehouse(self::TENANT_A, 'Edit Site');
        $location = $this->seedStorageLocation(self::TENANT_A, $warehouse, 'E-01');
        $this->actAs(self::TENANT_A, DefaultRoles::codesFor(DefaultRoles::WAREHOUSE_OPERATOR));

        $this->update($warehouse, $location, ['code' => 'E-02']);
        self::assertSame(200, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        self::assertSame(['id' => $location, 'code' => 'E-02', 'kind' => 'bin', 'archived_at' => null], $this->json());

        $this->update($warehouse, $location, ['kind' => 'shelf']);
        self::assertSame(['E-02', 'shelf'], [$this->json()['code'], $this->json()['kind']]);

        $this->update($warehouse, $location, []);
        self::assertSame(200, $this->httpStatus(), 'An empty PATCH is legal: nothing is written, the row is read back.');

        $this->update($warehouse, $location, ['code' => null, 'kind' => null]);
        self::assertSame(422, $this->httpStatus());
        self::assertSame(array_map(static fn (string $f): array => ['pointer' => '/'.$f, 'code' => 'not_nullable', 'message' => 'This field may not be cleared.'],
            ['code', 'kind']), $this->fieldErrors());
        self::assertSame(['code' => 'E-02', 'kind' => 'shelf'], $this->stored($location));
    }

    /** Design §1.5: `warehouse_id` is frozen — moving a location would move its stock with no movement. */
    #[Test]
    public function aBodyWarehouseIdIsRefusedOnUpdate(): void
    {
        $warehouse = $this->seedWarehouse(self::TENANT_A, 'Frozen Site');
        $location = $this->seedStorageLocation(self::TENANT_A, $warehouse, 'F-01');

        $this->update($warehouse, $location, ['warehouse_id' => $this->seedWarehouse(self::TENANT_A, 'Elsewhere')]);

        self::assertSame(422, $this->httpStatus());
        self::assertSame('undeclared_field', $this->fieldErrors()[0]['code']);
        self::assertSame($warehouse, $this->fetchAsOwnerForTenant(self::TENANT_A,
            'SELECT warehouse_id::text AS w FROM storage_location WHERE id = ?::uuid', [$location])[0]['w']);
    }

    /** ST-5: another warehouse's location under this path, a random id and a malformed one are the same 404. */
    #[Test]
    public function aLocationOfAnotherWarehouseIsNotFoundUnderThisPath(): void
    {
        $warehouse = $this->seedWarehouse(self::TENANT_A, 'Path Site');
        $theirs = $this->seedStorageLocation(self::TENANT_A, $this->seedWarehouse(self::TENANT_A, 'Neighbour'), 'N-01');

        $bodies = [];
        foreach ([$theirs, Uuid::v4()->toRfc4122(), 'not-a-uuid'] as $id) {
            $this->update($warehouse, $id, ['code' => 'MINE']);
            self::assertSame(404, $this->httpStatus(), $id);
            $bodies[] = (string) $this->client()->getResponse()->getContent();
        }
        self::assertSame($bodies[0], $bodies[1]);
        self::assertSame('N-01', $this->stored($theirs)['code']);
    }

    /** ST-9: an archived location is read-only until it is restored (wh-loc-delete C2). Passive copy (UX R-3), no "Restore it". */
    #[Test]
    public function anArchivedLocationCannotBeChanged(): void
    {
        $warehouse = $this->seedWarehouse(self::TENANT_A, 'Archive Site');
        $location = $this->seedStorageLocation(self::TENANT_A, $warehouse, 'D-01');
        $this->insertAsOwnerForTenant(self::TENANT_A, 'UPDATE storage_location SET deleted_at = now() WHERE id = ?::uuid', [$location]);

        $this->update($warehouse, $location, ['code' => 'D-02']);

        self::assertSame(409, $this->httpStatus());
        self::assertSame('storage_location.is_archived', $this->json()['rule']);
        self::assertSame('This location is archived, so it can\'t be changed until it\'s restored.', $this->json()['detail']);
        self::assertSame('D-01', $this->stored($location)['code']);
    }

    /** A recode onto a live location's code in this warehouse is refused, with the update's own sentence. */
    #[Test]
    public function aRecodeOntoATakenCodeIsRefused(): void
    {
        $warehouse = $this->seedWarehouse(self::TENANT_A, 'Recode Site');
        $this->seedStorageLocation(self::TENANT_A, $warehouse, 'T-01');
        $location = $this->seedStorageLocation(self::TENANT_A, $warehouse, 'T-02');

        $this->update($warehouse, $location, ['code' => 'T-01']);

        self::assertSame(409, $this->httpStatus());
        self::assertSame('storage_location.code_is_unique_per_warehouse', $this->json()['rule']);
        self::assertSame('Another location in this warehouse already uses this code. Keep the current code or choose a different one.', $this->json()['detail']);
    }

    /** Only `"storage_location_tenant_warehouse_code_live"` (quoted) is answered as the code rule; any other 23505 is rethrown. */
    #[Test]
    public function aUniqueViolationOnAnotherConstraintIsRethrownNotAnsweredAsTheCode(): void
    {
        $translated = new \ReflectionMethod(StorageLocationWriter::class, 'translated');
        $violation = static fn (string $constraint): UniqueConstraintViolationException => new UniqueConstraintViolationException(
            new class(\sprintf('SQLSTATE[23505]: Unique violation: 7 ERROR:  duplicate key value violates unique constraint "%s"', $constraint), '23505') extends AbstractException {},
            null,
        );

        $ours = $translated->invoke(null, $violation('storage_location_tenant_warehouse_code_live'), StorageLocationWriter::CODE_TAKEN_ON_CREATE);
        self::assertNotInstanceOf(UniqueConstraintViolationException::class, $ours, 'Control: the code unique was not translated.');

        foreach (['storage_location_pkey', 'storage_location_tenant_warehouse_code_live_shadow', 'warehouse_tenant_code_live'] as $other) {
            $e = $violation($other);
            self::assertSame($e, $translated->invoke(null, $e, StorageLocationWriter::CODE_TAKEN_ON_CREATE), $other.' was answered as the code rule.');
        }
    }

    /** Permissions: create needs `storage_location.create` (owner, admin), update `.edit`; `viewer` holds neither. */
    #[Test]
    public function onlyHoldersMayCreateOrChangeALocation(): void
    {
        $warehouse = $this->seedWarehouse(self::TENANT_A, 'Perm Site');
        $location = $this->seedStorageLocation(self::TENANT_A, $warehouse, 'P-01');

        $this->actAs(self::TENANT_A, DefaultRoles::codesFor(DefaultRoles::VIEWER));
        $this->create($warehouse, ['code' => 'P-V']);
        self::assertSame(403, $this->httpStatus());
        $this->update($warehouse, $location, ['code' => 'P-V']);
        self::assertSame(403, $this->httpStatus());

        // `warehouse_operator` holds `storage_location.edit` but not `.create` (design §1.3).
        $this->actAs(self::TENANT_A, DefaultRoles::codesFor(DefaultRoles::WAREHOUSE_OPERATOR));
        $this->create($warehouse, ['code' => 'P-OP']);
        self::assertSame(403, $this->httpStatus());

        self::assertSame('P-01', $this->stored($location)['code']);
        self::assertSame(1, $this->countAsOwnerForTenant(self::TENANT_A, 'SELECT count(*) FROM storage_location WHERE warehouse_id = ?::uuid', [$warehouse]));
    }

    // ---- the quarantine rule (Q-W1 (d)) ------------------------------------

    /**
     * A hold of a STOCKED location by a `warehouse_operator` is allowed and records `held_stock: true` (final RED M-1's
     * added test; mutation M2 — the guard firing on a hold — reddens it).
     */
    #[Test]
    public function aHoldOfAStockedLocationIsAllowedAndRecordsThatItHeldStock(): void
    {
        [$warehouse, $location] = $this->stockedLocation('bin', '4');
        $this->actAs(self::TENANT_A, DefaultRoles::codesFor(DefaultRoles::WAREHOUSE_OPERATOR));

        $this->update($warehouse, $location, ['kind' => 'quarantine']);

        self::assertSame(200, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        self::assertSame('quarantine', $this->stored($location)['kind']);
        self::assertSame([$this->updatedRow($location, ['kind' => 'bin'], ['kind' => 'quarantine'], true)],
            $this->auditRowsOf(AuditEvent::STORAGE_LOCATION_UPDATED));
    }

    /** @return iterable<string, array{string, string}> */
    public static function releasesByARoleWithoutTheMarker(): iterable
    {
        foreach ([DefaultRoles::WAREHOUSE_OPERATOR, DefaultRoles::ADMIN] as $role) {
            foreach (['bin', 'shelf', 'zone', 'staging'] as $kind) {
                yield $role.' to '.$kind => [$role, $kind];
            }
        }
    }

    /**
     * **A release of a stocked quarantine location, to ANY other kind, by a role without the owner marker is refused**
     * (Q-W1 (d); final RED L-1's data provider over the four exits; `admin` lacks the marker, T-15). The kind, the stock and
     * the trail are untouched. Mutation M1 (drop the guard) reddens every case.
     */
    #[Test]
    #[DataProvider('releasesByARoleWithoutTheMarker')]
    public function aReleaseOfAStockedLocationIsRefusedWithoutTheOwnerMarker(string $role, string $to): void
    {
        self::assertNotContains(OwnerMarker::CODE, DefaultRoles::codesFor($role), 'Precondition (T-15): '.$role.' holds the owner marker.');
        [$warehouse, $location] = $this->stockedLocation('quarantine', '6');
        $this->actAs(self::TENANT_A, DefaultRoles::codesFor($role));

        $this->update($warehouse, $location, ['kind' => $to, 'code' => 'Q-RENAMED']);

        self::assertSame(409, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        self::assertSame('storage_location.release_needs_owner', $this->json()['rule']);
        self::assertSame('This location holds stock, so only an owner can take it out of quarantine.', $this->json()['detail']);
        self::assertSame(['code' => 'Q-01', 'kind' => 'quarantine'], $this->stored($location), 'A refused release changed the row.');
        self::assertSame([], $this->auditRowsOf(AuditEvent::STORAGE_LOCATION_UPDATED), 'A refused release wrote a row.');
    }

    /** The owner releases a stocked quarantine location; the trail records `held_stock: true`. Mutation M11 reddens it. */
    #[Test]
    public function theOwnerReleasesAStockedLocation(): void
    {
        [$warehouse, $location] = $this->stockedLocation('quarantine', '6');
        $this->actAs(self::TENANT_A, DefaultRoles::codesFor(DefaultRoles::OWNER));

        $this->update($warehouse, $location, ['kind' => 'bin']);

        self::assertSame(200, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        self::assertSame('bin', $this->stored($location)['kind']);
        self::assertSame([$this->updatedRow($location, ['kind' => 'quarantine'], ['kind' => 'bin'], true)],
            $this->auditRowsOf(AuditEvent::STORAGE_LOCATION_UPDATED));
    }

    /**
     * An EMPTY quarantine location is released by any editor — both "never held stock" and "held stock that was all
     * picked" (a level row at zero). Mutation M5 (`qty_on_hand >= 0`) reddens the second.
     */
    #[Test]
    public function anEmptyLocationIsReleasedByAnyEditor(): void
    {
        [$warehouse, $drained] = $this->stockedLocation('quarantine', '0');
        $never = $this->seedStorageLocation(self::TENANT_A, $warehouse, 'Q-02', 'quarantine');
        $this->actAs(self::TENANT_A, DefaultRoles::codesFor(DefaultRoles::WAREHOUSE_OPERATOR));

        foreach ([$drained, $never] as $location) {
            $this->update($warehouse, $location, ['kind' => 'staging']);
            self::assertSame(200, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        }

        self::assertSame([
            $this->updatedRow($drained, ['kind' => 'quarantine'], ['kind' => 'staging'], false),
            $this->updatedRow($never, ['kind' => 'quarantine'], ['kind' => 'staging'], false),
        ], $this->auditRowsOf(AuditEvent::STORAGE_LOCATION_UPDATED));
    }

    /** Changes among the other four kinds are free, stocked or not (nothing reads them); `held_stock` is still recorded. */
    #[Test]
    public function aChangeAmongThePickableKindsIsFree(): void
    {
        [$warehouse, $location] = $this->stockedLocation('bin', '3');
        $this->actAs(self::TENANT_A, DefaultRoles::codesFor(DefaultRoles::WAREHOUSE_OPERATOR));

        $this->update($warehouse, $location, ['kind' => 'shelf']);

        self::assertSame(200, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        self::assertSame([$this->updatedRow($location, ['kind' => 'bin'], ['kind' => 'shelf'], true)],
            $this->auditRowsOf(AuditEvent::STORAGE_LOCATION_UPDATED));
    }

    /**
     * **The lock follows the body (design §4.1, L-5):** `FOR UPDATE` when `kind` is submitted, `FOR NO KEY UPDATE` when only
     * the code is, and the stock is read only when the kind changes — never on a re-label. Mutation M3 reddens it too.
     */
    #[Test]
    public function theRowLockIsChosenByWhetherTheKindCanChange(): void
    {
        [$warehouse, $location] = $this->stockedLocation('bin', '2');

        $this->recorder()->start();
        $this->update($warehouse, $location, ['code' => 'L-02']);
        $this->recorder()->stop();
        self::assertSame(200, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        self::assertCount(1, $this->recorder()->matching('/FROM storage_location .*FOR NO KEY UPDATE/s'), 'A re-label did not take FOR NO KEY UPDATE.');
        self::assertSame([], $this->recorder()->matching('/FOR UPDATE/'), 'A re-label took FOR UPDATE: it would wait for every stock write.');
        self::assertSame([], $this->recorder()->matching('/stock_level/'), 'A re-label read the stock.');

        $this->recorder()->start();
        $this->update($warehouse, $location, ['kind' => 'zone']);
        $this->recorder()->stop();
        self::assertSame(200, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        self::assertCount(1, $this->recorder()->matching('/FROM storage_location .*FOR UPDATE/s'), 'A kind change did not take FOR UPDATE.');
        self::assertCount(1, $this->recorder()->matching('/FROM stock_level/'), 'A kind change did not read the stock in its own statement.');
    }

    /**
     * End to end, both directions: an owner's release puts the location on the pick list and a fulfilment picks from it; a
     * hold takes it off the pick list and `CreateFulfilment` refuses it (`storage_location.is_pickable`).
     */
    #[Test]
    public function aReleaseMakesStockPickableAndAHoldStopsThePick(): void
    {
        $f = $this->confirmedOrder('SO-449000', '5');
        $location = $this->seedStorageLocation(self::TENANT_A, $f['warehouse'], 'H-01', 'quarantine');
        $this->stock($f['item'], $location, '5');

        self::assertSame([], $this->candidateBins($f['order']), 'A quarantine location was offered for picking.');
        $this->actAs(self::TENANT_A, DefaultRoles::codesFor(DefaultRoles::OWNER));
        $this->update($f['warehouse'], $location, ['kind' => 'bin']);
        self::assertSame(200, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        self::assertSame([$location], $this->candidateBins($f['order']), 'The released location is not offered for picking.');

        $this->actAs(self::TENANT_A, DefaultRoles::codesFor(DefaultRoles::WAREHOUSE_OPERATOR));
        $this->fulfil($f['order'], $f['line'], $location, '2');
        self::assertSame(201, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());

        $this->update($f['warehouse'], $location, ['kind' => 'quarantine']);
        self::assertSame(200, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        self::assertSame([], $this->candidateBins($f['order']), 'A held location is still offered for picking.');
        $this->fulfil($f['order'], $f['line'], $location, '1');
        self::assertSame(422, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        self::assertSame('storage_location.is_pickable', $this->json()['errors'][0]['code']);
        self::assertSame('3.000000', $this->onHand($location));
    }

    /**
     * `RESIDUAL_GAPS.md` G-43 half (ii), accepted (Q-W3 (a)): a re-label **relabels** an issued shipment, which reads the
     * location's code through a live join — and the old code survives on the trail (`from`). Pinned so a snapshot added
     * later is a decision, not a surprise (`WarehouseWriteTest::aRenameRelabelsAnIssuedOrderAndTheOldNameIsOnTheTrail`'s twin).
     */
    #[Test]
    public function aRelabelRelabelsAnIssuedShipmentAndTheOldCodeIsOnTheTrail(): void
    {
        $f = $this->confirmedOrder('SO-449200', '5');
        $location = $this->seedStorageLocation(self::TENANT_A, $f['warehouse'], 'OLD-01');
        $this->stock($f['item'], $location, '5');
        $this->fulfil($f['order'], $f['line'], $location, '2');
        self::assertSame(201, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        $fulfilment = $this->json()['id'];

        $this->update($f['warehouse'], $location, ['code' => 'NEW-01']);
        self::assertSame(200, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());

        $this->send('GET', '/fulfilments/'.$fulfilment);
        self::assertSame(['id' => $location, 'code' => 'NEW-01'], $this->json()['lines'][0]['storage_location'],
            'G-43 (ii): the shipment no longer reads the live code.');
        self::assertSame([$this->updatedRow($location, ['code' => 'OLD-01'], ['code' => 'NEW-01'], null)],
            $this->auditRowsOf(AuditEvent::STORAGE_LOCATION_UPDATED), 'The old code is not recoverable from the trail.');
    }

    // ---- the trail ---------------------------------------------------------

    /**
     * All four location events are minted — in the code catalogue AND the live CHECK: `.created`, `.updated`, `.restored`
     * (wh-loc-delete C2, `RestoreStorageLocation`) and `.deleted` (C3, `DeleteStorageLocation`). The pair is complete.
     */
    #[Test]
    public function onlyTheStorageLocationEventsWithAWriterAreMinted(): void
    {
        $definition = (string) $this->fetchAsOwnerForTenant(self::TENANT_A,
            "SELECT pg_get_constraintdef(oid) AS d FROM pg_constraint WHERE conname = 'audit_log_event_type_values'")[0]['d'];
        self::assertSame(1, preg_match_all("/'warehouse\\.created'/", $definition), 'Control: the live catalogue was not read.');

        preg_match_all("/'(storage_location\\.[a-z_]+)'/", $definition, $live);
        $code = array_values(array_filter(AuditEvent::ALL, static fn (string $event): bool => str_starts_with($event, 'storage_location.')));
        sort($code);
        $liveMembers = $live[1];
        sort($liveMembers);

        self::assertSame(['storage_location.created', 'storage_location.deleted', 'storage_location.restored', 'storage_location.updated'], $code, 'AuditEvent mints a location event without its writer.');
        self::assertSame(['storage_location.created', 'storage_location.deleted', 'storage_location.restored', 'storage_location.updated'], $liveMembers, 'The live CHECK admits a location event without its writer.');
    }

    /** One `storage_location.created` naming the creator, with the code and the STORED kind (the default when omitted). */
    #[Test]
    public function aCreatedLocationWritesExactlyOneRowWithItsValues(): void
    {
        $warehouse = $this->seedWarehouse(self::TENANT_A, 'Audit Site');
        $this->actAs(self::TENANT_A, DefaultRoles::codesFor(DefaultRoles::ADMIN));

        $this->create($warehouse, ['code' => 'AU-01']);

        self::assertSame(201, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        self::assertSame([array_replace($this->businessRow(AuditEvent::STORAGE_LOCATION_CREATED, 'storage_location', $this->json()['id']), [
            'metadata' => $this->jsonb(['values' => ['code' => 'AU-01', 'kind' => 'bin']]),
        ])], $this->auditRowsOf(AuditEvent::STORAGE_LOCATION_CREATED));
        self::assertSame([], $this->auditRowsOf(AuditEvent::STORAGE_LOCATION_UPDATED), 'A create wrote an update row.');
    }

    /** A create refused for a taken code (409), a shape (422) or an archived warehouse (409) writes no row. */
    #[Test]
    public function aCreateRefusedWritesNoRow(): void
    {
        $warehouse = $this->seedWarehouse(self::TENANT_A, 'Once Site');
        $closed = $this->seedWarehouse(self::TENANT_A, 'Closed');
        $this->insertAsOwnerForTenant(self::TENANT_A, 'UPDATE warehouse SET deleted_at = now() WHERE id = ?::uuid', [$closed]);

        $this->create($warehouse, ['code' => 'O-01']);
        self::assertSame(201, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        $this->create($warehouse, ['code' => 'O-01']);
        self::assertSame(409, $this->httpStatus());
        $this->create($warehouse, ['code' => 'O-02', 'kind' => 'pallet']);
        self::assertSame(422, $this->httpStatus());
        $this->create($closed, ['code' => 'O-03']);
        self::assertSame(409, $this->httpStatus());

        self::assertCount(1, $this->auditRowsOf(AuditEvent::STORAGE_LOCATION_CREATED), 'A refused create wrote a storage_location.created row.');
    }

    /** A re-label writes one row with the old and new code and NO `held_stock` (the kind did not change — M-1). */
    #[Test]
    public function aRelabelWritesOneRowWithTheOldAndNewCodeAndNoStockAnswer(): void
    {
        $warehouse = $this->seedWarehouse(self::TENANT_A, 'Relabel Site');
        $location = $this->seedStorageLocation(self::TENANT_A, $warehouse, 'RL-01');

        $this->update($warehouse, $location, ['code' => 'RL-02', 'kind' => 'bin']);

        self::assertSame(200, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        self::assertSame([$this->updatedRow($location, ['code' => 'RL-01'], ['code' => 'RL-02'], null)],
            $this->auditRowsOf(AuditEvent::STORAGE_LOCATION_UPDATED));
    }

    /** Both submitted equal to the stored values: no row, no `UPDATE storage_location`, no stock read, `updated_at` unmoved. */
    #[Test]
    public function aNoOpUpdateWritesNoRowAndNoStatement(): void
    {
        $warehouse = $this->seedWarehouse(self::TENANT_A, 'Noop Site');
        $location = $this->seedStorageLocation(self::TENANT_A, $warehouse, 'NO-01', 'quarantine');
        $before = $this->fetchAsOwnerForTenant(self::TENANT_A, 'SELECT updated_at::text AS u FROM storage_location WHERE id = ?::uuid', [$location]);

        $this->recorder()->start();
        $this->update($warehouse, $location, ['code' => 'NO-01', 'kind' => 'quarantine']);
        $this->recorder()->stop();

        self::assertSame(200, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        self::assertSame([], $this->recorder()->matching('/\bUPDATE\s+storage_location\b/i'), 'A no-op update issued an UPDATE.');
        self::assertSame([], $this->recorder()->matching('/stock_level/'), 'A no-op update read the stock.');
        self::assertSame([], $this->auditRowsOf(AuditEvent::STORAGE_LOCATION_UPDATED), 'A no-op update wrote a row.');
        self::assertSame($before, $this->fetchAsOwnerForTenant(self::TENANT_A, 'SELECT updated_at::text AS u FROM storage_location WHERE id = ?::uuid', [$location]));
    }

    /**
     * **Final RED M-4 / design R-9 — the value allowlist is a test, not a docblock.** (1) Pinned by literal (M15). (2) An
     * update changing every accepted field records `from`/`to` keyed by the allowlist, plus `held_stock` (the kind changed).
     * (3) The writer refuses a field off the allowlist, and refuses `held_stock` unless the kind changed (M-1's one rule).
     */
    #[Test]
    public function theRecordedValuesAreExactlyTheAllowlist(): void
    {
        self::assertSame(['code', 'kind'], StorageLocationAuditWriter::VALUE_FIELDS,
            'The storage location value allowlist changed. A new field must not inherit the values rule silently (R-9): SEC decides.');

        $warehouse = $this->seedWarehouse(self::TENANT_A, 'Allowlist Site');
        $this->create($warehouse, ['code' => 'AL-01', 'kind' => 'shelf']);
        self::assertSame(201, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        $id = $this->json()['id'];
        $this->update($warehouse, $id, ['code' => 'AL-02', 'kind' => 'zone']);
        self::assertSame(200, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());

        $created = json_decode($this->auditRowsOf(AuditEvent::STORAGE_LOCATION_CREATED)[0]['metadata'], true, flags: \JSON_THROW_ON_ERROR);
        self::assertSame(['values'], array_keys($created));
        $keys = array_keys($created['values']);
        sort($keys);
        self::assertSame(StorageLocationAuditWriter::VALUE_FIELDS, $keys);

        $updated = json_decode($this->auditRowsOf(AuditEvent::STORAGE_LOCATION_UPDATED)[0]['metadata'], true, flags: \JSON_THROW_ON_ERROR);
        $top = array_keys($updated);
        sort($top);
        self::assertSame(['fields', 'from', 'held_stock', 'to'], $top, 'storage_location.updated carries a key other than fields/from/to/held_stock.');
        self::assertSame(StorageLocationAuditWriter::VALUE_FIELDS, $updated['fields']);
        self::assertFalse($updated['held_stock']);
        foreach (['from', 'to'] as $side) {
            $sideKeys = array_keys($updated[$side]);
            sort($sideKeys);
            self::assertSame(StorageLocationAuditWriter::VALUE_FIELDS, $sideKeys, $side.' is not keyed by the allowlist.');
        }

        $writer = self::getContainer()->get(StorageLocationAuditWriter::class);
        \assert($writer instanceof StorageLocationAuditWriter);
        foreach ([
            '"warehouse_id" is not on the storage location value allowlist' => [['warehouse_id' => 'a'], ['warehouse_id' => 'b'], null],
            'held_stock exactly when kind changed' => [['code' => 'a'], ['code' => 'b'], true],
            'held_stock exactly when kind changed ' => [['kind' => 'bin'], ['kind' => 'zone'], null],
        ] as $expected => [$from, $to, $held]) {
            try {
                $writer->recordUpdated(tenantId: self::TENANT_A, actorUserId: $this->actorId, actorLabel: 'Probe', storageLocationId: $id,
                    from: $from, to: $to, heldStock: $held);
                self::fail('StorageLocationAuditWriter recorded: '.$expected);
            } catch (\LogicException $e) {
                self::assertStringContainsString(trim($expected), $e->getMessage());
            }
        }
        self::assertCount(1, $this->auditRowsOf(AuditEvent::STORAGE_LOCATION_UPDATED), 'A refused record reached the trail.');
    }

    // ---- the races ---------------------------------------------------------

    /**
     * **Race (a), final RED M-2:** a goods receipt is in flight into an EMPTY quarantine location — its row naming the
     * location is inserted (holding `FOR KEY SHARE` on it) and not committed. A `warehouse_operator`'s release waits on its
     * `FOR UPDATE`, then reads the stock in a separate statement, sees the receipt's committed units and is refused.
     * M3 (`FOR NO KEY UPDATE`), M16 (no locking read) and M10 (the stock read folded into the locking read) each let the
     * release through on a location that holds stock → red.
     */
    #[Test]
    public function aNonOwnerReleaseWaitsForAReceiptInFlightAndIsThenRefused(): void
    {
        [$warehouse, $location, $item] = $this->emptyQuarantineLocation();
        $this->actAs(self::TENANT_A, DefaultRoles::codesFor(DefaultRoles::WAREHOUSE_OPERATOR));

        $holder = $this->raceAgainstAHolder(
            [[$this->stockLevelInsert(), [Uuid::v4()->toRfc4122(), self::TENANT_A, $item, $location]]],
            function () use ($warehouse, $location): void {
                $this->update($warehouse, $location, ['kind' => 'bin']);
            },
        );

        self::assertTrue($holder['waiter_seen'], 'The release never waited on the receipt in flight, so nothing here was a race: '.json_encode($holder));
        self::assertTrue($holder['committed'], 'The holder did not commit: '.json_encode($holder));
        self::assertSame(409, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        self::assertSame('storage_location.release_needs_owner', $this->json()['rule']);
        self::assertSame('quarantine', $this->stored($location)['kind'], 'Stock received during the release was released without the owner.');
    }

    /** **Race (a′):** the same sequence with the owner — the release waits, then succeeds, recording `held_stock: true`. */
    #[Test]
    public function anOwnerReleaseWaitsForAReceiptInFlightAndRecordsTheStock(): void
    {
        [$warehouse, $location, $item] = $this->emptyQuarantineLocation();
        $this->actAs(self::TENANT_A, DefaultRoles::codesFor(DefaultRoles::OWNER));

        $holder = $this->raceAgainstAHolder(
            [[$this->stockLevelInsert(), [Uuid::v4()->toRfc4122(), self::TENANT_A, $item, $location]]],
            function () use ($warehouse, $location): void {
                $this->update($warehouse, $location, ['kind' => 'bin']);
            },
        );

        self::assertTrue($holder['waiter_seen'], 'The release never waited on the receipt in flight: '.json_encode($holder));
        self::assertTrue($holder['committed'], 'The holder did not commit: '.json_encode($holder));
        self::assertSame(200, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        self::assertSame([$this->updatedRow($location, ['kind' => 'quarantine'], ['kind' => 'bin'], true)],
            $this->auditRowsOf(AuditEvent::STORAGE_LOCATION_UPDATED), 'The trail does not say the released location held stock.');
    }

    /**
     * **Race (b), final RED H-2 — the hold comes FIRST and COMMITS.** A second connection takes `FOR UPDATE` on a stocked
     * `bin` and changes it to `quarantine`, uncommitted; `DepleteStock` is called directly (the `DepleteStockTest` rig, no
     * location row inserted before it). With its `FOR KEY SHARE` the depletion waits, reads `quarantine` once the hold
     * commits, and is refused `storage_location.is_pickable`: no movement, the level untouched. **Mutation M4** (drop the
     * clause): the plain read sees `bin`, the movement insert waits for the hold, and the stock leaves a location by then in
     * quarantine → red. (The bin is this test's own, so nothing needs restoring.)
     */
    #[Test]
    public function aDepletionWaitsForAHoldAndIsRefusedOnceItCommits(): void
    {
        $warehouse = $this->seedWarehouse(self::TENANT_A, 'Race B Site');
        $location = $this->seedStorageLocation(self::TENANT_A, $warehouse, 'RB-01');
        $item = $this->seedItem(self::TENANT_A, 'SKU-RACE-B');
        $this->stock($item, $location, '5');

        $refused = null;
        $holder = $this->raceAgainstAHolder(
            [
                ['SELECT 1 FROM storage_location WHERE id = ?::uuid FOR UPDATE', [$location]],
                ["UPDATE storage_location SET kind = 'quarantine' WHERE id = ?::uuid", [$location]],
            ],
            function () use ($warehouse, $location, $item, &$refused): void {
                try {
                    $this->tenantContext()->run(self::TENANT_A, fn () => $this->depleter()->deplete(new StockDepletion(
                        fulfilmentId: Uuid::v4()->toRfc4122(),
                        warehouseId: $warehouse,
                        occurredAt: new \DateTimeImmutable('2026-10-13T08:00:00+00:00'),
                        lines: [new StockDepletionLine($item, $location, '2')],
                    )));
                } catch (StockDepletionRefused $e) {
                    $refused = $e;
                }
            },
        );

        self::assertTrue($holder['waiter_seen'], 'The depletion never waited on the hold, so nothing here was a race: '.json_encode($holder));
        self::assertTrue($holder['committed'], 'The hold did not commit: '.json_encode($holder));
        self::assertInstanceOf(StockDepletionRefused::class, $refused, 'Stock was depleted from a location a committed hold had put in quarantine.');
        self::assertSame('storage_location.is_pickable', $refused->rule());
        self::assertSame(0, $this->countAsOwnerForTenant(self::TENANT_A, 'SELECT count(*) FROM stock_movement WHERE storage_location_id = ?::uuid', [$location]));
        self::assertSame('5.000000', $this->onHand($location));
    }

    /**
     * **Race (c) — regression:** the same committed-first hold against `CreateFulfilment`. The fulfilment inserts its line
     * (whose foreign-key check needs `KEY SHARE` on the location) before it depletes, so it waits on the hold whatever the
     * depleter does, and is refused at the line. **Stays green under M4** — the line insert is today's control.
     */
    #[Test]
    public function aFulfilmentWaitsForAHoldAndIsRefusedOnceItCommits(): void
    {
        $f = $this->confirmedOrder('SO-449100', '5');
        $location = $this->seedStorageLocation(self::TENANT_A, $f['warehouse'], 'RC-01');
        $this->stock($f['item'], $location, '5');
        $this->actAs(self::TENANT_A, DefaultRoles::codesFor(DefaultRoles::WAREHOUSE_OPERATOR));

        $holder = $this->raceAgainstAHolder(
            [
                ['SELECT 1 FROM storage_location WHERE id = ?::uuid FOR UPDATE', [$location]],
                ["UPDATE storage_location SET kind = 'quarantine' WHERE id = ?::uuid", [$location]],
            ],
            function () use ($f, $location): void {
                $this->fulfil($f['order'], $f['line'], $location, '2');
            },
        );

        self::assertTrue($holder['waiter_seen'], 'The fulfilment never waited on the hold: '.json_encode($holder));
        self::assertTrue($holder['committed'], 'The hold did not commit: '.json_encode($holder));
        self::assertSame(422, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        self::assertSame('storage_location.is_pickable', $this->json()['errors'][0]['code']);
        self::assertSame('5.000000', $this->onHand($location));
    }

    // -----------------------------------------------------------------------

    /** @param array<string, mixed> $body */
    private function create(string $warehouse, array $body): void
    {
        $this->send('POST', '/warehouses/'.$warehouse.'/locations', $body, ['Idempotency-Key' => Uuid::v4()->toRfc4122()]);
    }

    /** @param array<string, mixed> $body */
    private function update(string $warehouse, string $location, array $body): void
    {
        $this->send('PATCH', '/warehouses/'.$warehouse.'/locations/'.$location, $body);
    }

    /** @return list<array{pointer: string, code: string, message: string}> */
    private function fieldErrors(): array
    {
        return $this->json()['errors'];
    }

    /** @return array{code: string, kind: string} */
    private function stored(string $location): array
    {
        return $this->fetchAsOwnerForTenant(self::TENANT_A, 'SELECT code, kind FROM storage_location WHERE id = ?::uuid', [$location])[0];
    }

    /** @return array{0: string, 1: string} [warehouse, location] — a location `Q-01` of the kind, with one level row */
    private function stockedLocation(string $kind, string $qty): array
    {
        $warehouse = $this->seedWarehouse(self::TENANT_A, 'Stocked '.$kind);
        $location = $this->seedStorageLocation(self::TENANT_A, $warehouse, 'Q-01', $kind);
        $this->stock($this->seedItem(self::TENANT_A, 'SKU-STOCKED-'.bin2hex(random_bytes(3))), $location, $qty);

        return [$warehouse, $location];
    }

    /** @return array{0: string, 1: string, 2: string} [warehouse, location, item] — an empty quarantine location */
    private function emptyQuarantineLocation(): array
    {
        $warehouse = $this->seedWarehouse(self::TENANT_A, 'Inspection Site');

        return [$warehouse, $this->seedStorageLocation(self::TENANT_A, $warehouse, 'IN-01', 'quarantine'),
            $this->seedItem(self::TENANT_A, 'SKU-INBOUND-'.bin2hex(random_bytes(3)))];
    }

    /** The receipt-in-flight stand-in: a level row naming the location (the FK check takes `KEY SHARE` on it). */
    private function stockLevelInsert(): string
    {
        return 'INSERT INTO stock_level (id, tenant_id, inventory_item_id, storage_location_id, qty_on_hand) VALUES (?::uuid, ?::uuid, ?::uuid, ?::uuid, 5)';
    }

    private function stock(string $itemId, string $locationId, string $qty): void
    {
        $this->insertAsOwnerForTenant(self::TENANT_A,
            'INSERT INTO stock_level (id, tenant_id, inventory_item_id, storage_location_id, qty_on_hand) VALUES (?::uuid, ?::uuid, ?::uuid, ?::uuid, ?::numeric)',
            [Uuid::v4()->toRfc4122(), self::TENANT_A, $itemId, $locationId, $qty]);
    }

    private function onHand(string $locationId): string
    {
        return (string) $this->fetchAsOwnerForTenant(self::TENANT_A,
            'SELECT qty_on_hand::text AS q FROM stock_level WHERE storage_location_id = ?::uuid', [$locationId])[0]['q'];
    }

    private function depleter(): DepleteStock
    {
        $depleter = self::getContainer()->get('test.inventory.deplete_stock');
        \assert($depleter instanceof DepleteStock);

        return $depleter;
    }

    /** @return array{order: string, warehouse: string, line: string, item: string} a confirmed order of one line, as the owner */
    private function confirmedOrder(string $number, string $quantity): array
    {
        $warehouse = $this->seedWarehouse(self::TENANT_A, 'WH '.$number);
        $order = $this->seedSalesOrder(self::TENANT_A, $number, null, $warehouse, SalesOrder::STATUS_CONFIRMED, '2026-09-20T10:00:00Z');
        $item = $this->seedItem(self::TENANT_A, 'SKU-'.$number);

        return ['order' => $order, 'warehouse' => $warehouse, 'item' => $item,
            'line' => $this->seedSalesOrderLine(self::TENANT_A, $order, $item, lineNumber: 1, quantity: $quantity)];
    }

    private function fulfil(string $order, string $line, string $location, string $quantity): void
    {
        $this->send('POST', '/sales-orders/'.$order.'/fulfilments',
            ['lines' => [['sales_order_line_id' => $line, 'quantity' => $quantity, 'storage_location_id' => $location]]],
            ['Idempotency-Key' => Uuid::v4()->toRfc4122()]);
    }

    /** @return list<string> the location ids `GetPickList` offers for the order's one line, read as the current actor */
    private function candidateBins(string $order): array
    {
        $this->send('GET', '/sales-orders/'.$order.'/pick-list');
        self::assertSame(200, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());

        return array_map(static fn (array $bin): string => $bin['storage_location']['id'], $this->json()['lines'][0]['candidate_bins']);
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
    private function updatedRow(string $id, array $from, array $to, ?bool $heldStock): array
    {
        $fields = array_keys($from);
        sort($fields);
        $metadata = ['fields' => $fields, 'from' => $from, 'to' => $to];
        if (null !== $heldStock) {
            $metadata['held_stock'] = $heldStock;
        }

        return array_replace($this->businessRow(AuditEvent::STORAGE_LOCATION_UPDATED, 'storage_location', $id), [
            'metadata' => $this->jsonb($metadata),
        ]);
    }

    /**
     * `WarehouseWriteTest::raceAgainstAHolder`'s shape (the holder's `HOLD_TABLE` whitelist names no `storage_location`,
     * which must NOT be added): the holder's own lock is on a random `purchase_order` id, so it matches no row; the real
     * locks are `HOLD_BEFORE`'s. **It COMMITS once it sees this request wait** (`hold_order_lock.php` step 4), which is the
     * commit-variant final RED H-2 asked to check: a holder that rolled back could not show the hold-first race.
     *
     * @param list<array{0: string, 1: list<string>}> $before
     *
     * @return array{waiter_seen: bool, committed: bool, sqlstate: ?string, error: ?string}
     */
    private function raceAgainstAHolder(array $before, callable $request): array
    {
        $readyFile = tempnam(sys_get_temp_dir(), 'erp_location_');
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
