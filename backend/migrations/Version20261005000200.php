<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * **Roles slice 3, C3 — `RejectRoleGrantProposal`'s one grant: a COLUMN-level `UPDATE` on `reason`**
 * (`docs/session13/roles-s3-design.md` §7; `roles-s3-data.md` §4: *"`UPDATE (reason)` waits for C3 (Reject)"*; ADR-002
 * A4.1 — a column arrives with its first writer). Reject's flush of `RoleGrantProposal::reject()` writes `status`,
 * `decided_by_user_id`, `decided_at`, `updated_at` (granted at C1/C2) and `reason`; Cancel's flush writes the first four.
 * After this the decision columns are complete; still no table-level `UPDATE`, never `DELETE`, `erp_ai` nothing. The
 * decided-is-final trigger still refuses any update of a decided row, so a reason cannot be rewritten after the decision.
 *
 * Transactional; nothing else. **`down()`** revokes the one column.
 */
final class Version20261005000200 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Roles slice 3 C3: erp_app UPDATE on role_grant_proposal.reason (the rejecter\'s reason), column-level.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('GRANT UPDATE (reason) ON role_grant_proposal TO erp_app');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('REVOKE UPDATE (reason) ON role_grant_proposal FROM erp_app');
    }
}
