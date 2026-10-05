<?php

declare(strict_types=1);

namespace App\SharedKernel\ValueObject;

/**
 * **The `metadata` key a `role.*` audit row names its role by — one constant, read by the writer and by the
 * reconciliation** (`RESIDUAL_GAPS.md` G-10 condition (1), A-7; roles slice 2, C2; design §4.5, D-8).
 *
 * `role.assigned` / `role.revoked` are `app_user`-subject events (the person whose privileges changed), so the role is
 * carried in `metadata` as `{"role_id": <uuid>}`. `RoleAuditWriter` writes the key and `app:roles:reconcile` reads it
 * **through this constant**, so a rename is a rename of both — and reconciliation arm C (C3) reports any `role.*` row
 * whose key is missing, so a drift that escapes review is loud rather than silent.
 *
 * **Not on `AuditEvent`**, because `AuditEventCatalogueTest::everyDeclaredConstantIsInTheCatalogue` reads every string
 * constant there as an event type. `SharedKernel` is readable from every layer, so the two readers share it with no new
 * cross-module edge.
 */
final class RoleEventMetadata
{
    public const ROLE_ID = 'role_id';

    /**
     * Roles slice 3 (`SECURITY_MODEL.md` §5.2.8; design §2 F-6): the proposal events' further keys, ids and one enum value
     * only — never a reason text, a display name or a code list (PC-16 condition 3). Every `role.*` row still carries
     * {@see self::ROLE_ID}, so reconciliation arm C sees a key drift on any of them.
     */
    public const PROPOSAL_ID = 'proposal_id';

    /** On a `role.assignment_proposed` that lapsed an expired same-pair proposal: the lapsed proposal's id. */
    public const SUPERSEDES_PROPOSAL_ID = 'supersedes_proposal_id';

    /** On `role.assignment_lapsed`: `expired` | `subject_removed` | `proposer_removed`. */
    public const LAPSE_REASON = 'lapse_reason';

    /** Not instantiable: named keys only. */
    private function __construct()
    {
    }
}
