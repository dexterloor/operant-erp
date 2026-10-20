<?php

declare(strict_types=1);

namespace App\Modules\Warehouses\Application;

use App\Modules\AuditLog\Infrastructure\StorageLocationAuditWriter;
use App\Modules\UserManagement\Application\Contract\OwnerPredicate;
use App\Modules\Warehouses\Application\Contract\LocationKinds;
use App\Modules\Warehouses\Application\Port\Inventory\WarehouseHeldStock;
use App\Platform\Http\Problem\ApiProblem;
use App\Platform\Security\RequestIdentity;
use App\Platform\Tenancy\CurrentTenant;
use App\Platform\Tenancy\TenantContext;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * **`CreateStorageLocation` and `UpdateStorageLocation`** (warehouses slice 1 C3; `docs/session13/warehouses-s1-design.md`
 * §4 and FINAL item 4; final RED `warehouses-s1-final-red.md` §3). {@see WarehouseWriter}'s shape for its reasons. Takes
 * wire-named scalars, returns a {@see StorageLocationView}; never an `ApiResource` class (A-7) and never a
 * `Doctrine\DBAL\Connection` (A-10).
 *
 * The two writes are raw SQL naming exactly the columns `erp_app`'s column grants cover
 * (`SchemaManifest['storage_location']`, `Version20261013000300`): the INSERT names `id, tenant_id, warehouse_id, code` and
 * `kind` only when it was sent (omitted, the column default `bin` applies, never a PHP copy); the UPDATE sets only the
 * **changed** columns plus `updated_at`. **`warehouse_id` is INSERT-only and the path's** — a body `warehouse_id` is an
 * undeclared field: moving a location would move its stock between warehouses with no movement. `deleted_at` is in
 * neither: it is written only by {@see self::restore()} (warehouse/location delete-restore C2, `Version20261020000300`)
 * and {@see self::delete()} (C3, `DeleteStorageLocation`; G-27).
 *
 * **Refusals, in order** (design §4.1; placeholder copy, `UX` owes it): the parent warehouse (absent or another
 * workspace's → 404) → (update) the location **of this warehouse** (another warehouse's location under this path is the
 * same 404, ST-5) → archived parent → 409 `warehouse.is_archived` → archived location → 409 `storage_location.is_archived`
 * → shape (422; `kind` one of {@see LocationKinds::ALL}, else `not_a_location_kind`) → (update) the release rule → write.
 * A code already used by a live location of this warehouse → 409 `storage_location.code_is_unique_per_warehouse`,
 * translated **by quoted constraint name** outside `TenantContext::run()`, any other 23505 rethrown; refused, never
 * replayed (the create's `Idempotency-Key` is demanded and unused).
 *
 * **The quarantine rule (owner Q-W1 (d), adopted under delegation; a mechanism, not prose).** `kind` decides whether stock
 * in the location can be picked: {@see LocationKinds::NEVER_PICKABLE} never can (owner ruling R2).
 *  - **Into quarantine — a hold — is always allowed**, stocked or not: a recall or damage found must hold at once.
 *  - **Out of quarantine — a release — while the location holds stock is the owner's alone**
 *    ({@see OwnerPredicate::holdsMarker()} over the request's permission set); anyone else is refused 409
 *    `storage_location.release_needs_owner`. For a non-owner the refusal is **unconditional** — a capability no catalogue
 *    code names (`SEC` S-2): `admin` and `warehouse_operator` cannot release stocked held goods at all. An EMPTY location
 *    is released by any `storage_location.edit` holder. `RESIDUAL_GAPS.md` G-44 records the cost.
 *  - Changes among the other four kinds are free (nothing reads them).
 *
 * **The rule, stated once (final RED M-1):** whenever `kind` is in the body AND differs from the locked value, a SEPARATE
 * statement after the row lock asks whether the location holds stock ({@see self::HELD_STOCK}: any `stock_level` row with
 * `qty_on_hand > 0`; `qty_on_hand` cannot be negative, `stock_level_reserved_sane`). `storage_location.updated` carries
 * that answer as `held_stock` exactly then. The owner check applies only to quarantine → any other kind with
 * `held_stock` true.
 *
 * **Statement order, prescribed (design §4.1, §4.4; measured by the final RED, §1):**
 *  1. the parent: {@see self::PARENT_LOCK} (`FOR SHARE`; conflicts with `DeleteWarehouse`'s `FOR UPDATE`, and is
 *     compatible with the `FOR KEY SHARE` that order and fulfilment inserts take). Legal for `erp_app` because it holds a
 *     column UPDATE on `warehouse` (C2, A4.3 constraint 1);
 *  2. **its own statement**, the location row: `FOR UPDATE` when `kind` is in the body, `FOR NO KEY UPDATE` when it is not
 *     (a code-only rename cannot cross quarantine, so it need not wait for stock writers). Every shipped stock writer
 *     inserts a row referencing the location (`goods_receipt_line`, `fulfilment_line`, `sales_return_line`,
 *     `stock_movement`, `stock_level`) before it moves stock, and that insert's foreign-key check holds `FOR KEY SHARE` on
 *     the location until commit — which conflicts with `FOR UPDATE` only. So a kind change waits for every in-flight
 *     stock write on the location, and a stock write that arrives second waits for the kind change. **A plain `UPDATE …
 *     SET kind` is NOT blocked by an in-flight insert (measured, T-8c)**: the separate locking read is load-bearing;
 *  3. **a separate, later** statement for {@see self::HELD_STOCK} — never folded into (2): under READ COMMITTED a statement
 *     that waited on a lock re-checks its own row but reads every other table from the snapshot it started with, so a
 *     folded `EXISTS` misses the stock the waited-on transaction committed (measured, T-13); a new statement sees it;
 *  4. the UPDATE; 5. the event.
 * The diff is taken against the locked row, so `storage_location.updated`'s `from` is exact (last writer still wins; no
 * `stale-record`). `StockDepleter` takes `FOR KEY SHARE` on the locations it reads (defence in depth for a future
 * depleter that writes no location row first); the grant this class needs is what makes that legal.
 *
 * **What the rule does NOT stop (disclosed):**
 * a return may be put straight into a pickable location (`ReturnStock` accepts any live location). Two operations apply
 * the same capability: `CreateStockAdjustment` since 2026-10-16 (`StockAdjuster`, owner Q1 (C′): a non-owner's quarantine
 * count-down, and count-up of an item held in quarantine anywhere, are refused) and `CreateStockTransfer` since
 * 2026-10-20 (`StockTransferrer`, owner Q-T2: a non-owner's move out of a quarantine bin into a non-quarantine one is
 * refused; into quarantine and between quarantine bins stay open). The count's held read takes no lock because only an
 * owner can take an item's stock out of quarantine altogether: a non-owner's move between quarantine bins can empty a
 * bin but never lowers the item's total in live quarantine bins, its two legs committing together (final RED L-3;
 * `StockTransferRaceTest` T-R7). Every `stock_level` writer's answer is recorded in `WriteOwnershipTest`'s
 * `STOCK_LEVEL_RELEASE_DECISIONS` (G-44).
 */
