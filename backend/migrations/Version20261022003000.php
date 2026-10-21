<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * **G5-C0 (remaining-ops, 2026-10-22): the envelope replay store's columns and constraints — no grant.**
 *
 * `API.md` §6.2 promises a replayed bulk request *"the stored response, byte for byte"*, and the table held only the
 * response's digest. This adds the envelope (`response_body`, `response_headers`) and four CHECKs, and grants nothing:
 * `erp_app` keeps SELECT only until the first caller, `CreateBulkRoleAssignment` (G5-C1), grants its INSERT column list in
 * its own migration (ADR-002 A4.1; design Response to RED M-1). Authority: `docs/session13/g5-g6-final-red.md` >
 * `g5-g6-bulk-export-design.md` Revision 1 FINAL (R1.2 G5-C0; §3.1 without its GRANT INSERT).
 *
 * **NOT NULL with no default is safe because the table has never held a row:** nothing could write it (`erp_app` lost
 * INSERT in ADR-002 Amendment 4), and `SELECT count(*) FROM idempotency_key` read 0 on all three local databases
 * before this commit (`docs/session13/g5-c0-progress.md`). If a database did hold rows, `ADD COLUMN … NOT NULL` fails on the
 * first one — which is the right failure: such a row has no envelope to replay.
 *
 *  - `idempotency_key_body_bytes`: 2..1024 bytes. A bulk body is `{"requested":n,"applied":n}` (plus `margin_exposure` for
 *    role assignment): counts only, no ids, no personal data — the cap makes that a database fact (design D-3).
 *  - `idempotency_key_body_digest`: the existing `response_digest` must be `sha256(response_body)`, so a body planted
 *    without its digest is refused (D-4).
 *  - `idempotency_key_headers_closed`: an object whose keys are only §6.2's normative headers, in lower case (D-5).
 *  - `idempotency_key_key_is_sha256_hex`: `key` holds `IdempotencyKeyHash::of(header)`, never the header (D-1, D-B's rule
 *    on every other retry key). `idempotency_key_key_bytes` (1..255) stays as a cheap backstop under it.
 *
 * The REVOKE-first grant restatement is the migration habit (`Version20260916000300`); it leaves the footprint exactly as
 * it was — SELECT, nothing else — and `GrantFootprintTest` proves that from the server.
 */
final class Version20261022003000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Remaining-ops G5-C0: idempotency_key response envelope (body + headers) and its CHECKs; no grant';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE idempotency_key ADD COLUMN response_body bytea NOT NULL, ADD COLUMN response_headers jsonb NOT NULL');
        $this->addSql(<<<'SQL'
            ALTER TABLE idempotency_key
              ADD CONSTRAINT idempotency_key_body_bytes        CHECK (octet_length(response_body) BETWEEN 2 AND 1024),
              ADD CONSTRAINT idempotency_key_body_digest       CHECK (response_digest = sha256(response_body)),
              ADD CONSTRAINT idempotency_key_headers_closed    CHECK (jsonb_typeof(response_headers) = 'object'
                    AND (response_headers - ARRAY['content-type', 'location', 'content-disposition']) = '{}'::jsonb),
              ADD CONSTRAINT idempotency_key_key_is_sha256_hex CHECK (key ~ '^[0-9a-f]{64}$')
            SQL);
        $this->addSql('REVOKE ALL ON idempotency_key FROM erp_app');
        $this->addSql('GRANT SELECT ON idempotency_key TO erp_app');
    }

    public function down(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            ALTER TABLE idempotency_key
              DROP CONSTRAINT idempotency_key_key_is_sha256_hex,
              DROP CONSTRAINT idempotency_key_headers_closed,
              DROP CONSTRAINT idempotency_key_body_digest,
              DROP CONSTRAINT idempotency_key_body_bytes
            SQL);
        $this->addSql('ALTER TABLE idempotency_key DROP COLUMN response_headers, DROP COLUMN response_body');
    }
}
