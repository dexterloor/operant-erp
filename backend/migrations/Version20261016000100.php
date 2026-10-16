<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * **The stock count's retry key, laid before its writer** — stock count C0, 2026-10-16
 * (`docs/session13/stock-adjustment-design.md` §3 as amended by Revision 1 R1.5; owner ruling
 * `stock-adjustment-ruling.md`). `CreateStockAdjustment` (C1) writes one `stock_movement` row per request and demands the
 * `Idempotency-Key` header; this commit gives that row somewhere to keep it.
 *
 * - **Nullable**, with a both-or-neither CHECK — the `adjustment_decision` form (`Version20261005000400`), never
 *   `NOT NULL`: `stock_movement` already holds rows on any database that has received, shipped or returned anything
 *   (`StockReceiver`, `StockDepleter`, `StockReturner` write it), and `ADD COLUMN … NOT NULL` would fail on the first one
 *   (the `85c08fb` warning). The three shipped writers name neither column, so their rows keep both NULL.
 * - Two shape CHECKs: each column, when set, is 64 lower-case hex — the hash of the header (D-B), never its value.
 * - **A TOTAL unique** `stock_movement_idem_unique (tenant_id, idempotency_key)`: the table is class `none` (no
 *   `deleted_at`), and `IdempotencyContractTest::everyUniqueIndexIsLiveRowPartialExactlyWhenItsTableSoftDeletes` demands a
 *   total unique there (the `goods_receipt` ground). NULLs never collide under the default `NULLS DISTINCT`, so the other
 *   writers' rows are untouched by it.
 * - **Grants nothing** (M-1): `erp_app` (SELECT, INSERT) and `erp_ai` (SELECT) hold table-level grants on
 *   `stock_movement`, which the new columns inherit; there is no UPDATE for anyone, so a stored key can never be moved.
 *
 * Forward note for `CreateStockTransfer` (not built): a transfer is two rows per request; under this unique it puts the
 * key on ONE leg only.
 */
final class Version20261016000100 extends AbstractMigration
{
    private const TABLE = 'stock_movement';

    private const KEY_SHAPE = 'stock_movement_idempotency_key_sha256';

    private const DIGEST_SHAPE = 'stock_movement_request_digest_sha256';

    private const PAIRED = 'stock_movement_key_both_or_neither';

    private const UNIQUE = 'stock_movement_idem_unique';

    public function getDescription(): string
    {
        return 'Stock count C0: stock_movement.idempotency_key + request_digest (nullable, hex, both-or-neither) and the total unique stock_movement_idem_unique; no grant.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE stock_movement ADD COLUMN idempotency_key text, ADD COLUMN request_digest text');

        foreach ([self::KEY_SHAPE => 'idempotency_key', self::DIGEST_SHAPE => 'request_digest'] as $constraint => $column) {
            $this->addSql(\sprintf("ALTER TABLE %s ADD CONSTRAINT %s CHECK (%s IS NULL OR %s ~ '^[0-9a-f]{64}$')",
                self::TABLE, $constraint, $column, $column));
        }

        // Nullable columns, so nothing else keeps a digest without its key, or a key without its digest, out.
        $this->addSql(\sprintf('ALTER TABLE %s ADD CONSTRAINT %s CHECK ((idempotency_key IS NULL) = (request_digest IS NULL))',
            self::TABLE, self::PAIRED));

        $this->addSql(\sprintf('ALTER TABLE %s ADD CONSTRAINT %s UNIQUE (tenant_id, idempotency_key)', self::TABLE, self::UNIQUE));
    }

    /**
     * A genuine reverse. Without these columns `CreateStockAdjustment`'s §4.2 `Idem` cell goes back to `⛔ none` and
     * `DELIVERY_LEDGER.md` E9-2's condition cell names it again.
     */
    public function down(Schema $schema): void
    {
        foreach ([self::UNIQUE, self::PAIRED, self::DIGEST_SHAPE, self::KEY_SHAPE] as $constraint) {
            $this->addSql(\sprintf('ALTER TABLE %s DROP CONSTRAINT IF EXISTS %s', self::TABLE, $constraint));
        }

        $this->addSql('ALTER TABLE stock_movement DROP COLUMN IF EXISTS request_digest, DROP COLUMN IF EXISTS idempotency_key');
    }
}
