<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * **Audit reads C1 — end the three whole-trail `audit_log` indexes with `(occurred_at, id)`** (`ListAuditLogEntries`;
 * `docs/session13/audit-reads-design.md` §5.2 and Revision 1 R1.3, L-1).
 *
 * | Old (dropped)                                          | New (created)                                                    |
 * |--------------------------------------------------------|------------------------------------------------------------------|
 * | `audit_log_tenant_occurred_idx (tenant_id, occurred_at)` | `audit_log_tenant_occurred_id_idx (tenant_id, occurred_at, id)` |
 * | `audit_log_tenant_actor_idx (…, actor_user_id, occurred_at)` | `audit_log_tenant_actor_occurred_id_idx (…, occurred_at, id)` |
 * | `audit_log_tenant_event_idx (…, event_type, occurred_at)` | `audit_log_tenant_event_occurred_id_idx (…, occurred_at, id)` |
 *
 * **Why:** every writer stamps `occurred_at` with `now()`, the transaction start, so one transaction's rows share an
 * instant and nothing bounds the group. The list's keyset is `(occurred_at, id) < (?, ?)` as ONE row comparison (API-18);
 * an index ending at `occurred_at` can only use `occurred_at <= ?` as the index condition and must sort every tie group
 * before `LIMIT` — exactly what `Version20261015000100` fixed for `stock_movement`. Ending each index with `id` makes the
 * whole comparison an index condition, so `LIMIT` stops a backward scan inside a group (`SchemaQueryPlanTest`'s audit
 * tie-group tests). **Replaced, not added beside** (`DATA` TO CONFIRM, design §5.2): `audit_log` is the one table designed
 * to grow without bound, and a fourth/fifth/sixth index would double its write cost. The prefixes are unchanged, so
 * `PasswordChangeThrottle`'s read-back and ADR-006 §2.2's failed-login query are served by the new actor and event indexes
 * exactly as by the old.
 *
 * **Not touched:** `audit_log_tenant_subject_idx` (C2's, `ListAuditRecords`) and `audit_log_denial_cap_idx` (E-3a's).
 *
 * **CONCURRENTLY, so non-transactional** (the stricter precedent, on this very table: `Version20260909000200` and
 * `SCHEMA.md` §9's *"a plain `CREATE INDEX` takes `ShareLock` and blocks audit writes"*). This differs deliberately from
 * stock reads C2's plain `CREATE INDEX`: that table had no real-volume tenant; this one is the trail every write appends to.
 * **Re-entrancy (L-1):** each create is preceded by a plain `DROP INDEX IF EXISTS` of its own name — a failed concurrent
 * build leaves an `indisvalid = false` index that `IF NOT EXISTS` would accept as done; dropping it first rebuilds it
 * (`SchemaQueryPlanTest` asserts `indisvalid` for each new index). Each old index is dropped `CONCURRENTLY IF EXISTS` only
 * after its replacement exists, so the trail is never without an index for its screen. `down()` is the mirror image,
 * concurrent too.
 */
final class Version20261021000100 extends AbstractMigration
{
    /** @var array<string, array{0: string, 1: string}> new name => [columns, old name] */
    private const REBUILDS = [
        'audit_log_tenant_occurred_id_idx' => ['tenant_id, occurred_at, id', 'audit_log_tenant_occurred_idx'],
        'audit_log_tenant_actor_occurred_id_idx' => ['tenant_id, actor_user_id, occurred_at, id', 'audit_log_tenant_actor_idx'],
        'audit_log_tenant_event_occurred_id_idx' => ['tenant_id, event_type, occurred_at, id', 'audit_log_tenant_event_idx'],
    ];

    /** @var array<string, string> old name => its columns, for `down()` */
    private const OLD = [
        'audit_log_tenant_occurred_idx' => 'tenant_id, occurred_at',
        'audit_log_tenant_actor_idx' => 'tenant_id, actor_user_id, occurred_at',
        'audit_log_tenant_event_idx' => 'tenant_id, event_type, occurred_at',
    ];

    /** `CREATE INDEX CONCURRENTLY` cannot run inside a transaction block. */
    public function isTransactional(): bool
    {
        return false;
    }

    public function getDescription(): string
    {
        return 'Audit reads C1: end the three whole-trail audit_log indexes with (occurred_at, id), concurrently.';
    }

    public function up(Schema $schema): void
    {
        foreach (self::REBUILDS as $new => [$columns, $old]) {
            $this->addSql(\sprintf('DROP INDEX IF EXISTS %s', $new));
            $this->addSql(\sprintf('CREATE INDEX CONCURRENTLY %s ON audit_log (%s)', $new, $columns));
            $this->addSql(\sprintf('DROP INDEX CONCURRENTLY IF EXISTS %s', $old));
        }
    }

    public function down(Schema $schema): void
    {
        foreach (self::REBUILDS as $new => [, $old]) {
            $this->addSql(\sprintf('DROP INDEX IF EXISTS %s', $old));
            $this->addSql(\sprintf('CREATE INDEX CONCURRENTLY %s ON audit_log (%s)', $old, self::OLD[$old]));
            $this->addSql(\sprintf('DROP INDEX CONCURRENTLY IF EXISTS %s', $new));
        }
    }
}
