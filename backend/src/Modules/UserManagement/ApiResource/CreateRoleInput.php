<?php

declare(strict_types=1);

namespace App\Modules\UserManagement\ApiResource;

use Symfony\Component\Serializer\Attribute\SerializedName;

/**
 * `CreateRole`'s accepted request fields — §4.2's cell verbatim: `name; description; permission_codes` (roles slice 4, C1).
 * Every member is `mixed` so the service, not the serializer, answers a wrong type with the house 422 (`not_a_string`,
 * `not_a_list`) — the `CreateAdjustmentDecisionInput` precedent. Unknown keys are refused (`allow_extra_attributes => false`);
 * `id`, `is_system`, `holder_count`, `archived_at` and `margin_exposure` are not accepted (B-3, E-15h). **No
 * `idempotency_key`:** §4.2's `Idem` cell is `unique:role_tenant_name_live`, whose key is `name`
 * (`IdempotencyContractTest`'s `PINNED_CLIENT_KEY`); the header is demanded (API-14, 428 without it) and not stored.
 */
final class CreateRoleInput
{
    public mixed $name = null;

    public mixed $description = null;

    #[SerializedName('permission_codes')]
    public mixed $permissionCodes = null;
}
