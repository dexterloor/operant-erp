<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * **Customers slice 2, C0 — the retry key `CreateCustomerContact` never had, the one-primary rule's index, and
 * `erp_ai`'s SELECT on contacts withdrawn** (`docs/session13/customers-s2-design.md` Revision 2 R2.4 C0; owner ruling
 * `s35-ruling.md` Q-1 (a); SEC `customers-s2-sec-arch.md` S-2).
 *
 * **The retry key** — `customer_address`'s shape (`Version20261009000200`) exactly, for its three reasons:
 *   - **no history:** nothing in `src/` has ever written `customer_contact` (`erp_app` has held SELECT only since
 *     `Version20260916000300`), so there is no row for a NULL to describe;
 *   - **one row per request:** no nested contact path exists or is contracted, so no N rows share one `Idempotency-Key`;
 *   - **no `deleted_at`:** the table is class `hard`, so the unique is **TOTAL**
 *     (`IdempotencyContractTest::everyUniqueIndexIsLiveRowPartialExactlyWhenItsTableSoftDeletes`).
 * So `idempotency_key` (hex SHA-256 of the header, D-B) and `request_digest` are plain `NOT NULL`, each CHECKed to 64
 * lower-case hex, on `customer_contact_idem_unique (tenant_id, idempotency_key)`. **If a database does hold contacts**,
 * `ADD COLUMN … NOT NULL` fails on the first row — the right outcome: a key is never invented.
 *
 * **One primary per customer** (owner Q-1 (a): one primary, marking another moves it): the partial unique index
 * `customer_contact_one_primary (tenant_id, customer_id) WHERE is_primary`. The writer (C1) keeps it unreachable by
 * serialising on the customer row and demoting first; the index is the database's backstop.
 *
 * **`REVOKE SELECT ON customer_contact FROM erp_ai`** (SEC S-2, the `role_grant_proposal` precedent): a table of contact
 * data with no identified Ask use, empty today, so denying it now removes nothing anyone uses. The table SELECT came from
 * the Output 2 migration's `TENANT_READ_DEFAULT`, so only a `REVOKE` removes it; `SchemaManifest` declares the grant
 * explicitly and `GrantFootprintTest` holds the two together.
 *
 * **Grants to `erp_app`: none.** The new columns inherit its table-level SELECT; the writer's column INSERT lands with
 * the writer (C1, ADR-002 A4.1).
 */
final class Version20261019000100 extends AbstractMigration
{
    private const TABLE = 'customer_contact';

    private const KEY = 'idempotency_key';

    private const DIGEST = 'request_digest';

    private const KEY_SHAPE = 'customer_contact_idempotency_key_sha256';

    private const DIGEST_SHAPE = 'customer_contact_request_digest_sha256';

    private const UNIQUE = 'customer_contact_idem_unique';

    private const ONE_PRIMARY = 'customer_contact_one_primary';

    public function getDescription(): string
    {
        return 'Customers slice 2 C0: customer_contact.idempotency_key + request_digest (NOT NULL, total unique), one-primary partial unique, erp_ai SELECT revoked';
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

        // Led by tenant_id (ADR-002 §5). TOTAL: the table is class `hard`.
        $this->addSql(sprintf(
            'ALTER TABLE %s ADD CONSTRAINT %s UNIQUE (tenant_id, %s)',
            self::TABLE,
            self::UNIQUE,
            self::KEY,
        ));

        $this->addSql(sprintf(
            'CREATE UNIQUE INDEX %s ON %s (tenant_id, customer_id) WHERE is_primary',
            self::ONE_PRIMARY,
            self::TABLE,
        ));

        $this->addSql(sprintf('REVOKE SELECT ON %s FROM erp_ai', self::TABLE));
    }

    /**
     * A genuine reverse: `erp_ai` gets its inherited SELECT back, and without the key `CreateCustomerContact`'s §4.2
     * cell goes back to `⛔ none`.
     */
    public function down(Schema $schema): void
    {
        $this->addSql(sprintf('GRANT SELECT ON %s TO erp_ai', self::TABLE));
        $this->addSql(sprintf('DROP INDEX IF EXISTS %s', self::ONE_PRIMARY));

        foreach ([self::UNIQUE, self::DIGEST_SHAPE, self::KEY_SHAPE] as $constraint) {
            $this->addSql(sprintf('ALTER TABLE %s DROP CONSTRAINT IF EXISTS %s', self::TABLE, $constraint));
        }

        foreach ([self::DIGEST, self::KEY] as $column) {
            $this->addSql(sprintf('ALTER TABLE %s DROP COLUMN IF EXISTS %s', self::TABLE, $column));
        }
    }
}
