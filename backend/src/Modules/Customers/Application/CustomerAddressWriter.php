<?php

declare(strict_types=1);

namespace App\Modules\Customers\Application;

use App\Modules\AuditLog\Infrastructure\CustomerAddressAuditWriter;
use App\Platform\Http\Idempotency\IdempotencyKeyHash;
use App\Platform\Http\Idempotency\RequestDigest;
use App\Platform\Http\Problem\ApiProblem;
use App\Platform\Security\RequestIdentity;
use App\Platform\Tenancy\CurrentTenant;
use App\Platform\Tenancy\TenantContext;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * **`CreateCustomerAddress` and `UpdateCustomerAddress`** (customers slice 1 C3; `docs/session13/customers-s1-design.md`
 * §5 and its FINAL C3 block, `customers-s1-final-red.md` L-4, M-4's View shape). Takes wire-named scalars, returns a
 * {@see CustomerAddressView}; never an `ApiResource` class (A-7) and never a `Doctrine\DBAL\Connection` (A-10).
 *
 * The two writes are raw SQL naming exactly the columns `erp_app`'s column grants cover (`SchemaManifest['customer_address']`,
 * `Version20261009000500`): the INSERT names `id, tenant_id, customer_id, kind, address_line, city, postal_code,
 * country_code, idempotency_key, request_digest` (`region` omitted: its default `''` applies — it is neither accepted nor
 * served, `sales_order` has no column for it); the UPDATE sets only submitted columns plus `updated_at`. **No statement
 * touches `sales_order`:** an order holds a snapshot of the address (`ship_to_*`, copied by
 * `SalesOrderWriter::shipToInside()`), so correcting an address moves no order, draft or confirmed (design §5.2; owner
 * ruling O-3).
 *
 * **Create, in order** (design §5.1 as revised per RED L-1 — the purchase-order-line order):
 *  1. shape, before any transaction (all five fields required; blank after trimming → `blank`, stored as sent otherwise —
 *     no trim, final RED L-2; `kind` ∈ {billing, shipping}; country code shape);
 *  2. the parent, read {@see self::PARENT_KEY_SHARE} (C5 C4b): absent or another workspace's → 404. **An archived
 *     customer is found here** (final RED L-4 (a)), so its refusal is step 4's 409, not a 404;
 *  3. **the key** (`unique:customer_address_idem_unique`, header-sourced, NOT NULL, TOTAL — the table is `hard`): the same
 *     key with the same digest replays the stored row (201), with another digest → 409 `idempotency-key-conflict`. The
 *     digest covers the **path's customer id** plus the shape-checked values (`SalesOrderWriter::lineDigest()`'s rule,
 *     L-4 (b)), so the same key sent to another customer is a conflict, never a replay of that customer's address. The
 *     lookup runs under RLS after the 404, so a key cannot be probed across workspaces. **It runs before the archived
 *     guard on purpose:** a retry of a create that succeeded is handed its row even if the customer was archived in
 *     between (`PurchaseOrderLineIdempotencyTest::aLineCreateRetryAfterApprovalReturnsTheLine`'s exemption);
 *  4. archived customer → 409 `customer.is_archived` (ST-9: an archived row is read-only, children included). Inlined,
 *     never a `refuseUnless…` method (`EditWindowGuardTest` clause (b) would read one as an edit window, RED L-3);
 *  5. country not in the reference table → 422 `unknown_reference` (four rows, `RESIDUAL_GAPS.md` G-37);
 *  6. the INSERT, `ON CONFLICT (tenant_id, idempotency_key) DO NOTHING` — exactly `customer_address_idem_unique`'s columns
 *     (a total, non-partial unique). Two requests with one key can both pass step 3 (the parent's `FOR KEY SHARE` does not
 *     serialise them); the second waits at the unique until the first commits, then inserts nothing. Its 0 rows are
 *     answered by step 3's key read again, in the SAME transaction (READ COMMITTED: the next statement sees the winner):
 *     the same digest replays the winner's row (201), any other is the 409 conflict. No audit row on that branch. The id
 *     is minted here, so the only 23505 left is a minted-UUID collision, which is a 500.
 *     *(2026-10-17, shipped-defect fix: until then the INSERT was plain, its 23505 was caught OUTSIDE `run()` and the key
 *     re-read in a SECOND `run()`. The first `run()` had closed the EntityManager on the way out, so that re-read died at
 *     its `flush()` — an identical retry that lost the race got HTTP 500 "The EntityManager is closed." instead of the
 *     replay; the conflict branch was right only because its 409 escaped before the `flush()`. Found by the stock count
 *     build's D-1 measurement; `docs/session13/reread-defect-progress.md`. `NoRunAfterAFailedRunTest` now refuses the shape.)*
 *
 * **Update:** the parent, read {@see self::PARENT_KEY_SHARE} (C5 C4b), then the pair `(customer, address)`, the address read `FOR NO KEY UPDATE` (404 for an absent parent, a foreign
 * one, or another customer's address — ST-5) → archived customer 409 → shape → country support → the **changed** fields
 * only: `UPDATE … SET <changed>, updated_at WHERE id = ? AND customer_id = ?`. An update that changes nothing — an empty
 * PATCH, or every submitted value equal to the stored one — issues no statement, writes no audit row and answers the row
 * (suppliers slice 1 C2b: until then a same-values PATCH moved `updated_at`). `null` on any field is refused (all five
 * are NOT NULL).
 *
 * **The trail** (suppliers slice 1 C2b, 2026-10-12, owner ruling F-12 / S4 "Record address changes",
 * `docs/session13/suppliers-s1-ruling.md`): one `customer_address.created` (`{}`) per INSERT — never on a replay, which
 * inserts nothing — and one `customer_address.updated` (`{"fields": [...]}`, wire names, never values: an address is
 * personal data) per CHANGING update, each written by {@see CustomerAddressAuditWriter} after its statement inside the
 * same transaction, so a refused or failed write leaves no row. The diff is taken under the row lock, the `UpdateCustomer`
 * precedent (`customers-s1-g35-judge.md` C-3): a concurrent update holding the row makes this one wait and diff against
 * what it committed, so whatever this request changes back is named. *(Until C2b this said "No audit row" — customers C3
 * deviation D-1 — and that was the state the owner ruled on.)*
 */
final readonly class CustomerAddressWriter
{
    public const CREATE_OPERATION = 'CreateCustomerAddress';

    /**
     * The parent read both address writes take (C5 C4b; `docs/session13/c5-design.md` §4.1, closing customers C3
     * deviation D-4). `FOR KEY SHARE` conflicts with `DeleteCustomer`'s `FOR UPDATE` ({@see CustomerWriter::DELETE_LOCK}),
     * so an address cannot be added to, or changed on, a customer that is being archived: it waits, re-reads the
     * committed row and is refused `customer.is_archived`. It does NOT conflict with `UpdateCustomer`'s / `RestoreCustomer`'s
     * `FOR NO KEY UPDATE`, so an address write and a customer edit still run side by side. Lock order: customer (KEY
     * SHARE) → address row (`FOR NO KEY UPDATE`, update only); `DeleteCustomer` takes no address lock, so no cycle.
     * Public so the race tests name this statement (mutation: drop the lock → `CustomerDeleteTest`'s address races red).
     */
    public const PARENT_KEY_SHARE = 'SELECT deleted_at IS NOT NULL AS archived FROM customer WHERE id = ?::uuid FOR KEY SHARE';

    public const ARCHIVED_ON_CREATE = 'This customer is archived, so no address can be added to it until it\'s restored.';

    public const ARCHIVED_ON_UPDATE = 'This customer is archived, so its addresses can\'t be changed until it\'s restored.';

    public const KIND_UNKNOWN = 'An address is either a shipping address or a billing address.';

    /** The five accepted fields, in §4.2's order, as wire names, and the column each is stored in. */
    private const COLUMNS = [
        'kind' => 'kind',
        'line' => 'address_line',
        'city' => 'city',
        'postal_code' => 'postal_code',
        'country_code' => 'country_code',
    ];

    private const KINDS = ['billing', 'shipping'];

    public function __construct(
        private TenantContext $tenants,
        private CurrentTenant $tenant,
        private RequestIdentity $identities,
        private CustomerAddressAuditWriter $audit,
    ) {
    }

    /** @param array<string, mixed> $values wire name => value, for the fields the body carried */
    public function create(string $customerId, array $values, string $idempotencyKey): CustomerAddressView
    {
        self::refuseMalformed($values, array_keys(self::COLUMNS));

        if (!Uuid::isValid($customerId)) {
            throw ApiProblem::notFound();
        }
        $customerId = Uuid::fromString($customerId)->toRfc4122();

        // D-B: the column holds the header's hash, never its value.
        $keyHash = IdempotencyKeyHash::of($idempotencyKey);
        $digest = self::digest($customerId, $values);
        $tenantId = $this->tenant->tenantId();
        $identity = $this->identities->authenticated();

        return $this->tenants->run($tenantId, function (EntityManagerInterface $em) use ($customerId, $values, $keyHash, $digest, $tenantId, $identity): CustomerAddressView {
            $archived = self::parentArchivedInside($em, $customerId) ?? throw ApiProblem::notFound();

            $replay = self::createdByKeyInside($em, $customerId, $keyHash, $digest);
            if (null !== $replay) {
                return $replay;
            }

            // ST-9, after the key on purpose (see the class docblock, step 3).
            if ($archived) {
                throw ApiProblem::businessRuleViolated('customer.is_archived', self::ARCHIVED_ON_CREATE);
            }

            self::refuseUnsupportedCountry($em, $values);

            // Step 6: the conflict target is `customer_address_idem_unique`'s columns exactly. A same-key request that
            // committed while this one waited at the unique makes this insert nothing — no 23505, so no exception leaves
            // `run()` and the manager stays open (the class docblock's 2026-10-17 marker).
            $id = Uuid::v4()->toRfc4122();
            $inserted = $em->getConnection()->executeStatement(
                'INSERT INTO customer_address (id, tenant_id, customer_id, kind, address_line, city, postal_code, country_code, idempotency_key, request_digest)'
                .' VALUES (?::uuid, ?::uuid, ?::uuid, ?, ?, ?, ?, ?, ?, ?)'
                .' ON CONFLICT (tenant_id, idempotency_key) DO NOTHING',
                [$id, $tenantId, $customerId, $values['kind'], $values['line'], $values['city'], $values['postal_code'], $values['country_code'], $keyHash, $digest],
            );
            if (1 !== $inserted) {
                // The race's loser, in this transaction: the winner's row is visible to this statement. Same digest →
                // its address (201); another → 409. No audit row: this request inserted nothing.
                return self::createdByKeyInside($em, $customerId, $keyHash, $digest) ?? throw ApiProblem::idempotencyKeyConflict();
            }

            // After the INSERT, in its transaction: a replay or a lost race never reaches here.
            $this->audit->recordCreated(
                tenantId: $identity->tenantId,
                actorUserId: $identity->userId,
                actorLabel: $identity->actorLabel,
                addressId: $id,
            );

            return CustomerAddressReader::readInside($em, $customerId, $id)
                ?? throw new \LogicException('The address just inserted is not readable.');
        });
    }

    /** @param array<string, mixed> $submitted wire name => value, exactly the fields the body carried */
    public function update(string $customerId, string $addressId, array $submitted): CustomerAddressView
    {
        if (!Uuid::isValid($customerId) || !Uuid::isValid($addressId)) {
            throw ApiProblem::notFound();
        }
        $customerId = Uuid::fromString($customerId)->toRfc4122();
        $addressId = Uuid::fromString($addressId)->toRfc4122();

        $identity = $this->identities->authenticated();

        return $this->tenants->run($this->tenant->tenantId(), function (EntityManagerInterface $em) use ($customerId, $addressId, $submitted, $identity): CustomerAddressView {
            $archived = self::parentArchivedInside($em, $customerId) ?? throw ApiProblem::notFound();
            // The pair: another customer's address under this path is the same 404 as a random id (ST-5). The row lock
            // (C2b): the diff below is taken against a row no concurrent update can move until this transaction ends, so
            // `customer_address.updated` names exactly what this request changed.
            $row = $em->getConnection()->fetchAssociative(
                'SELECT kind, address_line, city, postal_code, country_code FROM customer_address WHERE id = ?::uuid AND customer_id = ?::uuid FOR NO KEY UPDATE',
                [$addressId, $customerId],
            );
            if (false === $row) {
                throw ApiProblem::notFound();
            }

            if ($archived) {
                throw ApiProblem::businessRuleViolated('customer.is_archived', self::ARCHIVED_ON_UPDATE);
            }

            self::refuseMalformed($submitted, []);
            self::refuseUnsupportedCountry($em, $submitted);

            $changed = self::changedFields($row, $submitted);
            if ([] !== $changed) {
                $set = [];
                $parameters = [];
                foreach ($changed as $field) {
                    $set[] = self::COLUMNS[$field].' = ?';
                    $parameters[] = $submitted[$field];
                }
                $set[] = 'updated_at = now()';
                $parameters[] = $addressId;
                $parameters[] = $customerId;
                $em->getConnection()->executeStatement(
                    'UPDATE customer_address SET '.implode(', ', $set).' WHERE id = ?::uuid AND customer_id = ?::uuid',
                    $parameters,
                );

                // After the UPDATE, in its transaction.
                $this->audit->recordUpdated(
                    tenantId: $identity->tenantId,
                    actorUserId: $identity->userId,
                    actorLabel: $identity->actorLabel,
                    addressId: $addressId,
                    fields: $changed,
                );
            }

            return CustomerAddressReader::readInside($em, $customerId, $addressId)
                ?? throw new \LogicException('The address just updated is not readable.');
        });
    }

    /**
     * The wire names whose submitted value differs from the locked row, sorted ascending: every field compared as an exact
     * string — no trim, no case folding (the stored-as-sent rule).
     *
     * @param array<string, mixed> $row       column => stored value
     * @param array<string, mixed> $submitted wire name => value, shape-checked
     *
     * @return list<string>
     */
    private static function changedFields(array $row, array $submitted): array
    {
        $changed = [];
        foreach (self::COLUMNS as $field => $column) {
            if (\array_key_exists($field, $submitted) && (string) $row[$column] !== $submitted[$field]) {
                $changed[] = $field;
            }
        }
        sort($changed);

        return $changed;
    }

    /**
     * The customer's archived flag, or null when no customer of this workspace has the id. Archived customers ARE found.
     * Locked {@see self::PARENT_KEY_SHARE} against `DeleteCustomer` (C5 C4b).
     */
    private static function parentArchivedInside(EntityManagerInterface $em, string $customerId): ?bool
    {
        // `fetchAssociative`, not `fetchOne`: a live customer's `false` would be indistinguishable from "no row".
        $row = $em->getConnection()->fetchAssociative(self::PARENT_KEY_SHARE, [$customerId]);

        return false === $row ? null : (true === $row['archived']);
    }

    /**
     * The address this key already created, or null. A digest mismatch — another body **or another customer**, since the
     * path id is digested — is §6.2's 409, thrown before anything is written.
     */
    private static function createdByKeyInside(EntityManagerInterface $em, string $customerId, string $keyHash, string $digest): ?CustomerAddressView
    {
        $row = $em->getConnection()->fetchAssociative(
            'SELECT id, request_digest FROM customer_address WHERE idempotency_key = ?',
            [$keyHash],
        );
        if (false === $row) {
            return null;
        }
        if (!hash_equals((string) $row['request_digest'], $digest)) {
            throw ApiProblem::idempotencyKeyConflict();
        }

        // The digest carries the customer id, so a match is an address of THIS customer.
        return CustomerAddressReader::readInside($em, $customerId, (string) $row['id'])
            ?? throw new \LogicException('An address found by its key is not readable in the same transaction.');
    }

    /**
     * D-B's digest: the **path's customer id** plus the five shape-checked fields, values as sent (nothing is normalised
     * beyond the shape check: the house stores text as sent, so two spellings are two requests).
     *
     * @param array<string, mixed> $values
     */
    private static function digest(string $customerId, array $values): string
    {
        $fields = ['customer_id' => RequestDigest::uuid($customerId)];
        foreach (array_keys(self::COLUMNS) as $field) {
            $fields[$field] = $values[$field];
        }

        return RequestDigest::of(self::CREATE_OPERATION, $fields);
    }

    /**
     * Shape, and only shape: decidable without the database.
     *
     * @param array<string, mixed> $values
     * @param list<string>         $required
     */
    private static function refuseMalformed(array $values, array $required): void
    {
        $errors = [];

        foreach (array_keys(self::COLUMNS) as $field) {
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
                case 'kind':
                    if (!\in_array($value, self::KINDS, true)) {
                        $errors[] = self::error($field, 'not_an_address_kind', self::KIND_UNKNOWN);
                    }
                    break;
                case 'line':
                case 'city':
                case 'postal_code':
                    if (!\is_string($value)) {
                        $errors[] = self::error($field, 'not_a_string', 'This field must be a string.');
                    } elseif ('' === trim($value)) {
                        $errors[] = self::error($field, 'blank', 'This field may not be blank.');
                    }
                    break;
                case 'country_code':
                    if (!\is_string($value) || 1 !== preg_match('/^[A-Z]{2}$/', $value)) {
                        $errors[] = self::error($field, 'not_a_country_code', CustomerWriter::COUNTRY_SHAPE);
                    }
                    break;
            }
        }

        if ([] !== $errors) {
            throw ApiProblem::validationFailed($errors);
        }
    }

    /**
     * `country` is reference data (ADR-002 §6), not tenant-scoped: an existence check that discloses nothing.
     *
     * @param array<string, mixed> $values
     */
    private static function refuseUnsupportedCountry(EntityManagerInterface $em, array $values): void
    {
        if (isset($values['country_code']) && false === $em->getConnection()->fetchOne('SELECT 1 FROM country WHERE code = ?', [$values['country_code']])) {
            throw ApiProblem::validationFailed([self::error('country_code', 'unknown_reference', CustomerWriter::COUNTRY_UNSUPPORTED)]);
        }
    }

    /** @return array{pointer: string, code: string, message: string} */
    private static function error(string $field, string $code, string $message): array
    {
        return ['pointer' => '/'.$field, 'code' => $code, 'message' => $message];
    }
}
