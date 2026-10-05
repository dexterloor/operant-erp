<?php

declare(strict_types=1);

namespace App\Modules\UserManagement\ApiResource;

use Symfony\Component\Serializer\Attribute\SerializedName;

/**
 * `CreateRoleGrantProposal`'s accepted request fields — §4.2's cell verbatim: `role_id`. The subject is the path's
 * `{id}`; the proposer is the authenticated caller; nothing else is accepted. **No `idempotency_key`:** §4.2's `Idem` cell
 * is `unique:role_grant_proposal_pending_unique`, whose key is the natural pair `(user_id, role_id)`
 * (`IdempotencyContractTest`'s `PINNED_CLIENT_KEY`); the `Idempotency-Key` header is demanded (API-14, 428 without it)
 * and its value is not stored — a replay meets the pending proposal and is told `already_pending`.
 */
final class CreateRoleGrantProposalInput
{
    #[SerializedName('role_id')]
    public ?string $roleId = null;
}
