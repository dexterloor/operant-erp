<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * **V2-R-12: the retry key `CreatePurchaseOrderLine` never had** (V4 batch B3a, standing rule 1, 2026-09-16).
 *
 * The line create minted a new id and `MAX(line_number) + 1` on every request, and the table's only unique is
 * `purchase_order_line_unique (tenant_id, purchase_order_id, line_number) WHERE deleted_at IS NULL`. A retried timeout
 * therefore wrote a second line and raised the order's total. This lays `CreatePurchaseOrder`'s already-ruled mechanism
 * (API-O-31 domain-column key, D-B hash + digest) on the line table:
 *
 *   - `idempotency_key` — lower-case hex SHA-256 of the `Idempotency-Key` header (`IdempotencyKeyHash`), never the raw value;
 *   - `request_digest` — `RequestDigest::of('CreatePurchaseOrderLine', …)`, which includes the path's order id;
 *   - both hex-64 or NULL, and both-or-neither (`Version20260911000300`'s three CHECKs, one table over);
 *   - `purchase_order_line_tenant_idem_live (tenant_id, idempotency_key) WHERE deleted_at IS NULL`.
 *
 * **The unique is tenant-wide, not per order.** `IdempotencyContractTest` admits a header-sourced key only on an index whose
 * whole key less `tenant_id` is `idempotency_key`: a header supplies one value. So the same key sent to another order is
 * refused by the digest (the path id is in it) or, in a race, by this index — a 409 either way, never a replay of another
 * order's line.
 *
 * **No required-key trigger — deliberately unlike `Version20260911000200`.** `CreatePurchaseOrder` inserts its nested lines
 * through the same writer path, and those lines carry NULL: the order holds that request's key, and N lines sharing one key
 * would collide on this unique. A row trigger cannot tell the two paths apart. The writer and
 * `PurchaseOrderLineIdempotencyTest` are the control that a single-line create writes the key.
 *
 * **Existing rows** keep NULL in both columns and satisfy every CHECK. NULL keys never collide. **Grants: none** — the table's
 * grants are table-level, so the new columns inherit them.
 *
 * **Locks:** `ADD COLUMN` without a default and `ADD CONSTRAINT … CHECK` take ACCESS EXCLUSIVE briefly (the CHECKs scan the
 * table); the index is built non-concurrently inside the migration transaction. Nothing is deployed and no client exists.
 */
final class Version20260916000200 extends AbstractMigration
{
    private const TABLE = 'purchase_order_line';

    private const KEY = 'idempotency_key';

    private const DIGEST = 'request_digest';

    private const KEY_SHAPE = 'purchase_order_line_idempotency_key_sha256';

    private const DIGEST_SHAPE = 'purchase_order_line_request_digest_sha256';

    private const PAIRED = 'purchase_order_line_request_digest_with_key';

    private const INDEX = 'purchase_order_line_tenant_idem_live';

    public function getDescription(): string
    {
        return 'V2-R-12: purchase_order_line.idempotency_key + request_digest and the live-row unique purchase_order_line_tenant_idem_live';
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

    public function down(Schema $schema): void
    {
        $this->addSql(sprintf('DROP INDEX IF EXISTS %s', self::INDEX));

        foreach ([self::PAIRED, self::DIGEST_SHAPE, self::KEY_SHAPE] as $constraint) {
            $this->addSql(sprintf('ALTER TABLE %s DROP CONSTRAINT IF EXISTS %s', self::TABLE, $constraint));
        }

        $this->addSql(sprintf('ALTER TABLE %s DROP COLUMN IF EXISTS %s, DROP COLUMN IF EXISTS %s', self::TABLE, self::DIGEST, self::KEY));
    }
}
