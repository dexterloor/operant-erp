<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * **V2-R-12: the retry key `CreateSalesOrderLine` never had** (Output 9, sales slice 4, commit 1, 2026-09-21).
 *
 * `CreateSalesOrderLine` mints a new id and `MAX(line_number) + 1` on every request, and the table's only unique is
 * `sales_order_line_unique (tenant_id, sales_order_id, line_number) WHERE deleted_at IS NULL`. A retried timeout would
 * therefore write a second line and raise the order's total — the revenue-side twin of the defect `Version20260916000200`
 * closed on `purchase_order_line`. This lays the same already-ruled mechanism (API-O-31 domain-column key, D-B hash +
 * digest) on the sales line table:
 *
 *   - `idempotency_key` — lower-case hex SHA-256 of the `Idempotency-Key` header (`IdempotencyKeyHash`), never the raw value;
 *   - `request_digest` — `RequestDigest::of('CreateSalesOrderLine', …)`, which includes the path's order id;
 *   - both hex-64 or NULL, and both-or-neither;
 *   - `sales_order_line_tenant_idem_live (tenant_id, idempotency_key) WHERE deleted_at IS NULL`.
 *
 * ---------------------------------------------------------------------------------------------------
 * ⚠ **The worked example is `Version20260916000200` (`purchase_order_line`), NOT `Version20260919000100`
 * (`sales_order`), and the difference is the columns' nullability — which is FORCED, not preferred.**
 * ---------------------------------------------------------------------------------------------------
 * `sales_order`'s key could be `NOT NULL` on a stated premise: *"nothing in `src/` has ever written a `sales_order` row."*
 * That premise is **false for `sales_order_line` and stays false forever forward.**
 * `SalesOrderWriter::insertLineInside()` has written this table since `c761ba8` as `CreateSalesOrder`'s nested `lines[]`,
 * and **N lines of one request share ONE `Idempotency-Key`**: the order holds that request's key, so under `NOT NULL`
 * plus `(tenant_id, idempotency_key)` the second line of every multi-line create would collide with the first. The
 * columns are therefore nullable and the **paired** CHECK carries what `NOT NULL` said one table over.
 *
 * **No required-key trigger — deliberately, and for the same reason as the purchase twin.** A row trigger cannot tell the
 * nested path from the single-line path, and the nested path legitimately writes NULL. The control that a single-line
 * create writes the key is the writer plus its idempotency test, not a trigger nothing scans for.
 *
 * **The unique is tenant-wide, not per order.** `IdempotencyContractTest` admits a header-sourced key only on an index
 * whose whole key less `tenant_id` is `idempotency_key`: a header supplies one value. The same key sent to another order
 * is refused by the digest (the path id is in it) or, in a race, by this index — a 409 either way, never a replay of
 * another order's line. **Partial** on `deleted_at IS NULL` because `SchemaManifest` classes this table `SOFT` and
 * `everyUniqueIndexIsLiveRowPartialExactlyWhenItsTableSoftDeletes` derives that requirement from the manifest in both
 * directions. Led by `tenant_id` per ADR-002 §5, asserted catalogue-wide by `SchemaGuardTest`.
 *
 * ⚠ **What this index creates, named here because it is a real hazard and not a theoretical one (V2-R-12b's sales twin).**
 * The partiality that lets an archived line release its key also lets a live line take it: delete line L (key K), create
 * L2 with key K, then restore. **Measured** on a replica of this exact index: the restore raises `23505`. There are **two**
 * operations that can raise it — `RestoreSalesOrderLine`, which owns its statement, and `RestoreSalesOrder`, whose
 * `sales_order_cascade_deletion` trigger sets **every** line live in one statement it did not write. The first is built in
 * this slice's commit 5 and translates the violation by name; the second is `RestoreSalesOrder`'s (step 4) and is handed
 * to it rather than left to be discovered by it.
 *
 * **Existing rows** keep NULL in both columns and satisfy every CHECK. NULL keys never collide — measured: three live
 * NULL-keyed rows in one tenant are all accepted. **Grants: none** — the table's grants are table-level, so the new
 * columns inherit them (M-1, re-measured against this cluster with a `SELECT = false` positive control).
 *
 * **Locks:** `ADD COLUMN` without a default and `ADD CONSTRAINT … CHECK` take ACCESS EXCLUSIVE briefly (the CHECKs scan
 * the table); the index is built non-concurrently inside the migration transaction. Nothing is deployed and no client exists.
 */
final class Version20260921000100 extends AbstractMigration
{
    private const TABLE = 'sales_order_line';

    private const KEY = 'idempotency_key';

    private const DIGEST = 'request_digest';

    private const KEY_SHAPE = 'sales_order_line_idempotency_key_sha256';

    private const DIGEST_SHAPE = 'sales_order_line_request_digest_sha256';

    private const PAIRED = 'sales_order_line_request_digest_with_key';

    private const INDEX = 'sales_order_line_tenant_idem_live';

    public function getDescription(): string
    {
        return 'V2-R-12: sales_order_line.idempotency_key + request_digest and the live-row unique sales_order_line_tenant_idem_live';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(sprintf('ALTER TABLE %s ADD COLUMN %s text, ADD COLUMN %s text', self::TABLE, self::KEY, self::DIGEST));

        foreach ([self::KEY_SHAPE => self::KEY, self::DIGEST_SHAPE => self::DIGEST] as $constraint => $column) {
            $this->addSql(sprintf(
                "ALTER TABLE %s ADD CONSTRAINT %s CHECK (%s IS NULL OR %s ~ '^[0-9a-f]{64}$')",
                self::TABLE,
                $constraint,
                $column,
                $column,
            ));
        }

        // The both-or-neither CHECK `Version20260919000100` omitted, because there `NOT NULL` said it. Here the columns
        // are nullable, so this is the only thing keeping a digest without its key — or a key without its digest — out.
        $this->addSql(sprintf(
            'ALTER TABLE %s ADD CONSTRAINT %s CHECK ((%s IS NULL) = (%s IS NULL))',
            self::TABLE,
            self::PAIRED,
            self::KEY,
            self::DIGEST,
        ));

        $this->addSql(sprintf(
            'CREATE UNIQUE INDEX %s ON %s (tenant_id, %s) WHERE deleted_at IS NULL',
            self::INDEX,
            self::TABLE,
            self::KEY,
        ));
    }

    /**
     * A genuine reverse. Without these columns `CreateSalesOrderLine`'s §4.2 `Idem` cell goes back to `⛔ none` and
     * `DELIVERY_LEDGER.md` E9-2's condition cell names it again — nine operations, not eight.
     */
    public function down(Schema $schema): void
    {
        $this->addSql(sprintf('DROP INDEX IF EXISTS %s', self::INDEX));

        foreach ([self::PAIRED, self::DIGEST_SHAPE, self::KEY_SHAPE] as $constraint) {
            $this->addSql(sprintf('ALTER TABLE %s DROP CONSTRAINT IF EXISTS %s', self::TABLE, $constraint));
        }

        $this->addSql(sprintf('ALTER TABLE %s DROP COLUMN IF EXISTS %s, DROP COLUMN IF EXISTS %s', self::TABLE, self::DIGEST, self::KEY));
    }
}
