<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Application;

use App\Modules\Inventory\ApiResource\CreateItemInput;
use App\Modules\Inventory\ApiResource\ItemResource;
use App\Modules\Inventory\ApiResource\UpdateItemInput;
use App\Modules\UserManagement\Application\Contract\WorkspaceSettings;
use App\Platform\Http\Problem\ApiProblem;
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

    public function __construct(
        private TenantContext $tenants,
        private CurrentTenant $tenant,
        private ItemReader $reader,
        private WorkspaceSettings $settings,
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

    public function update(string $id, UpdateItemInput $input): ItemResource
    {
        $submitted = $input->submitted();
        $this->refuseMalformed($submitted, required: []);

        return $this->translatingConstraintFailures(fn (): ItemResource => $this->tenants->run(
            $this->tenant->tenantId(),
            function (EntityManagerInterface $em) use ($id, $submitted): ItemResource {
                $connection = $em->getConnection();
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
                    $connection->executeStatement(
                        'UPDATE inventory_item SET '.implode(', ', array_map(
                            static fn (string $property): string => self::COLUMNS[$property].' = '.self::bind($property),
                            array_keys($submitted),
                        )).', updated_at = now() WHERE id = CAST(:id AS uuid)',
                        [...$submitted, 'id' => $id],
                    );
                }

                return $this->readInside($connection, $id) ?? throw ApiProblem::notFound();
            },
        ));
    }

    /** API-21 branch 3 — 204, and no body. */
    public function archive(string $id): void
    {
        $this->tenants->run($this->tenant->tenantId(), function (EntityManagerInterface $em) use ($id): void {
            $connection = $em->getConnection();
            $current = $this->readInside($connection, $id) ?? throw ApiProblem::notFound();

            if (null !== $current->archivedAt) {
                throw self::archived();
            }

            $connection->executeStatement(
                'UPDATE inventory_item SET deleted_at = now(), updated_at = now() WHERE id = ?::uuid',
                [$id],
            );
        });
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
     */
    public function restore(string $id): ItemResource
    {
        return $this->translatingConstraintFailures(fn (): ItemResource => $this->tenants->run(
            $this->tenant->tenantId(),
            function (EntityManagerInterface $em) use ($id): ItemResource {
                $connection = $em->getConnection();
                $current = $this->readInside($connection, $id) ?? throw ApiProblem::notFound();

                if (null === $current->archivedAt) {
                    throw ApiProblem::businessRuleViolated(
                        'inventory_item.is_not_archived',
                        'This item is not archived, so there is nothing to restore.',
                    );
                }

                $connection->executeStatement(
                    'UPDATE inventory_item SET deleted_at = NULL, updated_at = now() WHERE id = ?::uuid',
                    [$id],
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
