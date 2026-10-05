<?php

declare(strict_types=1);

namespace App\Modules\UserManagement\Application;

use App\Platform\Http\Pagination\Cursor;

/**
 * `ListRoleGrantProposals`' parsed query (roles slice 3, C1; design §3.1, §4.6, F-7). One order, `proposed_at DESC,
 * id DESC`, keyset-paginated (proposals are class `none` and accumulate for the tenant's lifetime, so a bounded list
 * would not survive TB-1c). Each filter is `null` when absent.
 */
final readonly class RoleGrantProposalListCriteria
{
    public function __construct(
        public int $limit,
        public ?string $status = null,
        public ?string $userId = null,
        public ?string $roleId = null,
        public ?Cursor $after = null,
        public ?Cursor $before = null,
    ) {
    }

    public function isBackwards(): bool
    {
        return null !== $this->before;
    }
}
