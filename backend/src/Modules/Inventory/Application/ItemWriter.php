<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Application;

use App\Modules\AuditLog\Infrastructure\ItemAuditWriter;
use App\Modules\Inventory\ApiResource\CreateItemInput;
use App\Modules\Inventory\ApiResource\ItemResource;
use App\Modules\Inventory\ApiResource\UpdateItemInput;
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
    /** numeric(19,6) — precision 19, scale 6, so at most 13 digits before the point. */
    private const DECIMAL = '/^-?\d{1,13}(\.\d{1,6})?$/';

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
     * The bulk-only stock read (G5-C2, {@see self::archiveUnlessStocked()}): any non-zero on-hand in any location, any kind,
     * quarantine included — the same "any stock" `storage_location.holds_stock` means. A read of `stock_level`, never a
     * write, so TB-6's *"anything touching stock_level"* (writes) is not crossed (`UX` to confirm the reading).
     */
    public const STOCKED = 'SELECT EXISTS (SELECT 1 FROM stock_level WHERE inventory_item_id = ?::uuid AND qty_on_hand <> 0)';

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
     */
    public function update(string $id, UpdateItemInput $input): ItemResource
    {
        $submitted = $input->submitted();
        $this->refuseMalformed($submitted, required: []);

        return $this->translatingConstraintFailures(fn (): ItemResource => $this->tenants->run(
            $this->tenant->tenantId(),
            function (EntityManagerInterface $em) use ($id, $submitted): ItemResource {
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

                return $this->readInside($connection, $id) ?? throw ApiProblem::notFound();
            },
        ));
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
                $errors[] = self::error($property, 'not_a_decimal', 'This field must be a decimal string with at most 13 integer digits and 6 fractional digits.');
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
