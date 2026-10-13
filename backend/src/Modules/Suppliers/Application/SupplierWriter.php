<?php

declare(strict_types=1);

namespace App\Modules\Suppliers\Application;

use App\Modules\AuditLog\Infrastructure\SupplierAuditWriter;
use App\Modules\Suppliers\Application\Contract\SupplierPaymentTerms;
use App\Platform\Http\Problem\ApiProblem;
use App\Platform\Security\RequestIdentity;
use App\Platform\Tenancy\CurrentTenant;
use App\Platform\Tenancy\TenantContext;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * **`CreateSupplier`, `UpdateSupplier` and `RestoreSupplier`** (suppliers slice 1 C2; `RestoreSupplier` C5 C2a, see
 * {@see self::restore()}; `docs/session13/suppliers-s1-design.md` FINAL F-3 and
 * §4; `CustomerWriter`'s shape, file for file, for its reasons). Takes wire-named scalars, returns a {@see SupplierView};
 * never an `ApiResource` class (A-7) and never a `Doctrine\DBAL\Connection` (A-10: an unbound autowired `Connection` is
 * the schema owner).
 *
 * The two writes are raw SQL naming exactly the columns `erp_app`'s column grants cover (`SchemaManifest['supplier']`,
 * `Version20261012000200`): the INSERT names `id, tenant_id, code, name, country_code, currency_code` and
 * `payment_terms_days` only when sent (omitted, the column default 30 applies, never a PHP copy); the UPDATE sets only
 * the **changed** columns plus `updated_at`. `email`, `phone` and `is_active` are in neither (owner Q-S6,
 * `RESIDUAL_GAPS.md` G-36); `deleted_at` is written only by `restore()` (C5 C2a, `Version20261013000500`). A 42501 from either is fixed by removing the column from the statement, never by widening the
 * grant.
 *
 * **Refusals, in order** (placeholder copy on the customer sentences; `UX` owes the supplier copy):
 *  1. shape, before any transaction: required/null, blank (empty after trimming — the value is stored as sent, no trim),
 *     country/currency code shape, payment terms not a whole number in 0..{@see SupplierPaymentTerms::MAXIMUM_DAYS};
 *  2. (update) the row: absent or foreign → 404; archived → 409 `supplier.is_archived` ({@see self::refuseIfArchived()},
 *     never a `refuseUnless…` name, which `EditWindowGuardTest` clause (b) would read as an edit window);
 *  3. country/currency not in the reference tables → 422 `unknown_reference` (the house's one sentence per code, held
 *     equal to `CustomerWriter`'s by `SupplierWriteTest`; `RESIDUAL_GAPS.md` G-37);
 *  4. (update) `currency_code` CHANGED while the supplier has catalogue entries → 409
 *     `supplier.currency_is_fixed_while_terms_exist` (C3, owner Q-S4 (ii); counted under the row lock, final RED M-B);
 *  5. **two live uniques, each refused, never replayed** (and, on `restore()`, each refused with a restore sentence), translated **by constraint name** from the 23505 **outside**
 *     `TenantContext::run()` (the failed statement aborted the transaction): `supplier_tenant_code_live` → 409
 *     `supplier.code_is_unique_per_workspace` (the §4.2 `Idem` mechanism; the `Idempotency-Key` header is demanded and
 *     unused) and `supplier_tenant_name_live` → 409 `supplier.name_is_unique_per_workspace`. **The name unique compares
 *     the name as stored** (case-sensitive; LEAD 2026-10-12, owner notice): `ACME` beside `Acme` is accepted, so the
 *     refusal copy says "this name", never "a name like this". Any other 23505 is not ours and is rethrown.
 *
 * One row lock, taken by the first read of every update (`FOR NO KEY UPDATE`, the lock the `UPDATE` would take anyway):
 * last writer still wins, and there is no `stale-record`; the lock exists so `supplier.updated`'s field list is exact —
 * an unlocked diff could hide a concurrent terms reset (`customers-s1-g35-judge.md` C-3). It is also the lock final RED
 * M-B asks `UpdateSupplier` to hold whenever `currency_code` is submitted: `CreateSupplierProduct`'s parent read is
 * `FOR SHARE` (C3), which this lock conflicts with, and the currency-change refusal counts entries after taking it.
 * One audit row per create and per changing update, field names only. Both audit calls sit **after** their write statement, inside the same `run()`, so a 23505 (caught outside
 * `run()`) or any 4xx leaves no row. An update that changes nothing issues no `UPDATE` and writes no row.
 *
 * Every field is editable at any time **except `currency_code` while the supplier has catalogue entries** (suppliers slice 1
 * C3, owner Q-S4 (ii)): 409 `supplier.currency_is_fixed_while_terms_exist`, because each entry's `currency_code` was copied
 * from the supplier (Q-S4 (a)) and a relabelled number is not a converted one. Refused only when the value CHANGES. A rename
 * changes the name shown on issued purchase orders, which join the live row (`RESIDUAL_GAPS.md` G-40, G-38's twin); who
 * renamed is recorded (`supplier.updated` `["name"]`), the old name is not.
 */
final readonly class SupplierWriter
{
    public const CODE_TAKEN_ON_CREATE = 'Another supplier already uses this code. Choose a different code.';

    public const CODE_TAKEN_ON_UPDATE = 'Another supplier already uses this code. Keep the current code or choose a different one.';

    public const NAME_TAKEN_ON_CREATE = 'Another supplier already uses this name. Choose a different name.';

    public const NAME_TAKEN_ON_UPDATE = 'Another supplier already uses this name. Keep the current name or choose a different one.';

    public const ARCHIVED = 'This supplier is archived, so it can\'t be changed.';

    /** C5 C2a, placeholder (`UX` owes it; `ItemWriter::restore()`'s wording). */
    public const NOT_ARCHIVED = 'This supplier is not archived, so there is nothing to restore.';

    /** C5 C2a, placeholder (design §2.5): a live supplier took the archived one's code while it was archived. */
    public const CODE_TAKEN_ON_RESTORE = 'A live supplier now uses this supplier\'s code. Change that supplier\'s code, then restore this one.';

    /** C5 C2a, placeholder: a live supplier took the archived one's name while it was archived. */
    public const NAME_TAKEN_ON_RESTORE = 'A live supplier now uses this supplier\'s name. Rename that supplier, then restore this one.';

    public const CURRENCY_FIXED = 'This supplier\'s catalogue is priced in its current currency. Remove its catalogue entries before changing the currency.';

    public const TERMS_OUT_OF_RANGE = 'Payment terms must be a whole number of days from 0 to %d.';

    public const COUNTRY_UNSUPPORTED = 'This country isn\'t supported yet.';

    public const CURRENCY_UNSUPPORTED = 'This currency isn\'t supported yet.';

    public const COUNTRY_SHAPE = 'Use the two-letter country code, for example GB.';

    public const CURRENCY_SHAPE = 'Use the three-letter currency code, for example GBP.';

    private const CODE_UNIQUE = 'supplier_tenant_code_live';

    private const NAME_UNIQUE = 'supplier_tenant_name_live';

    /** The five accepted fields, in §4.2's order, as wire names. */
    private const FIELDS = ['code', 'name', 'country_code', 'currency_code', 'payment_terms_days'];

    /** Required on create; `payment_terms_days` is optional (the column default applies). */
    private const REQUIRED_ON_CREATE = ['code', 'name', 'country_code', 'currency_code'];

    public function __construct(
        private TenantContext $tenants,
        private CurrentTenant $tenant,
        private RequestIdentity $identities,
        private SupplierAuditWriter $audit,
    ) {
    }

    /** @param array<string, mixed> $values wire name => value, for the fields the body carried */
    public function create(array $values): SupplierView
    {
        self::refuseMalformed($values, self::REQUIRED_ON_CREATE);

        $id = Uuid::v4()->toRfc4122();
        $tenantId = $this->tenant->tenantId();
        $identity = $this->identities->authenticated();

        try {
            return $this->tenants->run($tenantId, function (EntityManagerInterface $em) use ($id, $tenantId, $values, $identity): SupplierView {
                self::refuseUnsupportedReferences($em, $values);

                $columns = ['id', 'tenant_id', 'code', 'name', 'country_code', 'currency_code'];
                $parameters = [$id, $tenantId, $values['code'], $values['name'], $values['country_code'], $values['currency_code']];
                if (\array_key_exists('payment_terms_days', $values)) {
                    $columns[] = 'payment_terms_days';
                    $parameters[] = $values['payment_terms_days'];
                }

                $em->getConnection()->executeStatement(
                    \sprintf('INSERT INTO supplier (%s) VALUES (?::uuid, ?::uuid%s)', implode(', ', $columns), str_repeat(', ?', \count($columns) - 2)),
                    $parameters,
                );

                // After the INSERT, in its transaction: a 23505 above leaves no row.
                $this->audit->recordCreated(
                    tenantId: $identity->tenantId,
                    actorUserId: $identity->userId,
                    actorLabel: $identity->actorLabel,
                    supplierId: $id,
                );

                return SupplierReader::readInside($em, $id) ?? throw new \LogicException('The supplier just inserted is not readable.');
            });
        } catch (UniqueConstraintViolationException $e) {
            // Outside `run()`: the failed INSERT aborted the transaction. The id is minted here, so only the two live
            // uniques can fire; each is answered by its own rule.
            throw self::translated($e, self::CODE_TAKEN_ON_CREATE, self::NAME_TAKEN_ON_CREATE);
        }
    }

    /** @param array<string, mixed> $submitted wire name => value, exactly the fields the body carried */
    public function update(string $id, array $submitted): SupplierView
    {
        if (!Uuid::isValid($id)) {
            throw ApiProblem::notFound();
        }
        $id = Uuid::fromString($id)->toRfc4122();

        self::refuseMalformed($submitted, []);
        $identity = $this->identities->authenticated();

        try {
            return $this->tenants->run($this->tenant->tenantId(), function (EntityManagerInterface $em) use ($id, $submitted, $identity): SupplierView {
                $connection = $em->getConnection();
                // The row lock: the diff below is taken against a row no concurrent update can move until this transaction
                // ends, so `supplier.updated` names exactly what this request changed.
                $row = $connection->fetchAssociative(
                    'SELECT deleted_at IS NOT NULL AS archived, code, name, country_code, currency_code, payment_terms_days FROM supplier WHERE id = ?::uuid FOR NO KEY UPDATE',
                    [$id],
                );
                if (false === $row) {
                    throw ApiProblem::notFound();
                }
                self::refuseIfArchived($row);

                self::refuseUnsupportedReferences($em, $submitted);

                $changed = self::changedFields($row, $submitted);
                // Q-S4 (ii) (owner, 2026-10-12): the currency is fixed while the supplier has catalogue entries, whose
                // `currency_code` was copied from it. Counted AFTER the row lock above, so a concurrent
                // `CreateSupplierProduct` (whose parent read is `FOR SHARE`, `SupplierProductWriter::PARENT_LOCK`) has either
                // committed its entry — and this statement, under READ COMMITTED, sees it — or is waiting on this lock and
                // will copy the new currency (final RED M-B). An unchanged currency is not a change, so it is not refused.
                if (\in_array('currency_code', $changed, true)
                    && false !== $connection->fetchOne('SELECT 1 FROM supplier_product WHERE supplier_id = ?::uuid LIMIT 1', [$id])) {
                    throw ApiProblem::businessRuleViolated('supplier.currency_is_fixed_while_terms_exist', self::CURRENCY_FIXED);
                }
                if ([] !== $changed) {
                    $set = [];
                    $parameters = [];
                    foreach ($changed as $field) {
                        $set[] = $field.' = ?';
                        $parameters[] = $submitted[$field];
                    }
                    $set[] = 'updated_at = now()';
                    $parameters[] = $id;
                    $connection->executeStatement('UPDATE supplier SET '.implode(', ', $set).' WHERE id = ?::uuid', $parameters);

                    // After the UPDATE, in its transaction: a 23505 above leaves no row.
                    $this->audit->recordUpdated(
                        tenantId: $identity->tenantId,
                        actorUserId: $identity->userId,
                        actorLabel: $identity->actorLabel,
                        supplierId: $id,
                        fields: $changed,
                    );
                }

                return SupplierReader::readInside($em, $id) ?? throw new \LogicException('The supplier just updated is not readable.');
            });
        } catch (UniqueConstraintViolationException $e) {
            throw self::translated($e, self::CODE_TAKEN_ON_UPDATE, self::NAME_TAKEN_ON_UPDATE);
        }
    }

    /**
     * ST-9: an archived supplier is read-only. Reachable only by SQL until `DeleteSupplier` ships (C5 C2b). The sentence
     * does not say "Restore it" **pending `UX`** (C5 R1.1 M-3): `RestoreSupplier` ships at C5 C2a, so the reason is no
     * longer that restore is blocked, but a caller holding `supplier.edit` may lack `supplier.delete`, which restoring
     * needs — `UX` rules whether the refusal names the remedy.
     *
     * @param array<string, mixed> $row
     */
    private static function refuseIfArchived(array $row): void
    {
        if (true === $row['archived']) {
            throw ApiProblem::businessRuleViolated('supplier.is_archived', self::ARCHIVED);
        }
    }

    /**
     * **`RestoreSupplier`** (C5 C2a; `docs/session13/c5-design.md` §2.5; `RestoreItem`'s shape, API-21 branch 5: 200 with the
     * supplier in its new state). In one `TenantContext::run()`:
     *  1. a non-uuid, absent or foreign id → 404 (ST-5);
     *  2. the row read `FOR NO KEY UPDATE` — the update lock is enough: a restore must not wait on Purchasing's
     *     `FOR KEY SHARE` readers, and it conflicts with `DeleteSupplier`'s `FOR UPDATE` and with `UpdateSupplier`'s lock;
     *  3. live → 409 `supplier.is_not_archived` — the §4.2 `guard:supplier.deleted_at` mechanism: a replayed restore is
     *     told the first one committed (no `Idempotency-Key` demanded, `RestoreItem`'s reason);
     *  4. `UPDATE supplier SET deleted_at = NULL, updated_at = now()` — the column grant `Version20261013000500` adds;
     *  5. `supplier.restored`, `{}`, after the UPDATE, in its transaction;
     *  6. the row read back.
     *
     * **The restore collision** (design §2.5): `supplier_tenant_code_live` and `supplier_tenant_name_live` are partial on
     * `deleted_at IS NULL`, so a live supplier may have taken the archived one's code or name while it was archived. The
     * 23505 is translated **outside** `run()` by constraint name to the existing rule codes with restore sentences; the
     * failed UPDATE aborted the transaction, so no audit row is written. **No open-work check**: restoring creates no
     * work. Children (`supplier_product`, `hard`) were never touched by archiving, so nothing is resurrected.
     */
    public function restore(string $id): SupplierView
    {
        if (!Uuid::isValid($id)) {
            throw ApiProblem::notFound();
        }
        $id = Uuid::fromString($id)->toRfc4122();
        $identity = $this->identities->authenticated();

        try {
            return $this->tenants->run($this->tenant->tenantId(), function (EntityManagerInterface $em) use ($id, $identity): SupplierView {
                $connection = $em->getConnection();
                // fetchAssociative, never fetchOne: a live row's  would read as "no row".
                $row = $connection->fetchAssociative('SELECT deleted_at IS NOT NULL AS archived FROM supplier WHERE id = ?::uuid FOR NO KEY UPDATE', [$id]);
                if (false === $row) {
                    throw ApiProblem::notFound();
                }
                if (true !== $row['archived']) {
                    throw ApiProblem::businessRuleViolated('supplier.is_not_archived', self::NOT_ARCHIVED);
                }

                $connection->executeStatement('UPDATE supplier SET deleted_at = NULL, updated_at = now() WHERE id = ?::uuid', [$id]);

                // After the UPDATE, in its transaction: a 23505 above leaves no row.
                $this->audit->recordRestored(
                    tenantId: $identity->tenantId,
                    actorUserId: $identity->userId,
                    actorLabel: $identity->actorLabel,
                    supplierId: $id,
                );

                return SupplierReader::readInside($em, $id) ?? throw new \LogicException('The supplier just restored is not readable.');
            });
        } catch (UniqueConstraintViolationException $e) {
            throw self::translated($e, self::CODE_TAKEN_ON_RESTORE, self::NAME_TAKEN_ON_RESTORE);
        }
    }

    /**
     * Which live unique the 23505 names, read out of the driver's message (`duplicate key value violates unique constraint
     * "<name>"`) — DBAL carries no structured constraint name (`PurchaseOrderWriter::violated()`'s reason). The name is
     * matched **quoted**, so neither index name can match inside the other. A 23505 naming neither is not ours: rethrown.
     */
    private static function translated(UniqueConstraintViolationException $e, string $codeTaken, string $nameTaken): \Throwable
    {
        if (str_contains($e->getMessage(), '"'.self::CODE_UNIQUE.'"')) {
            return ApiProblem::businessRuleViolated('supplier.code_is_unique_per_workspace', $codeTaken);
        }
        if (str_contains($e->getMessage(), '"'.self::NAME_UNIQUE.'"')) {
            return ApiProblem::businessRuleViolated('supplier.name_is_unique_per_workspace', $nameTaken);
        }

        return $e;
    }

    /**
     * The wire names whose submitted value differs from the locked row, sorted ascending: `payment_terms_days` compared
     * as an integer, the others as exact strings — no trim, no case folding (stored as sent).
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
            if (!\array_key_exists($field, $submitted)) {
                continue;
            }
            $differs = 'payment_terms_days' === $field
                ? (int) $row[$field] !== (int) $submitted[$field]
                : (string) $row[$field] !== $submitted[$field];
            if ($differs) {
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

            switch ($field) {
                case 'code':
                case 'name':
                    if (!\is_string($value)) {
                        $errors[] = self::error($field, 'not_a_string', 'This field must be a string.');
                    } elseif ('' === trim($value)) {
                        $errors[] = self::error($field, 'blank', 'This field may not be blank.');
                    }
                    break;
                case 'country_code':
                    if (!\is_string($value) || 1 !== preg_match('/^[A-Z]{2}$/', $value)) {
                        $errors[] = self::error($field, 'not_a_country_code', self::COUNTRY_SHAPE);
                    }
                    break;
                case 'currency_code':
                    if (!\is_string($value) || 1 !== preg_match('/^[A-Z]{3}$/', $value)) {
                        $errors[] = self::error($field, 'not_a_currency_code', self::CURRENCY_SHAPE);
                    }
                    break;
                case 'payment_terms_days':
                    if (!\is_int($value) || $value < SupplierPaymentTerms::MINIMUM_DAYS || $value > SupplierPaymentTerms::MAXIMUM_DAYS) {
                        $errors[] = self::error($field, 'out_of_range', \sprintf(self::TERMS_OUT_OF_RANGE, SupplierPaymentTerms::MAXIMUM_DAYS));
                    }
                    break;
            }
        }

        if ([] !== $errors) {
            throw ApiProblem::validationFailed($errors);
        }
    }

    /**
     * `country` and `currency` are **reference data** (ADR-002 §6), not tenant-scoped, so the lookups are existence
     * checks and disclose nothing. Each holds four rows today (`RESIDUAL_GAPS.md` G-37), hence "not supported yet".
     *
     * @param array<string, mixed> $values
     */
    private static function refuseUnsupportedReferences(EntityManagerInterface $em, array $values): void
    {
        $connection = $em->getConnection();
        $errors = [];

        if (isset($values['country_code']) && false === $connection->fetchOne('SELECT 1 FROM country WHERE code = ?', [$values['country_code']])) {
            $errors[] = self::error('country_code', 'unknown_reference', self::COUNTRY_UNSUPPORTED);
        }
        if (isset($values['currency_code']) && false === $connection->fetchOne('SELECT 1 FROM currency WHERE code = ?', [$values['currency_code']])) {
            $errors[] = self::error('currency_code', 'unknown_reference', self::CURRENCY_UNSUPPORTED);
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
