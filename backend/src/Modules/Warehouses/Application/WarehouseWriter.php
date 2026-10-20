<?php

declare(strict_types=1);

namespace App\Modules\Warehouses\Application;

use App\Modules\AuditLog\Infrastructure\WarehouseAuditWriter;
use App\Modules\Warehouses\Application\Port\Inventory\WarehouseHeldStock;
use App\Modules\Warehouses\Application\Port\Purchasing\WarehouseOpenPurchaseOrders;
use App\Modules\Warehouses\Application\Port\Sales\WarehouseOpenSalesOrders;
use App\Platform\Http\Problem\ApiProblem;
use App\Platform\Security\RequestIdentity;
use App\Platform\Tenancy\CurrentTenant;
use App\Platform\Tenancy\TenantContext;
use App\SharedKernel\Display\Counted;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * **`CreateWarehouse` and `UpdateWarehouse`** (warehouses slice 1 C2; `docs/session13/warehouses-s1-design.md` §3 and FINAL
 * item 3; `SupplierWriter`'s shape, file for file, for its reasons). Takes wire-named scalars, returns a
 * {@see WarehouseView}; never an `ApiResource` class (A-7) and never a `Doctrine\DBAL\Connection` (A-10: an unbound
 * autowired `Connection` is the schema owner).
 *
 * The two writes are raw SQL naming exactly the columns `erp_app`'s column grants cover (`SchemaManifest['warehouse']`,
 * `Version20261013000100`): the INSERT names `id, tenant_id, code, name, country_code`; the UPDATE sets only the
 * **changed** columns plus `updated_at`. `address_line`, `city`, `postal_code`, `is_active` and `deleted_at` are in
 * neither (Q-W4, `RESIDUAL_GAPS.md` G-36); `deleted_at` is written only by {@see self::restore()} (warehouse/location
 * delete-restore C1, `Version20261020000100`) and {@see self::delete()} (C4, `DeleteWarehouse`). A 42501 from either is fixed by removing the column from the statement, never by
 * widening the grant.
 *
 * **Refusals, in order** (placeholder copy; `UX` owes the warehouse copy):
 *  1. shape, before any transaction: required/null, blank (empty after trimming — the value is stored as sent, no trim),
 *     country code shape;
 *  2. (update) the row: absent or foreign → 404; archived → 409 `warehouse.is_archived` ({@see self::refuseIfArchived()},
 *     never a `refuseUnless…` name, which `EditWindowGuardTest` clause (b) would read as an edit window);
 *  3. country not in the reference table → 422 `unknown_reference` (the house's one sentence, held equal to
 *     `CustomerWriter`'s by `WarehouseWriteTest`; `RESIDUAL_GAPS.md` G-37);
 *  4. **one live unique, refused, never replayed**, translated **by quoted constraint name** from the 23505 **outside**
 *     `TenantContext::run()` (the failed statement aborted the transaction): `warehouse_tenant_code_live` → 409
 *     `warehouse.code_is_unique_per_workspace` (the §4.2 `Idem` mechanism; the `Idempotency-Key` header is demanded and
 *     unused). Any other 23505 is not ours and is rethrown. **The name is not unique** (no index): two warehouses may share
 *     a name.
 *
 * One row lock, taken by the first read of every update (`FOR NO KEY UPDATE`, the lock the `UPDATE` would take anyway):
 * last writer still wins, and there is no `stale-record`; the lock exists so `warehouse.updated`'s `from` values are exact —
 * an unlocked diff could record a value nobody saw, or hide a concurrent change. The grant on `updated_at` (and the other
 * three columns) is also what makes this locking read legal for `erp_app` (A4.3 constraint 1), and C3's `FOR SHARE` on the
 * parent warehouse. Both audit calls sit **after** their write statement, inside the same `run()`, so a 23505 (caught
 * outside `run()`) or any 4xx leaves no row. An update that changes nothing issues no `UPDATE` and writes no row.
 *
 * **Values are recorded** (owner Q-W2 (a), adopted under delegation; final RED M-4: values ship, `SEC` confirmation is not a
 * build gate): `warehouse.created` carries `{values}`, `warehouse.updated` `{fields, from, to}`, over the explicit allowlist
 * {@see WarehouseAuditWriter::VALUE_FIELDS}. A rename changes the name shown on issued purchase orders, sales orders and
 * pick lists, which join the live row (`RESIDUAL_GAPS.md` G-43 half (i)); the old name is recoverable from the trail.
 */
final readonly class WarehouseWriter
{
    public const CODE_TAKEN_ON_CREATE = 'Another warehouse already uses this code. Choose a different code.';

    public const CODE_TAKEN_ON_UPDATE = 'Another warehouse already uses this code. Keep the current code or choose a different one.';

    public const ARCHIVED = 'This warehouse is archived, so it can\'t be changed until it\'s restored.';

    /** `RestoreWarehouse` on a live warehouse (`UX` U-1, `wh-loc-delete-roles.md`). */
    public const NOT_ARCHIVED = 'This warehouse is not archived, so there is nothing to restore.';

    /** `RestoreWarehouse` when a live warehouse took this one's code while it was archived (`UX` U-1). Passive (R-3). */
    public const CODE_TAKEN_ON_RESTORE = 'Another warehouse now uses this warehouse\'s code, so it can\'t be restored. It can be restored once that warehouse\'s code is changed.';

    /**
     * `DeleteWarehouse`'s row lock: `FOR UPDATE`, its first and only row lock (W-5 rule 3; the warehouse row locks amendment,
     * rule 1). Only `FOR UPDATE` conflicts with the `FOR KEY SHARE` every path creating work or stock in a warehouse holds, so
     * nothing new can arrive while it is held (mutation M10: `FOR NO KEY UPDATE` → the races are red). Public so a race test
     * holds THIS statement (`SupplierWriter::DELETE_LOCK`'s reason).
     */
    public const DELETE_LOCK = 'SELECT deleted_at IS NOT NULL AS archived FROM warehouse WHERE id = ?::uuid FOR UPDATE';

    /** The archive itself: the two columns `erp_app` may write for it (`Version20261020000100`). Its locations are untouched. */
    public const ARCHIVE = 'UPDATE warehouse SET deleted_at = now(), updated_at = now() WHERE id = ?::uuid';

    /** `DeleteWarehouse` repeated (ST-9; `UX` U-1 C4). */
    public const ALREADY_ARCHIVED = 'This warehouse is already archived. It\'s in the archived warehouses.';

    /** API-O-34, first leg (`UX` U-1 C4, U-3 precedence). `%s` from {@see self::openPurchaseOrders()}. Passive (R-3). */
    public const HAS_OPEN_PURCHASE_ORDERS = 'This warehouse still has %s, so it can\'t be deleted until each open order is received in full or cancelled. A draft can be moved to another warehouse or simply deleted.';

    /** API-O-34, second leg. `%s` from {@see self::openSalesOrders()}. */
    public const HAS_OPEN_SALES_ORDERS = 'This warehouse still has %s, so it can\'t be deleted until each open order is shipped in full or cancelled. A draft can be moved to another warehouse or simply deleted.';

    /** API-O-34, third leg: stock in any of its locations, live or archived. `%s` from {@see self::locations()}. */
    public const HOLDS_STOCK = 'This warehouse still holds stock in %s, so it can\'t be deleted until all of it is moved to another warehouse or counted off.';

    /** Appended with one space when any of that stock is an archived item's (`UX` U-4; `RESIDUAL_GAPS.md` G-51). */
    public const ARCHIVED_ITEM_STOCK = 'Stock of an archived item can be moved or counted off only once that item is restored.';

    public const COUNTRY_UNSUPPORTED = 'This country isn\'t supported yet.';

    public const COUNTRY_SHAPE = 'Use the two-letter country code, for example GB.';

    private const CODE_UNIQUE = 'warehouse_tenant_code_live';

    /** The three accepted fields, in §4.2's order, as wire names. All three are required on create. */
    private const FIELDS = ['code', 'name', 'country_code'];

    public function __construct(
        private TenantContext $tenants,
        private CurrentTenant $tenant,
        private RequestIdentity $identities,
        private WarehouseAuditWriter $audit,
        private WarehouseOpenPurchaseOrders $openPurchaseOrders,
        private WarehouseOpenSalesOrders $openSalesOrders,
        private WarehouseHeldStock $heldStock,
    ) {
    }

    /** The noun of {@see self::HAS_OPEN_PURCHASE_ORDERS} (`UX` R-2; the counting rule is {@see Counted::of()}). */
    public static function openPurchaseOrders(int $count): string
    {
        return Counted::of($count, 'open purchase order', 'open purchase orders');
    }

    /** The noun of {@see self::HAS_OPEN_SALES_ORDERS}. */
    public static function openSalesOrders(int $count): string
    {
        return Counted::of($count, 'open sales order', 'open sales orders');
    }

    /** The noun of {@see self::HOLDS_STOCK}: locations, never quantities or items (`SEC` S-3). */
    public static function locations(int $count): string
    {
        return Counted::of($count, 'location', 'locations');
    }

    /** @param array<string, mixed> $values wire name => value, for the fields the body carried */
    public function create(array $values): WarehouseView
    {
        self::refuseMalformed($values, self::FIELDS);

        $id = Uuid::v4()->toRfc4122();
        $tenantId = $this->tenant->tenantId();
        $identity = $this->identities->authenticated();

        try {
            return $this->tenants->run($tenantId, function (EntityManagerInterface $em) use ($id, $tenantId, $values, $identity): WarehouseView {
                self::refuseUnsupportedReferences($em, $values);

                $em->getConnection()->executeStatement(
                    'INSERT INTO warehouse (id, tenant_id, code, name, country_code) VALUES (?::uuid, ?::uuid, ?, ?, ?)',
                    [$id, $tenantId, $values['code'], $values['name'], $values['country_code']],
                );

                // After the INSERT, in its transaction: a 23505 above leaves no row.
                $this->audit->recordCreated(
                    tenantId: $identity->tenantId,
                    actorUserId: $identity->userId,
                    actorLabel: $identity->actorLabel,
                    warehouseId: $id,
                    values: ['code' => $values['code'], 'name' => $values['name'], 'country_code' => $values['country_code']],
                );

                return WarehouseReader::readInside($em, $id) ?? throw new \LogicException('The warehouse just inserted is not readable.');
            });
        } catch (UniqueConstraintViolationException $e) {
            // Outside `run()`: the failed INSERT aborted the transaction. The id is minted here, so only the live code
            // unique can fire.
            throw self::translated($e, self::CODE_TAKEN_ON_CREATE);
        }
    }

    /** @param array<string, mixed> $submitted wire name => value, exactly the fields the body carried */
    public function update(string $id, array $submitted): WarehouseView
    {
        if (!Uuid::isValid($id)) {
            throw ApiProblem::notFound();
        }
        $id = Uuid::fromString($id)->toRfc4122();

        self::refuseMalformed($submitted, []);
        $identity = $this->identities->authenticated();

        try {
            return $this->tenants->run($this->tenant->tenantId(), function (EntityManagerInterface $em) use ($id, $submitted, $identity): WarehouseView {
                $connection = $em->getConnection();
                // The row lock: the diff below is taken against a row no concurrent update can move until this transaction
                // ends, so `warehouse.updated`'s `from` values are what this request replaced.
                $row = $connection->fetchAssociative(
                    'SELECT deleted_at IS NOT NULL AS archived, code, name, country_code FROM warehouse WHERE id = ?::uuid FOR NO KEY UPDATE',
                    [$id],
                );
                if (false === $row) {
                    throw ApiProblem::notFound();
                }
                self::refuseIfArchived($row);

                self::refuseUnsupportedReferences($em, $submitted);

                $changed = self::changedFields($row, $submitted);
                if ([] !== $changed) {
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
                    $parameters[] = $id;
                    $connection->executeStatement('UPDATE warehouse SET '.implode(', ', $set).' WHERE id = ?::uuid', $parameters);

                    // After the UPDATE, in its transaction: a 23505 above leaves no row.
                    $this->audit->recordUpdated(
                        tenantId: $identity->tenantId,
                        actorUserId: $identity->userId,
                        actorLabel: $identity->actorLabel,
                        warehouseId: $id,
                        from: $from,
                        to: $to,
                    );
                }

                return WarehouseReader::readInside($em, $id) ?? throw new \LogicException('The warehouse just updated is not readable.');
            });
        } catch (UniqueConstraintViolationException $e) {
            throw self::translated($e, self::CODE_TAKEN_ON_UPDATE);
        }
    }

    /**
     * **`RestoreWarehouse`** (warehouse/location delete-restore C1, 2026-10-20; `docs/session13/wh-loc-delete-design.md`
     * §3.3, §4.1, Revision 2; `SupplierWriter::restore()`'s shape). In one `TenantContext::run()`:
     *  1. a non-uuid, absent or foreign id → 404 (ST-5, before any transaction for a non-uuid);
     *  2. the row read `FOR NO KEY UPDATE` (the C5 restore lock: it must not wait on document readers' `FOR KEY SHARE`;
     *     it serialises with `DeleteWarehouse`'s `FOR UPDATE` and the location writers' `FOR SHARE`, and takes nothing
     *     after — `ARCHITECTURE.md` W-5 "warehouse row locks") — live → 409 `warehouse.is_not_archived` (§4.2
     *     `guard:warehouse.deleted_at`: a replayed restore meets a live row, so no `Idempotency-Key`);
     *  3. `UPDATE warehouse SET deleted_at = NULL, updated_at = now()` (the column grant of `Version20261020000100`);
     *  4. `warehouse.restored`, `{}`, after the UPDATE, in its transaction — a refusal writes none;
     *  5. 200 with `GetWarehouse`'s body, read inside the transaction.
     * A live warehouse that took this one's code meanwhile → `warehouse_tenant_code_live` 23505 at step 3, translated
     * **outside** `run()` to 409 `warehouse.code_is_unique_per_workspace` with the restore sentence. **No open-work or
     * stock check** (§4.1: restoring creates no work). Its locations are untouched (owner Q-2 (a)): the ones left live are
     * usable again at once, the ones archived on their own stay archived.
     */
    public function restore(string $id): WarehouseView
    {
        if (!Uuid::isValid($id)) {
            throw ApiProblem::notFound();
        }
        $id = Uuid::fromString($id)->toRfc4122();
        $identity = $this->identities->authenticated();

        try {
            return $this->tenants->run($this->tenant->tenantId(), function (EntityManagerInterface $em) use ($id, $identity): WarehouseView {
                $connection = $em->getConnection();
                // fetchAssociative, never fetchOne: a live row's `false` would read as "no row".
                $row = $connection->fetchAssociative('SELECT deleted_at IS NOT NULL AS archived FROM warehouse WHERE id = ?::uuid FOR NO KEY UPDATE', [$id]);
                if (false === $row) {
                    throw ApiProblem::notFound();
                }
                if (true !== $row['archived']) {
                    throw ApiProblem::businessRuleViolated('warehouse.is_not_archived', self::NOT_ARCHIVED);
                }

                $connection->executeStatement('UPDATE warehouse SET deleted_at = NULL, updated_at = now() WHERE id = ?::uuid', [$id]);

                // After the UPDATE, in its transaction: a 23505 above leaves no row.
                $this->audit->recordRestored(
                    tenantId: $identity->tenantId,
                    actorUserId: $identity->userId,
                    actorLabel: $identity->actorLabel,
                    warehouseId: $id,
                );

                return WarehouseReader::readInside($em, $id) ?? throw new \LogicException('The warehouse just restored is not readable.');
            });
        } catch (UniqueConstraintViolationException $e) {
            throw self::translated($e, self::CODE_TAKEN_ON_RESTORE);
        }
    }

    /**
     * **`DeleteWarehouse`** (warehouse/location delete-restore C4, 2026-10-20; `docs/session13/wh-loc-delete-design.md` §0,
     * §2.1, §3.3, Revision 2; API-O-34, the owner's note of 2026-09-13; owner Q-2 (a) and Q-3 adopted under delegation,
     * `wh-loc-delete-q-ruling.md`). A soft delete: the warehouse stays readable (`GetWarehouse`, `ListWarehouses?archived=true`)
     * and is restored by {@see self::restore()}. In one `TenantContext::run()`:
     *  1. a non-uuid, absent or foreign id → 404;
     *  2. {@see self::DELETE_LOCK} — `FOR UPDATE`, the first and **only** row lock (W-5 rule 3; `PartyLockOrderScanTest` holds
     *     this method to it): every path that creates work or stock in a warehouse holds it `FOR KEY SHARE` (W-5 warehouse row
     *     locks, rule 1), so each count below — a later statement under READ COMMITTED — sees everything committed before the
     *     lock was granted, and nothing new can arrive while it is held. Already archived → 409 `warehouse.is_archived`;
     *  3. open purchase orders (live `draft`, `ordered`, `partially_received`; Purchasing answers) → 409
     *     `warehouse.has_open_purchase_orders`;
     *  4. open sales orders (live `draft`, `confirmed`, `partially_fulfilled`; Sales answers) → 409
     *     `warehouse.has_open_sales_orders`;
     *  5. stock in any of its locations, live **or archived** (Inventory answers) → 409 `warehouse.holds_stock`, plus the
     *     archived-item sentence when any of it is an archived item's. Order: `UX` U-3 (resolving orders changes stock);
     *  6. {@see self::ARCHIVE}; 7. `warehouse.deleted`, `{}`, after it — none on any refusal. 204, no body.
     * **Not refused** (`PO` P-2): the last live warehouse (N-2); its live locations, left as they are (owner Q-2 (a): unusable
     * while it is archived, back as before on restore — W-6); posted documents, which keep its name (G-43). **Not locked**: its
     * locations (rejected design (L): a bin lock after the party lock could deadlock with a stock writer); the Port answerers
     * lock nothing. It can wait behind a stream of `KEY SHARE` holders (disclosed, W-5; no fairness mechanism).
     */
    public function delete(string $id): void
    {
        if (!Uuid::isValid($id)) {
            throw ApiProblem::notFound();
        }
        $id = Uuid::fromString($id)->toRfc4122();
        $identity = $this->identities->authenticated();

        $this->tenants->run($this->tenant->tenantId(), function (EntityManagerInterface $em) use ($id, $identity): void {
            $connection = $em->getConnection();
            // fetchAssociative, never fetchOne: a live row's `false` would read as "no row".
            $row = $connection->fetchAssociative(self::DELETE_LOCK, [$id]);
            if (false === $row) {
                throw ApiProblem::notFound();
            }
            if (true === $row['archived']) {
                throw ApiProblem::businessRuleViolated('warehouse.is_archived', self::ALREADY_ARCHIVED);
            }

            $purchase = $this->openPurchaseOrders->openPurchaseOrderCountInside($em, $id);
            if ($purchase > 0) {
                throw ApiProblem::businessRuleViolated('warehouse.has_open_purchase_orders', \sprintf(self::HAS_OPEN_PURCHASE_ORDERS, self::openPurchaseOrders($purchase)));
            }
            $sales = $this->openSalesOrders->openSalesOrderCountInside($em, $id);
            if ($sales > 0) {
                throw ApiProblem::businessRuleViolated('warehouse.has_open_sales_orders', \sprintf(self::HAS_OPEN_SALES_ORDERS, self::openSalesOrders($sales)));
            }
            $held = $this->heldStock->stockedLocationCountInWarehouseInside($em, $id);
            if ($held->stocked > 0) {
                $sentence = \sprintf(self::HOLDS_STOCK, self::locations($held->stocked));
                throw ApiProblem::businessRuleViolated('warehouse.holds_stock',
                    $held->archivedItemStocked > 0 ? $sentence.' '.self::ARCHIVED_ITEM_STOCK : $sentence);
            }

            $connection->executeStatement(self::ARCHIVE, [$id]);

            // After the UPDATE, in its transaction: a refusal above leaves no row.
            $this->audit->recordDeleted(
                tenantId: $identity->tenantId,
                actorUserId: $identity->userId,
                actorLabel: $identity->actorLabel,
                warehouseId: $id,
            );
        });
    }

    /**
     * ST-9: an archived warehouse is read-only, until it is restored ({@see self::restore()}). Reachable through the
     * product since `DeleteWarehouse` (C4, 2026-10-20). The sentence is passive (`UX` R-3): the `.edit`
     * holder may not hold `warehouse.delete`, which restoring needs.
     *
     * @param array<string, mixed> $row
     */
    private static function refuseIfArchived(array $row): void
    {
        if (true === $row['archived']) {
            throw ApiProblem::businessRuleViolated('warehouse.is_archived', self::ARCHIVED);
        }
    }

    /**
     * The 23505 names the live code unique, read out of the driver's message (`duplicate key value violates unique
     * constraint "<name>"`) — DBAL carries no structured constraint name (`PurchaseOrderWriter::violated()`'s reason). The
     * name is matched **quoted**. A 23505 naming anything else is not ours: rethrown.
     */
    private static function translated(UniqueConstraintViolationException $e, string $codeTaken): \Throwable
    {
        if (str_contains($e->getMessage(), '"'.self::CODE_UNIQUE.'"')) {
            return ApiProblem::businessRuleViolated('warehouse.code_is_unique_per_workspace', $codeTaken);
        }

        return $e;
    }

    /**
     * The wire names whose submitted value differs from the locked row, sorted ascending, compared as exact strings — no
     * trim, no case folding (stored as sent).
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
     * Shape, and only shape: decidable without the database, so it runs before a transaction is opened.
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

            if ('country_code' === $field) {
                if (!\is_string($value) || 1 !== preg_match('/^[A-Z]{2}$/', $value)) {
                    $errors[] = self::error($field, 'not_a_country_code', self::COUNTRY_SHAPE);
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

    /**
     * `country` is **reference data** (ADR-002 §6), not tenant-scoped, so the lookup is an existence check and discloses
     * nothing. It holds four rows today (`RESIDUAL_GAPS.md` G-37), hence "not supported yet".
     *
     * @param array<string, mixed> $values
     */
    private static function refuseUnsupportedReferences(EntityManagerInterface $em, array $values): void
    {
        if (isset($values['country_code']) && false === $em->getConnection()->fetchOne('SELECT 1 FROM country WHERE code = ?', [$values['country_code']])) {
            throw ApiProblem::validationFailed([self::error('country_code', 'unknown_reference', self::COUNTRY_UNSUPPORTED)]);
        }
    }

    /** @return array{pointer: string, code: string, message: string} */
    private static function error(string $field, string $code, string $message): array
    {
        return ['pointer' => '/'.$field, 'code' => $code, 'message' => $message];
    }
}
