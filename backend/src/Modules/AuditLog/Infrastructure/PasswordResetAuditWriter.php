<?php

declare(strict_types=1);

namespace App\Modules\AuditLog\Infrastructure;

use App\SharedKernel\ValueObject\AuditEvent;
use Doctrine\DBAL\Connection;
use Symfony\Component\Uid\Uuid;

/**
 * **The mail worker's two audit events** (Mail-C1, `mail-c1-build-plan.md` R1.6 steps 4 and 9; route (ii), M-2):
 * `auth.password_reset.issued` and `auth.credential.identity_mismatch`, on **`erp_worker`** (bound in `services.yaml`),
 * inside the handler's own transaction — the token row, the audit row and the mail succeed or roll back together.
 *
 * - **Subjectless (E-13):** actor = the user the mail is about, `actor_label` = the stored email (sign-in's
 *   `AuthTrailWriter` precedent: neither pre-authentication principal can read `display_name`), `ip_address` = the
 *   requester's, carried on the message. Both events are IP-bearing (`audit_log_ip_address_scope`).
 * - **`occurred_at` is bound from the worker's clock**, the same clock {@see self::identityMismatchRecordedToday()}'s day
 *   bound comes from (final RED L-1). The audit-order trigger does not raise it: `erp_worker` is INSERT-only at table level
 *   (`RESIDUAL_GAPS.md` G-54 (i); `GrantFootprintTest::UNCLAMPED_AUDIT_WRITERS`).
 * - **Only these two events can be written on this connection** — the restrictive policy `audit_log_worker_events`
 *   refuses any other (RED L-1). Never a token, a verifier or a link in `metadata`.
 *
 * The caller owns the transaction and the tenant context (`TenantContext::establishOn()`); every method refuses outside a
 * transaction, where an autocommitted row would survive a failed send.
 */
final readonly class PasswordResetAuditWriter
{
    private const INSERT = <<<'SQL'
        INSERT INTO audit_log (id, tenant_id, occurred_at, event_type, actor_user_id, actor_label, subject_type, subject_id, ip_address, metadata)
        VALUES (?::uuid, ?::uuid, ?::timestamptz, ?, ?::uuid, ?, NULL, NULL, ?::inet, ?::jsonb)
        SQL;

    public function __construct(private Connection $worker)
    {
    }

    /**
     * `purpose` and `requested_by` are literals the HANDLER chooses (`anonymous` for a reset, `invitation` for an invitation;
     * U-C1 H-2, L-1), never a value read from a queued message: whoever can write to the queue must not be able to put a
     * name in the trail. Anything outside {@see self::REQUESTERS} is refused.
     */
    public function recordIssued(string $tenantId, string $userId, string $storedEmail, ?string $ipAddress, string $messageId, \DateTimeImmutable $at, string $purpose, string $requestedBy): void
    {
        self::requireRequester($purpose, $requestedBy);
        $this->insert(AuditEvent::AUTH_PASSWORD_RESET_ISSUED, $tenantId, $userId, $storedEmail, $ipAddress, $at,
            ['purpose' => $purpose, 'message_id' => $messageId, 'requested_by' => $requestedBy]);
    }

    /**
     * `$unknownKeyId`: the seal names a key id this worker does not hold (the park path, U-C1a; Mail-C1 carry item 5) —
     * `{"unknown_key_id": true}` is added, the sign-in's D-C2 shape. Otherwise the metadata is unchanged since Mail-C1.
     */
    public function recordIdentityMismatch(string $tenantId, string $userId, string $storedEmail, ?string $ipAddress, string $messageId, \DateTimeImmutable $at, string $requestedBy, bool $unknownKeyId): void
    {
        if (!\in_array($requestedBy, self::REQUESTERS, true)) {
            throw new \LogicException('An identity-mismatch row names a requester that is not a handler literal.');
        }
        $metadata = ['message_id' => $messageId, 'requested_by' => $requestedBy];
        if ($unknownKeyId) {
            $metadata[self::UNKNOWN_KEY_ID] = true;
        }
        $this->insert(AuditEvent::AUTH_CREDENTIAL_IDENTITY_MISMATCH, $tenantId, $userId, $storedEmail, $ipAddress, $at, $metadata);
    }

    /** The sign-in's metadata key (`SealsCredentials::UNKNOWN_KEY_ID`), restated: AuditLog does not import UserManagement's Domain. */
    private const UNKNOWN_KEY_ID = 'unknown_key_id';

    /** purpose => the one `requested_by` literal it may carry. */
    public const REQUESTERS = ['reset' => 'anonymous', 'invitation' => 'invitation'];

    private static function requireRequester(string $purpose, string $requestedBy): void
    {
        if ((self::REQUESTERS[$purpose] ?? null) !== $requestedBy) {
            throw new \LogicException('A link row names a purpose or requester that is not a handler literal.');
        }
    }

    /**
     * Whether this user already has an `auth.credential.identity_mismatch` row since `$dayStart` — the once-a-day
     * read-back (final RED L-1). One indexed probe (`audit_log_tenant_actor_occurred_id_idx`), under the caller's tenant
     * context, on the four columns `erp_worker` may read.
     */
    public function identityMismatchRecordedSince(string $userId, \DateTimeImmutable $dayStart): bool
    {
        $this->requireTransaction();

        return false !== $this->worker->fetchOne(
            'SELECT 1 FROM audit_log WHERE actor_user_id = ?::uuid AND event_type = ? AND occurred_at >= ?::timestamptz LIMIT 1',
            [$userId, AuditEvent::AUTH_CREDENTIAL_IDENTITY_MISMATCH, $dayStart->format('Y-m-d H:i:s.uP')],
        );
    }

    /** @param array<string, string|bool> $metadata */
    private function insert(string $event, string $tenantId, string $userId, string $label, ?string $ip, \DateTimeImmutable $at, array $metadata): void
    {
        $this->requireTransaction();
        $this->worker->executeStatement(self::INSERT, [
            Uuid::v4()->toRfc4122(), $tenantId, $at->format('Y-m-d H:i:s.uP'), $event, $userId, $label, $ip,
            json_encode($metadata, \JSON_THROW_ON_ERROR),
        ]);
    }

    private function requireTransaction(): void
    {
        if (0 === $this->worker->getTransactionNestingLevel()) {
            throw new \LogicException('PasswordResetAuditWriter runs inside the worker\'s transaction, so the row rolls back with a failed send.');
        }
    }
}
