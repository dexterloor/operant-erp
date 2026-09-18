<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * **V2-R-11: the retry key `CreateSalesOrder` never had** (Output 9, sales slice 1, commit 1, 2026-09-19).
 *
 * `API.md` §4.2 marked `CreateSalesOrder` `⛔ none` and E9-2 named it in its condition cell. The reason is
 * exactly `CreatePurchaseOrder`'s: `sales_order`'s only live-row unique is
 * `sales_order_tenant_number_live (tenant_id, order_number)` and `order_number` is **server-minted**, so a
 * retried timeout mints a second sales order — the flagship defect P-5 exists to prevent, this time on the
 * revenue side. `IdempotencyKeyGuard` requires the header and discards it; it deduplicates nothing.
 *
 * The mechanism was ruled long before this file: API-O-31's domain-column key in the shape D-B left it in.
 * This lays it on `sales_order`:
 *
 *   - `idempotency_key` — `IdempotencyKeyHash::of()` of the `Idempotency-Key` header, lower-case hex
 *     SHA-256, never the header itself;
 *   - `request_digest` — `RequestDigest::of('CreateSalesOrder', …)`. Same key and same digest is a replay,
 *     answered with the order; same key and a different digest is `idempotency-key-conflict` (409).
 *
 * ---------------------------------------------------------------------------
 * NOT NULL, and this is the one place it departs from `purchase_order`
 * ---------------------------------------------------------------------------
 * `purchase_order.idempotency_key` is nullable, and step 5 said why: rows existed before it, so the
 * non-null demand had to become a `BEFORE INSERT` trigger (`Version20260911000200`) — a CHECK would have
 * refused every update of a historical row.
 *
 * **`sales_order` has no such history.** Nothing in `src/` has ever written one: there is no
 * `INSERT INTO sales_order` and no `new SalesOrder(` under `backend/src/` at `90162a6`, and
 * `PurchaseOrderRowLockTest::everyMethodThatWritesASalesOrderIsCoveredByALockFirstCheck()` asserts
 * exactly that and is green — it was named `noMethodInSrcWritesASalesOrderYet()` when this migration
 * shipped, and `CreateSalesOrder` renamed it in the next commit because the set is no longer empty. The
 * matching precedent is therefore `Version20260911000400` (`goods_receipt`), not `Version20260911000100`:
 * *"A nullable column plus a trigger would describe a population that does not exist."* Both columns are
 * plain `NOT NULL`, which says the same thing in one clause and needs no function, no grant and no `WHEN`.
 *
 * Four consequences, and each removes something rather than adding it:
 *
 *   1. `Version20260916000200`'s paired `CHECK ((key IS NULL) = (digest IS NULL))` is **not** written here —
 *      `NOT NULL` on both columns already says it, and a CHECK that can never fail is a check that proves
 *      nothing;
 *   2. the deferred `sales_order_idempotency_key_required` trigger goes **entirely**. It was to be carried
 *      by one sentence in a design document, and nothing in `backend/tests/` scans `pg_trigger` for such a
 *      trigger — measured, not assumed. A promise nothing can enforce is not a mechanism;
 *   3. the partial unique becomes a real constraint on **every** live row. Under nullable columns two NULL
 *      keys do not collide, so the index would have constrained only the rows that already carried a key;
 *   4. the two fixtures that insert `sales_order` today (`DeletionPolicyTest`, `SchemaQueryPlanTest`) supply
 *      both values in this same commit, where they owe nothing.
 *
 * **If a database did hold sales orders**, `ADD COLUMN … NOT NULL` fails on the first row. That is the right
 * outcome, and it is the outcome `Version20260911000400` chose deliberately: such rows have no request to
 * digest and no value this file could invent for them would be true. Nothing is deployed.
 *
 * ---------------------------------------------------------------------------
 * A PARTIAL unique, because the table soft-deletes
 * ---------------------------------------------------------------------------
 * `SchemaManifest` classes `sales_order` `soft`, so a discarded draft must stop occupying its key — the
 * opposite of `goods_receipt`, which is class `none` and takes a total `UNIQUE` constraint.
 * `IdempotencyContractTest::everyUniqueIndexIsLiveRowPartialExactlyWhenItsTableSoftDeletes` derives that
 * requirement from the manifest and is red on the wrong one, in both directions. Led by `tenant_id`
 * (ADR-002 §5), which `SchemaGuardTest::noTenantScopedTableHasAUniqueIndexThatDoesNotLeadWithTenantId`
 * asserts catalogue-wide.
 *
 * **Tenant-wide, not per customer or per order.** `IdempotencyContractTest` admits a header-sourced key only
 * on an index whose whole key less `tenant_id` is `idempotency_key`: a header supplies exactly one value.
 *
 * ---------------------------------------------------------------------------
 * Grants: NONE, and this was measured rather than carried
 * ---------------------------------------------------------------------------
 * `erp_app` holds **SELECT only** on `sales_order` (`SchemaManifest` declares no `grants` key for it, so it
 * falls to `TENANT_READ_DEFAULT`), and this migration widens nothing. The two new columns inherit the
 * table-level ACL: probed on 2026-09-19 against this cluster on a scratch table — `has_column_privilege()`
 * returned true for INSERT and UPDATE on a newly added column under a table-level grant, **false for
 * SELECT, which was never granted at table level**, and `pg_attribute.attacl` on the new column was NULL.
 * The false row is the positive control: a blanket true would have meant the probe measured nothing.
 *
 * So the columns arrive **readable and not writable**, which is correct for a migration whose writer does
 * not exist yet. The `INSERT` grant is owed by the commit that ships `CreateSalesOrder` — ADR-002 A4.1's
 * *"the migration that ships the first caller grants the write and edits `SchemaManifest` in the same
 * commit"*. `GrantFootprintTest::everyColumnLevelPrivilegeIsExactlyTheDeclaredSet` is what fails if a
 * column ACL ever appears here.
 *
 * ---------------------------------------------------------------------------
 * Locks
 * ---------------------------------------------------------------------------
 * `ADD COLUMN … NOT NULL` with no default takes ACCESS EXCLUSIVE briefly and, on PostgreSQL 11+, rewrites
 * nothing; the CHECKs and the index scan a table this deployment has never written a row into.
 */