final readonly class StorageLocationWriter
{
    /**
     * The parent read both writes take — public so a race test can hold the lock with THIS statement, never a
     * transcription of it (`SupplierProductWriter::PARENT_LOCK`'s reason).
     */
    public const PARENT_LOCK = 'SELECT deleted_at IS NOT NULL AS archived FROM warehouse WHERE id = ?::uuid FOR SHARE';

    /** The location of THIS warehouse, read for the diff; the row lock is appended by {@see self::update()} (step 2). */
    public const LOCATION_READ = 'SELECT code, kind, deleted_at IS NOT NULL AS archived FROM storage_location WHERE id = ?::uuid AND warehouse_id = ?::uuid';

    /**
     * Step 3, its own statement after the lock: does the location hold stock. `tenant_id` named as well as RLS's. A raw
     * read of Inventory's table (A-2, the `SupplierProductWriter` → `inventory_item` precedent): Warehouses may not import
     * Inventory, which already depends on `WarehousesContract`.
     */
    public const HELD_STOCK = 'SELECT EXISTS (SELECT 1 FROM stock_level WHERE tenant_id = ?::uuid AND storage_location_id = ?::uuid AND qty_on_hand > 0)';

    public const CODE_TAKEN_ON_CREATE = 'Another location in this warehouse already uses this code. Choose a different code.';

    public const CODE_TAKEN_ON_UPDATE = 'Another location in this warehouse already uses this code. Keep the current code or choose a different one.';

    public const WAREHOUSE_ARCHIVED_ON_CREATE = 'This warehouse is archived, so no location can be added to it until it\'s restored.';

    public const WAREHOUSE_ARCHIVED_ON_UPDATE = 'This warehouse is archived, so its locations can\'t be changed until it\'s restored.';

    public const ARCHIVED = 'This location is archived, so it can\'t be changed until it\'s restored.';

    /**
     * `DeleteStorageLocation`'s location read: {@see self::LOCATION_READ} `FOR UPDATE` — public so a race test holds THIS
     * statement, never a transcription (`SupplierWriter::DELETE_LOCK`'s reason). `FOR UPDATE`, never `FOR NO KEY UPDATE`:
     * only `FOR UPDATE` conflicts with the `FOR KEY SHARE` every stock writer holds on the location (mutation M7).
     */
    public const DELETE_LOCK = self::LOCATION_READ.' FOR UPDATE';

    /** The archive itself: `deleted_at` and `updated_at`, the two columns `erp_app` may write for it (`Version20261020000300`). */
    public const ARCHIVE = 'UPDATE storage_location SET deleted_at = now(), updated_at = now() WHERE id = ?::uuid';

    /** `DeleteStorageLocation` on a location of an archived warehouse (owner Q-2 (a); `UX` U-1 C3). Passive (R-3). */
    public const WAREHOUSE_ARCHIVED_ON_DELETE = 'This location\'s warehouse is archived, so the location can\'t be deleted until the warehouse is restored.';

    /** `DeleteStorageLocation` repeated (ST-9; `UX` U-1 C3). */
    public const ALREADY_ARCHIVED = 'This location is already archived. It\'s in this warehouse\'s archived locations.';

    /** `DeleteStorageLocation` on a location holding stock, any kind, quarantine included (owner Q-1; `UX` U-1 C3). Passive. */
    public const HOLDS_STOCK = 'This location still holds stock, so it can\'t be deleted until all of it is moved to another location or counted off.';

    /**
     * Appended to {@see self::HOLDS_STOCK} with one space when any of the stock is an archived item's (`UX` U-4, design
     * Revision 2 L-5): one sentence true for "some" and "all". `RESIDUAL_GAPS.md` G-51.
     */
    public const ARCHIVED_ITEM_STOCK = 'Stock of an archived item can be moved or counted off only once that item is restored.';

    /** `RestoreStorageLocation` on a location of an archived warehouse (owner Q-2 (a); `UX` U-1). Passive (R-3). */
    public const WAREHOUSE_ARCHIVED_ON_RESTORE = 'This location\'s warehouse is archived, so the location can\'t be restored until the warehouse is restored.';

    /** `RestoreStorageLocation` on a live location (`UX` U-1). */
    public const NOT_ARCHIVED = 'This location is not archived, so there is nothing to restore.';

    /** `RestoreStorageLocation` when a live location of the same warehouse took this one's code meanwhile (`UX` U-1). */
    public const CODE_TAKEN_ON_RESTORE = 'Another location in this warehouse now uses this location\'s code, so it can\'t be restored. It can be restored once that location\'s code is changed.';

    public const RELEASE_NEEDS_OWNER = 'This location holds stock, so only an owner can take it out of quarantine.';

    public const KIND_SHAPE = 'Use one of: bin, shelf, zone, staging, quarantine.';

    private const CODE_UNIQUE = 'storage_location_tenant_warehouse_code_live';

    /** The two accepted fields, in §4.2's order, as wire names. `code` is required on create; `kind` never is. */
    private const FIELDS = ['code', 'kind'];

    public function __construct(
        private TenantContext $tenants,
        private CurrentTenant $tenant,
        private RequestIdentity $identities,
        private StorageLocationAuditWriter $audit,
        private WarehouseHeldStock $heldStock,
    ) {
    }

    /** @param array<string, mixed> $values wire name => value, for the fields the body carried */
    public function create(string $warehouseId, array $values): StorageLocationView
    {
        if (!Uuid::isValid($warehouseId)) {
            throw ApiProblem::notFound();
        }
        $warehouseId = Uuid::fromString($warehouseId)->toRfc4122();
        $tenantId = $this->tenant->tenantId();
        $identity = $this->identities->authenticated();

        try {
            return $this->tenants->run($tenantId, function (EntityManagerInterface $em) use ($warehouseId, $tenantId, $values, $identity): StorageLocationView {
                $connection = $em->getConnection();

                $parent = $connection->fetchAssociative(self::PARENT_LOCK, [$warehouseId]);
                if (false === $parent) {
                    throw ApiProblem::notFound();
                }
                // ST-9. Inlined, never a `refuseUnless…` method (`EditWindowGuardTest` clause (b)).
                if (true === $parent['archived']) {
                    throw ApiProblem::businessRuleViolated('warehouse.is_archived', self::WAREHOUSE_ARCHIVED_ON_CREATE);
                }

                self::refuseMalformed($values, ['code']);

                $id = Uuid::v4()->toRfc4122();
                $columns = ['id', 'tenant_id', 'warehouse_id', 'code'];
                $parameters = [$id, $tenantId, $warehouseId, $values['code']];
                // Omitted, the column default (`bin`) applies — never a PHP copy of it.
                if (\array_key_exists('kind', $values)) {
                    $columns[] = 'kind';
                    $parameters[] = $values['kind'];
                }
                $connection->executeStatement(
                    \sprintf('INSERT INTO storage_location (%s) VALUES (?::uuid, ?::uuid, ?::uuid%s)', implode(', ', $columns), str_repeat(', ?', \count($columns) - 3)),
                    $parameters,
                );

                $view = StorageLocationReader::readInside($em, $warehouseId, $id)
                    ?? throw new \LogicException('The storage location just inserted is not readable.');

                // After the INSERT, in its transaction: a 23505 above leaves no row. The kind is the stored one.
                $this->audit->recordCreated(
                    tenantId: $identity->tenantId,
                    actorUserId: $identity->userId,
                    actorLabel: $identity->actorLabel,
                    storageLocationId: $id,
                    values: ['code' => $view->code, 'kind' => $view->kind],
                );

                return $view;
            });
        } catch (UniqueConstraintViolationException $e) {
            // Outside `run()`: the failed INSERT aborted the transaction. The id is minted here, so only the live code
            // unique can fire.
            throw self::translated($e, self::CODE_TAKEN_ON_CREATE);
        }
    }

    /** @param array<string, mixed> $submitted wire name => value, exactly the fields the body carried */
    public function update(string $warehouseId, string $locationId, array $submitted): StorageLocationView
    {
        if (!Uuid::isValid($warehouseId) || !Uuid::isValid($locationId)) {
            throw ApiProblem::notFound();
        }
        $warehouseId = Uuid::fromString($warehouseId)->toRfc4122();
        $locationId = Uuid::fromString($locationId)->toRfc4122();
        $tenantId = $this->tenant->tenantId();
        $identity = $this->identities->authenticated();

        try {
            return $this->tenants->run($tenantId, function (EntityManagerInterface $em) use ($warehouseId, $locationId, $tenantId, $submitted, $identity): StorageLocationView {
                $connection = $em->getConnection();

                // 1. The parent.
                $parent = $connection->fetchAssociative(self::PARENT_LOCK, [$warehouseId]);
                if (false === $parent) {
                    throw ApiProblem::notFound();
                }

                // 2. The location row, its own statement: FOR UPDATE when the kind may change (it must wait for every
                // in-flight stock write's KEY SHARE), FOR NO KEY UPDATE when it cannot.
                $lock = \array_key_exists('kind', $submitted) ? ' FOR UPDATE' : ' FOR NO KEY UPDATE';
                $row = $connection->fetchAssociative(self::LOCATION_READ.$lock, [$locationId, $warehouseId]);
                if (false === $row) {
                    throw ApiProblem::notFound();
                }
                if (true === $parent['archived']) {
                    throw ApiProblem::businessRuleViolated('warehouse.is_archived', self::WAREHOUSE_ARCHIVED_ON_UPDATE);
                }
                self::refuseIfArchived($row);

                self::refuseMalformed($submitted, []);

                $changed = self::changedFields($row, $submitted);
                if ([] === $changed) {
                    return StorageLocationReader::readInside($em, $warehouseId, $locationId)
                        ?? throw new \LogicException('The storage location just read is not readable.');
                }

                // 3. A separate, later statement — only when the kind changes — so it sees what a waited-on stock write
                // committed (T-13).
                $heldStock = null;
                if (\in_array('kind', $changed, true)) {
                    $heldStock = true === $connection->fetchOne(self::HELD_STOCK, [$tenantId, $locationId]);

                    $releases = LocationKinds::NEVER_PICKABLE === $row['kind'] && LocationKinds::NEVER_PICKABLE !== $submitted['kind'];
                    if ($releases && $heldStock && !OwnerPredicate::holdsMarker($identity->permissionCodes)) {
                        throw ApiProblem::businessRuleViolated('storage_location.release_needs_owner', self::RELEASE_NEEDS_OWNER);
                    }
                }

                // 4. The UPDATE.
                $set = [];
                $parameters = [];
                $from = [];
                $to = [];
                foreach ($changed as $field) {
                    $set[] = $field.' = ?';
                    $parameters[] = $submitted[$field];
                    $from[$field] = (string) $row[$field];
                    $to[$field] = $submitted[$field];
                }
                $set[] = 'updated_at = now()';
                $parameters[] = $locationId;
                $connection->executeStatement('UPDATE storage_location SET '.implode(', ', $set).' WHERE id = ?::uuid', $parameters);

                // 5. The event, after the UPDATE, in its transaction: a 23505 above leaves no row.
                $this->audit->recordUpdated(
                    tenantId: $identity->tenantId,
                    actorUserId: $identity->userId,
                    actorLabel: $identity->actorLabel,
                    storageLocationId: $locationId,
                    from: $from,
                    to: $to,
                    heldStock: $heldStock,
                );

                return StorageLocationReader::readInside($em, $warehouseId, $locationId)
                    ?? throw new \LogicException('The storage location just updated is not readable.');
            });
        } catch (UniqueConstraintViolationException $e) {
            throw self::translated($e, self::CODE_TAKEN_ON_UPDATE);
        }
    }

    /**
     * **`RestoreStorageLocation`** (warehouse/location delete-restore C2, 2026-10-20; `docs/session13/wh-loc-delete-design.md`
     * §3.3, §4.1, §4.2, Revision 2; {@see WarehouseWriter::restore()}'s shape under this class's parent order). In one
     * `TenantContext::run()`:
     *  1. a non-uuid id → 404; the parent {@see self::PARENT_LOCK} (`FOR SHARE`: serialises with `DeleteWarehouse`'s
     *     `FOR UPDATE` and `RestoreWarehouse`'s `FOR NO KEY UPDATE`; warehouse before location, W-5 "warehouse row locks") —
     *     absent or foreign → 404;
     *  2. the location **of this warehouse**, `FOR NO KEY UPDATE` ({@see self::LOCATION_READ}) — another warehouse's location
     *     under this path is the same 404 (ST-5);
     *  3. archived warehouse → 409 `warehouse.is_archived` (§4.2: a location of an archived warehouse is restored only after
     *     the warehouse — owner Q-2 (a), ST-9 parity with create/update);
     *  4. live location → 409 `storage_location.is_not_archived` (§4.2 `guard:storage_location.deleted_at`; no key);
     *  5. `UPDATE storage_location SET deleted_at = NULL, updated_at = now()`; 6. `storage_location.restored`, `{}`, after it;
     *  7. 200 with the location, read inside the transaction.
     * A live location of the same warehouse that took this one's code → `storage_location_tenant_warehouse_code_live` 23505
     * at step 5, translated **outside** `run()` to 409 `storage_location.code_is_unique_per_warehouse`. **No stock check**
     * (§4.1: an archived location is empty and no stock writer reaches it while archived).
     */
    public function restore(string $warehouseId, string $locationId): StorageLocationView
    {
        if (!Uuid::isValid($warehouseId) || !Uuid::isValid($locationId)) {
            throw ApiProblem::notFound();
        }
        $warehouseId = Uuid::fromString($warehouseId)->toRfc4122();
        $locationId = Uuid::fromString($locationId)->toRfc4122();
        $identity = $this->identities->authenticated();

        try {
            return $this->tenants->run($this->tenant->tenantId(), function (EntityManagerInterface $em) use ($warehouseId, $locationId, $identity): StorageLocationView {
                $connection = $em->getConnection();

                $parent = $connection->fetchAssociative(self::PARENT_LOCK, [$warehouseId]);
                if (false === $parent) {
                    throw ApiProblem::notFound();
                }
                $row = $connection->fetchAssociative(self::LOCATION_READ.' FOR NO KEY UPDATE', [$locationId, $warehouseId]);
                if (false === $row) {
                    throw ApiProblem::notFound();
                }
                if (true === $parent['archived']) {
                    throw ApiProblem::businessRuleViolated('warehouse.is_archived', self::WAREHOUSE_ARCHIVED_ON_RESTORE);
                }
                if (true !== $row['archived']) {
                    throw ApiProblem::businessRuleViolated('storage_location.is_not_archived', self::NOT_ARCHIVED);
                }

                $connection->executeStatement('UPDATE storage_location SET deleted_at = NULL, updated_at = now() WHERE id = ?::uuid', [$locationId]);

                // After the UPDATE, in its transaction: a 23505 above leaves no row.
                $this->audit->recordRestored(
                    tenantId: $identity->tenantId,
                    actorUserId: $identity->userId,
                    actorLabel: $identity->actorLabel,
                    storageLocationId: $locationId,
                );

                return StorageLocationReader::readInside($em, $warehouseId, $locationId)
                    ?? throw new \LogicException('The storage location just restored is not readable.');
            });
        } catch (UniqueConstraintViolationException $e) {
            throw self::translated($e, self::CODE_TAKEN_ON_RESTORE);
        }
    }

    /**
     * **`DeleteStorageLocation`** (warehouse/location delete-restore C3, 2026-10-20; `docs/session13/wh-loc-delete-design.md`
     * §0, §2.2, §3.3, Revision 2; owner Q-1 adopted under delegation, `wh-loc-delete-q-ruling.md`). A soft delete: the
     * location stays readable (`ListStorageLocations?archived=true`) and is restored by {@see self::restore()}. In one
     * `TenantContext::run()`:
     *  1. a non-uuid id → 404; the parent {@see self::PARENT_LOCK} (`FOR SHARE`: serialises with `DeleteWarehouse`'s
     *     `FOR UPDATE`; warehouse before location, W-5 "warehouse row locks" rule 2) — absent or foreign → 404;
     *  2. the location **of this warehouse**, {@see self::DELETE_LOCK} (`FOR UPDATE`, its own statement) — another
     *     warehouse's location under this path is the same 404 (ST-5). `FOR UPDATE` waits for every in-flight stock
     *     writer's `FOR KEY SHARE` on the location (an explicit lock, or the foreign-key check of the line, movement or level
     *     row it inserts), and a stock writer arriving second waits for the archive, then re-reads the location and is
     *     refused (`storage_location.is_archived`, or the receipt's / return's live-location rule);
     *  3. archived warehouse → 409 `warehouse.is_archived` (ST-9: a location of an archived warehouse is read-only; owner
     *     Q-2 (a));
     *  4. already archived → 409 `storage_location.is_archived` (ST-9; §4.2 `Idem` `no`: refused, never replayed);
     *  5. **a separate, later statement**, through {@see WarehouseHeldStock} (Inventory answers; A-2): stock in the location →
     *     409 `storage_location.holds_stock` (owner Q-1), any kind, quarantine included, plus {@see self::ARCHIVED_ITEM_STOCK}
     *     when any of it is an archived item's. Never folded into (2): under READ COMMITTED only a new statement sees what a
     *     waited-on stock writer committed (T-13's lesson);
     *  6. {@see self::ARCHIVE}; 7. `storage_location.deleted`, `{}`, after it — none on any refusal. 204, no body.
     * **Not refused:** an empty quarantine location (no held stock to hide, G-44); a location that is some shipment's origin
     * (G-27); zero `stock_level` rows (they stay, G-47).
     */
    public function delete(string $warehouseId, string $locationId): void
    {
        if (!Uuid::isValid($warehouseId) || !Uuid::isValid($locationId)) {
            throw ApiProblem::notFound();
        }
        $warehouseId = Uuid::fromString($warehouseId)->toRfc4122();
        $locationId = Uuid::fromString($locationId)->toRfc4122();
        $identity = $this->identities->authenticated();

        $this->tenants->run($this->tenant->tenantId(), function (EntityManagerInterface $em) use ($warehouseId, $locationId, $identity): void {
            $connection = $em->getConnection();

            // fetchAssociative, never fetchOne: a live row's `false` would read as "no row".
            $parent = $connection->fetchAssociative(self::PARENT_LOCK, [$warehouseId]);
            if (false === $parent) {
                throw ApiProblem::notFound();
            }
            $row = $connection->fetchAssociative(self::DELETE_LOCK, [$locationId, $warehouseId]);
            if (false === $row) {
                throw ApiProblem::notFound();
            }
            // ST-9. Inlined, never a `refuseUnless…` method (`EditWindowGuardTest` clause (b)).
            if (true === $parent['archived']) {
                throw ApiProblem::businessRuleViolated('warehouse.is_archived', self::WAREHOUSE_ARCHIVED_ON_DELETE);
            }
            if (true === $row['archived']) {
                throw ApiProblem::businessRuleViolated('storage_location.is_archived', self::ALREADY_ARCHIVED);
            }

            $held = $this->heldStock->stockedLocationCountInside($em, $locationId);
            if ($held->stocked > 0) {
                throw ApiProblem::businessRuleViolated('storage_location.holds_stock',
                    $held->archivedItemStocked > 0 ? self::HOLDS_STOCK.' '.self::ARCHIVED_ITEM_STOCK : self::HOLDS_STOCK);
            }

            $connection->executeStatement(self::ARCHIVE, [$locationId]);

            // After the UPDATE, in its transaction: a refusal above leaves no row.
            $this->audit->recordDeleted(
                tenantId: $identity->tenantId,
                actorUserId: $identity->userId,
                actorLabel: $identity->actorLabel,
                storageLocationId: $locationId,
            );
        });
    }

    /**
     * ST-9: an archived location is read-only, until it is restored ({@see self::restore()}). Reachable through the product
     * since `DeleteStorageLocation` (C3, 2026-10-20). Passive copy (`UX` R-3).
     *
     * @param array<string, mixed> $row
     */
    private static function refuseIfArchived(array $row): void
    {
        if (true === $row['archived']) {
            throw ApiProblem::businessRuleViolated('storage_location.is_archived', self::ARCHIVED);
        }
    }

    /**
     * The 23505 names the live code unique, read out of the driver's message, matched **quoted**
     * ({@see WarehouseWriter}'s reason). A 23505 naming anything else is not ours: rethrown.
     */
    private static function translated(UniqueConstraintViolationException $e, string $codeTaken): \Throwable
    {
        if (str_contains($e->getMessage(), '"'.self::CODE_UNIQUE.'"')) {
            return ApiProblem::businessRuleViolated('storage_location.code_is_unique_per_warehouse', $codeTaken);
        }

        return $e;
    }

    /**
     * The wire names whose submitted value differs from the locked row, sorted, compared as exact strings.
     *
     * @param array<string, mixed> $row
     * @param array<string, mixed> $submitted
     *
     * @return list<string>
     */
    private static function changedFields(array $row, array $submitted): array
    {
        $changed = [];
        foreach (self::FIELDS as $field) {
            if (\array_key_exists($field, $submitted) && (string) $row[$field] !== $submitted[$field]) {
                $changed[] = $field;
            }
        }
        sort($changed);

        return $changed;
    }

    /**
     * Shape: required/null, blank (stored as sent, no trim), and `kind` among the five. The `storage_location_kind_values`
     * CHECK is the backstop, never the answer.
     *
     * @param array<string, mixed> $values
     * @param list<string>         $required
     */
    private static function refuseMalformed(array $values, array $required): void
    {
        $errors = [];

        foreach (self::FIELDS as $field) {
            $present = \array_key_exists($field, $values);
            $value = $values[$field] ?? null;

            if (null === $value) {
                if (\in_array($field, $required, true)) {
                    $errors[] = self::error($field, 'required', 'This field is required.');
                } elseif ($present) {
                    $errors[] = self::error($field, 'not_nullable', 'This field may not be cleared.');
                }
                continue;
            }

            if ('kind' === $field) {
                if (!\in_array($value, LocationKinds::ALL, true)) {
                    $errors[] = self::error($field, 'not_a_location_kind', self::KIND_SHAPE);
                }
                continue;
            }

            if (!\is_string($value)) {
                $errors[] = self::error($field, 'not_a_string', 'This field must be a string.');
            } elseif ('' === trim($value)) {
                $errors[] = self::error($field, 'blank', 'This field may not be blank.');
            }
        }

        if ([] !== $errors) {
            throw ApiProblem::validationFailed($errors);
        }
    }

    /** @return array{pointer: string, code: string, message: string} */
    private static function error(string $field, string $code, string $message): array
    {
        return ['pointer' => '/'.$field, 'code' => $code, 'message' => $message];
    }
}
