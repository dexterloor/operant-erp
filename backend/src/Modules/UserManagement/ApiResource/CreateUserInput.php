<?php

declare(strict_types=1);

namespace App\Modules\UserManagement\ApiResource;

use Symfony\Component\Serializer\Attribute\SerializedName;

/**
 * **`CreateUser`'s body: `email` and `display_name`, and nothing else** (U-C1b; `API.md` §4.2, §4.3a). A password, a password
 * hash, roles, `is_active`, an id or a token is refused as an undeclared field (`allow_extra_attributes: false`) — §4.3a's
 * fails-if: the operation *"accepts a password, a password hash or an invitation token"*. Roles come only from `AssignRole`
 * / `CreateBulkRoleAssignment` under E-15e (owner, 2026-09-15: `role_ids` removed). Typed `mixed` so a wrong type reaches the
 * application's own 422, named by pointer.
 */
final class CreateUserInput
{
    public mixed $email = null;

    #[SerializedName('display_name')]
    public mixed $displayName = null;

    /** @return array<string, mixed> wire name => value */
    public function submitted(): array
    {
        return ['email' => $this->email, 'display_name' => $this->displayName];
    }
}