final class Version20260919000100 extends AbstractMigration
{
    private const TABLE = 'sales_order';

    private const KEY = 'idempotency_key';

    private const DIGEST = 'request_digest';

    private const KEY_SHAPE = 'sales_order_idempotency_key_sha256';

    private const DIGEST_SHAPE = 'sales_order_request_digest_sha256';

    private const INDEX = 'sales_order_tenant_idem_live';

    public function getDescription(): string
    {
        return 'V2-R-11: sales_order.idempotency_key (hex SHA-256 of the header) + request_digest, both NOT NULL, and the live-row unique sales_order_tenant_idem_live';
    }

    public function up(Schema $schema): void
    {
        foreach ([self::KEY, self::DIGEST] as $column) {
            $this->addSql(sprintf('ALTER TABLE %s ADD COLUMN %s text NOT NULL', self::TABLE, $column));
        }

        foreach ([self::KEY_SHAPE => self::KEY, self::DIGEST_SHAPE => self::DIGEST] as $constraint => $column) {
            $this->addSql(sprintf(
                "ALTER TABLE %s ADD CONSTRAINT %s CHECK (%s ~ '^[0-9a-f]{64}$')",
                self::TABLE,
                $constraint,
                $column,
            ));
        }

        $this->addSql(sprintf(
            'CREATE UNIQUE INDEX %s ON %s (tenant_id, %s) WHERE deleted_at IS NULL',
            self::INDEX,
            self::TABLE,
            self::KEY,
        ));
    }

    /**
     * A genuine reverse. Without the columns there is no mechanism, so `CreateSalesOrder`'s §4.2 cell goes
     * back to `⛔ none` and E9-2's condition cell names it again.
     */
    public function down(Schema $schema): void
    {
        $this->addSql(sprintf('DROP INDEX IF EXISTS %s', self::INDEX));

        foreach ([self::DIGEST_SHAPE, self::KEY_SHAPE] as $constraint) {
            $this->addSql(sprintf('ALTER TABLE %s DROP CONSTRAINT IF EXISTS %s', self::TABLE, $constraint));
        }

        foreach ([self::DIGEST, self::KEY] as $column) {
            $this->addSql(sprintf('ALTER TABLE %s DROP COLUMN IF EXISTS %s', self::TABLE, $column));
        }
    }
}
