<?php

declare(strict_types=1);

namespace App\Modules\UserManagement\ApiResource;

use Symfony\Component\Serializer\Attribute\SerializedName;

/**
 * **An `app_user` reference on a grant proposal — `{id, display_name}`, PC-16's closed shape** (roles slice 3, C1;
 * `SECURITY_MODEL.md` PC-16; API-23b's display field for `app_user`). Served only under `role.assign`, and only as the
 * proposal's `user`, `proposed_by` and `decided_by`: **no other `app_user` field may be added here without editing
 * PC-16** (`RoleGrantProposalTest`'s shape test holds the member set exactly).
 */
final readonly class UserDisplayReference
{
    public function __construct(
        public string $id,
        #[SerializedName('display_name')]
        public string $displayName,
    ) {
    }
}
