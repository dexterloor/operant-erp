<?php

declare(strict_types=1);

namespace App\Modules\AuditLog\Infrastructure;

use App\Platform\Tenancy\TenantContext;
use App\SharedKernel\ValueObject\AuditEvent;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * **The writer of `user.deactivated`, `user.reactivated` and `user.updated`** — G4-C1 `UpdateUser`, 2026-10-23
 * (`docs/session13/g4-users-design.md` §5.3, D-9, D-10; `RoleAuditWriter`'s shape).
 *
 * All three are **SECURITY** events (`AuditEvent::SECURITY`; PC-11 (4a): a liveness change is privilege history, and (4c) bars
 * an `app_user` subject from the business allowlist), so each carries the request's IP and is read only through
 * `audit_log.view`. Subject `('app_user', <the person whose liveness or name changed>)`.
 *
 *  - `user.deactivated` / `user.reactivated`: `metadata` `{}`. The roles a deactivation (or a leftover strip) takes away are
 *    their own `role.revoked` rows with `revoke_reason`, and the lapsed proposals their own `role.assignment_lapsed` rows —
 *    written AFTER this row, through `RoleAuditWriter`, in the same transaction.
 *  - `user.updated`: `metadata` `{"fields": ["display_name"]}` — **field names only, never the old or new name** (D-9). The
 *    current name is the row's; the subject's own earlier rows keep the label they were written with (`actor_label`).
 *
 * `occurred_at` is `clock_timestamp()` in the `INSERT`'s own SQL, as every `role.*` row: the instant written, after the role
 * lock was taken (`audit_log_monotonic_occurred_at` keeps the tenant's folds ordered under a backward clock step, G-54).
 * Joins the caller's locked transaction (`TenantContext::run()` is re-entrant for the same tenant): the change and the row
 * that records it commit together or not at all.
 *
 * @see AuditEvent::USER_DEACTIVATED
 * @see AuditEvent::USER_REACTIVATED
 * @see AuditEvent::USER_UPDATED
 * @see AuditEvent::USER_DELETED
 * @see AuditEvent::USER_RESTORED
 */
final readonly class UserAuditWriter
{
    /** The only field `UpdateUser` renames, and so the only field `user.updated` can name. */
    public const FIELDS = ['display_name'];

    public function __construct(private TenantContext $tenants)
    {
    }

    /** `user.deactivated` — `UpdateUser`, after its flush, in its locked transaction. */
    public function recordDeactivated(string $tenantId, string $actorUserId, string $actorLabel, string $subjectUserId, ?string $ipAddress): void
    {
        $this->record(AuditEvent::USER_DEACTIVATED, $tenantId, $actorUserId, $actorLabel, $subjectUserId, $ipAddress, '{}');
    }

    /** `user.reactivated` — `UpdateUser`, after its flush, in its locked transaction. */
    public function recordReactivated(string $tenantId, string $actorUserId, string $actorLabel, string $subjectUserId, ?string $ipAddress): void
    {
        $this->record(AuditEvent::USER_REACTIVATED, $tenantId, $actorUserId, $actorLabel, $subjectUserId, $ipAddress, '{}');
    }

    /** `user.deleted` — `DeleteUser` (G4-C2), after its flush, in its locked transaction. `{}`. */
    public function recordDeleted(string $tenantId, string $actorUserId, string $actorLabel, string $subjectUserId, ?string $ipAddress): void
    {
        $this->record(AuditEvent::USER_DELETED, $tenantId, $actorUserId, $actorLabel, $subjectUserId, $ipAddress, '{}');
    }

    /** `user.restored` — `RestoreUser` (G4-C3), after its flush, in its locked transaction. `{}`. */
    public function recordRestored(string $tenantId, string $actorUserId, string $actorLabel, string $subjectUserId, ?string $ipAddress): void
    {
        $this->record(AuditEvent::USER_RESTORED, $tenantId, $actorUserId, $actorLabel, $subjectUserId, $ipAddress, '{}');
    }

    /** The fields `user.created` names (U-C1b, D-U6): never a value. */
    public const CREATED_FIELDS = ['display_name', 'email'];

    /** `user.created` — `CreateUser` (U-C1b), after the INSERT and its read-back, in its locked transaction. Field names only. */
    public function recordCreated(string $tenantId, string $actorUserId, string $actorLabel, string $subjectUserId, ?string $ipAddress): void
    {
        $this->record(AuditEvent::USER_CREATED, $tenantId, $actorUserId, $actorLabel, $subjectUserId, $ipAddress,
            json_encode(['fields' => self::CREATED_FIELDS], \JSON_THROW_ON_ERROR));
    }

    /** `user.updated` — a rename, `{"fields": ["display_name"]}`, never a value. */
    public function recordRenamed(string $tenantId, string $actorUserId, string $actorLabel, string $subjectUserId, ?string $ipAddress): void
    {
        $this->record(AuditEvent::USER_UPDATED, $tenantId, $actorUserId, $actorLabel, $subjectUserId, $ipAddress,
            json_encode(['fields' => self::FIELDS], \JSON_THROW_ON_ERROR));
    }

    private function record(string $event, string $tenantId, string $actorUserId, string $actorLabel, string $subjectUserId, ?string $ipAddress, string $metadata): void
    {
        $this->tenants->run($tenantId, static function (EntityManagerInterface $em) use ($event, $tenantId, $actorUserId, $actorLabel, $subjectUserId, $ipAddress, $metadata): void {
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
                    // Read off the catalogue: `audit_log_subject_pair` requires the pair to arrive together.
                    AuditEvent::SUBJECT_TYPES[$event],
                    $subjectUserId,
                    $ipAddress,
                    $metadata,
                ]);
        });
    }
}
