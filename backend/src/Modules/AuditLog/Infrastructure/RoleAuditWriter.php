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
 * `role.revoked` (C2) and `role.assigned` (C3). Both are **security** events (`AuditEvent::SECURITY`): read only
 * through `audit_log.view`, and they carry the request's IP (E-3's field; `audit_log_ip_address_scope` admits one for
 * `role.*`, measured) — the `PasswordChangeAuditWriter` precedent.
 *
 *  - **Subject:** `('app_user', <the user whose privileges changed>)`, read off `AuditEvent::SUBJECT_TYPES`.
 *  - **`metadata` is exactly `{RoleEventMetadata::ROLE_ID: <role id>}`** — the key is the constant the reconciliation
 *    reads (G-10 condition (1), A-7), bound as a parameter with the value, never interpolated. Nothing else is copied:
 *    the role's name and codes are the role row's, and PC-15 condition 6 forbids display data in `metadata`.
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

    /** `role.revoked` — `RevokeRole` (C2), after its `DELETE`, in the same locked transaction. */
    public function recordRevoked(string $tenantId, string $actorUserId, string $actorLabel, string $subjectUserId, string $roleId, ?string $ipAddress): void
    {
        $this->record(AuditEvent::ROLE_REVOKED, $tenantId, $actorUserId, $actorLabel, $subjectUserId, $roleId, $ipAddress);
    }

    /** One statement for both events, so the two rows cannot drift apart in shape (reconciliation arms A and B read them together). */
    private function record(string $event, string $tenantId, string $actorUserId, string $actorLabel, string $subjectUserId, string $roleId, ?string $ipAddress): void
    {
        $this->tenants->run($tenantId, static function (EntityManagerInterface $em) use ($event, $tenantId, $actorUserId, $actorLabel, $subjectUserId, $roleId, $ipAddress): void {
            $em->getConnection()->executeStatement(<<<'SQL'
                INSERT INTO audit_log
                    (id, tenant_id, occurred_at, event_type, actor_user_id, actor_label,
                     subject_type, subject_id, ip_address, metadata)
                VALUES
                    (?::uuid, ?::uuid, clock_timestamp(), ?, ?::uuid, ?, ?, ?::uuid, ?::inet, jsonb_build_object(?::text, ?::text))
                SQL, [
                    Uuid::v4()->toRfc4122(),
                    $tenantId,
                    $event,
                    $actorUserId,
                    $actorLabel,
                    AuditEvent::SUBJECT_TYPES[$event],
                    $subjectUserId,
                    $ipAddress,
                    RoleEventMetadata::ROLE_ID,
                    $roleId,
                ]);
        });
    }
}
