<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * **Sales step 5, commit 2 — the retry key `CreateFulfilment` never had, and the
 * column that says who shipped.**
 *
 * `API.md` §4.2 marked `CreateFulfilment` `⛔ none` and E9-2 named it: a retried
 * timeout would post a second fulfilment and take the stock out twice, partial
 * fulfilment is legal so no status precondition refuses it, and the table's only
 * non-surrogate unique is `fulfilment_number_unique` on the **server-minted**
 * `fulfilment_number`. The mechanism is the one `goods_receipt` carries
 * (`Version20260911000400`), and that file — not `Version20260916000200`
 * (`purchase_order_line`) — is the precedent, for three facts about this table
 * (design §7, attacked by `RED` R-6 and upheld):
 *
 *   - **no history:** no `INSERT INTO fulfilment` and no `new Fulfilment(` exists in
 *     `src/`, `tests/` or `migrations/` (measured), and `erp_app` has held SELECT
 *     only since `Version20260916000300`; `count(*)` is 0 on every database this
 *     project has run;
 *   - **one row per request:** a fulfilment's lines carry no key, as a goods
 *     receipt's do not, so no N rows share one `Idempotency-Key`;
 *   - **no `deleted_at`:** the table is class `none`, so the unique is **TOTAL**, and
 *     `IdempotencyContractTest::everyUniqueIndexIsLiveRowPartialExactlyWhenItsTableSoftDeletes`
 *     demands exactly that.
 *
 * So `idempotency_key` and `request_digest` are plain `NOT NULL`, each CHECKed to
 * 64 lower-case hex, on the total `fulfilment_idem_unique (tenant_id,
 * idempotency_key)`. With both columns `NOT NULL` a both-or-neither CHECK is implied
 * and would add nothing.
 *
 * ---------------------------------------------------------------------------
 * `shipped_by_user_id` — NOT NULL, owner ruling R6 of 2026-09-22 (V2-R-23)
 * ---------------------------------------------------------------------------
 * Who declared the goods shipped: the authenticated caller at write time, never a
 * request field. **NOT NULL where `goods_receipt.received_by_user_id` is nullable**,
 * because a fulfilment is the event SoD-4 exists to attribute and there is no
 * `fulfilment.*` audit event (B-4a), so this column is the only attribution — G-9's
 * lesson one module over. **The owner accepted the trade:** a future non-human
 * writer (a carrier webhook) needs a user to attribute its fulfilment to, or a
 * migration first. Exposed as `shipped_by = {id, display_name}` under PC-11 (4a),
 * as evidence for SoD-4, not enforcement (`SECURITY_MODEL.md` PC-11), when the
 * read ships. `fulfilment_shipper_fk` is composite, like every tenant-scoped
 * foreign key here (A1.6).
 *
 * **If a database does hold fulfilments** (none can: nothing has ever been able to
 * write one), `ADD COLUMN … NOT NULL` fails on the first row. That is the right
 * outcome, for `Version20260911000400`'s reason: no value this file could invent
 * for such a row would be true.
 *
 * ---------------------------------------------------------------------------
 * Grants: none
 * ---------------------------------------------------------------------------
 * A new column inherits the table-level ACL (M-1, measured on other tables on
 * 2026-09-19 and 2026-09-21, and re-measured for this one in this commit with a
 * SELECT=false positive control), so the columns arrive readable and not
 * writable. `erp_app` holds SELECT on `fulfilment` and nothing else until the
 * commit that ships `CreateFulfilment`'s writer grants the INSERT it issues
 * (ADR-002 A4.1). `erp_ai` reads the hash and the digest by inheritance, the
 * state API-O-32 accepted for `purchase_order`.
 *
 * Locks: `ADD COLUMN` with no default is catalogue-only; the CHECKs, the unique
 * and the foreign key scan an empty table.
 */
final class Version20260922000100 extends AbstractMigration
{
    private const TABLE = 'fulfilment';

    private const KEY = 'idempotency_key';

    private const DIGEST = 'request_digest';

    private const SHIPPER = 'shipped_by_user_id';

    private const KEY_SHAPE = 'fulfilment_idempotency_key_sha256';

    private const DIGEST_SHAPE = 'fulfilment_request_digest_sha256';

    private const UNIQUE = 'fulfilment_idem_unique';

    private const SHIPPER_FK = 'fulfilment_shipper_fk';

    public function getDescription(): string
    {
        return 'Sales step 5 C2: fulfilment.idempotency_key + request_digest (NOT NULL, total unique) and shipped_by_user_id NOT NULL';
    }

    public function up(Schema $schema): void
    {
        foreach ([self::KEY, self::DIGEST] as $column) {
            $this->addSql(sprintf('ALTER TABLE %s ADD COLUMN %s text NOT NULL', self::TABLE, $column));
        }
        $this->addSql(sprintf('ALTER TABLE %s ADD COLUMN %s uuid NOT NULL', self::TABLE, self::SHIPPER));

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

        $this->addSql(sprintf(
            'ALTER TABLE %s ADD CONSTRAINT %s FOREIGN KEY (tenant_id, %s) REFERENCES app_user (tenant_id, id)',
            self::TABLE,
            self::SHIPPER_FK,
            self::SHIPPER,
        ));
    }

    /**
     * A genuine reverse. Without the key there is no mechanism, so
     * `CreateFulfilment`'s §4.2 cell goes back to `⛔ none` and E9-2 names it again.
     */
    public function down(Schema $schema): void
    {
        foreach ([self::SHIPPER_FK, self::UNIQUE, self::DIGEST_SHAPE, self::KEY_SHAPE] as $constraint) {
            $this->addSql(sprintf('ALTER TABLE %s DROP CONSTRAINT IF EXISTS %s', self::TABLE, $constraint));
        }

        foreach ([self::SHIPPER, self::DIGEST, self::KEY] as $column) {
            $this->addSql(sprintf('ALTER TABLE %s DROP COLUMN IF EXISTS %s', self::TABLE, $column));
        }
    }
}
