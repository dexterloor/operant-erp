<?php

declare(strict_types=1);

namespace App\Modules\Customers\Application;

use App\Modules\AuditLog\Infrastructure\CustomerAuditWriter;
use App\Modules\Customers\Application\Contract\PaymentTerms;
use App\Modules\Customers\Application\Port\CustomerOpenWork;
use App\Platform\Http\Problem\ApiProblem;
use App\Platform\Security\RequestIdentity;
use App\Platform\Tenancy\CurrentTenant;
use App\Platform\Tenancy\TenantContext;
use App\SharedKernel\Display\Counted;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * **`CreateCustomer`, `UpdateCustomer`, `RestoreCustomer` and `DeleteCustomer`** (customers slice 1 C2; `RestoreCustomer`
 * C5 C4a, see {@see self::restore()}; `DeleteCustomer` C5 C4b, see {@see self::delete()};
 * `docs/session13/customers-s1-design.md` §4, final RED
 * M-4's View shape, `customers-s1-g35-judge.md` FINAL item 4). Takes wire-named scalars, returns a {@see CustomerView};
 * never an `ApiResource` class (A-7) and never a `Doctrine\DBAL\Connection` (A-10: an unbound autowired `Connection` is
 * the schema owner).
 *
 * The two writes are raw SQL naming exactly the columns `erp_app`'s column grants cover (`SchemaManifest['customer']`,
 * `Version20261009000300`): the INSERT names `id, tenant_id, code, name, country_code, currency_code` and
 * `payment_terms_days` only when sent (omitted, the column default 30 applies, never a PHP copy — and the INSERT's
 * `RETURNING payment_terms_days` is what `customer.created` records, G-35 (g)); the UPDATE sets only
 * the **changed** columns plus `updated_at`. `deleted_at` is written only by `restore()` and `delete()` (C5 C4a / C4b,
 * the column grant `Version20261014000200`). A 42501 from any of them is fixed by removing the column from the statement, never by
 * widening the grant.
 *
 * **Refusals, in order** (copy: `customers-s1-ux.md` §2 as amended by final RED L-1):
 *  1. shape, before any transaction: required/null, blank (empty after trimming — the value is stored as sent, no trim,
 *     final RED L-2), country/currency code shape, payment terms not a whole number in 0..{@see PaymentTerms::MAXIMUM_DAYS}
 *     (365 since G-35 (f), C0b). Shape runs **before** the diff, so a full-form PATCH that resends **unchanged** terms
 *     above the bound is refused 422 although it changes nothing; a PATCH that omits terms is unaffected. No customer can
 *     be stored above 365 by the API, so only a row set by SQL meets this (C0b migration docblock);
 *  2. (update) the row: absent or foreign → 404; archived → 409 `customer.is_archived` (ST-9; inlined, never a
 *     `refuseUnless…` method, which `EditWindowGuardTest` clause (b) would read as an edit window);
 *  3. country/currency not in the reference tables → 422 `unknown_reference` ("not supported yet": four rows each,
 *     `RESIDUAL_GAPS.md` G-37);
 *  4. a live customer already holding the code → 409 `customer.code_is_unique_per_workspace`, **refused, never
 *     replayed** (`unique:customer_tenant_code_live`; the `Idempotency-Key` header is demanded and unused). Since BK-47 a
 *     **pre-check** ({@see self::CODE_TAKEN}) answers it before the write, with the same rule and sentence; the 23505,
 *     translated **outside** `TenantContext::run()` (the failed statement aborted the transaction), stays as the race
 *     backstop for a code taken between the pre-check and the write;
 *  5. **(BK-47) another customer, live or archived, with the same name** → 409 `customer.name_is_shared`
 *     ({@see self::NAME_SHARED}: the stored `customer.name_normalised` — `lower(btrim(name))` over
 *     {@see self::NAME_TRIM_CHARACTERS}, computed by the database — scoped to the workspace by RLS, never this customer's
 *     own id), unless the request carries `?allow_shared_name=true`
 *     ({@see SharedNameSwitch}). Customer names are still not unique (`s34-ruling.md` D-2): the refusal is a warning a
 *     caller can pass. It runs **last**, after the code check, so "go ahead anyway" is offered only when going ahead would
 *     work (`docs/session13/bk47-design.md` §2.3, R1-3). A malformed switch is refused in step 1's 422, beside the body's
 *     field errors. A rename that changes only capitals or outer white space is not checked; bulk restore always checks
 *     (`CustomerBulkArchiveMember`). The bounds of the comparison are `RESIDUAL_GAPS.md` G-48's.
 *
 * One row lock, taken by the first read (`FOR NO KEY UPDATE`, the lock the `UPDATE` would take anyway): last writer still
 * wins, and there is no `stale-record`; the lock exists so `customer.updated`'s field list is exact — an unlocked diff
 * could hide a concurrent terms reset (`customers-s1-g35-judge.md` C-3). One audit row per create and per changing
 * update, field names only except the terms value (`{payment_terms_days}` on create, `{from, to}` from the locked row on
 * a terms change; `RESIDUAL_GAPS.md` G-35 (g)). Both audit calls sit **after** their write statement, inside the
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

    /** M-3 decided (`owed-ux-c5.md` Response to RED (a2)): a passive "until it's restored", never "Restore it" (R-3). */
    public const ARCHIVED = 'This customer is archived, so it can\'t be changed until it\'s restored.';

    /** A repeated delete (§4.2 `Idem` `no`; ST-9). `UX` final copy, `owed-ux-c5.md` FINAL (a1); `SupplierWriter`'s twin. */
    public const ALREADY_ARCHIVED = 'This customer is already archived. It\'s in the archived customers.';

    /*
     * C5 C4b — the four (b′) refusals (`c5-design.md` R1.2; ruling Q-1), in the order `delete()` applies them. `UX` final
     * copy (`owed-ux-c5.md` FINAL (a1)); `SEC` S-2 kept the count (`owed-sec-c5.md` §2.3). Each `%s` is its noun function
     * below, never a bare `%d` (R-2: singular or plural, never `(s)`); each remedy is count-neutral and passive (R-3:
     * `customer.delete` does not imply `invoice.pay` or `credit_note.issue`).
     */
    public const HAS_OPEN_SALES_ORDERS = 'This customer still has %s, so it can\'t be deleted until each open order is shipped in full or cancelled. A draft can simply be deleted.';

    public const HAS_UNBILLED_SHIPMENTS = 'This customer has %s waiting to be invoiced, so it can\'t be deleted until every shipment is invoiced.';

    public const HAS_UNPAID_INVOICES = 'This customer has %s, so it can\'t be deleted until every invoice is paid or fully credited.';

    public const IS_OWED_A_CREDIT = 'This customer is still owed a credit for %s, so it can\'t be deleted until each return is credited. A return on a paid invoice can be credited only after that payment is reversed.';

    /**
     * **`DeleteCustomer`'s row lock** — public so the two-connection race tests hold THIS statement, never a transcription.
     * **`FOR UPDATE`, never `FOR NO KEY UPDATE`** (`SupplierWriter::DELETE_LOCK`'s reason, final RED L-B; mutation M12):
     * only `FOR UPDATE` conflicts with the `FOR KEY SHARE` Sales takes on the customer (`SalesOrderWriter`'s liveness read,
     * C5 C3), the one the address writers take (C4b), and an FK check's (`IssueInvoice`'s `invoice_customer_fk`).
     */
    public const DELETE_LOCK = 'SELECT deleted_at IS NOT NULL AS archived FROM customer WHERE id = ?::uuid FOR UPDATE';

    /** The one write `DeleteCustomer` issues (public for the race tests' holder). */
    public const ARCHIVE = 'UPDATE customer SET deleted_at = now(), updated_at = now() WHERE id = ?::uuid';

    /** `SupplierWriter::NOT_ARCHIVED`'s wording; kept as is by `UX` (`owed-ux-c5.md` FINAL (a1)). */
    public const NOT_ARCHIVED = 'This customer is not archived, so there is nothing to restore.';

    /**
     * C5 C4a, `UX` final copy (`owed-ux-c5.md` FINAL (a1)): a live customer took the archived one's code while it was archived.
     * Its name twin is not a uniqueness rule (`customer_tenant_name_live` is a plain index, measured C4a T-3): since BK-47 a
     * restore beside a customer of the same name is refused {@see self::NAME_SHARED_ON_RESTORE} unless the request allows a
     * shared name, and with the switch it may still leave two live customers of one name.
     */
    public const CODE_TAKEN_ON_RESTORE = 'Another customer now uses this customer\'s code, so it can\'t be restored. It can be restored once that customer\'s code is changed.';

    /*
     * BK-47 (`docs/session13/bk47-design.md` §2.1; `UX` TO CONFIRM): the three shared-name refusals. They state the condition
     * the server checked, name the switch, and echo nothing submitted; never the other customer's id, code or name (SEC,
     * design §6: every caller is checked, a `customer.create`-only seat included, so the body carries one bit only).
     */
    public const NAME_SHARED_ON_CREATE = 'Another customer, live or archived, already has this name, so this customer was not created. Two customers can share a name: to create it anyway, send the request again with allow_shared_name=true.';

    public const NAME_SHARED_ON_UPDATE = 'Another customer, live or archived, already has this name, so the change was not saved. Two customers can share a name: to save it anyway, send the request again with allow_shared_name=true.';

    public const NAME_SHARED_ON_RESTORE = 'Another customer, live or archived, has this customer\'s name, so it was not restored. Two customers can share a name: to restore it anyway, send the request again with allow_shared_name=true.';

    /**
     * The characters the name comparison ignores at either end (BK-47, `DATA` TO CONFIRM): PHP `trim()`'s set minus `\0`
     * (which `text` cannot hold), i.e. exactly what the `blank` check already treats as nothing. **Bound as a parameter**
     * in every statement; its one other copy is the `chr()` list in `Version20261125000100`'s generation expression,
     * held equal to this constant by `CustomerSharedNameTest` P-1 — never written into a statement as a literal (a standard-string `' \t\n…'`
     * would trim the letters `t`, `n`, `r`, `B`; final RED L-5). No-break and other Unicode spaces are NOT trimmed, by
     * choice (G-48's bounds, pinned by `CustomerSharedNameTest`).
     */
    public const NAME_TRIM_CHARACTERS = " \t\n\r\x0B";

    /**
     * **The shared-name check** (BK-47) — public so tests run THIS statement. Parameters: the name, the trim set, this
     * customer's id. It compares the stored generated column `name_normalised` (`Version20261125000100`, the trim set's
     * second copy, held equal by `CustomerSharedNameTest` P-1) with the bound name normalised the same way. The functions
     * apply to the bound value only, so under row security the clause is an index condition on
     * `customer_tenant_name_normalised` (`SchemaQueryPlanTest`); an expression index on `lower(btrim(name))` never is
     * (`lower`/`btrim` are not leakproof; `bk47-option-a-design.md`). No `deleted_at` predicate (archived customers count)
     * and no `tenant_id` predicate (RLS scopes it, as every statement here relies on). Read unlocked, under READ COMMITTED:
     * a point-in-time check (design §5).
     */
    public const NAME_SHARED = 'SELECT EXISTS (SELECT 1 FROM customer WHERE name_normalised = lower(btrim(?::text, ?::text)) AND id <> ?::uuid)';

    /**
     * **The code pre-check** (BK-47) — the live code unique's question asked before the write, so a request colliding on code
     * and name is told the code first (R1-3). Compared exactly as stored, as `customer_tenant_code_live` does. `id <> ?` is a
     * defence for a future caller: on every path today the own row is already excluded (final RED M-1).
     */
    public const CODE_TAKEN = 'SELECT EXISTS (SELECT 1 FROM customer WHERE code = ? AND deleted_at IS NULL AND id <> ?::uuid)';

    /** Whether a rename changes the name beyond capitals and outer white space — decided by the database, never PHP (design §2.2). */
    public const NAME_CHANGES = 'SELECT lower(btrim(?::text, ?::text)) IS DISTINCT FROM lower(btrim(?::text, ?::text))';

    public const TERMS_OUT_OF_RANGE = 'Payment terms must be a whole number of days from 0 to %d.';

    public const COUNTRY_UNSUPPORTED = 'This country isn\'t supported yet.';

    public const CURRENCY_UNSUPPORTED = 'This currency isn\'t supported yet.';

    public const COUNTRY_SHAPE = 'Use the two-letter country code, for example GB.';

    public const CURRENCY_SHAPE = 'Use the three-letter currency code, for example GBP.';

    /** The five accepted fields, in §4.2's order, as wire names. */
    private const FIELDS = ['code', 'name', 'country_code', 'currency_code', 'payment_terms_days'];

    /** Required on create; `payment_terms_days` is optional (the column default applies). */
    private const REQUIRED_ON_CREATE = ['code', 'name', 'country_code', 'currency_code'];

    /*
     * The nouns of the four refusals above (R-2): this module's vocabulary; the counting rule is {@see Counted::of()}
     * (`ARCH` F-10). Each returns only a noun phrase, no verb, so nothing has to agree with the count (`UX` M-3).
     */
    public static function openSalesOrders(int $count): string
    {
        return Counted::of($count, 'open sales order', 'open sales orders');
    }

    public static function shipments(int $count): string
    {
        return Counted::of($count, 'shipment', 'shipments');
    }

    public static function unpaidInvoices(int $count): string
    {
        return Counted::of($count, 'unpaid invoice', 'unpaid invoices');
    }

    public static function returns(int $count): string
    {
        return Counted::of($count, 'return', 'returns');
    }

    public function __construct(
        private TenantContext $tenants,
        private CurrentTenant $tenant,
        private RequestIdentity $identities,
        private CustomerAuditWriter $audit,
        private CustomerOpenWork $openWork,
    ) {
    }

    /** @param array<string, mixed> $values wire name => value, for the fields the body carried */
    public function create(array $values, SharedNameSwitch $switch): CustomerView
    {
        self::refuseMalformed($values, self::REQUIRED_ON_CREATE, $switch);

        $id = Uuid::v4()->toRfc4122();
        $tenantId = $this->tenant->tenantId();
        $identity = $this->identities->authenticated();

        try {
            return $this->tenants->run($tenantId, function (EntityManagerInterface $em) use ($id, $tenantId, $values, $identity, $switch): CustomerView {
                self::refuseUnsupportedReferences($em, $values);
                self::refuseIfCodeTaken($em, (string) $values['code'], $id, self::CODE_TAKEN_ON_CREATE);
                if (!$switch->isOn()) {
                    self::refuseIfNameShared($em, (string) $values['name'], $id, self::NAME_SHARED_ON_CREATE);
                }

                $columns = ['id', 'tenant_id', 'code', 'name', 'country_code', 'currency_code'];
                $parameters = [$id, $tenantId, $values['code'], $values['name'], $values['country_code'], $values['currency_code']];
                if (\array_key_exists('payment_terms_days', $values)) {
                    $columns[] = 'payment_terms_days';
                    $parameters[] = $values['payment_terms_days'];
                }

                // `RETURNING payment_terms_days` (G-35 (g)): the trail records the terms as STORED, so an omitted field
                // records the column default, read back from the database — never a PHP copy of 30.
                $storedTerms = $em->getConnection()->fetchOne(
                    \sprintf('INSERT INTO customer (%s) VALUES (?::uuid, ?::uuid%s) RETURNING payment_terms_days', implode(', ', $columns), str_repeat(', ?', \count($columns) - 2)),
                    $parameters,
                );
                if (!\is_int($storedTerms) && !(\is_string($storedTerms) && ctype_digit($storedTerms))) {
                    // Never `(int) false` = 0: a missing RETURNING row would record terms nobody set.
                    throw new \LogicException('The customer INSERT returned no payment_terms_days.');
                }

                // After the INSERT, in its transaction: a 23505 above leaves no row (G-35).
                $this->audit->recordCreated(
                    tenantId: $identity->tenantId,
                    actorUserId: $identity->userId,
                    actorLabel: $identity->actorLabel,
                    customerId: $id,
                    paymentTermsDays: (int) $storedTerms,
                );

                return CustomerReader::readInside($em, $id) ?? throw new \LogicException('The customer just inserted is not readable.');
            });
        } catch (UniqueConstraintViolationException) {
            // Outside `run()`: the failed INSERT aborted the transaction. The table's only unique a write can hit is
            // `customer_tenant_code_live` (the id is minted here) — reached only by a code taken after the pre-check.
            throw ApiProblem::businessRuleViolated('customer.code_is_unique_per_workspace', self::CODE_TAKEN_ON_CREATE);
        }
    }

    /** @param array<string, mixed> $submitted wire name => value, exactly the fields the body carried */
    public function update(string $id, array $submitted, SharedNameSwitch $switch): CustomerView
    {
        if (!Uuid::isValid($id)) {
            throw ApiProblem::notFound();
        }
        $id = Uuid::fromString($id)->toRfc4122();

        self::refuseMalformed($submitted, [], $switch);
        $identity = $this->identities->authenticated();

        try {
            return $this->tenants->run($this->tenant->tenantId(), function (EntityManagerInterface $em) use ($id, $submitted, $identity, $switch): CustomerView {
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
                // ST-9: an archived row is read-only (archived by `DeleteCustomer` since C5 C4b). The
                // sentence does not say "Restore it" — **decided** (`owed-ux-c5.md` Response to RED, M-3): `RestoreCustomer`
                // ships at C5 C4a, so the reason is no longer that restore is blocked, but a caller holding `customer.edit`
                // may lack `customer.delete`, which restoring needs; "until it's restored" is true for every reader (R-3).
                if (true === $row['archived']) {
                    throw ApiProblem::businessRuleViolated('customer.is_archived', self::ARCHIVED);
                }

                self::refuseUnsupportedReferences($em, $submitted);

                $changed = self::changedFields($row, $submitted);
                if (\in_array('code', $changed, true)) {
                    self::refuseIfCodeTaken($em, (string) $submitted['code'], $id, self::CODE_TAKEN_ON_UPDATE);
                }
                // A rename that changes only capitals or outer white space creates no new collision: written and audited,
                // never checked. The database decides "changes", with the check's own expression (design §2.2).
                if (!$switch->isOn() && \array_key_exists('name', $submitted)
                    && true === $connection->fetchOne(self::NAME_CHANGES, [$submitted['name'], self::NAME_TRIM_CHARACTERS, $row['name'], self::NAME_TRIM_CHARACTERS])) {
                    self::refuseIfNameShared($em, (string) $submitted['name'], $id, self::NAME_SHARED_ON_UPDATE);
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
                    $connection->executeStatement('UPDATE customer SET '.implode(', ', $set).' WHERE id = ?::uuid', $parameters);

                    // After the UPDATE, in its transaction: a 23505 above leaves no row (G-35).
                    $this->audit->recordUpdated(
                        tenantId: $identity->tenantId,
                        actorUserId: $identity->userId,
                        actorLabel: $identity->actorLabel,
                        customerId: $id,
                        fields: $changed,
                        // G-35 (g): `from` is the LOCKED row's value — what this request actually replaced, even when a
                        // concurrent write moved it after the caller read it — and `to` is what it set.
                        paymentTerms: \in_array('payment_terms_days', $changed, true)
                            ? ['from' => (int) $row['payment_terms_days'], 'to' => (int) $submitted['payment_terms_days']]
                            : null,
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
     *  4. (BK-47) another live customer holding this row's code → 409, {@see self::CODE_TAKEN_ON_RESTORE} (the pre-check);
     *  5. (BK-47) another customer, live or archived, with this row's name → 409 `customer.name_is_shared`, unless
     *     `?allow_shared_name=true` (a malformed switch is a 422 after the uuid 404, before anything is read);
     *  6. `UPDATE customer SET deleted_at = NULL, updated_at = now()` — the column grant `Version20261014000200` adds;
     *  7. `customer.restored`, `{}`, after the UPDATE, in its transaction;
     *  8. the row read back.
     *
     * **The restore collision** (design §3.4): `customer_tenant_code_live` is partial on `deleted_at IS NULL`, so a live
     * customer may have taken the archived one's code while it was archived. Step 4 answers it before the write; a code
     * taken after the pre-check still meets the 23505, translated **outside** `run()` to the same rule and sentence (the
     * failed UPDATE aborted the transaction, so no audit row is written). It is the table's only unique a restore can hit
     * (the name index is not unique, measured C4a T-3). **The name** (BK-47, `bk47-design.md`): customer names are not
     * unique, so with the switch a restore may still leave two live customers of one name; without it, a namesake —
     * archived ones included — refuses. **No open-work check**: restoring creates no work. Children (`customer_address`,
     * `customer_contact`, `hard`) were never touched by archiving, so nothing is resurrected.
     */
    public function restore(string $id, SharedNameSwitch $switch): CustomerView
    {
        if (!Uuid::isValid($id)) {
            throw ApiProblem::notFound();
        }
        $id = Uuid::fromString($id)->toRfc4122();
        // The one shape error a restore can have (it has no body), after the uuid 404 as `update()`'s shape check sits.
        if (null !== $error = $switch->error()) {
            throw ApiProblem::validationFailed([$error]);
        }
        $identity = $this->identities->authenticated();

        try {
            return $this->tenants->run($this->tenant->tenantId(), function (EntityManagerInterface $em) use ($id, $identity, $switch): CustomerView {
                $connection = $em->getConnection();
                // fetchAssociative, never fetchOne: a live row's `false` would read as "no row" (C2a deviation 5).
                $row = $connection->fetchAssociative('SELECT deleted_at IS NOT NULL AS archived, code, name FROM customer WHERE id = ?::uuid FOR NO KEY UPDATE', [$id]);
                if (false === $row) {
                    throw ApiProblem::notFound();
                }
                if (true !== $row['archived']) {
                    throw ApiProblem::businessRuleViolated('customer.is_not_archived', self::NOT_ARCHIVED);
                }
                self::refuseIfCodeTaken($em, (string) $row['code'], $id, self::CODE_TAKEN_ON_RESTORE);
                if (!$switch->isOn()) {
                    self::refuseIfNameShared($em, (string) $row['name'], $id, self::NAME_SHARED_ON_RESTORE);
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
     * **`DeleteCustomer`** (C5 C4b; `docs/session13/c5-design.md` §3.4 and Revision 1 R1.2 / R1.6; ruling Q-1 = (b′),
     * Q-3 = (i) + G-45). A soft delete: `customer` is `SchemaManifest::SOFT`, so this sets `deleted_at` — an UPDATE under
     * the column grant `RestoreCustomer` brought (`Version20261014000200`); `erp_app` holds no DELETE on the table. In one
     * `TenantContext::run()`:
     *  1. a non-uuid, absent or foreign id → 404 (ST-5);
     *  2. the row read {@see self::DELETE_LOCK} (`FOR UPDATE`) — archived → 409 `customer.is_archived` (ST-9; a repeated
     *     delete, §4.2 `Idem` `no`: no `Idempotency-Key`);
     *  3. open work ({@see CustomerOpenWork}, answered by Sales in ONE statement, counted AFTER the lock); the first
     *     non-zero leg refuses, naming its count: `customer.has_open_sales_orders` → `customer.has_unbilled_shipments` →
     *     `customer.has_unpaid_invoices` → `customer.is_owed_a_credit`;
     *  4. {@see self::ARCHIVE};
     *  5. `customer.deleted`, `{}`, after the UPDATE, in its transaction — a refusal writes none;
     *  6. 204, no body (API-21 branch 3).
     *
     * **The race, closed by the lock and RUN in both orders** (`CustomerDeleteTest`): a sales order first — its customer
     * `FOR KEY SHARE` (`SalesOrderWriter`, C5 C3) or its FK check makes this `FOR UPDATE` wait, and the count, a later
     * statement under READ COMMITTED, sees the order → refused. The delete first — the order's locking liveness read waits,
     * re-checks `deleted_at IS NULL` on the committed row and finds none → `unknown_reference`. The same for
     * `UpdateSalesOrder` moving a draft onto this customer, and the address writers read the parent `FOR KEY SHARE`
     * (`CustomerAddressWriter::PARENT_KEY_SHARE`), so no address lands on a customer this archives. `IssueInvoice`'s
     * INSERT takes `FOR KEY SHARE` through `invoice_customer_fk` and so waits too. **No cycle:** this takes one lock, on
     * the customer row, and never a document row.
     *
     * **A check at the moment of archiving, not an invariant afterwards** (ruling Q-3 (i), `RESIDUAL_GAPS.md` G-45):
     * `CreateSalesReturn`, `ReverseInvoicePayment` and an owner's adjustment-decision reversal can reopen work on an
     * archived customer, and none of the settling writes reads customer liveness — pinned by
     * `CustomerDeleteTest::workCanReopenOnAnArchivedCustomerAndIsNotRefused`. No lock is taken on those paths (R1.3).
     * Addresses are kept, read-only while archived, and come back unchanged with `RestoreCustomer`.
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
                throw ApiProblem::businessRuleViolated('customer.is_archived', self::ALREADY_ARCHIVED);
            }

            $open = $this->openWork->openWorkInside($em, $id);
            foreach ([
                ['customer.has_open_sales_orders', self::HAS_OPEN_SALES_ORDERS, $open->openSalesOrders, self::openSalesOrders(...)],
                ['customer.has_unbilled_shipments', self::HAS_UNBILLED_SHIPMENTS, $open->unbilledShipments, self::shipments(...)],
                ['customer.has_unpaid_invoices', self::HAS_UNPAID_INVOICES, $open->unpaidInvoices, self::unpaidInvoices(...)],
                ['customer.is_owed_a_credit', self::IS_OWED_A_CREDIT, $open->owedCredits, self::returns(...)],
            ] as [$rule, $sentence, $count, $noun]) {
                if ($count > 0) {
                    throw ApiProblem::businessRuleViolated($rule, \sprintf($sentence, $noun($count)));
                }
            }

            $connection->executeStatement(self::ARCHIVE, [$id]);

            // After the UPDATE, in its transaction: a refusal above leaves no row.
            $this->audit->recordDeleted(
                tenantId: $identity->tenantId,
                actorUserId: $identity->userId,
                actorLabel: $identity->actorLabel,
                customerId: $id,
            );
        });
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
     * Shape, and only shape: decidable without the database, so it runs before a transaction is opened. The switch's error
     * (BK-47) joins the same list, after the body's field errors, so one 422 names everything (`bk47-red.md` L-4).
     *
     * @param array<string, mixed> $values
     * @param list<string>         $required
     */
    private static function refuseMalformed(array $values, array $required, SharedNameSwitch $switch): void
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
                    if (!\is_string($value) || 1 !== preg_match('/\A[A-Z]{2}\z/', $value)) {
                        $errors[] = self::error($field, 'not_a_country_code', self::COUNTRY_SHAPE);
                    }
                    break;
                case 'currency_code':
                    if (!\is_string($value) || 1 !== preg_match('/\A[A-Z]{3}\z/', $value)) {
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

        if (null !== $error = $switch->error()) {
            $errors[] = $error;
        }

        if ([] !== $errors) {
            throw ApiProblem::validationFailed($errors);
        }
    }

    /** BK-47: the code pre-check ({@see self::CODE_TAKEN}), the same rule and sentence the 23505 backstop gives. */
    private static function refuseIfCodeTaken(EntityManagerInterface $em, string $code, string $id, string $sentence): void
    {
        if (true === $em->getConnection()->fetchOne(self::CODE_TAKEN, [$code, $id])) {
            throw ApiProblem::businessRuleViolated('customer.code_is_unique_per_workspace', $sentence);
        }
    }

    /** BK-47: the shared-name check ({@see self::NAME_SHARED}); the trim set is bound, never a literal. */
    private static function refuseIfNameShared(EntityManagerInterface $em, string $name, string $id, string $sentence): void
    {
        if (true === $em->getConnection()->fetchOne(self::NAME_SHARED, [$name, self::NAME_TRIM_CHARACTERS, $id])) {
            throw ApiProblem::businessRuleViolated('customer.name_is_shared', $sentence);
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
