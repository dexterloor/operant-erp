<?php

declare(strict_types=1);

namespace App\Modules\Customers\Application;

use App\Modules\AuditLog\Infrastructure\CustomerAuditWriter;
use App\Modules\Customers\Application\Contract\PaymentTerms;
use App\Platform\Http\Problem\ApiProblem;
use App\Platform\Security\RequestIdentity;
use App\Platform\Tenancy\CurrentTenant;
use App\Platform\Tenancy\TenantContext;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * **`CreateCustomer`, `UpdateCustomer` and `RestoreCustomer`** (customers slice 1 C2; `RestoreCustomer` C5 C4a, see
 * {@see self::restore()}; `docs/session13/customers-s1-design.md` §4, final RED
 * M-4's View shape, `customers-s1-g35-judge.md` FINAL item 4). Takes wire-named scalars, returns a {@see CustomerView};
 * never an `ApiResource` class (A-7) and never a `Doctrine\DBAL\Connection` (A-10: an unbound autowired `Connection` is
 * the schema owner).
 *
 * The two writes are raw SQL naming exactly the columns `erp_app`'s column grants cover (`SchemaManifest['customer']`,
 * `Version20261009000300`): the INSERT names `id, tenant_id, code, name, country_code, currency_code` and
 * `payment_terms_days` only when sent (omitted, the column default 30 applies, never a PHP copy); the UPDATE sets only
 * the **changed** columns plus `updated_at`. `deleted_at` is written only by `restore()` (C5 C4a,
 * `Version20261014000200`). A 42501 from any of them is fixed by removing the column from the statement, never by
 * widening the grant.
 *
 * **Refusals, in order** (copy: `customers-s1-ux.md` §2 as amended by final RED L-1):
 *  1. shape, before any transaction: required/null, blank (empty after trimming — the value is stored as sent, no trim,
 *     final RED L-2), country/currency code shape, payment terms not a whole number in 0..{@see PaymentTerms::MAXIMUM_DAYS};
 *  2. (update) the row: absent or foreign → 404; archived → 409 `customer.is_archived` (ST-9; inlined, never a
 *     `refuseUnless…` method, which `EditWindowGuardTest` clause (b) would read as an edit window);
 *  3. country/currency not in the reference tables → 422 `unknown_reference` ("not supported yet": four rows each,
 *     `RESIDUAL_GAPS.md` G-37);
 *  4. a live customer already holding the code → 409 `customer.code_is_unique_per_workspace`, **refused, never
 *     replayed** (`unique:customer_tenant_code_live`; the `Idempotency-Key` header is demanded and unused), translated
 *     from the 23505 **outside** `TenantContext::run()`, because the failed statement aborted the transaction.
 *
 * One row lock, taken by the first read (`FOR NO KEY UPDATE`, the lock the `UPDATE` would take anyway): last writer still
 * wins, and there is no `stale-record`; the lock exists so `customer.updated`'s field list is exact — an unlocked diff
 * could hide a concurrent terms reset (`customers-s1-g35-judge.md` C-3). One audit row per create and per changing
 * update, field names only (`RESIDUAL_GAPS.md` G-35). Both audit calls sit **after** their write statement, inside the
 * same `run()`, so a 23505 (caught outside `run()`) or any 4xx leaves no row. An update that changes nothing issues no
 * `UPDATE` and writes no row; its 200 body is the unchanged row.
 *
 * Every field is editable at any time, `currency_code` included — nothing reads it (design §4.3, owner notice N-2). A
 * rename changes the name shown on issued invoices and pick lists, which join the live row (owner N-1,
 * `RESIDUAL_GAPS.md`); who renamed is recorded (`customer.updated` `["name"]`), the old name is not.
 */
