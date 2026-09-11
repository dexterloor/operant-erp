<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * **D-B (ruled 2026-09-11) — the request digest a `unique:` replay is compared
 * against.**
 *
 * `purchase_order.request_digest` holds `RequestDigest::of()` of the request that
 * created the row: lower-case hex SHA-256 over the deserialised, normalised input.
 * A replay with the same key and the same digest is answered with the order; the
 * same key with a different digest is `idempotency-key-conflict` (409), which is
 * §6.2's clause and was never honoured on this route before this file.
 *
 * Nullable, for step 5's reason: rows raised before `Version20260911000100` have
 * no key and therefore nothing to compare. Hex `text` rather than `bytea(32)` —
 * the store table's shape — because it sits beside a hex key on the same row and
 * the two are read together.
 *
 * ---------------------------------------------------------------------------
 * The key is stored HASHED from here on, and the database says so
 * ---------------------------------------------------------------------------
 * D-B's second half: `idempotency_key` holds `IdempotencyKeyHash::of()` — the
 * lower-case hex SHA-256 of the header — and never the header's value. The type
 * and step 5's `octet_length BETWEEN 1 AND 255` CHECK are unchanged (64 fits).
 * `purchase_order_idempotency_key_sha256` narrows the column to that shape, so a
 * writer that forgot to hash — the reorder agent is the next one due — is
 * refused by Postgres (`23514`) instead of storing a raw key the AI principal
 * can read (API-O-32).
 *
 * `purchase_order_request_digest_with_key` makes the key and its digest one
 * fact: both or neither. A keyed row with no digest could never be told apart
 * from a different request, so every honest replay against it would be a 409.
 * It is a CHECK, not step 5's trigger shape, because it holds for historical
 * rows as written: they carry neither.
 *
 * **Existing rows.** Both CHECKs validate at add time. The dev table held **0
 * rows** when D-B was ruled (measured as superuser), and nothing has been
 * deployed, so there is nothing to rehash. A database holding raw keys would
 * make this migration fail by name, which is the correct outcome: a raw key
 * cannot be converted silently without also changing the digest it pairs with.
 *
 * **Grants: none.** A new column on a table whose grants are all table-level
 * (step 5 measured `pg_attribute.attacl` at 0 rows) inherits them. `erp_ai`
 * therefore reads `request_digest` — harmless: a digest of a request the reader
 * cannot invert, in its own workspace.
 */
final class Version20260911000300 extends AbstractMigration
{
    private const TABLE = 'purchase_order';

    private const KEY = 'idempotency_key';

    private const DIGEST = 'request_digest';

    private const KEY_SHAPE = 'purchase_order_idempotency_key_sha256';

    private const DIGEST_SHAPE = 'purchase_order_request_digest_sha256';

    private const PAIRED = 'purchase_order_request_digest_with_key';

    public function getDescription(): string
    {
        return 'D-B: purchase_order.request_digest (what a same-key replay is compared against), and the key stored as hex SHA-256';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(sprintf('ALTER TABLE %s ADD COLUMN %s text', self::TABLE, self::DIGEST));

        foreach ([self::DIGEST_SHAPE => self::DIGEST, self::KEY_SHAPE => self::KEY] as $constraint => $column) {
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
    }

    public function down(Schema $schema): void
    {
        foreach ([self::PAIRED, self::KEY_SHAPE, self::DIGEST_SHAPE] as $constraint) {
            $this->addSql(sprintf('ALTER TABLE %s DROP CONSTRAINT IF EXISTS %s', self::TABLE, $constraint));
        }

        $this->addSql(sprintf('ALTER TABLE %s DROP COLUMN IF EXISTS %s', self::TABLE, self::DIGEST));
    }
}
