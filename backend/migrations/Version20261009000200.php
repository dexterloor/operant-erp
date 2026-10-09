<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * **Customers slice 1, C0 — the retry key `CreateCustomerAddress` never had.**
 *
 * `API.md` §4.2 marked `CreateCustomerAddress` `⛔ none` and E9-2 named it: `customer_address` carried no unique
 * beyond its surrogate, so a retried timeout would have written a second address. The mechanism is the one
 * `fulfilment` carries (`Version20260922000100`), for its three reasons:
 *
 *   - **no history:** nothing in `src/` has ever written `customer_address` (`erp_app` held SELECT only since
 *     `Version20260916000300`), so there is no row for a NULL to describe — test fixtures insert after migrating and
 *     now mint a key of their own on every row;
 *   - **one row per request:** no nested address path exists or is contracted (`CreateCustomer` accepts no
 *     `addresses[]`), so no N rows share one `Idempotency-Key` — the reason `purchase_order_line`'s columns had to be
 *     nullable does not arise;
 *   - **no `deleted_at`:** the table is class `hard`, so the unique is **TOTAL**, which
 *     `IdempotencyContractTest::everyUniqueIndexIsLiveRowPartialExactlyWhenItsTableSoftDeletes` demands.
 *
 * So `idempotency_key` (the hex SHA-256 of the header, D-B) and `request_digest` (`RequestDigest::of()` of the request,
 * which digests the PATH's customer id, so the same key against another customer is a conflict, never a replay) are
 * plain `NOT NULL`, each CHECKed to 64 lower-case hex, on the total `customer_address_idem_unique (tenant_id,
 * idempotency_key)`. With both `NOT NULL` a both-or-neither CHECK is implied.
 *
 * **Hard-delete consequence, recorded:** if `DeleteCustomerAddress` is ever built, a replay of a deleted address's
 * create key finds nothing to replay and inserts a fresh row.
 *
 * **Grants: none** (M-1, re-measured in this commit with `erp_ai` INSERT = false as the positive control). The column
 * INSERT is owed by the commit that ships the writer (customers slice 1 C3, ADR-002 A4.1). `erp_ai` reads the hash and
 * the digest by inheritance, the state API-O-32 accepted for `purchase_order`.
 *
 * **If a database does hold addresses**, `ADD COLUMN … NOT NULL` fails on the first row — the right outcome, for
 * `Version20260911000400`'s reason.
 */
final class Version20261009000200 extends AbstractMigration
{
    private const TABLE = 'customer_address';

    private const KEY = 'idempotency_key';

    private const DIGEST = 'request_digest';

    private const KEY_SHAPE = 'customer_address_idempotency_key_sha256';

    private const DIGEST_SHAPE = 'customer_address_request_digest_sha256';

    private const UNIQUE = 'customer_address_idem_unique';

    public function getDescription(): string
    {
        return 'Customers slice 1 C0: customer_address.idempotency_key + request_digest (NOT NULL, total unique)';
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

        // Led by tenant_id (ADR-002 §5): SchemaGuardTest asserts it catalogue-wide. TOTAL: the table is class `hard`.
        $this->addSql(sprintf(
            'ALTER TABLE %s ADD CONSTRAINT %s UNIQUE (tenant_id, %s)',
            self::TABLE,
            self::UNIQUE,
            self::KEY,
        ));
    }

    /**
     * A genuine reverse. Without the key there is no mechanism, so `CreateCustomerAddress`'s §4.2 cell goes back to
     * `⛔ none` and E9-2 names it again.
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
