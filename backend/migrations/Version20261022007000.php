<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * **G4-C0 — stop a deactivated person's session at once, and never let a re-enabled person's old sessions back in.**
 * Authority: `docs/session13/g4-users-design.md` §4 + Revision 1 FINAL R1.2 (G4-C0); `remaining-ops-final-red.md` M-1, H-2.
 *
 * `app_user.sessions_valid_after timestamptz NULL` — **NULL means no restriction** and every row is NULL after this
 * migration (metadata-only `ADD COLUMN`, no default, no rewrite). `IdentityReader` refuses a token whose integer `iat` is not
 * strictly after the stamp (`iat > s` ⇔ `iat > floor(s)` for integer `iat`). **No writer yet**: C1 (`UpdateUser`), C3
 * (`RestoreUser`) and Mail-C2 write it from the injected PSR clock, monotone. **No CHECK compares it with any other instant**
 * (M-1(d)), no index, **no grant**: `erp_app` reads it through its table-level SELECT; its `UPDATE (sessions_valid_after)`
 * ships with the first writer (ADR-002 A4.1); `erp_auth` does not read it (sign-in cannot see the stamp — the 200-then-401
 * once cost, owner notice 3).
 */
final class Version20261022007000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'G4-C0: app_user.sessions_valid_after (nullable; NULL = no restriction), read by IdentityReader, written from C1';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE app_user ADD COLUMN sessions_valid_after timestamptz');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE app_user DROP COLUMN sessions_valid_after');
    }
}
