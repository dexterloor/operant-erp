<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * **API-O-31 step 5 — the retry key `CreatePurchaseOrder` never had.**
 *
 * ---------------------------------------------------------------------------
 * What is actually missing, stated as the defect rather than as a feature
 * ---------------------------------------------------------------------------
 * `API.md` §4.2 marked `CreatePurchaseOrder` idempotent and **nothing refused
 * its repeat.** The reclassification pass of 2026-09-11 (API-14 as amended) made
 * that sayable for the first time: the cell read `⛔ none`, and the reason is
 * exact. The table's only live-row unique is
 * `purchase_order_tenant_number_live ON purchase_order (tenant_id, order_number)`
 * and **`order_number` is server-generated** — it is not in the operation's
 * accepted-field set, so the caller sends nothing the index can collide on. A
 * client that retried a timed-out create got a **second purchase order**, and it
 * got it through a green suite.
 *
 * This migration lays the column that index needs to be about.
 *
 * ---------------------------------------------------------------------------
 * Why a domain column and not the `idempotency_key` table
 * ---------------------------------------------------------------------------
 * API-O-31, after two adversarial rounds. The envelope store is scoped to the
 * **three bulk operations** and cannot serve this one: §2.2 serializes the
 * response *after* commit, and a create's response carries a server-minted `id`
 * and a `Location` that do not exist until the insert has happened — so the
 * row would have to be claimed on the way in and filled on the way out, which
 * needs the `UPDATE` §6.1 deliberately withholds.
 *
 * The client's key becomes **a column on the row it creates**, and a retry then
 * collides at the database exactly as `CreateItem` collides on `sku`. No second
 * copy of business data, no expiry, no purge job, and no conflict with §2.2.
 *
 * ---------------------------------------------------------------------------
 * `text` + `octet_length`, NOT `varchar(255)` — the reasoning is
 * `Version20260910000200`'s and is matched rather than re-invented
 * ---------------------------------------------------------------------------
 * §6.2 caps the key at **255 bytes**. `varchar(n)` counts **characters**, so a
 * 255-character multibyte key would be accepted by a `varchar(255)` column and
 * refused by `IdempotencyKeyGuard::MAXIMUM_KEY_BYTES` — two limits disagreeing
 * about what they measure, which is the defect that constant already exists to
 * close on the store side. The lower bound is 1 because the guard treats the
 * empty string as a **missing** header, and a column that accepts what the guard
 * refuses is the same gap facing the other way.
 *
 * `idempotency_key.key` is bounded `BETWEEN 1 AND 255` by
 * `idempotency_key_key_bytes`. This column is bounded identically, with one
 * added disjunct for NULL — see below.
 *
 * ---------------------------------------------------------------------------
 * ⚠ The nullability is a statement about HISTORY, and it is deferred BY NAME
 * ---------------------------------------------------------------------------
 * The column is nullable because rows that predate this migration have no key
 * and never will. That is the only reason. **Once `CreatePurchaseOrder` demands
 * the header — which it now does, its §4.2 cell having become
 * `unique:purchase_order_tenant_idem_live` — every new row carries one**, so
 * "nullable" stops describing the product's main path and starts describing a
 * closed set of old rows.
 *
 * **That is the exact shape ADR-008 criticised once already** (ADR-008:231): a
 * NULL justified as *"a fixture argument for a column the product writes NULL to
 * on its main automated path"*, with the ADR's own correction attached — *"the
 * migration cannot tell you why a column is nullable and the entity can"*. So it
 * is not left to be inferred from the DDL: `PurchaseOrder::$idempotencyKey`
 * carries the reason in a docblock, and this paragraph carries it here.
 *
 * **Is a `CHECK` requiring non-null for post-migration rows owed? Yes — and it
 * is NOT installed here, for a reason that is a fact about the mechanism rather
 * than a preference.**
 *
 *   - **A plain `CHECK (idempotency_key IS NOT NULL)` is wrong**: it applies to
 *     every row, including the historical ones this column is nullable *for*,
 *     so it cannot be validated and would refuse every `UPDATE` of an old order.
 *   - **`ADD CONSTRAINT … NOT VALID` is the obvious near-miss and is also
 *     wrong.** It exempts existing rows at add time, which is the right half —
 *     but it still enforces on any later `UPDATE` of one of those rows, so
 *     editing a pre-migration purchase order would start failing. `NOT VALID`
 *     exempts rows from the *scan*, never from subsequent writes.
 *   - **What the demand actually needs is a `BEFORE INSERT` trigger** — it can
 *     discriminate the one event that must carry a key (an insert) from the one
 *     that must not be forced to (an update of a historical row), which is a
 *     distinction no `CHECK` can express.
 *
 * **It is deferred to step 6 and named:** `purchase_order_idempotency_key_required`,
 * a `BEFORE INSERT` trigger, to be installed by the migration that ships
 * `CreatePurchaseOrder` itself. Installing it **today** would refuse every
 * insert the suite and the product currently make, because **nothing writes the
 * column yet** — this migration lays a mechanism, and step 6 is what makes it
 * bite. A trigger that refuses every live write in service of an operation that
 * does not exist is not a control; it is an outage.
 *
 * **The residual, named rather than solved: a column that exists is not a column
 * that deduplicates.** This is `Version20260910000200`'s own lesson arriving one
 * table over, and it is recorded here rather than left for the next reader to
 * find. The index below refuses a duplicate **only for rows whose
 * `idempotency_key` is actually written**, and no code writes it: there is no
 * `CreatePurchaseOrder` operation, and `PurchaseOrder` deliberately exposes no
 * setter (a writer with no caller is the defect `Version20260910000200`'s
 * docblock closes on — *"nothing that has no consumer"*). Two NULL keys do not
 * collide, because Postgres treats NULLs as distinct in a unique index — which
 * is precisely what lets the historical rows coexist, and precisely what makes
 * this mechanism inert until step 6 supplies the value.
 *
 * ---------------------------------------------------------------------------
 * Grants: none are owed, and that was VERIFIED rather than assumed
 * ---------------------------------------------------------------------------
 * `purchase_order` is an existing table with an existing role footprint, so the
 * claim is that a new column inherits it. That is true **only** if the grants on
 * this table are table-level; a column-level grant enumerates its columns and
 * would silently exclude a new one.
 *
 * Measured against the live catalogue on 2026-09-11, before this file was
 * written:
 *
 *     SELECT relacl FROM pg_class WHERE relname = 'purchase_order';
 *       -> {neondb_owner=arwdDxtm/neondb_owner,erp_app=arwd/neondb_owner,erp_ai=r/neondb_owner}
 *
 *     SELECT attname, attacl FROM pg_attribute
 *      WHERE attrelid = 'purchase_order'::regclass AND attnum > 0 AND attacl IS NOT NULL;
 *       -> (0 rows)
 *
 * Every privilege on this table is held at table level and **no column carries
 * an ACL of its own**, so `erp_app`'s `arwd` and `erp_ai`'s `r` cover this
 * column from the moment it exists. No `GRANT` is issued here, and
 * `GrantFootprintTest::everyRoleHoldsExactlyTheTablePrivilegesTheManifestDeclares`
 * — which is bidirectional — is what would fail if that reasoning were wrong.
 * `::everyColumnLevelPrivilegeIsExactlyTheDeclaredSet` is the check that would
 * fail if a column ACL appeared here later.
 *
 * ---------------------------------------------------------------------------
 * Locks
 * ---------------------------------------------------------------------------
 * `ADD COLUMN` that is **nullable with no default** is a catalogue-only change:
 * no table rewrite, `ACCESS EXCLUSIVE` held only for the catalogue update. The
 * `CHECK` is added in the same statement, so it is verified against the column
 * as it is created rather than scanning afterwards. `CREATE UNIQUE INDEX`
 * (non-concurrent) takes `SHARE`, which blocks writes for the build.
 *
 * **Not re-measured against `pg_locks` on this pass** — `Version20260907000200`
 * did that for the operations it used, and these are the documented levels
 * rather than observed ones. Stated so the difference is visible: this is the
 * one claim in this file that is read rather than measured. At this table's size
 * the build is milliseconds either way, and `CONCURRENTLY` is not used because
 * it cannot run inside the migration's transaction.
 *
 * ---------------------------------------------------------------------------
 * What this migration is NOT authorised to do
 * ---------------------------------------------------------------------------
 *   - **No `CreatePurchaseOrder`.** Step 6 owns the operation, its processor and
 *     its collision handling (a `23505` on this index is a *replay*, and must be
 *     answered as one rather than as a 500). This file lays the mechanism that
 *     operation will consume.
 *   - **No column for `CreateSalesOrder`, `CreateCustomerContact` or
 *     `CreateCustomerAddress`.** Their operations do not exist, and a column
 *     with no consumer is exactly what `Version20260910000200`'s docblock
 *     refuses. All three stay named in E9-2.
 *   - **No ruling on `IssueInvoice`.** Step 4 found it unprotected while §6.1
 *     listed it as safe. Whether one sales order may carry two invoices is a
 *     **product** decision and it is not `DATA`'s. It stays named in E9-2.
 *   - **No grant, no policy, no RLS statement.** The table already has all
 *     three; a column changes none of them.
 *
 * @see docs/API.md §6.1, §6.2 — the mechanism vocabulary and the 255-byte cap
 * @see docs/API.md §14.1 API-O-31 — the ruling this builds the fifth step of
 * @see docs/DELIVERY_LEDGER.md E9-2 — the gate `CreatePurchaseOrder` comes off
 * @see docs/adr/ADR-008-separation-of-duty-and-role-expansion.md:231 — the nullable-column criticism
 */
