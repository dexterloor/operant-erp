<?php

declare(strict_types=1);

namespace App\Modules\UserManagement\ApiResource;

use Symfony\Component\Serializer\Attribute\SerializedName;

/**
 * `AssignRole`'s accepted request fields — §4.2's cell verbatim: `role_id`. Nothing else: the user is the path's `{id}`,
 * and there is no `id` to send (the grant row's id is minted server-side and is not part of the contract — the grant is
 * addressed as `/users/{id}/roles/{roleId}`). **No `idempotency_key` either:** §4.2's `Idem` cell is
 * `unique:user_role_unique`, whose key is the natural pair `(user_id, role_id)` (`IdempotencyContractTest`'s
 * `PINNED_CLIENT_KEY`); the `Idempotency-Key` header is demanded (API-14, 428 without it) and its value is not stored.
 */
final class AssignRoleInput
{
    #[SerializedName('role_id')]
    public ?string $roleId = null;
}
