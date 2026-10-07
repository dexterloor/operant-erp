<?php

declare(strict_types=1);

namespace App\Modules\AuditLog\Infrastructure;

use App\Platform\Tenancy\TenantContext;
use App\SharedKernel\ValueObject\AuditEvent;
use App\SharedKernel\ValueObject\RoleEventMetadata;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * **E-15 — a change to a user's roles writes exactly one `role.*` row, in the transaction that made it** (roles slice 2;
 * `docs/session13/roles-s2-design.md` §4.5; `API.md` §9.2's `ROLE_ASSIGNED` / `ROLE_REVOKED` row).
 *
 * `role.revoked` (C2), `role.assigned` (C3; and from a confirmation, roles slice 3 C2), and E-15e's
 * `role.assignment_proposed` / `role.assignment_lapsed` (roles slice 3, C1) and `role.assignment_rejected` /
 * `role.assignment_cancelled` (C3). All are **security** events (`AuditEvent::SECURITY`): read only
 * through `audit_log.view`, and they carry the request's IP (E-3's field; `audit_log_ip_address_scope` admits one for
 * `role.*`, measured) — the `PasswordChangeAuditWriter` precedent.
 *
 *  - **Subject:** `('app_user', <the user whose privileges changed>)`, read off `AuditEvent::SUBJECT_TYPES` — except
 *    `role.permissions_changed` (roles slice 4 C1), `role.updated` (C2) and `role.deleted` (C3), whose subject is `('role', <the role whose
 *    definition changed>)`.
 *  - **`metadata` always carries `RoleEventMetadata::ROLE_ID`** — the key the reconciliation reads (G-10 condition (1),
 *    A-7) — and, for the proposal events (roles slice 3, C1; `SECURITY_MODEL.md` §5.2.8), the further id keys
 *    `PROPOSAL_ID`, `SUPERSEDES_PROPOSAL_ID`, `PROPOSED_BY_USER_ID` (a confirmation's `role.assigned`, C2) and the
 *    `LAPSE_REASON` enum value. The map is built in PHP from
 *    `RoleEventMetadata` constants and bound as ONE `?::jsonb` parameter, never interpolated; one private statement
 *    still writes every `role.*` row (design §2 F-6), so the rows cannot drift apart in shape. Nothing else is copied:
 *    the role's name and codes are the role row's, and PC-15 condition 6 / PC-16 condition 3 forbid display data.
 *  - **`occurred_at` is `clock_timestamp()`, in the `INSERT`'s own SQL** (q7-spar §2.2, Q-11; spar §5 item 7): the
 *    instant the row is written, after the role-administration lock was taken — never `now()`, which is the start of a
 *    transaction that may have waited on the lock, and never a PHP clock.
 *
 * `TenantContext::run()` is re-entrant for the same tenant, so a call from inside the locked transaction **joins** it: the
 * `DELETE` and the row that records it commit together or not at all. A failed write is a failed request (API.md §9.2).
 */
final readonly class RoleAuditWriter
{
    public function __construct(private TenantContext $tenants)
    {
    }

    /** `role.assigned` — `AssignRole` (C3), after its `INSERT`, in the same locked transaction. */
    public function recordAssigned(string $tenantId, string $actorUserId, string $actorLabel, string $subjectUserId, string $roleId, ?string $ipAddress): void
    {
        $this->record(AuditEvent::ROLE_ASSIGNED, $tenantId, $actorUserId, $actorLabel, $subjectUserId, $roleId, $ipAddress);
    }

    /**
     * `role.assigned` written by a CONFIRMATION — `ConfirmRoleGrantProposal` (roles slice 3, C2), after its `INSERT`, in the
     * same locked transaction. The actor is the confirmer, the granter of record; `metadata` adds the proposal and its
     * proposer (Q1 of the E-15e ruling; `SECURITY_MODEL.md` §5.2.8). The same event as a direct grant, so arms A and B pair
     * it with the `user_role` row through {@see RoleEventMetadata::ROLE_ID} exactly as they pair `AssignRole`'s.
     */
    public function recordAssignedByConfirmation(string $tenantId, string $actorUserId, string $actorLabel, string $subjectUserId, string $roleId, string $proposalId, string $proposedByUserId, ?string $ipAddress): void
    {
        $this->record(AuditEvent::ROLE_ASSIGNED, $tenantId, $actorUserId, $actorLabel, $subjectUserId, $roleId, $ipAddress, [
            RoleEventMetadata::PROPOSAL_ID => $proposalId,
            RoleEventMetadata::PROPOSED_BY_USER_ID => $proposedByUserId,
        ]);
    }

    /**
     * `role.assignment_rejected` — `RejectRoleGrantProposal` (roles slice 3, C3), after its flush, in the same locked
     * transaction. The actor is the rejecter; `metadata` ids only (§5.2.8) — never the reason text (PC-16 condition 3).
     */
    public function recordRejected(string $tenantId, string $actorUserId, string $actorLabel, string $subjectUserId, string $roleId, string $proposalId, string $proposedByUserId, ?string $ipAddress): void
    {
        $this->record(AuditEvent::ROLE_ASSIGNMENT_REJECTED, $tenantId, $actorUserId, $actorLabel, $subjectUserId, $roleId, $ipAddress, [
            RoleEventMetadata::PROPOSAL_ID => $proposalId,
            RoleEventMetadata::PROPOSED_BY_USER_ID => $proposedByUserId,
        ]);
    }

    /** `role.assignment_cancelled` — `CancelRoleGrantProposal` (roles slice 3, C3): the proposer withdrew it. */
    public function recordCancelled(string $tenantId, string $actorUserId, string $actorLabel, string $subjectUserId, string $roleId, string $proposalId, ?string $ipAddress): void
    {
        $this->record(AuditEvent::ROLE_ASSIGNMENT_CANCELLED, $tenantId, $actorUserId, $actorLabel, $subjectUserId, $roleId, $ipAddress, [
            RoleEventMetadata::PROPOSAL_ID => $proposalId,
        ]);
    }

    /** `role.revoked` — `RevokeRole` (C2), after its `DELETE`, in the same locked transaction. */
    public function recordRevoked(string $tenantId, string $actorUserId, string $actorLabel, string $subjectUserId, string $roleId, ?string $ipAddress): void
    {
        $this->record(AuditEvent::ROLE_REVOKED, $tenantId, $actorUserId, $actorLabel, $subjectUserId, $roleId, $ipAddress);
    }

    /**
     * `role.assignment_proposed` — `CreateRoleGrantProposal` (roles slice 3, C1), after its `INSERT`, in the same locked
     * transaction. `$supersedesProposalId` is the expired same-pair proposal this request lapsed, if any.
     */
    public function recordProposed(string $tenantId, string $actorUserId, string $actorLabel, string $subjectUserId, string $roleId, string $proposalId, ?string $supersedesProposalId, ?string $ipAddress): void
    {
        $extra = [RoleEventMetadata::PROPOSAL_ID => $proposalId];
        if (null !== $supersedesProposalId) {
            $extra[RoleEventMetadata::SUPERSEDES_PROPOSAL_ID] = $supersedesProposalId;
        }

        $this->record(AuditEvent::ROLE_ASSIGNMENT_PROPOSED, $tenantId, $actorUserId, $actorLabel, $subjectUserId, $roleId, $ipAddress, $extra);
    }

    /**
     * `role.assignment_lapsed` — a lapse caused by a committed request (C1: Create's lapse of an expired same-pair
     * proposal, `expired`), written BEFORE the superseding proposal's row (T11, R-18). The actor is the requester.
     */
    public function recordLapsed(string $tenantId, string $actorUserId, string $actorLabel, string $subjectUserId, string $roleId, string $proposalId, string $lapseReason, ?string $ipAddress): void
    {
        $this->record(AuditEvent::ROLE_ASSIGNMENT_LAPSED, $tenantId, $actorUserId, $actorLabel, $subjectUserId, $roleId, $ipAddress, [
            RoleEventMetadata::PROPOSAL_ID => $proposalId,
            RoleEventMetadata::LAPSE_REASON => $lapseReason,
        ]);
    }

    /**
     * `role.permissions_changed` — `CreateRole` (roles slice 4 C1) with the full set and `removed` empty; `UpdateRole` (C2)
     * when the code set changes, with `$fields` naming any of `name` / `description` the same edit changed (`SEC` D-7.3;
     * the key is written only when non-empty, so a creation's row is C1's shape). The ROLE is the subject
     * (`AuditEvent::SUBJECT_TYPES`), so `$roleId` is passed as both the subject and `RoleEventMetadata::ROLE_ID` — arm C's key
     * stays on every `role.*` row.
     *
     * @param list<string> $added
     * @param list<string> $removed
     * @param list<string> $fields
     */
    public function recordPermissionsChanged(string $tenantId, string $actorUserId, string $actorLabel, string $roleId, array $added, array $removed, ?string $ipAddress, array $fields = []): void
    {
        $extra = [
            RoleEventMetadata::ADDED => array_values($added),
            RoleEventMetadata::REMOVED => array_values($removed),
        ];
        if ([] !== $fields) {
            $extra[RoleEventMetadata::FIELDS] = array_values($fields);
        }

        $this->record(AuditEvent::ROLE_PERMISSIONS_CHANGED, $tenantId, $actorUserId, $actorLabel, $roleId, $roleId, $ipAddress, $extra);
    }

    /**
     * `role.updated` — `UpdateRole` (roles slice 4 C2) when ONLY the name and/or description changed (`SEC` D-7.3): `metadata`
     * `{role_id, fields}`, field NAMES only, never values. Subject the role, as `role.permissions_changed`.
     *
     * @param list<string> $fields
     */
    public function recordUpdated(string $tenantId, string $actorUserId, string $actorLabel, string $roleId, array $fields, ?string $ipAddress): void
    {
        $this->record(AuditEvent::ROLE_UPDATED, $tenantId, $actorUserId, $actorLabel, $roleId, $roleId, $ipAddress, [
            RoleEventMetadata::FIELDS => array_values($fields),
        ]);
    }

    /**
     * `role.deleted` — `DeleteRole` (roles slice 4 C3): `metadata` `{role_id}` only. Subject the role, as the other two
     * definition events.
     */
    public function recordDeleted(string $tenantId, string $actorUserId, string $actorLabel, string $roleId, ?string $ipAddress): void
    {
        $this->record(AuditEvent::ROLE_DELETED, $tenantId, $actorUserId, $actorLabel, $roleId, $roleId, $ipAddress);
    }

    /**
     * One statement for every `role.*` event, so the rows cannot drift apart in shape (reconciliation arms A, B and C read
     * them together). `ROLE_ID` is always the first key; `$extra` adds the proposal keys (ids) or, for a definition change,
     * the code lists. `$subjectId` is a user's id, or a role's for `role.permissions_changed` (`AuditEvent::SUBJECT_TYPES`).
     *
     * @param array<string, string|list<string>> $extra
     */
    private function record(string $event, string $tenantId, string $actorUserId, string $actorLabel, string $subjectId, string $roleId, ?string $ipAddress, array $extra = []): void
    {
        $metadata = json_encode([RoleEventMetadata::ROLE_ID => $roleId, ...$extra], \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES);

        $this->tenants->run($tenantId, static function (EntityManagerInterface $em) use ($event, $tenantId, $actorUserId, $actorLabel, $subjectId, $metadata, $ipAddress): void {
            $em->getConnection()->executeStatement(<<<'SQL'
                INSERT INTO audit_log
                    (id, tenant_id, occurred_at, event_type, actor_user_id, actor_label,
                     subject_type, subject_id, ip_address, metadata)
                VALUES
                    (?::uuid, ?::uuid, clock_timestamp(), ?, ?::uuid, ?, ?, ?::uuid, ?::inet, ?::jsonb)
                SQL, [
                    Uuid::v4()->toRfc4122(),
                    $tenantId,
                    $event,
                    $actorUserId,
                    $actorLabel,
                    AuditEvent::SUBJECT_TYPES[$event],
                    $subjectId,
                    $ipAddress,
                    $metadata,
                ]);
        });
    }
}
