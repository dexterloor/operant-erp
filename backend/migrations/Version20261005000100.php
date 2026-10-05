<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * **Roles slice 3, C2 — `ConfirmRoleGrantProposal`'s one grant: a COLUMN-level `UPDATE` on `decided_by_user_id`**
 * (`docs/session13/roles-s3-design.md` §7; `roles-s3-data.md` §4: *"`UPDATE (decided_by_user_id)` waits for C2"*; ADR-002
 * A4.1 — a write no `src/` path issues is not granted, so the column arrives with its first writer).
 *
 * Confirm's ORM flush of `RoleGrantProposal::confirm()` writes `status`, `decided_by_user_id`, `decided_at` and
 * `updated_at`. Three of the four were granted with Create's lapse (`Version20261004000100`); this adds the decider.
 * Reject and Cancel (C3) reuse it, and Reject's `reason` arrives with its writer there. Still no table-level `UPDATE`,
 * never `DELETE`; `erp_ai` still gets nothing. The decided-is-final trigger still refuses any update of a decided row, so
 * the decider of a decision cannot be rewritten through this grant (`DATA` §3) — G-10's bound is unchanged: SQL as
 * `erp_app` can decide a still-pending row with any decider the CHECKs admit (`roles-s3-data.md` §3.2).
 *
 * Transactional; nothing else. **`down()`** revokes the one column.
 */
final class Version20261005000100 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Roles slice 3 C2: erp_app UPDATE on role_grant_proposal.decided_by_user_id (the confirmer), column-level.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('GRANT UPDATE (decided_by_user_id) ON role_grant_proposal TO erp_app');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('REVOKE UPDATE (decided_by_user_id) ON role_grant_proposal FROM erp_app');
    }
}
