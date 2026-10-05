<?php

declare(strict_types=1);

namespace App\Modules\UserManagement\Application;

/**
 * One grant proposal as `GetRoleGrantProposal` and `ListRoleGrantProposals` serve it (roles slice 3, C1;
 * `docs/session13/roles-s3-design.md` §3.3). The three `app_user` references are PC-16's closed list — `user`,
 * `proposed_by`, `decided_by`, each `{id, display_name}` — served only under `role.assign`. Scalars only, so the
 * `ApiResource` layer builds the references and this class depends on nothing.
 */
final readonly class RoleGrantProposalView
{
    /**
     * @param list<string> $permissionCodes the proposal-time snapshot, sorted
     */
    public function __construct(
        public string $id,
        public string $userId,
        public string $userDisplayName,
        public string $roleId,
        public string $roleName,
        public array $permissionCodes,
        public string $status,
        public bool $isExpired,
        public string $proposedById,
        public string $proposedByDisplayName,
        public string $proposedAt,
        public string $expiresAt,
        public ?string $decidedById,
        public ?string $decidedByDisplayName,
        public ?string $decidedAt,
        public ?string $reason,
        public ?string $lapseReason,
    ) {
    }
}
