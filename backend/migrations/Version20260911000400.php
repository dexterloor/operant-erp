<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * **Step 8-i — the retry key `CreateGoodsReceipt` never had.**
 *
 * `API.md` §4.2 marked `CreateGoodsReceipt` `⛔ none` and E9-2 named it: a
 * retried timeout posts a second receipt and a second set of stock movements,
 * partial receipt is legal so no status precondition refuses it, and the
 * table's only non-surrogate unique is `goods_receipt_number_unique` on the
 * **server-minted** `receipt_number`. The mechanism was ruled before this file:
 * step 5's domain-column key (`Version20260911000100`), in the shape D-B left
 * it in (`Version20260911000300`):
 *
 *   - `idempotency_key` — `IdempotencyKeyHash::of()` of the `Idempotency-Key`
 *     header, lower-case hex SHA-256, never the header itself;
 *   - `request_digest` — `RequestDigest::of()` of the request that created the
 *     row. Same key and same digest is a replay, answered with the receipt; same
 *     key and a different digest is `idempotency-key-conflict` (409).
 *
 * ---------------------------------------------------------------------------
 * NOT NULL, and that is the one place this departs from `purchase_order`
 * ---------------------------------------------------------------------------
 * `purchase_order.idempotency_key` is nullable, and step 5 said why: rows
 * existed before it. Its non-null demand then had to be a `BEFORE INSERT`
 * trigger (`Version20260911000200`), because a CHECK would refuse every update
 * of a historical row.
 *
 * **`goods_receipt` has no history.** No code has ever written a row: there is
 * no `INSERT INTO goods_receipt` and no `new GoodsReceipt(` anywhere in `src/`
 * or `tests/` at `157d299`, and `CreateGoodsReceipt` ships in the commit after
 * this one. A nullable column plus a trigger would describe a population that
 * does not exist. So both columns are plain `NOT NULL`, which says the same
 * thing in one clause and needs no function, no grant and no `WHEN`. An
 * integration or a scanner that posts receipts later (the entity allows a NULL
 * `received_by_user_id` for them) mints its own key, as the reorder agent must
 * for `purchase_order`.
 *
 * **If a database does hold receipts** (none has been deployed), `ADD COLUMN …
 * NOT NULL` fails on the first row. That is the right outcome: such rows have
 * no request to digest, and no value this file could invent for them would be
 * true.
 *
 * ---------------------------------------------------------------------------
 * A TOTAL unique, because the table has no `deleted_at`
 * ---------------------------------------------------------------------------
 * `SchemaManifest` classes `goods_receipt` `none`: a receipt is corrected by a
 * further receipt, never deleted, so there is no archived row that must stop
 * occupying the key. `purchase_order_tenant_idem_live` is partial on
 * `deleted_at IS NULL` because its table soft-deletes. This one is a plain
 * `UNIQUE (tenant_id, idempotency_key)` constraint, in the style of
 * `goods_receipt_number_unique` beside it.
 * `IdempotencyContractTest::everyUniqueIndexIsLiveRowPartialExactlyWhenItsTableSoftDeletes`
 * derives that requirement from the manifest, and would fail on a partial one.
 *
 * ---------------------------------------------------------------------------
 * Grants: none
 * ---------------------------------------------------------------------------
 * `goods_receipt`'s grants are table-level (`SchemaManifest`: `erp_app`
 * SELECT, INSERT, UPDATE; `erp_ai` SELECT), so both columns inherit them.
 * `erp_ai` therefore reads a hash and a digest. That is the state API-O-32
 * accepted for `purchase_order`: neither value can be sent back as a key.
 * `GrantFootprintTest` is what fails if a column ACL appears.
 *
 * Locks: `ADD COLUMN` with no default is catalogue-only. The CHECKs and the
 * unique scan an empty table.
 */
final class Version20260911000400 extends AbstractMigration
{
    private const TABLE = 'goods_receipt';

    private const KEY = 'idempotency_key';

    private const DIGEST = 'request_digest';

    private const KEY_SHAPE = 'goods_receipt_idempotency_key_sha256';

    private const DIGEST_SHAPE = 'goods_receipt_request_digest_sha256';

    private const UNIQUE = 'goods_receipt_idem_unique';

    public function getDescription(): string
    {
        return 'Step 8-i: goods_receipt.idempotency_key (hex SHA-256 of the header) + request_digest, NOT NULL, unique per tenant';
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

        // Led by tenant_id (ADR-002 §5): SchemaGuardTest asserts it catalogue-wide.
        $this->addSql(sprintf(
            'ALTER TABLE %s ADD CONSTRAINT %s UNIQUE (tenant_id, %s)',
            self::TABLE,
            self::UNIQUE,
            self::KEY,
        ));
    }

    /**
     * A genuine reverse. Without the columns there is no mechanism, so
     * `CreateGoodsReceipt`'s §4.2 cell goes back to `⛔ none` and E9-2 names it
     * again. The operation shipped after this file cannot run without them.
     */
    public function down(Schema $schema): void
    {
        foreach ([self::UNIQUE, self::DIGEST_SHAPE, self::KEY_SHAPE] as $constraint) {
            $this->addSql(sprintf('ALTER TABLE %s DROP CONSTRAINT IF EXISTS %s', self::TABLE, $constraint));
        }

        foreach ([self::DIGEST, self::KEY] as $column) {
            $this->addSql(sprintf('ALTER TABLE %s DROP COLUMN IF EXISTS %s', self::TABLE, $column));
        }
    }
}