final readonly class CustomerWriter
{
    public const CODE_TAKEN_ON_CREATE = 'Another customer already uses this code. Choose a different code.';

    public const CODE_TAKEN_ON_UPDATE = 'Another customer already uses this code. Keep the current code or choose a different one.';

    public const ARCHIVED = 'This customer is archived, so it can\'t be changed.';

    /** C5 C4a, placeholder (`UX` owes it; `SupplierWriter::NOT_ARCHIVED`'s wording). */
    public const NOT_ARCHIVED = 'This customer is not archived, so there is nothing to restore.';

    /**
     * C5 C4a, placeholder (design §3.4): a live customer took the archived one's code while it was archived. **No name
     * twin:** `customer_tenant_name_live` is a plain index, not a unique (measured C4a T-3) — customer names have no
     * uniqueness rule, so a restore may leave two live customers of one name, as create already can.
     */
    public const CODE_TAKEN_ON_RESTORE = 'A live customer now uses this customer\'s code. Change that customer\'s code, then restore this one.';

    public const TERMS_OUT_OF_RANGE = 'Payment terms must be a whole number of days from 0 to %d.';

    public const COUNTRY_UNSUPPORTED = 'This country isn\'t supported yet.';

    public const CURRENCY_UNSUPPORTED = 'This currency isn\'t supported yet.';

    public const COUNTRY_SHAPE = 'Use the two-letter country code, for example GB.';

    public const CURRENCY_SHAPE = 'Use the three-letter currency code, for example GBP.';

    /** The five accepted fields, in §4.2's order, as wire names. */
    private const FIELDS = ['code', 'name', 'country_code', 'currency_code', 'payment_terms_days'];

    /** Required on create; `payment_terms_days` is optional (the column default applies). */
    private const REQUIRED_ON_CREATE = ['code', 'name', 'country_code', 'currency_code'];

    public function __construct(
        private TenantContext $tenants,
        private CurrentTenant $tenant,
        private RequestIdentity $identities,
        private CustomerAuditWriter $audit,
    ) {
    }

    /** @param array<string, mixed> $values wire name => value, for the fields the body carried */
    public function create(array $values): CustomerView
    {
        self::refuseMalformed($values, self::REQUIRED_ON_CREATE);

        $id = Uuid::v4()->toRfc4122();
        $tenantId = $this->tenant->tenantId();
        $identity = $this->identities->authenticated();

        try {
            return $this->tenants->run($tenantId, function (EntityManagerInterface $em) use ($id, $tenantId, $values, $identity): CustomerView {
                self::refuseUnsupportedReferences($em, $values);

                $columns = ['id', 'tenant_id', 'code', 'name', 'country_code', 'currency_code'];
                $parameters = [$id, $tenantId, $values['code'], $values['name'], $values['country_code'], $values['currency_code']];
                if (\array_key_exists('payment_terms_days', $values)) {
                    $columns[] = 'payment_terms_days';
                    $parameters[] = $values['payment_terms_days'];
                }

                $em->getConnection()->executeStatement(
                    \sprintf('INSERT INTO customer (%s) VALUES (?::uuid, ?::uuid%s)', implode(', ', $columns), str_repeat(', ?', \count($columns) - 2)),
                    $parameters,
                );

                // After the INSERT, in its transaction: a 23505 above leaves no row (G-35).
                $this->audit->recordCreated(
                    tenantId: $identity->tenantId,
                    actorUserId: $identity->userId,
                    actorLabel: $identity->actorLabel,
                    customerId: $id,
                );

                return CustomerReader::readInside($em, $id) ?? throw new \LogicException('The customer just inserted is not readable.');
            });
        } catch (UniqueConstraintViolationException) {
            // Outside `run()`: the failed INSERT aborted the transaction. The table's only unique a write can hit is
            // `customer_tenant_code_live` (the id is minted here).
            throw ApiProblem::businessRuleViolated('customer.code_is_unique_per_workspace', self::CODE_TAKEN_ON_CREATE);
        }
    }

    /** @param array<string, mixed> $submitted wire name => value, exactly the fields the body carried */
    public function update(string $id, array $submitted): CustomerView
    {
        if (!Uuid::isValid($id)) {
            throw ApiProblem::notFound();
        }
        $id = Uuid::fromString($id)->toRfc4122();

        self::refuseMalformed($submitted, []);
        $identity = $this->identities->authenticated();

        try {
            return $this->tenants->run($this->tenant->tenantId(), function (EntityManagerInterface $em) use ($id, $submitted, $identity): CustomerView {
                $connection = $em->getConnection();
                // The row lock (judge C-3): the diff below is taken against a row no concurrent update can move until this
                // transaction ends, so `customer.updated` names exactly what this request changed.
                $row = $connection->fetchAssociative(
                    'SELECT deleted_at IS NOT NULL AS archived, code, name, country_code, currency_code, payment_terms_days FROM customer WHERE id = ?::uuid FOR NO KEY UPDATE',
                    [$id],
                );
                if (false === $row) {
                    throw ApiProblem::notFound();
                }
                // ST-9: an archived row is read-only. Reachable only by SQL until `DeleteCustomer` ships (C5 C4b). The
                // sentence does not say "Restore it" **pending `UX`** (C5 R1.1 M-3): `RestoreCustomer` ships at C5 C4a, so
                // the reason is no longer that restore is blocked, but a caller holding `customer.edit` may lack
                // `customer.delete`, which restoring needs — `UX` rules whether the refusal names the remedy.
                if (true === $row['archived']) {
                    throw ApiProblem::businessRuleViolated('customer.is_archived', self::ARCHIVED);
                }

                self::refuseUnsupportedReferences($em, $submitted);

                $changed = self::changedFields($row, $submitted);
                if ([] !== $changed) {
                    $set = [];
                    $parameters = [];
                    foreach ($changed as $field) {
                        $set[] = $field.' = ?';
                        $parameters[] = $submitted[$field];
                    }
                    $set[] = 'updated_at = now()';
                    $parameters[] = $id;
                    $connection->executeStatement('UPDATE customer SET '.implode(', ', $set).' WHERE id = ?::uuid', $parameters);

                    // After the UPDATE, in its transaction: a 23505 above leaves no row (G-35).
                    $this->audit->recordUpdated(
                        tenantId: $identity->tenantId,
                        actorUserId: $identity->userId,
                        actorLabel: $identity->actorLabel,
                        customerId: $id,
                        fields: $changed,
                    );
                }

                return CustomerReader::readInside($em, $id) ?? throw new \LogicException('The customer just updated is not readable.');
            });
        } catch (UniqueConstraintViolationException) {
            throw ApiProblem::businessRuleViolated('customer.code_is_unique_per_workspace', self::CODE_TAKEN_ON_UPDATE);
        }
    }

    /**
     * **`RestoreCustomer`** (C5 C4a; `docs/session13/c5-design.md` §3.4 / §2.5; `RestoreSupplier`'s shape, API-21 branch 5:
     * 200 with the customer in its new state). In one `TenantContext::run()`:
     *  1. a non-uuid, absent or foreign id → 404 (ST-5);
     *  2. the row read `FOR NO KEY UPDATE` — the update lock is enough: a restore must not wait on Sales' `FOR KEY SHARE`
     *     readers (C5 C3), and it conflicts with `DeleteCustomer`'s `FOR UPDATE` (C4b) and with `UpdateCustomer`'s lock;
     *  3. live → 409 `customer.is_not_archived` — the §4.2 `guard:customer.deleted_at` mechanism: a replayed restore is
     *     told the first one committed (no `Idempotency-Key` demanded, `RestoreItem`'s reason);
     *  4. `UPDATE customer SET deleted_at = NULL, updated_at = now()` — the column grant `Version20261014000200` adds;
     *  5. `customer.restored`, `{}`, after the UPDATE, in its transaction;
     *  6. the row read back.
     *
     * **The restore collision** (design §3.4): `customer_tenant_code_live` is partial on `deleted_at IS NULL`, so a live
     * customer may have taken the archived one's code while it was archived. The 23505 is translated **outside** `run()`
     * to the existing rule code with a restore sentence; the failed UPDATE aborted the transaction, so no audit row is
     * written. It is the table's only unique a restore can hit (the name index is not unique, measured C4a T-3), the same
     * reasoning as `create()`'s catch. **No open-work check**: restoring creates no work. Children (`customer_address`,
     * `customer_contact`, `hard`) were never touched by archiving, so nothing is resurrected.
     */
    public function restore(string $id): CustomerView
    {
        if (!Uuid::isValid($id)) {
            throw ApiProblem::notFound();
        }
        $id = Uuid::fromString($id)->toRfc4122();
        $identity = $this->identities->authenticated();

        try {
            return $this->tenants->run($this->tenant->tenantId(), function (EntityManagerInterface $em) use ($id, $identity): CustomerView {
                $connection = $em->getConnection();
                // fetchAssociative, never fetchOne: a live row's `false` would read as "no row" (C2a deviation 5).
                $row = $connection->fetchAssociative('SELECT deleted_at IS NOT NULL AS archived FROM customer WHERE id = ?::uuid FOR NO KEY UPDATE', [$id]);
                if (false === $row) {
                    throw ApiProblem::notFound();
                }
                if (true !== $row['archived']) {
                    throw ApiProblem::businessRuleViolated('customer.is_not_archived', self::NOT_ARCHIVED);
                }

                $connection->executeStatement('UPDATE customer SET deleted_at = NULL, updated_at = now() WHERE id = ?::uuid', [$id]);

                // After the UPDATE, in its transaction: a 23505 above leaves no row.
                $this->audit->recordRestored(
                    tenantId: $identity->tenantId,
                    actorUserId: $identity->userId,
                    actorLabel: $identity->actorLabel,
                    customerId: $id,
                );

                return CustomerReader::readInside($em, $id) ?? throw new \LogicException('The customer just restored is not readable.');
            });
        } catch (UniqueConstraintViolationException) {
            // Outside `run()`: the failed UPDATE aborted the transaction. `customer_tenant_code_live` is the only unique.
            throw ApiProblem::businessRuleViolated('customer.code_is_unique_per_workspace', self::CODE_TAKEN_ON_RESTORE);
        }
    }

    /**
     * The wire names whose submitted value differs from the locked row, sorted ascending (judge FINAL item 2):
     * `payment_terms_days` compared as an integer, the others as exact strings — no trim, no case folding (the
     * stored-as-sent rule, final RED L-2).
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
                    if (!\is_int($value) || $value < PaymentTerms::MINIMUM_DAYS || $value > PaymentTerms::MAXIMUM_DAYS) {
                        $errors[] = self::error($field, 'out_of_range', \sprintf(self::TERMS_OUT_OF_RANGE, PaymentTerms::MAXIMUM_DAYS));
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