final class Version20260911000100 extends AbstractMigration
{
    private const TABLE = 'purchase_order';

    private const COLUMN = 'idempotency_key';

    private const INDEX = 'purchase_order_tenant_idem_live';

    private const BYTES = 'purchase_order_idempotency_key_bytes';

    public function getDescription(): string
    {
        return 'API-O-31 step 5: purchase_order.idempotency_key — the client-supplied retry key CreatePurchaseOrder had none of';
    }

    public function up(Schema $schema): void
    {
        // Nullable, no default: a catalogue-only change, and the nullability is
        // about rows that predate this file. See the class docblock for why the
        // non-null demand is a trigger step 6 installs and not a CHECK here.
        $this->addSql(sprintf(
            'ALTER TABLE %s ADD COLUMN %s text',
            self::TABLE,
            self::COLUMN,
        ));

        // §6.2's cap is 255 OCTETS. `varchar(255)` would count characters and
        // disagree with IdempotencyKeyGuard::MAXIMUM_KEY_BYTES on any multibyte
        // key; the lower bound is 1 because the guard treats '' as absent.
        // The NULL disjunct is the history exemption and nothing more.
        $this->addSql(sprintf(
            'ALTER TABLE %s ADD CONSTRAINT %s CHECK (%s IS NULL OR octet_length(%s) BETWEEN 1 AND 255)',
            self::TABLE,
            self::BYTES,
            self::COLUMN,
            self::COLUMN,
        ));

        // Partial on `deleted_at IS NULL` because `SchemaManifest` classes this
        // table `soft` -- ADR-007 §1: an archived row must not keep occupying a
        // unique, or re-creating it fails. `SchemaManifestTest::businessUniquenessIsPartialOnSoftDeletedTablesAndTotalElsewhere`
        // derives that requirement from the manifest and would fail on a total one.
        //
        // Led by tenant_id (ADR-002 §5), which SchemaGuardTest::noTenantScopedTableHasAUniqueIndexThatDoesNotLeadWithTenantId
        // asserts over the whole catalogue.
        $this->addSql(sprintf(
            'CREATE UNIQUE INDEX %s ON %s (tenant_id, %s) WHERE deleted_at IS NULL',
            self::INDEX,
            self::TABLE,
            self::COLUMN,
        ));
    }

    /**
     * The reverse, in dependency order: the index is on the column, and the
     * constraint is about the column, so both go before it.
     *
     * This is a genuine reverse rather than a no-op. Dropping the column loses
     * the recorded keys, and that is correct: without the column there is no
     * mechanism, and `CreatePurchaseOrder`'s cell must go back to `⛔ none` with
     * E9-2 naming it again. A `down()` that kept the data while removing the
     * constraint would leave a column that looks like a retry key and refuses
     * nothing, which is the state this whole step exists to end.
     */
    public function down(Schema $schema): void
    {
        $this->addSql(sprintf('DROP INDEX IF EXISTS %s', self::INDEX));
        $this->addSql(sprintf('ALTER TABLE %s DROP CONSTRAINT IF EXISTS %s', self::TABLE, self::BYTES));
        $this->addSql(sprintf('ALTER TABLE %s DROP COLUMN IF EXISTS %s', self::TABLE, self::COLUMN));
    }
}
