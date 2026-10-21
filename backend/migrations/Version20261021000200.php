<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * **Audit reads C2 — end the per-record History index with `(occurred_at, id)`** (`ListAuditRecords`;
 * `docs/session13/audit-reads-design.md` §5.2 and R1.5). C1's migration (`Version20261021000100`) did the same for the
 * three whole-trail indexes and left this one to the commit whose reader it serves.
 *
 * | Old (dropped) | New (created) |
 * |---|---|
 * | `audit_log_tenant_subject_idx (tenant_id, subject_type, subject_id, occurred_at)` | `audit_log_tenant_subject_occurred_id_idx (…, occurred_at, id)` |
 *
 * **Why:** one transaction's rows share `now()`, so a record's History can hold a tie group (a transfer's two legs name two
 * movements, but a bulk operation's rows on ONE subject share an instant); the keyset `(occurred_at, id) < (?, ?)` is
 * only a whole index condition when the index ends `id`, so `LIMIT` stops a backward scan inside the group
 * (`SchemaQueryPlanTest`'s History tie-group test). Replaced, not added beside (`DATA` TO CONFIRM, design §5.2).
 *
 * **CONCURRENTLY, so non-transactional; re-entrant; `down()` the mirror, concurrent too** — C1's reasons, verbatim in
 * that migration's docblock.
 */
final class Version20261021000200 extends AbstractMigration
{
    private const NEW = 'audit_log_tenant_subject_occurred_id_idx';
    private const NEW_COLUMNS = 'tenant_id, subject_type, subject_id, occurred_at, id';
    private const OLD = 'audit_log_tenant_subject_idx';
    private const OLD_COLUMNS = 'tenant_id, subject_type, subject_id, occurred_at';

    /** `CREATE INDEX CONCURRENTLY` cannot run inside a transaction block. */
    public function isTransactional(): bool
    {
        return false;
    }

    public function getDescription(): string
    {
        return 'Audit reads C2: end the per-record History audit_log index with (occurred_at, id), concurrently.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(\sprintf('DROP INDEX IF EXISTS %s', self::NEW));
        $this->addSql(\sprintf('CREATE INDEX CONCURRENTLY %s ON audit_log (%s)', self::NEW, self::NEW_COLUMNS));
        $this->addSql(\sprintf('DROP INDEX CONCURRENTLY IF EXISTS %s', self::OLD));
    }

    public function down(Schema $schema): void
    {
        $this->addSql(\sprintf('DROP INDEX IF EXISTS %s', self::OLD));
        $this->addSql(\sprintf('CREATE INDEX CONCURRENTLY %s ON audit_log (%s)', self::OLD, self::OLD_COLUMNS));
        $this->addSql(\sprintf('DROP INDEX CONCURRENTLY IF EXISTS %s', self::NEW));
    }
}
