<?php

declare(strict_types=1);

namespace App\Modules\Customers\Application;

use App\Modules\AuditLog\Infrastructure\CustomerContactAuditWriter;
use App\Platform\Http\Idempotency\IdempotencyKeyHash;
use App\Platform\Http\Idempotency\RequestDigest;
use App\Platform\Http\Problem\ApiProblem;
use App\Platform\Security\RequestIdentity;
use App\Platform\Tenancy\CurrentTenant;
use App\Platform\Tenancy\TenantContext;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * **`CreateCustomerContact` and `UpdateCustomerContact`** (customers slice 2 C1; `docs/session13/customers-s2-design.md`
 * Revision 2 R2.2 / R2.3, owner rulings `s35-ruling.md` Q-1 (a), Q-2 yes). Takes wire-named values, returns a
 * {@see CustomerContactView}; never an `ApiResource` class (A-7) and never a `Doctrine\DBAL\Connection` (A-10).
 * {@see CustomerAddressWriter}'s shape, with one deliberate difference: the parent lock.
 *
 * **The parent lock is `FOR NO KEY UPDATE`, not the address writers' `FOR KEY SHARE`** ({@see self::PARENT_LOCK}; owner
 * Q-1 (a): one primary contact per customer, marking another moves the mark; `ARCHITECTURE.md` W-5 amendment, co-signed by
 * `ARCH`). Two concurrent "make primary" writes each demote, then set. `FOR KEY SHARE` does not conflict with itself, so
 * both would pass and the second would hit `customer_contact_one_primary` with a 23505 in the middle of an `UPDATE` (which
 * has no `ON CONFLICT`) — an exception leaving `run()`, a closed manager, a 500. `FOR NO KEY UPDATE` conflicts with itself,
 * so every contact write of one customer is serialised; it also conflicts with `DeleteCustomer`'s `FOR UPDATE` (no contact
 * lands on a customer being archived) and with `UpdateCustomer` / `RestoreCustomer`, and NOT with Sales' or the address
 * writers' `FOR KEY SHARE`. It is legal because `erp_app` holds column UPDATE on `customer`. Lock order: customer → contact
 * rows; nothing takes a contact row and then the customer, so no cycle. **Downgrading it re-opens the 500** (mutation M1-5,
 * `CustomerContactWriteTest::twoRequestsMakingContactsPrimaryAtOnceLeaveExactlyOne`).
 *
 * **Create, in one `run()`** (R2.2): 1. shape, before any transaction (422s name the pointer and the code, never the
 * submitted value — SEC S-6.4); 2. the parent, locked: absent or another workspace's → 404, **an archived customer is
 * found**; 3. **the locked key read** ({@see self::KEY_READ}, R2.3): no row → no key; another digest → 409
 * `idempotency-key-conflict` carrying nothing of the stored row (S-6.3); a stored row of another customer under a matching
 * digest → `LogicException` thrown before any view is built (an invariant: the digest carries the path's customer id); else
 * the replay, from this row, no second read; 4. archived → 409 `customer.is_archived` (after the key on purpose: a retry of
 * a create that succeeded is handed its row; inlined, never a `refuseUnless…` method — `EditWindowGuardTest` clause (b));
 * 5. `INSERT … is_primary = false … ON CONFLICT (tenant_id, idempotency_key) DO NOTHING` — 0 rows → the key read again, in
 * this transaction (replay or 409; **a row that vanished → 409 `idempotency-key-conflict`**, a disclosed mislabel for an
 * identical body, final RED F-L1) and nothing else is written on that branch; 6. if primary was asked (owner Q-1 (a),
 * implemented as (a′)): demote every other primary of this customer ({@see self::DEMOTE}, `RETURNING id`), then
 * {@see self::SET_PRIMARY} on the new row; 7. `customer_contact.created` for the new row (no separate `.updated` for it)
 * and one `customer_contact.updated {"fields":["is_primary"]}` per demoted sibling; read back; 201.
 *
 * **Update:** the parent, locked → the pair `(customer, contact)` `FOR NO KEY UPDATE` (404 for an absent parent, a foreign
 * one, or another customer's contact — ST-5) → archived 409 → shape → the **changed** fields only (exact compare, no trim;
 * `null` compared as null) → the changed non-primary fields (and `is_primary = false`, if that is the change) in one
 * UPDATE → if `is_primary` turns true: demote, then set → one `customer_contact.updated` for the target naming every
 * changed field, one per demoted sibling → 200. A no-op issues no statement, leaves `updated_at`, writes no row.
 *
 * **What the parent lock makes unobservable (`ARCH` A-3, recorded so nobody spends time on it):** because every contact
 * write of one customer queues at step 2, the `FOR KEY SHARE` on the key read is a backstop under Q-1 (a), not evidence —
 * removing it changes no outcome a test can reach (M1-14 is a REVIEW CHECK; the address twin, whose parent lock does not
 * serialise, evidences the shape at C3). `ON CONFLICT` does not absorb a `customer_contact_one_primary` violation; the
 * parent `FOR NO KEY UPDATE` keeps it unreachable, and downgrading that lock re-opens a 500 (M1-5). Different-customer
 * same-key creates are not serialised by the parent lock; their loser ends in a thrown 409 (the digest carries the customer
 * id) that rolls back nothing, because (a′) writes nothing before the insert succeeds.
 */
final readonly class CustomerContactWriter
{
    public const CREATE_OPERATION = 'CreateCustomerContact';

    /**
     * Every contact write's parent read (owner Q-1 (a); see the class docblock for why it is stronger than the address
     * writers'). Public so the race tests take the writer's own statement.
     */
    public const PARENT_LOCK = 'SELECT deleted_at IS NOT NULL AS archived FROM customer WHERE id = ?::uuid FOR NO KEY UPDATE';

    /**
     * The locked key read (R2.3): the served columns ({@see CustomerContactReader::COLUMNS}) plus the two the replay decision
     * needs, in ONE statement. `FOR KEY SHARE` conflicts with a DELETE's row lock (C2), so a retry arriving while a delete
     * is uncommitted waits, then sees the row gone.
     */
    public const KEY_READ = 'SELECT '.CustomerContactReader::COLUMNS.', customer_id, request_digest FROM customer_contact WHERE idempotency_key = ? FOR KEY SHARE';

    /** The one INSERT, never primary: (a′) sets the mark in a second statement, after the demotion. */
    public const INSERT = 'INSERT INTO customer_contact (id, tenant_id, customer_id, name, email, phone, is_primary, idempotency_key, request_digest)'
        .' VALUES (?::uuid, ?::uuid, ?::uuid, ?, ?, ?, false, ?, ?)'
        .' ON CONFLICT (tenant_id, idempotency_key) DO NOTHING';

    /** Un-mark every other primary of this customer — BEFORE the mark is set (the unique is not deferrable). */
    public const DEMOTE = 'UPDATE customer_contact SET is_primary = false, updated_at = now() WHERE customer_id = ?::uuid AND is_primary AND id <> ?::uuid RETURNING id';

    public const SET_PRIMARY = 'UPDATE customer_contact SET is_primary = true, updated_at = now() WHERE id = ?::uuid AND customer_id = ?::uuid';

    public const ARCHIVED_ON_CREATE = 'This customer is archived, so no contact can be added to it until it\'s restored.';

    public const ARCHIVED_ON_UPDATE = 'This customer is archived, so its contacts can\'t be changed until it\'s restored.';

    public const NOT_AN_EMAIL = 'Enter an email address like name@example.com.';

    public const NOT_A_BOOLEAN = 'This field must be true or false.';

    public const PHONE_TOO_LONG = 'A phone number can be at most 50 characters.';

    /** The accepted fields, in §4.2's order, as wire names (= column names). */
    private const FIELDS = ['name', 'email', 'phone', 'is_primary'];

    private const EMAIL_MAX_OCTETS = 254;

    private const PHONE_MAX_CHARACTERS = 50;

    public function __construct(
        private TenantContext $tenants,
        private CurrentTenant $tenant,
        private RequestIdentity $identities,
        private CustomerContactAuditWriter $audit,
    ) {
    }

    /** @param array<string, mixed> $values wire name => value, for the fields the body carried */
    public function create(string $customerId, array $values, string $idempotencyKey): CustomerContactView
    {
        self::refuseMalformed($values, ['name']);

        if (!Uuid::isValid($customerId)) {
            throw ApiProblem::notFound();
        }
        $customerId = Uuid::fromString($customerId)->toRfc4122();

        // Absent optional fields take their defaults, so `{name}` and `{name, is_primary: false}` are one request.
        $values += ['email' => null, 'phone' => null, 'is_primary' => false];

        // D-B: the column holds the header's hash, never its value.
        $keyHash = IdempotencyKeyHash::of($idempotencyKey);
        $digest = self::digest($customerId, $values);
        $tenantId = $this->tenant->tenantId();
        $identity = $this->identities->authenticated();

        return $this->tenants->run($tenantId, function (EntityManagerInterface $em) use ($customerId, $values, $keyHash, $digest, $tenantId, $identity): CustomerContactView {
            $archived = self::parentArchivedInside($em, $customerId) ?? throw ApiProblem::notFound();

            $replay = self::createdByKeyInside($em, $customerId, $keyHash, $digest);
            if (null !== $replay) {
                return $replay;
            }

            // ST-9, after the key on purpose (see the class docblock, step 4).
            if ($archived) {
                throw ApiProblem::businessRuleViolated('customer.is_archived', self::ARCHIVED_ON_CREATE);
            }

            // Step 5: the conflict target is `customer_contact_idem_unique`'s columns exactly. A same-key request that
            // committed while this one waited at the unique makes this insert nothing — no 23505, so no exception leaves
            // `run()` and the manager stays open.
            $id = Uuid::v4()->toRfc4122();
            $connection = $em->getConnection();
            $inserted = $connection->executeStatement(self::INSERT,
                [$id, $tenantId, $customerId, $values['name'], $values['email'], $values['phone'], $keyHash, $digest]);
            if (1 !== $inserted) {
                // The race's loser: same digest → the winner's contact (201); another → 409. A row that vanished between
                // the winner's commit and this read is answered 409 too (F-L1). Nothing else is written on this branch.
                return self::createdByKeyInside($em, $customerId, $keyHash, $digest) ?? throw ApiProblem::idempotencyKeyConflict();
            }

            $demoted = true === $values['is_primary'] ? self::markPrimaryInside($em, $customerId, $id) : [];

            // After the statements, in their transaction: a replay or a lost race never reaches here.
            $this->audit->recordCreated(
                tenantId: $identity->tenantId,
                actorUserId: $identity->userId,
                actorLabel: $identity->actorLabel,
                contactId: $id,
            );
            foreach ($demoted as $sibling) {
                $this->audit->recordUpdated(
                    tenantId: $identity->tenantId,
                    actorUserId: $identity->userId,
                    actorLabel: $identity->actorLabel,
                    contactId: $sibling,
                    fields: ['is_primary'],
                );
            }

            return CustomerContactReader::readInside($em, $customerId, $id)
                ?? throw new \LogicException('The contact just inserted is not readable.');
        });
    }

    /** @param array<string, mixed> $submitted wire name => value, exactly the fields the body carried */
    public function update(string $customerId, string $contactId, array $submitted): CustomerContactView
    {
        if (!Uuid::isValid($customerId) || !Uuid::isValid($contactId)) {
            throw ApiProblem::notFound();
        }
        $customerId = Uuid::fromString($customerId)->toRfc4122();
        $contactId = Uuid::fromString($contactId)->toRfc4122();

        $identity = $this->identities->authenticated();

        return $this->tenants->run($this->tenant->tenantId(), function (EntityManagerInterface $em) use ($customerId, $contactId, $submitted, $identity): CustomerContactView {
            $archived = self::parentArchivedInside($em, $customerId) ?? throw ApiProblem::notFound();
            // The pair: another customer's contact under this path is the same 404 as a random id (ST-5). The row lock:
            // the diff below is taken against a row no concurrent write can move until this transaction ends.
            $row = $em->getConnection()->fetchAssociative(
                'SELECT name, email, phone, is_primary FROM customer_contact WHERE id = ?::uuid AND customer_id = ?::uuid FOR NO KEY UPDATE',
                [$contactId, $customerId],
            );
            if (false === $row) {
                throw ApiProblem::notFound();
            }

            if ($archived) {
                throw ApiProblem::businessRuleViolated('customer.is_archived', self::ARCHIVED_ON_UPDATE);
            }

            self::refuseMalformed($submitted, []);

            $changed = self::changedFields($row, $submitted);
            if ([] === $changed) {
                return CustomerContactReader::readInside($em, $customerId, $contactId)
                    ?? throw new \LogicException('The contact just locked is not readable.');
            }

            // (a′): the changed fields other than a false → true primary first (an un-mark rides here), then demote, then set.
            $becomesPrimary = \in_array('is_primary', $changed, true) && true === $submitted['is_primary'];
            $set = [];
            $parameters = [];
            foreach ($changed as $field) {
                if ('is_primary' === $field && $becomesPrimary) {
                    continue;
                }
                $set[] = $field.' = ?';
                $parameters[] = 'is_primary' === $field ? ($submitted[$field] ? 'true' : 'false') : $submitted[$field];
            }
            if ([] !== $set) {
                $set[] = 'updated_at = now()';
                $parameters[] = $contactId;
                $parameters[] = $customerId;
                $em->getConnection()->executeStatement(
                    'UPDATE customer_contact SET '.implode(', ', $set).' WHERE id = ?::uuid AND customer_id = ?::uuid',
                    $parameters,
                );
            }

            $demoted = $becomesPrimary ? self::markPrimaryInside($em, $customerId, $contactId) : [];

            // After the statements, in their transaction.
            $this->audit->recordUpdated(
                tenantId: $identity->tenantId,
                actorUserId: $identity->userId,
                actorLabel: $identity->actorLabel,
                contactId: $contactId,
                fields: $changed,
            );
            foreach ($demoted as $sibling) {
                $this->audit->recordUpdated(
                    tenantId: $identity->tenantId,
                    actorUserId: $identity->userId,
                    actorLabel: $identity->actorLabel,
                    contactId: $sibling,
                    fields: ['is_primary'],
                );
            }

            return CustomerContactReader::readInside($em, $customerId, $contactId)
                ?? throw new \LogicException('The contact just updated is not readable.');
        });
    }

    /**
     * Owner Q-1 (a), as (a′): un-mark every other primary of this customer, then mark this one — in that order, because
     * `customer_contact_one_primary` is checked per statement. Runs under the parent's `FOR NO KEY UPDATE`, so no other
     * contact write of this customer is between the two statements.
     *
     * @return list<string> the demoted contacts' ids
     */
    private static function markPrimaryInside(EntityManagerInterface $em, string $customerId, string $contactId): array
    {
        $connection = $em->getConnection();
        $demoted = array_map('strval', $connection->fetchFirstColumn(self::DEMOTE, [$customerId, $contactId]));
        $connection->executeStatement(self::SET_PRIMARY, [$contactId, $customerId]);

        return $demoted;
    }

    /**
     * The wire names whose submitted value differs from the locked row, sorted ascending: text compared as an exact string
     * (no trim, no case folding), `null` as null, `is_primary` as a boolean.
     *
     * @param array<string, mixed> $row       column => stored value
     * @param array<string, mixed> $submitted wire name => value, shape-checked
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
            $stored = 'is_primary' === $field ? (true === $row[$field]) : (null === $row[$field] ? null : (string) $row[$field]);
            if ($stored !== $submitted[$field]) {
                $changed[] = $field;
            }
        }
        sort($changed);

        return $changed;
    }

    /**
     * The customer's archived flag, or null when no customer of this workspace has the id. Archived customers ARE found.
     * Locked {@see self::PARENT_LOCK}.
     */
    private static function parentArchivedInside(EntityManagerInterface $em, string $customerId): ?bool
    {
        // `fetchAssociative`, not `fetchOne`: a live customer's `false` would be indistinguishable from "no row".
        $row = $em->getConnection()->fetchAssociative(self::PARENT_LOCK, [$customerId]);

        return false === $row ? null : (true === $row['archived']);
    }

    /**
     * The contact this key already created, or null — ONE locked statement (R2.3), the view built from that row. A digest
     * mismatch (another body or another customer, since the path id is digested) is §6.2's 409, thrown before anything is
     * written and carrying nothing of the stored row (S-6.3).
     */
    private static function createdByKeyInside(EntityManagerInterface $em, string $customerId, string $keyHash, string $digest): ?CustomerContactView
    {
        $row = $em->getConnection()->fetchAssociative(self::KEY_READ, [$keyHash]);
        if (false === $row) {
            return null;
        }
        if (!hash_equals((string) $row['request_digest'], $digest)) {
            throw ApiProblem::idempotencyKeyConflict();
        }
        // Unreachable while the digest carries the customer id; thrown before any view of another customer's row is built.
        if ((string) $row['customer_id'] !== $customerId) {
            throw new \LogicException('A contact found by its key and digest belongs to another customer: the digest no longer carries the customer id.');
        }

        return CustomerContactReader::view($row);
    }

    /**
     * D-B's digest: the **path's customer id** plus the four fields as sent, absent optional fields at their default
     * (nothing is normalised beyond the shape check: the house stores text as sent, so two spellings are two requests).
     *
     * @param array<string, mixed> $values
     */
    private static function digest(string $customerId, array $values): string
    {
        $fields = ['customer_id' => RequestDigest::uuid($customerId)];
        foreach (self::FIELDS as $field) {
            $fields[$field] = $values[$field];
        }

        return RequestDigest::of(self::CREATE_OPERATION, $fields);
    }

    /**
     * Shape, and only shape: decidable without the database. Every message is fixed text: no submitted value is ever echoed
     * (an e-mail or a phone is personal data, SEC S-6.4).
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
                } elseif ($present && \in_array($field, ['name', 'is_primary'], true)) {
                    $errors[] = self::error($field, 'not_nullable', 'This field may not be cleared.');
                }
                continue;
            }

            if ('is_primary' === $field) {
                if (!\is_bool($value)) {
                    $errors[] = self::error($field, 'not_a_boolean', self::NOT_A_BOOLEAN);
                }
                continue;
            }

            if (!\is_string($value)) {
                $errors[] = self::error($field, 'not_a_string', 'This field must be a string.');
                continue;
            }
            if ('' === trim($value)) {
                $errors[] = self::error($field, 'blank', 'This field may not be blank.');
                continue;
            }

            if ('email' === $field) {
                $parts = explode('@', $value);
                if (\strlen($value) > self::EMAIL_MAX_OCTETS || 2 !== \count($parts) || '' === $parts[0] || '' === $parts[1]) {
                    $errors[] = self::error($field, 'not_an_email', self::NOT_AN_EMAIL);
                }
            } elseif ('phone' === $field && mb_strlen($value) > self::PHONE_MAX_CHARACTERS) {
                $errors[] = self::error($field, 'too_long', self::PHONE_TOO_LONG);
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
