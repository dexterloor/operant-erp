<?php

declare(strict_types=1);

namespace App\Modules\UserManagement\ApiResource;

/**
 * `RejectRoleGrantProposal`'s accepted request fields (§4.2): one optional field, `reason` (roles slice 3, C3; design
 * §4.4 step 6).
 *
 * **Typed `mixed` on purpose**, the `PurchaseOrderWriter` nested-line precedent: a non-string `reason` is named back as
 * `not_a_string` with `UX`'s sentence (`roles-s3-ux-copy.md` #33), never coerced and never the framework's generic
 * `wrong_type`. Absent, null or blank is "no reason given" and stored as null; 1–500 characters otherwise
 * (`role_grant_proposal_reason_only_on_reject`), else `too_long` (#32). The text lives on the proposal row only, served
 * under `role.assign` — the audit row never carries it (PC-16 condition 3).
 */
final class RejectRoleGrantProposalInput
{
    public mixed $reason = null;
}
