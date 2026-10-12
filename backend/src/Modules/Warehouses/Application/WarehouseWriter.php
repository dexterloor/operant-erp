<?php

declare(strict_types=1);

namespace App\Modules\Warehouses\Application;

use App\Modules\AuditLog\Infrastructure\WarehouseAuditWriter;
use App\Platform\Http\Problem\ApiProblem;
use App\Platform\Security\RequestIdentity;
use App\Platform\Tenancy\CurrentTenant;
use App\Platform\Tenancy\TenantContext;
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
 * neither (Q-W4, `RESIDUAL_GAPS.md` G-36). A 42501 from either is fixed by removing the column from the statement, never by
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

    public const ARCHIVED = 'This warehouse is archived, so it can\'t be changed.';

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
    ) {
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
     * ST-9: an archived warehouse is read-only. Reachable only by SQL: `DeleteWarehouse` is out of this slice (API-O-34,
     * G-7). No "Restore it": `RestoreWarehouse` is not built.
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
