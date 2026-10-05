<?php

declare(strict_types=1);

namespace App\Modules\UserManagement\Application;

/** One page of `ListRoleGrantProposals` (roles slice 3, C1). */
final readonly class RoleGrantProposalPage
{
    /** @param list<RoleGrantProposalView> $items */
    public function __construct(
        public array $items,
        public ?string $nextCursor,
        public ?string $previousCursor,
        public bool $tenantHasRows,
    ) {
    }
}
