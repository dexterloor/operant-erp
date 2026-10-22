<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * **G5-C2 (2026-10-22) — the envelope store's first caller, and so its grant** (ADR-002 A4.1: the migration that ships the
 * first caller grants the write; A4.4 duty 1: exactly what that caller issues). `CreateBulkArchival` is the first `store`
 * operation (it landed before `CreateBulkRoleAssignment`, which the design had expected first; `g5-c2-progress.md` CD-A),
 * and `IdempotencyEnvelopeStore::record()` issues one `INSERT … ON CONFLICT (tenant_id, operation_id, key) DO NOTHING` naming
 * these nine columns.
 *
 * Column-level, never table-level: `created_at` stays out, so `erp_app` cannot set the instant the 24-hour window is
 * measured from (D-2) — its `DEFAULT now()` is the database clock's. `DO NOTHING` needs no `UPDATE` privilege, and none is
 * granted: a replayed key is answered from the stored row, never rewritten (§6.1). No `DELETE`: a principal that can delete
 * its own idempotency records can replay anything (`SchemaManifest`'s `why`). `erp_ai` and the pre-auth roles are unmoved.
 */
final class Version20261022050000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'G5-C2: erp_app INSERT on the nine idempotency_key envelope columns (never created_at), for CreateBulkArchival.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('GRANT INSERT (id, tenant_id, operation_id, key, request_digest, response_status, response_digest, response_body, response_headers) ON idempotency_key TO erp_app');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('REVOKE INSERT (id, tenant_id, operation_id, key, request_digest, response_status, response_digest, response_body, response_headers) ON idempotency_key FROM erp_app');
    }
}
