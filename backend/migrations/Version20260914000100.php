<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * **E4-4 as amended (2026-09-14): E-12's suppression storage on `auth_lockout`.**
 *
 * `SECURITY_MODEL.md` E-12, ADR-006 §2.2 and Amendment 1 A1.3, `SCHEMA.md` §6.2.
 * The login-failure trail is capped at C = 20 rows per
 * `(slug_normalised, email_hash)` per UTC day, plus one marker row at C+1. The
 * count cannot live on `audit_log` (no role holds `UPDATE`, S-9) and cannot be
 * read back from it (`erp_authlog` holds no `SELECT`), so it lives here, on the
 * row step 7 already upserts on every surviving failure.
 *
 * - `suppression_count` — attempts today on the two branches that write the
 *   trail (`auth.login.failure`, `auth.login.tenant_inactive`). **Separate from
 *   `failure_count`**, which counts unresolved attempts too (E-8), resets on a
 *   success and on pause expiry (E-22), and has no day. Nothing but a new UTC
 *   day resets this one; `LockoutStore::clear()` does not name it.
 * - `suppression_day` — the UTC date the count belongs to, written by the
 *   upsert as `(now() AT TIME ZONE 'UTC')::date`. NULL only on a row no upsert
 *   has touched since this migration, whose count is 0.
 *
 * **Grants: none issued, deliberately.** `auth_lockout` carries a table-level
 * `SELECT, INSERT, UPDATE` to `erp_ratelimit` and nothing to any other
 * application role (`Version20260906000100`), and a table-level privilege covers
 * every column including ones added later. So the two columns are reachable by
 * `erp_ratelimit` alone the moment they exist, which is E4-4's principal clause.
 * `GrantFootprintTest` asserts it per column, for every role.
 *
 * **No E-11 column.** E4-4's amendment moved E-11's status-email half to the
 * output that builds the email; no reader for it exists this wave.
 *
 * **Locks:** two `ADD COLUMN`s with a constant default (catalogue-only since
 * PG11, no rewrite) and two `CHECK`s over a table that holds at most one row per
 * attempted pair. A new file rather than an edit: every earlier version is
 * recorded (E4-4's in-place-edit clause).
 */
final class Version20260914000100 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'C1 / E4-4 amended: auth_lockout.suppression_count + suppression_day — E-12\'s daily login-failure trail cap';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            ALTER TABLE auth_lockout
                ADD COLUMN suppression_count integer NOT NULL DEFAULT 0,
                ADD COLUMN suppression_day   date,
                ADD CONSTRAINT auth_lockout_suppression_count_sane CHECK (suppression_count >= 0),
                ADD CONSTRAINT auth_lockout_suppression_day_with_count CHECK (suppression_day IS NOT NULL OR suppression_count = 0)
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            ALTER TABLE auth_lockout
                DROP CONSTRAINT IF EXISTS auth_lockout_suppression_day_with_count,
                DROP CONSTRAINT IF EXISTS auth_lockout_suppression_count_sane,
                DROP COLUMN IF EXISTS suppression_day,
                DROP COLUMN IF EXISTS suppression_count
            SQL);
    }
}
