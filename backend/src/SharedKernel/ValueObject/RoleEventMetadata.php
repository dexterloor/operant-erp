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

    /**
     * On a `role.assigned` written by a confirmation (roles slice 3, C2; Q1 of the E-15e ruling, §5.2.8), beside
     * {@see self::PROPOSAL_ID}: who proposed the grant. The row's actor is the confirmer, the granter of record.
     */
    public const PROPOSED_BY_USER_ID = 'proposed_by_user_id';

    /**
     * On `role.assignment_lapsed`: `expired` | `subject_removed` | `proposer_removed`.
     *
     * **An E-15g lapse records its own transaction's cause** (G4-C1, `g4-users-design.md` D-15 / first RED L-8): a
     * deactivation lapses every pending proposal for the person as `subject_removed` and by the person as
     * `proposer_removed` — even a pending row already past `expires_at`. The list already shows that row as expired
     * (§5.2.7), so the reason loses nothing, and a `CASE` would add a database-clock branch to a statement that need not decide
     * expiry.
     */
    public const LAPSE_REASON = 'lapse_reason';

    /**
     * G4-C1 (`UpdateUser`; first RED M-3, D-11): on a `role.revoked` written by a STRIP — E-15g's taking away of every role
     * when a person is switched off, or of a leftover grant (a database edit) when they are switched back on — the cause:
     * {@see self::REVOKE_REASON_SUBJECT_REMOVED}. **A `role.revoked` with no `revoke_reason` means a direct revoke**
     * (`RevokeRole`); shipped rows stay `{role_id}`. A key, not an event type: reconciliation arm B pairs `role.revoked` with
     * the grant it ended either way, and arm C keys on {@see self::ROLE_ID} only.
     */
    public const REVOKE_REASON = 'revoke_reason';

    /** {@see self::REVOKE_REASON}'s one value today. */
    public const REVOKE_REASON_SUBJECT_REMOVED = 'subject_removed';

    /**
     * Roles slice 4 C1 (E-15f; `roles-s4-design.md` §7): on `role.permissions_changed`, the codes the change ADDED and
     * REMOVED, each a sorted list (bounded by the catalogue). At creation `added` is the full set and `removed` is `[]`.
     * Reconciliation arm D folds them, in `occurred_at` order, into the set `role_permission` must hold.
     */
    public const ADDED = 'added';

    public const REMOVED = 'removed';

    /**
     * Roles slice 4 C2 (`UpdateRole`; `SEC` D-7.3): the NAMES of the non-code fields an edit changed — a list drawn from
     * `name`, `description` — on `role.updated` always, and on a `role.permissions_changed` that also renamed or
     * re-described. **Field names only, never the values** (ids-and-codes-only metadata; the current name is `GetRole`'s).
     */
    public const FIELDS = 'fields';

    /** Not instantiable: named keys only. */
    private function __construct()
    {
    }
}
