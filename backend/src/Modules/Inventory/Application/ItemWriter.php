<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Application;

use App\Modules\AuditLog\Infrastructure\ItemAuditWriter;
use App\Modules\Inventory\ApiResource\CreateItemInput;
use App\Modules\Inventory\ApiResource\ItemResource;
use App\Modules\Inventory\ApiResource\UpdateItemInput;
use App\Modules\Inventory\Application\Port\Purchasing\ItemPurchaseLines;
use App\Modules\Inventory\Application\Port\Sales\ItemSalesLines;
use App\Modules\Inventory\Application\Port\Suppliers\ItemCatalogueEntries;
use App\Modules\UserManagement\Application\Contract\WorkspaceSettings;
use App\Platform\Http\Problem\ApiProblem;
use App\Platform\Security\RequestIdentity;
use App\Platform\Tenancy\CurrentTenant;
use App\Platform\Tenancy\TenantContext;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * The write half of the `/items` slice: create, update, archive, restore.
 *
 * ---------------------------------------------------------------------------
 * B-3 / API-10 / D-12
 * ---------------------------------------------------------------------------
 * `Uuid::v4()` is the only identifier source, and it is called here. Nothing the
 * caller sent reaches the `id` column: the input DTOs have no such property, the
 * deserializer refuses undeclared fields, and there is no `PUT` that could put a
 * client-chosen id in a path.
 *
 * ---------------------------------------------------------------------------
 * Where the currency comes from
 * ---------------------------------------------------------------------------
 * From `tenant_settings`, read inside the tenant transaction. It used to come
 * from `app.inventory.default_currency_code`, an **instance-wide container
 * parameter supplying a per-tenant business value** — so every tenant on the
 * deployment was priced in euros, and nothing could see it, because a PHP
 * constant never reaches the database. `Version20260906000100` dropped
 * `inventory_item.currency_code`'s default precisely so a row could not be
 * created *"with no stated currency and be silently priced in euros"*; the
 * parameter did it anyway, one layer up. Ledger E4-3.
 *
 * There is no fallback. A workspace with no settings row cannot create an item,
 * and that refusal is the point: a `?? 'EUR'` here would be the same defect
 * with a different address.
 *
 * ---------------------------------------------------------------------------
 * Where a uniqueness refusal is caught
 * ---------------------------------------------------------------------------
 * Outside `TenantContext::run()`, never inside it. A failed statement leaves the
 * Postgres transaction aborted (25P02); catching within the block and continuing
 * would make the following `COMMIT` fail with an error nobody could trace back to
 * the SKU that caused it. Rolling back first and translating afterwards is the
 * only order that produces the `business-rule-violated` §5.2 asks for.
 */
