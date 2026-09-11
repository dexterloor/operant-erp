<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * **API-O-31 step 6 — the non-null demand `Version20260911000100` deferred BY NAME.**
 *
 * ---------------------------------------------------------------------------
 * What is installed, and why it is a trigger rather than a `CHECK`
 * ---------------------------------------------------------------------------
 * Step 5 laid `purchase_order.idempotency_key` nullable, bounded it at 1–255
 * octets, and put it on the live-row unique `purchase_order_tenant_idem_live`.
 * It then stated the residual in its own docblock and named the repair:
 *
 * > **It is deferred to step 6 and named:** `purchase_order_idempotency_key_required`,
 * > a `BEFORE INSERT` trigger, to be installed by the migration that ships
 * > `CreatePurchaseOrder` itself.
 *
 * This is that file, and the name is taken from there rather than chosen here.
 * The argument is step 5's and is not re-made — it is quoted, because the two
 * near-misses are the kind somebody reaches for on a later pass:
 *
 *   - **A plain `CHECK (idempotency_key IS NOT NULL)` is wrong**: it applies to
 *     every row, including the historical ones the column is nullable *for*, so
 *     it cannot be validated and would refuse every `UPDATE` of an old order.
 *   - **`ADD CONSTRAINT … NOT VALID` is the obvious near-miss and is also
 *     wrong.** It exempts existing rows at add time — the right half — but still
 *     enforces on any later `UPDATE` of one of them, so editing a pre-migration
 *     purchase order would start failing. `NOT VALID` exempts rows from the
 *     *scan*, never from subsequent writes.
 *   - **A `BEFORE INSERT` trigger** discriminates the one event that must carry
 *     a key (an insert) from the one that must not be forced to (an update of a
 *     historical row), which is a distinction no `CHECK` can express.
 *
 * ---------------------------------------------------------------------------
 * Why it is installable NOW and was not installable then
 * ---------------------------------------------------------------------------
 * Step 5 refused to install it on a stated ground: *"Installing it **today**
 * would refuse every insert the suite and the product currently make, because
 * **nothing writes the column yet** … A trigger that refuses every live write in
 * service of an operation that does not exist is not a control; it is an
 * outage."*
 *
 * Both halves of that have changed in this same commit. `CreatePurchaseOrder`
 * exists and writes the header's value into the column, and
 * `PurchaseOrderTestCase::seedOrder()` — the only other writer of this table in
 * the tree — now supplies one too. **That fixture change is not incidental to
 * this migration; it is the other half of it.** A trigger demanding a value on
 * every insert is a statement that every insert has one, and the fixture is an
 * insert.
 *
 * ---------------------------------------------------------------------------
 * `WHEN (NEW.idempotency_key IS NULL)`, and why the predicate is on the trigger
 * ---------------------------------------------------------------------------
 * The function body is unconditional: reaching it *is* the violation. The
 * predicate lives in the trigger's `WHEN` clause instead, so the ordinary path —
 * every insert that carries a key — never enters a plpgsql function at all.
 * That is `purchase_order_cascade_deletion`'s own shape
 * (`Version20260906000100`), where the `WHEN` guard is what keeps an ordinary
 * status change on a thousand-line order costing nothing.
 *
 * ---------------------------------------------------------------------------
 * `ERRCODE = 'not_null_violation'`, chosen rather than defaulted
 * ---------------------------------------------------------------------------
 * `RAISE EXCEPTION` defaults to `P0001` (`raise_exception`), which DBAL surfaces
 * as a bare `DriverException` indistinguishable from any other server error.
 * `23502` is what this condition *is* — a null in a column that may not hold one
 * on this operation — and DBAL maps it to `NotNullConstraintViolationException`,
 * so a writer that forgot the key fails as a typed, greppable defect rather than
 * as a 500 with a string in it. **It is deliberately NOT `23505`**: a unique
 * violation on this table means *replay* and `PurchaseOrderWriter` answers one by
 * returning the existing order, so borrowing that code here would route a missing
 * key into the replay path and hand the caller somebody else's order.
 *
 * ---------------------------------------------------------------------------
 * Grants: `EXECUTE` is revoked from `PUBLIC`, and that is not hygiene
 * ---------------------------------------------------------------------------
 * ADR-002 Amendment 2's lesson reaches functions too — `EXECUTE` defaults to
 * `PUBLIC`, which is the same deny-by-default failure the table grants are
 * withdrawn for. `GrantFootprintTest::sequenceAndFunctionPrivilegesAreAsNarrowAsTheTableOnes`
 * asserts **no** function in `public` is executable by `PUBLIC`, so a function
 * added without this pair of statements is red rather than quietly world-callable.
 * The `GRANT … TO erp_app` mirrors `cascade_deletion_to_lines()`: Postgres checks
 * `EXECUTE` when the trigger is created rather than when it fires, but the grant
 * is what makes the privilege explicit instead of incidental.
 *
 * **`SECURITY INVOKER`** — the default, and left as the default deliberately.
 * `SchemaGuardTest::theSchemaContainsNoSecurityDefinerObjects` and
 * `GrantFootprintTest::noSecurityDefinerFunctionExistsInAnyNonSystemSchema` both
 * refuse a definer function outright, and this one needs no privilege its caller
 * lacks: it reads `NEW` and raises.
 *
 * ===========================================================================
 * ⚠ THE SECOND STATEMENT IN THIS FILE IS A DEFECT FOUND WHILE BUILDING `cancel()`,
 * and it is not what this migration was scheduled to do
 * ===========================================================================
 * `purchase_order_ordered_at_set` has read
 * `CHECK (status = 'draft' OR ordered_at IS NOT NULL)` since
 * `Version20260906000100`. It is correct for every transition that existed when
 * it was written, because `place()` was the only one and it sets `ordered_at`.
 *
 * **§8.3's matrix makes `STATUS_DRAFT` a legal source for `cancel`**, and a
 * draft has `ordered_at IS NULL` — that is what the constraint's own `draft`
 * disjunct says. So `draft → cancelled` produces a row with
 * `status = 'cancelled'` and `ordered_at IS NULL`, which satisfies neither
 * disjunct, and Postgres refuses it with `23514`: a 500 carrying a constraint
 * name, on a transition the contract declares legal.
 *
 * **This was not discoverable before this commit and that is the point.** The
 * constraint could not be wrong while `cancel()` did not exist — X9-1 and §8.3's
 * *(cancel — no method exists)* row are what were holding the question open, and
 * writing the method is what asked it. It is recorded here rather than quietly
 * fixed, because "the schema was already right for the transitions it had" and
 * "the schema is right" are different claims.
 *
 * The disjunct added is **`cancelled`**, and nothing else: an order cancelled
 * before it was ever placed legitimately has no ordered date, and one cancelled
 * after placing keeps the date it was placed on. `received` and
 * `partially_received` stay out — both are only reachable from `ordered`, so a
 * row in either has an `ordered_at` by construction and admitting them would
 * widen the constraint past anything that can happen.
 *
 * **The literals are hand-written**, on `audit_log_event_type_values`' rule
 * (`AuditEvent`'s docblock, step 5 of its extension process): a constraint whose
 * list is generated from the constants it is meant to constrain cannot disagree
 * with them, and the reconciliation becomes a tautology. The forward drop-and-
 * re-add is the same shape `Version20260907000200` and `Version20260909000300`
 * use, and for the same reason — never edit a migration that already issued it.
 *
 * ---------------------------------------------------------------------------
 * What this migration is NOT
 * ---------------------------------------------------------------------------
 *   - **Not a `NOT NULL` on the column.** The historical rows are real and the
 *     column's nullability is a statement about them (step 5, `PurchaseOrder::$idempotencyKey`).
 *   - **Not a column for `CreateSalesOrder`, `CreateCustomerContact` or
 *     `CreateCustomerAddress`**, and not a ruling on `IssueInvoice`. All four
 *     stay named in `DELIVERY_LEDGER.md` E9-2.
 *   - **No `REVOKE` for API-O-32.** `erp_ai` can read this column by table-level
 *     inheritance; whether it should is `SEC`'s ruling and a column-level ACL is
 *     a role-model change, not a tidy-up.
 *
 * @see Version20260911000100 — the column, the unique, and the deferral this discharges
 * @see docs/API.md §6.1, §6.2 — the mechanism vocabulary and the 255-byte cap
 * @see docs/DELIVERY_LEDGER.md E9-2
 */
final class Version20260911000200 extends AbstractMigration
{
    private const TABLE = 'purchase_order';

    private const COLUMN = 'idempotency_key';

    /** Step 5's name, quoted from its docblock rather than re-chosen. */
    private const TRIGGER = 'purchase_order_idempotency_key_required';

    /** `Version20260906000100`'s, re-issued here. See the docblock's second section. */
    private const ORDERED_AT_SET = 'purchase_order_ordered_at_set';

    public function getDescription(): string
    {
        return 'API-O-31 step 6: purchase_order_idempotency_key_required (BEFORE INSERT trigger), and purchase_order_ordered_at_set widened to admit a cancelled draft';
    }

    public function up(Schema $schema): void
    {
        // The second statement first, because it is the one that unblocks a
        // transition rather than constraining one: without it, §8.3's legal
        // `draft → cancelled` is refused by the database with 23514.
        $this->addSql(sprintf(
            'ALTER TABLE %s DROP CONSTRAINT %s',
            self::TABLE,
            self::ORDERED_AT_SET,
        ));
        $this->addSql(sprintf(
            "ALTER TABLE %s ADD CONSTRAINT %s CHECK (status IN ('draft', 'cancelled') OR ordered_at IS NOT NULL)",
            self::TABLE,
            self::ORDERED_AT_SET,
        ));

        // Unconditional: reaching this body IS the violation. The predicate is
        // on the trigger's WHEN clause, so an insert that carries a key never
        // enters plpgsql.
        $this->addSql(sprintf(<<<'SQL'
            CREATE FUNCTION %s() RETURNS trigger
            LANGUAGE plpgsql AS $$
            BEGIN
                RAISE EXCEPTION
                    'purchase_order.idempotency_key is required on every INSERT. The column is nullable only for rows raised before Version20260911000100; every row created since carries the client''s Idempotency-Key.'
                    USING ERRCODE = 'not_null_violation';
                RETURN NEW;
            END;
            $$
            SQL, self::TRIGGER));

        // Deny-by-default reaches functions: EXECUTE is granted to PUBLIC
        // unless withdrawn, which is the same shape as the table grants
        // Version20260906000100 spent its first statements revoking.
        $this->addSql(sprintf('REVOKE ALL ON FUNCTION %s() FROM PUBLIC', self::TRIGGER));
        $this->addSql(sprintf('GRANT EXECUTE ON FUNCTION %s() TO erp_app', self::TRIGGER));

        $this->addSql(sprintf(<<<'SQL'
            CREATE TRIGGER %s
            BEFORE INSERT ON %s
            FOR EACH ROW WHEN (NEW.%s IS NULL)
            EXECUTE FUNCTION %s()
            SQL, self::TRIGGER, self::TABLE, self::COLUMN, self::TRIGGER));
    }

    /**
     * A genuine reverse. Dropping the trigger restores the state step 5 left:
     * the column and its unique still exist, and an insert may again omit the
     * key — which is the state `CreatePurchaseOrder` must not ship into, and is
     * exactly what the trigger exists to end.
     */
    public function down(Schema $schema): void
    {
        $this->addSql(sprintf('DROP TRIGGER IF EXISTS %s ON %s', self::TRIGGER, self::TABLE));
        $this->addSql(sprintf('DROP FUNCTION IF EXISTS %s()', self::TRIGGER));

        // Back to Version20260906000100's wording. It will fail if any cancelled
        // draft exists, and that is correct rather than inconvenient: those rows
        // are exactly the ones the narrower constraint forbids, and reversing
        // past them silently would leave the table holding rows its own CHECK
        // rejects.
        $this->addSql(sprintf('ALTER TABLE %s DROP CONSTRAINT IF EXISTS %s', self::TABLE, self::ORDERED_AT_SET));
        $this->addSql(sprintf(
            "ALTER TABLE %s ADD CONSTRAINT %s CHECK (status = 'draft' OR ordered_at IS NOT NULL)",
            self::TABLE,
            self::ORDERED_AT_SET,
        ));
    }
}