final readonly class ItemWriter
{
    /**
     * numeric(19,6) — precision 19, scale 6, so at most 13 digits before the point. **No sign** (BK-26, items batch C4):
     * a reorder point, reorder quantity or safety stock below zero means nothing, so `-3` and `-0` are refused here and
     * the three `inventory_item_*_non_negative` CHECKs refuse it for SQL as `erp_app`. **`\z`, not `$`** (BK-23): `$`
     * also matches before a trailing line break, so `"5\n"` passed the check and was stored as 5. Used only for the three
     * reorder fields.
     */
    private const DECIMAL = '/^\d{1,13}(\.\d{1,6})?\z/';

    /**
     * The item `not_a_decimal` sentence (UX-I8′, `docs/design/items-ux-copy.md`), public so a test pins this constant AND
     * the literal (BK-20's shape). Only the item copy changes in C4; eight other writers keep their own sentence.
     */
    public const NOT_A_DECIMAL = 'Enter 0 or a positive number, for example 10 or 2.5. Use only digits and at most one decimal point, with 1 to 13 digits before the point and 1 to 6 after it.';

    /**
     * **`DeleteItem`'s row lock — public so a race test's holder runs THIS statement, never a transcription**
     * (`CustomerWriter::DELETE_LOCK`'s precedent). Taken before every check (G5-C2a, the shipped-defect fix, owner notice
     * N-8): before it, `archive()` read the row unlocked, so of two concurrent deletes both passed the "already archived"
     * check and the second's UPDATE, waiting on the first's row lock, then re-matched and moved `deleted_at` forward.
     * `FOR UPDATE`, not `FOR NO KEY UPDATE`: it also waits for an in-flight stock write's `KEY SHARE` on the item (the
     * `stock_movement` FK, or `StockAdjuster` / `StockTransferrer`'s explicit item read), so an archive and a stock write
     * serialise. The single delete reads no stock; the reasons are that an explicit `FOR KEY SHARE` liveness read (the
     * count, the transfer) is only effective against a lock it conflicts with — a count or transfer racing an archive now
     * waits and is refused as archived, where before it posted (owner notice, `g5-c2a-red.md` M-2) — parity with the bulk
     * archival's lock (design R1 M-4), and G-51's eventual stocked-item refusal. It is the ONLY item row lock this operation
     * takes; the audit INSERT afterwards also takes `KEY SHARE` on the actor's `app_user` row through its FK, which conflicts
     * with nothing `src/` takes on `app_user` (no `FOR UPDATE` there). So it cannot close a cycle with a multi-line stock
     * writer (final RED M-3; structural, with the waits and answers measured in `ItemDeleteLockOrderTest`).
     */
    public const DELETE_LOCK = 'SELECT deleted_at IS NOT NULL AS archived FROM inventory_item WHERE id = ?::uuid FOR UPDATE';

    /**
     * **`UpdateItem`'s row lock**, before the archived check (G5-C2a, RED M-1). Its own statement, never `ItemRow::FROM`'s
     * join, so it locks the item row only. `FOR NO KEY UPDATE`: it conflicts with {@see self::DELETE_LOCK} and with
     * itself, not with stock writers' `KEY SHARE` (`CustomerWriter::update()`'s lock).
     */
    public const EDIT_LOCK = 'SELECT 1 FROM inventory_item WHERE id = ?::uuid FOR NO KEY UPDATE';

    /**
     * **`UpdateItem`'s unit lock — an upgrade of {@see self::EDIT_LOCK} on the same row, in the same transaction** (BK-24,
     * items batch C3, 2026-11-10; `docs/session13/items-batch-design.md` "Revision 1 — FINAL" C3). Taken only when a unit is
     * submitted AND differs from the stored one, after the archived check and the reference check. `FOR UPDATE` conflicts
     * with `KEY SHARE`, so it waits for every in-flight write of a row that names the item — the FK of an insert into any
     * {@see self::UNIT_IN_USE_LEGS} table, and the explicit `FOR KEY SHARE` liveness reads of the order, catalogue and stock
     * writers — and the leg reads after it (separate statements, READ COMMITTED) see what those writers committed. Public so a
     * race test's holder runs THIS statement (the {@see self::DELETE_LOCK} precedent).
     *
     * A cycle through the item row is possible and is answered, not prevented (`ARCHITECTURE.md` W-5, the item-row amendment):
     * PostgreSQL aborts one side (`40P01`), and the upgrade's plain wait ends at {@see self::UNIT_LOCK_TIMEOUT} (`55P03`);
     * both answer 409 `transaction.contention` (`TransactionContention`), nothing saved.
     */
    public const UNIT_LOCK = 'SELECT 1 FROM inventory_item WHERE id = ?::uuid FOR UPDATE';

    /**
     * **The bound on {@see self::UNIT_LOCK}'s wait, and on nothing else** (first RED H-1; final RED FL-2). Run immediately
     * before the upgrade and undone by {@see self::UNIT_LOCK_TIMEOUT_OFF} immediately after it, so the leg reads, the UPDATE
     * and the audit INSERT run unbounded, as before. `SET LOCAL` is transaction-scoped, so it is safe under PgBouncer's
     * transaction pooling. **Why 5 s** (`DATA` TO CONFIRM the value): `deadlock_timeout` is 1 s, so a cycle is broken as
     * `40P01` before this fires; and the web app's BFF gives the API 15 s (`API_TIMEOUT_MS`), so a 5 s wait plus the rest
     * of the request ends with an API answer, never a proxy abort. `ItemUnitInUseTest` reads the seconds from this string.
     */
    public const UNIT_LOCK_TIMEOUT = "SET LOCAL lock_timeout = '5s'";

    /**
     * Back to the session's configured value (final RED FL-1): `TO DEFAULT`, never `= 0`, so a role or database default that
     * `DATA`/`OPS` may one day set is not silently switched off for the rest of the transaction. Both are 0 today.
     */
    public const UNIT_LOCK_TIMEOUT_OFF = 'SET LOCAL lock_timeout TO DEFAULT';

    /**
     * **Every table whose rows name an item, so a row in any of them means the item's unit is in use** (BK-24; first RED
     * M-3; `PO` TO CONFIRM). Exactly the tables with a foreign key to `inventory_item`, sorted — held equal to the server's
     * set by `ItemUnitInUseTest::everyTableThatNamesAnItemIsALegOfTheRule`, so a sixth (a future count line) cannot escape
     * the rule silently. Deleted order lines count: they still recorded a quantity in the unit. {@see self::unitInUse()}
     * iterates this list and refuses a member it cannot answer (final RED FL-5): Inventory's own tables by
     * {@see self::OWN_LEGS}, the other modules' through the three Ports.
     */
    public const UNIT_IN_USE_LEGS = UnitInUseLegs::TABLES;

    /**
     * The two legs that are Inventory's own tables: any row, any quantity (a zero stock row was a stock row). An alias of
     * {@see UnitInUseLegs::OWN}: the bare stock table names live in that constants class, never here, so this writer
     * holds no literal the stock-write scans read as a convenience writer (`items-c3-deviations-red.md` D-7).
     */
    public const OWN_LEGS = UnitInUseLegs::OWN;

    /**
     * `inventory_item.unit_in_use`'s sentence (`docs/design/items-owner-items.md` H-2's draft, no remedy). Its noun was re-worded
     * from "price lists" to "supplier catalogues" by `PO`, `docs/design/items-i4-po.md` item 2 (BK-30), and the new text is
     * carried unchanged by `UX`'s BE-S8 row (`suppliers-ux-copy.md` Revision 1 — FINAL). `UX` TO CONFIRM.
     * It names no leg, no count and no document (`SEC`, `items-batch-sec-arch.md` S-8): the caller may hold none of the
     * stock, order or catalogue views.
     */
    public const UNIT_IN_USE = 'This item\'s unit can\'t be changed because stock, orders or supplier catalogues already record quantities in it.';

    /**
     * The bulk-only stock read (G5-C2, {@see self::archiveUnlessStocked()}): any non-zero on-hand in any location, any kind,
     * quarantine included — the same "any stock" `storage_location.holds_stock` means. A read of `stock_level`, never a
     * write, so TB-6's *"anything touching stock_level"* (writes) is not crossed (`UX` to confirm the reading).
     *
     * **Built from {@see ItemRow::HOLDS_STOCK} since items batch C2 (BK-27)**, so the bulk refusal and the `holds_stock` an
     * item read serves are one predicate, not two copies that agree today (`ItemHoldsStockTest` pins the containment; a
     * copy that drifted by one character would leave every behaviour test green). Item-addressed: no row for an id the
     * caller cannot see, which `archiveUnlessStocked()` never asks about (it locks the item first).
     */
    public const STOCKED = 'SELECT '.ItemRow::HOLDS_STOCK.' FROM inventory_item i WHERE i.id = ?::uuid';

    /** Never shown: a bulk answers counts only (E-14). Here so the refusal is a named rule like every other. */
    public const HOLDS_STOCK_IN_BULK = 'This item still holds stock, so it is not archived in bulk.';

    /** The archive, live rows only: 0 rows means another delete committed first (refused, no audit row). */
    public const ARCHIVE = 'UPDATE inventory_item SET deleted_at = now(), updated_at = now() WHERE id = ?::uuid AND deleted_at IS NULL';

    /**
     * **`RestoreItem`'s row lock**, before every check. `FOR NO KEY UPDATE` is enough (`CustomerWriter::restore()`'s
     * reason): it conflicts with itself and with {@see self::DELETE_LOCK}, and a restore need not wait on stock writers.
     */
    public const RESTORE_LOCK = 'SELECT deleted_at IS NOT NULL AS archived FROM inventory_item WHERE id = ?::uuid FOR NO KEY UPDATE';

    /** The restore, archived rows only: 0 rows means another restore committed first (refused, no audit row). */
    public const RESTORE = 'UPDATE inventory_item SET deleted_at = NULL, updated_at = now() WHERE id = ?::uuid AND deleted_at IS NOT NULL';

    public function __construct(
        private TenantContext $tenants,
        private CurrentTenant $tenant,
        private ItemReader $reader,
        private WorkspaceSettings $settings,
        private RequestIdentity $identities,
        private ItemAuditWriter $audit,
        private ItemPurchaseLines $purchaseLines,
        private ItemSalesLines $salesLines,
        private ItemCatalogueEntries $catalogueEntries,
    ) {
    }

    public function create(CreateItemInput $input): ItemResource
    {
        // `CreateItemInput`'s properties default to null, so on a create there
        // is no difference between "absent" and "sent as null" and none is
        // invented: the nulls are dropped and the three required fields are
        // checked by name.
        $values = array_filter([
            'sku' => $input->sku,
            'name' => $input->name,
            'unitOfMeasureCode' => $input->unitOfMeasureCode,
            'supplierId' => $input->supplierId,
            'reorderPoint' => $input->reorderPoint,
            'reorderQuantity' => $input->reorderQuantity,
            'safetyStock' => $input->safetyStock,
        ], static fn (?string $value): bool => null !== $value);

        $this->refuseMalformed($values, required: ['sku', 'name', 'unitOfMeasureCode']);

        $id = Uuid::v4()->toRfc4122();

        return $this->translatingConstraintFailures(fn (): ItemResource => $this->tenants->run(
            $this->tenant->tenantId(),
            function (EntityManagerInterface $em) use ($values, $id): ItemResource {
                $connection = $em->getConnection();
                $this->refuseUnresolvableReferences($connection, $values);

                // Read INSIDE the transaction, and that is not incidental:
                // `set_config(..., true)` and the tenant filter's parameter are
                // both transaction-scoped, so this is the only place the read
                // resolves to this caller's workspace. See
                // `UserManagement\Infrastructure\TenantSettingsReader`.
                $currencyCode = $this->settings->defaultCurrencyCode();

                $connection->executeStatement(
                    <<<'SQL'
                    INSERT INTO inventory_item
                        (id, tenant_id, sku, name, unit_of_measure_code, currency_code,
                         supplier_id, reorder_point, reorder_quantity, safety_stock)
                    VALUES
                        (?::uuid, ?::uuid, ?, ?, ?, ?, ?::uuid,
                         COALESCE(?::numeric, 0), COALESCE(?::numeric, 0), COALESCE(?::numeric, 0))
                    SQL,
                    [
                        $id,
                        // Bound explicitly rather than read back out of the GUC.
                        // The row still has to satisfy the RLS WITH CHECK clause,
                        // so a mismatch between this value and the transaction's
                        // tenant is refused by Postgres rather than accepted.
                        $this->tenant->tenantId(),
                        $values['sku'],
                        $values['name'],
                        $values['unitOfMeasureCode'],
                        $currencyCode,
                        $values['supplierId'] ?? null,
                        $values['reorderPoint'] ?? null,
                        $values['reorderQuantity'] ?? null,
                        $values['safetyStock'] ?? null,
                    ],
                );

                return $this->readInside($connection, $id) ?? throw new \LogicException('The row just inserted is not visible to its own transaction.');
            },
        ));
    }

    /**
     * Since G5-C2a (RED `g5-c2a-red.md` M-1, a shipped defect of N-8's shape): {@see self::EDIT_LOCK} before the archived
     * check, and the UPDATE carries `AND deleted_at IS NULL` with a row-count check, so an edit racing a delete waits and is
     * refused as archived instead of landing on the archived row (ST-9).
     *
     * Since items batch C1 (BK-25, 2026-11-06): `inventory_item.updated` with the changed fields' old and new **values**,
     * after the UPDATE, in its transaction. The diff is {@see self::servedValues()} of the row read before the UPDATE and of
     * the row read back after it — both under {@see self::EDIT_LOCK} — never the submitted strings: `"10"` sent for a stored
     * `10.000000` is no change, and the served form is canonical (scale 6, the supplier's id). No row when nothing changed,
     * including a PATCH that resends the current values (it still moves `updated_at`, as before). A refused edit writes none:
     * every refusal is thrown before the audit call, and a SKU collision's 23505 rolls the row back with the transaction.
     *
     * Since items batch C3 (BK-24, 2026-11-10): a submitted unit that differs from the stored one takes
     * {@see self::UNIT_LOCK} (bounded by {@see self::UNIT_LOCK_TIMEOUT}) and is refused 409 `inventory_item.unit_in_use`
     * when any {@see self::UNIT_IN_USE_LEGS} row names the item. Order: lock → archived → references (an unknown unit is a
     * 422 first) → the unit rule → the UPDATE.
     */
    public function update(string $id, UpdateItemInput $input): ItemResource
    {
        $submitted = $input->submitted();
        $this->refuseMalformed($submitted, required: []);
        $identity = $this->identities->authenticated();

        return $this->translatingConstraintFailures(fn (): ItemResource => $this->tenants->run(
            $this->tenant->tenantId(),
            function (EntityManagerInterface $em) use ($id, $submitted, $identity): ItemResource {
                $connection = $em->getConnection();
                if (Uuid::isValid($id) && false === $connection->fetchOne(self::EDIT_LOCK, [$id])) {
                    throw ApiProblem::notFound();
                }
                $current = $this->readInside($connection, $id) ?? throw ApiProblem::notFound();

                // ST-9: an archived row renders read-only. §5.2a puts the
                // refusal on the business-rule branch, not on `not-found` --
                // the caller can see the row, so pretending it is gone would be
                // a different lie from the one it is being told.
                if (null !== $current->archivedAt) {
                    throw self::archived();
                }

                $this->refuseUnresolvableReferences($connection, $submitted);

                // BK-24: only a unit that is sent AND differs takes the upgrade and is checked, so a same-unit save never
                // waits on a stock or order write. Unknown units were refused just above, before any business rule.
                $unit = $submitted['unitOfMeasureCode'] ?? null;
                if (null !== $unit && $unit !== $current->unitOfMeasureCode) {
                    $connection->executeStatement(self::UNIT_LOCK_TIMEOUT);
                    $connection->fetchOne(self::UNIT_LOCK, [$id]);
                    $connection->executeStatement(self::UNIT_LOCK_TIMEOUT_OFF);
                    if ($this->unitInUse($em, $id)) {
                        throw ApiProblem::businessRuleViolated('inventory_item.unit_in_use', self::UNIT_IN_USE);
                    }
                }

                if ([] !== $submitted) {
                    // The assignment list is built from the DTO's own initialised
                    // properties, so the set of updatable fields is derived from
                    // the class the spec documents rather than restated here.
                    // Live rows only: 0 rows means a delete committed first.
                    if (1 !== $connection->executeStatement(
                        'UPDATE inventory_item SET '.implode(', ', array_map(
                            static fn (string $property): string => self::COLUMNS[$property].' = '.self::bind($property),
                            array_keys($submitted),
                        )).', updated_at = now() WHERE id = CAST(:id AS uuid) AND deleted_at IS NULL',
                        [...$submitted, 'id' => $id],
                    )) {
                        throw self::archived();
                    }
                }

                $updated = $this->readInside($connection, $id) ?? throw ApiProblem::notFound();

                // After the UPDATE and its read-back, in its transaction: a refusal above leaves no row.
                $before = self::servedValues($current);
                $after = self::servedValues($updated);
                $changed = array_keys(array_filter($before, static fn (?string $value, string $field): bool => $value !== $after[$field], \ARRAY_FILTER_USE_BOTH));
                if ([] !== $changed) {
                    $this->audit->recordUpdated(
                        tenantId: $identity->tenantId,
                        actorUserId: $identity->userId,
                        actorLabel: $identity->actorLabel,
                        itemId: $id,
                        from: array_intersect_key($before, array_flip($changed)),
                        to: array_intersect_key($after, array_flip($changed)),
                    );
                }

                return $updated;
            },
        ));
    }

    /**
     * **BK-24's legs, in {@see self::UNIT_IN_USE_LEGS}' order, each a separate statement after {@see self::UNIT_LOCK}**
     * (READ COMMITTED: each sees what the writers it waited for committed). The first leg that names the item answers; a
     * member with no answer here is a `\LogicException`, never a skipped leg (final RED FL-5).
     */
    private function unitInUse(EntityManagerInterface $em, string $id): bool
    {
        $ports = [
            'purchase_order_line' => $this->purchaseLines->namesItemInside(...),
            'sales_order_line' => $this->salesLines->namesItemInside(...),
            'supplier_product' => $this->catalogueEntries->namesItemInside(...),
        ];

        foreach (self::UNIT_IN_USE_LEGS as $table) {
            if (isset(self::OWN_LEGS[$table])) {
                if (true === $em->getConnection()->fetchOne(self::OWN_LEGS[$table], [$id])) {
                    return true;
                }
                continue;
            }
            if (!isset($ports[$table])) {
                throw new \LogicException('UNIT_IN_USE_LEGS names '.$table.', which neither an Inventory statement nor a Port answers.');
            }
            if ($ports[$table]($em, $id)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The values `inventory_item.updated` compares and records, by wire name: the item as it is served (`ItemRow`), so a
     * decimal is in its scale-6 form and the supplier is its id or `null`. Keys are exactly
     * `ItemAuditWriter::VALUE_FIELDS`.
     *
     * @return array<string, string|null>
     */
    private static function servedValues(ItemResource $item): array
    {
        return [
            'name' => $item->name,
            'reorder_point' => $item->reorderPoint,
            'reorder_quantity' => $item->reorderQuantity,
            'safety_stock' => $item->safetyStock,
            'sku' => $item->sku,
            'supplier_id' => $item->supplier?->id,
            'unit_of_measure_code' => $item->unitOfMeasureCode,
        ];
    }

    /**
     * API-21 branch 3 — 204, and no body. In one `TenantContext::run()`: {@see self::DELETE_LOCK} (a non-uuid, absent or
     * foreign id → 404); archived → 409 `inventory_item.is_archived`; {@see self::ARCHIVE}, whose live-row predicate and
     * row-count check refuse the same way should the lock ever be weakened; then `inventory_item.deleted`, `{}`, after
     * the UPDATE, in its transaction — a refusal writes none (G5-C2a).
     */
    public function archive(string $id): void
    {
        if (!Uuid::isValid($id)) {
            throw ApiProblem::notFound();
        }
        $identity = $this->identities->authenticated();

        $this->tenants->run($this->tenant->tenantId(), function (EntityManagerInterface $em) use ($id, $identity): void {
            $connection = $em->getConnection();
            // fetchAssociative, never fetchOne: a live row's `false` would read as "no row".
            $row = $connection->fetchAssociative(self::DELETE_LOCK, [$id]);
            if (false === $row) {
                throw ApiProblem::notFound();
            }

            // A repeat delete is told the item is already archived; the
            // update path keeps "can't be changed until it's restored".
            if (true === $row['archived']) {
                throw self::alreadyArchived();
            }

            if (1 !== $connection->executeStatement(self::ARCHIVE, [$id])) {
                throw self::alreadyArchived();
            }

            // After the UPDATE, in its transaction: a refusal above leaves no row.
            $this->audit->recordDeleted(
                tenantId: $identity->tenantId,
                actorUserId: $identity->userId,
                actorLabel: $identity->actorLabel,
                itemId: $id,
            );
        });
    }

    /**
     * **The bulk archival's item member** (G5-C2, 2026-10-22; design §5.2's one deliberate divergence, D-13; final RED M-4):
     * the single {@see self::archive()}, preceded by the stock refusal the single delete does not make (G-51, accepted and
     * shipped: `DeleteItem` archives a stocked item). Not applying it would hand `admin`, who holds `inventory_item.delete`
     * and `stock_movement.adjust`, a mass version of G-51.
     *
     * {@see self::DELETE_LOCK} FIRST — the item `FOR UPDATE`, which conflicts with every stock writer's `KEY SHARE` on the
     * item (the `stock_movement` FK, `StockAdjuster` / `StockTransferrer`'s explicit read) — THEN {@see self::STOCKED}, so
     * the read sees any receipt that got there first (M-4). Point-in-time all the same (RED `g5-c2-red.md` L-7): a receipt
     * that waits on this lock posts once it is released, against the just-archived item (G-51's measured note), so stock can
     * still arrive on an item archived in bulk — the same as for `DeleteItem`. Then the single
     * {@see self::archive()}, which re-takes the same lock in the same transaction and runs every one of its own rules.
     * Absent or foreign → 404; stocked → 409 `inventory_item.holds_stock`: both are "not applied" to the bulk, which never
     * says why (E-14), so the sentence is never shown to anyone.
     *
     * Pinned two-way by `BulkArchivalTest::aStockedItemIsSkippedWhileTheSingleDeleteStillArchivesIt`: the day G-51's own
     * closing work makes `DeleteItem` refuse a stocked item, that test reddens and this method folds into {@see self::archive()}.
     * Runs only inside the bulk's tenancy transaction (it joins it).
     */
    public function archiveUnlessStocked(string $id): void
    {
        if (!Uuid::isValid($id)) {
            throw ApiProblem::notFound();
        }

        $this->tenants->run($this->tenant->tenantId(), function (EntityManagerInterface $em) use ($id): void {
            $connection = $em->getConnection();
            if (false === $connection->fetchAssociative(self::DELETE_LOCK, [$id])) {
                throw ApiProblem::notFound();
            }
            if (true === $connection->fetchOne(self::STOCKED, [$id])) {
                throw ApiProblem::businessRuleViolated('inventory_item.holds_stock', self::HOLDS_STOCK_IN_BULK);
            }
        });

        $this->archive($id);
    }

    /**
     * API-12 / ST-9. `inventory_item` is `SchemaManifest::SOFT`, so it has a
     * `DELETE` and therefore must have a restore, at the permission that
     * archived it.
     *
     * The refusal below is also what makes this operation safe without the
     * idempotency store API-O-1 asks for: §6.1's interim rule is that an
     * operation whose repetition a **precondition** refuses needs no key, and a
     * replayed restore hits a live row and is answered `business-rule-violated`,
     * which tells the caller the first one committed.
     *
     * Since G5-C2a: {@see self::RESTORE_LOCK} before the check, {@see self::RESTORE} with its archived-row predicate and
     * row-count check (the second of two concurrent restores is told "not archived" and moves nothing), then
     * `inventory_item.restored`, `{}`, after the UPDATE — a 23505 on the UPDATE rolls the transaction back first, so a
     * refused restore writes none.
     */
    public function restore(string $id): ItemResource
    {
        if (!Uuid::isValid($id)) {
            throw ApiProblem::notFound();
        }
        $identity = $this->identities->authenticated();

        return $this->translatingConstraintFailures(fn (): ItemResource => $this->tenants->run(
            $this->tenant->tenantId(),
            function (EntityManagerInterface $em) use ($id, $identity): ItemResource {
                $connection = $em->getConnection();
                $row = $connection->fetchAssociative(self::RESTORE_LOCK, [$id]);
                if (false === $row) {
                    throw ApiProblem::notFound();
                }

                if (true !== $row['archived'] || 1 !== $connection->executeStatement(self::RESTORE, [$id])) {
                    throw self::notArchived();
                }

                // After the UPDATE, in its transaction: a refusal above leaves no row.
                $this->audit->recordRestored(
                    tenantId: $identity->tenantId,
                    actorUserId: $identity->userId,
                    actorLabel: $identity->actorLabel,
                    itemId: $id,
                );

                return $this->readInside($connection, $id) ?? throw ApiProblem::notFound();
            },
        ));
    }

    // -----------------------------------------------------------------------

    /** Property name => column, so the UPDATE never restates the field list. */
    private const COLUMNS = [
        'sku' => 'sku',
        'name' => 'name',
        'unitOfMeasureCode' => 'unit_of_measure_code',
        'supplierId' => 'supplier_id',
        'reorderPoint' => 'reorder_point',
        'reorderQuantity' => 'reorder_quantity',
        'safetyStock' => 'safety_stock',
    ];

    /**
     * The database type each field is bound as. Written as `CAST(:p AS t)`
     * rather than `:p::t` because DBAL's parser has to tell a Postgres cast from
     * a named placeholder, and `:supplierId::uuid` is exactly the string where
     * that goes wrong.
     */
    private const CASTS = [
        'sku' => null,
        'name' => null,
        'unitOfMeasureCode' => null,
        'supplierId' => 'uuid',
        'reorderPoint' => 'numeric',
        'reorderQuantity' => 'numeric',
        'safetyStock' => 'numeric',
    ];

    /** Columns declared NOT NULL by the migration; none may be cleared by a PATCH. */
    private const NOT_NULLABLE = ['sku', 'name', 'unitOfMeasureCode', 'reorderPoint', 'reorderQuantity', 'safetyStock'];

    private static function bind(string $property): string
    {
        $type = self::CASTS[$property];

        return null === $type ? ':'.$property : \sprintf('CAST(:%s AS %s)', $property, $type);
    }

    private const POINTERS = [
        'sku' => '/sku',
        'name' => '/name',
        'unitOfMeasureCode' => '/unit_of_measure_code',
        'supplierId' => '/supplier_id',
        'reorderPoint' => '/reorder_point',
        'reorderQuantity' => '/reorder_quantity',
        'safetyStock' => '/safety_stock',
    ];

    private function readInside(Connection $connection, string $id): ?ItemResource
    {
        if (!Uuid::isValid($id)) {
            return null;
        }

        $row = $connection->fetchAssociative(
            'SELECT '.ItemRow::COLUMNS.' '.ItemRow::FROM.' WHERE i.id = ?::uuid',
            [$id],
        );

        return false === $row ? null : ItemRow::toResource($row);
    }

    /**
     * Shape, and only shape. Everything here is decidable without the database,
     * so it runs before a transaction is opened.
     *
     * @param array<string, string|null> $values property name => submitted value
     * @param list<string>               $required
     */
    private function refuseMalformed(array $values, array $required): void
    {
        $errors = [];

        foreach ($required as $property) {
            if (null === ($values[$property] ?? null)) {
                $errors[] = self::error($property, 'required', 'This field is required.');
            }
        }

        foreach (array_keys($values) as $property) {
            if (null === $values[$property]
                && \in_array($property, self::NOT_NULLABLE, true)
                && !\in_array($property, $required, true)
            ) {
                $errors[] = self::error($property, 'not_nullable', 'This field may not be cleared.');
            }
        }

        foreach (['sku', 'name', 'unitOfMeasureCode'] as $property) {
            $value = $values[$property] ?? null;
            if (null !== $value && '' === trim($value)) {
                $errors[] = self::error($property, 'blank', 'This field may not be blank.');
            }
        }

        // `supplier_id` is nullable on the column, so `null` is a legal PATCH
        // value meaning "clear it". A non-uuid string is not.
        if (\array_key_exists('supplierId', $values) && null !== $values['supplierId'] && !Uuid::isValid($values['supplierId'])) {
            $errors[] = self::error('supplierId', 'not_a_uuid', 'This field must be a UUID naming a supplier in this workspace.');
        }

        foreach (['reorderPoint', 'reorderQuantity', 'safetyStock'] as $property) {
            $value = $values[$property] ?? null;
            if (null !== $value && 1 !== preg_match(self::DECIMAL, $value)) {
                $errors[] = self::error($property, 'not_a_decimal', self::NOT_A_DECIMAL);
            }
        }

        if ([] !== $errors) {
            throw ApiProblem::validationFailed($errors);
        }
    }

    /**
     * The two reference checks that need a tenant context to be meaningful.
     *
     * `unit_of_measure` is shared reference data with no `tenant_id`; `supplier`
     * is tenant-scoped, so the lookup below is bounded by RLS and a supplier id
     * belonging to another tenant is indistinguishable from one that never
     * existed. That is ST-5's oracle, and it is the reason this is a
     * `validation-failed` on the *field* rather than a `not-found` on a row the
     * caller never addressed.
     *
     * @param array<string, string|null> $values
     */
    private function refuseUnresolvableReferences(Connection $connection, array $values): void
    {
        $errors = [];

        $code = $values['unitOfMeasureCode'] ?? null;
        if (null !== $code && false === $connection->fetchOne('SELECT 1 FROM unit_of_measure WHERE code = ?', [$code])) {
            $errors[] = self::error('unitOfMeasureCode', 'unknown_reference', 'No unit of measure has this code.');
        }

        $supplierId = $values['supplierId'] ?? null;
        if (null !== $supplierId && false === $connection->fetchOne('SELECT 1 FROM supplier WHERE id = ?::uuid AND deleted_at IS NULL', [$supplierId])) {
            $errors[] = self::error('supplierId', 'unknown_reference', 'No live supplier in this workspace has this id.');
        }

        if ([] !== $errors) {
            throw ApiProblem::validationFailed($errors);
        }
    }

    /** @return array{pointer: string, code: string, message: string} */
    private static function error(string $property, string $code, string $message): array
    {
        return ['pointer' => self::POINTERS[$property], 'code' => $code, 'message' => $message];
    }

    private static function archived(): ApiProblem
    {
        return ApiProblem::businessRuleViolated(
            'inventory_item.is_archived',
            'This item is archived, so it can\'t be changed until it\'s restored.',
        );
    }

    private static function notArchived(): ApiProblem
    {
        return ApiProblem::businessRuleViolated(
            'inventory_item.is_not_archived',
            'This item is not archived, so there is nothing to restore.',
        );
    }

    private static function alreadyArchived(): ApiProblem
    {
        return ApiProblem::businessRuleViolated(
            'inventory_item.is_archived',
            // Since remaining-ops G-I the clause `IA.md` §5.7's D-a items note (s34 D-1 item 6) held back until the `/items`
            // Archived view existed, as the supplier and customer sentences carry.
            'This item is already archived. It\'s in the archived items.',
        );
    }

    /**
     * @template T
     *
     * @param callable(): T $write
     *
     * @return T
     */
    private function translatingConstraintFailures(callable $write): mixed
    {
        try {
            return $write();
        } catch (UniqueConstraintViolationException) {
            // The only uniqueness this table carries on a write path is
            // `inventory_item_tenant_sku_live` -- (tenant_id, sku) among live
            // rows. It fires on a duplicate create, on a rename onto a taken
            // SKU, and on restoring a row whose SKU was reused while it was
            // archived.
            throw ApiProblem::businessRuleViolated(
                'inventory_item.sku_is_unique_per_workspace',
                'Another live item in this workspace already uses this SKU.',
            );
        }
    }
}
