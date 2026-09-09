<?php

declare(strict_types=1);

namespace App\Audit;

use App\Tenancy\TenantContext;
use Doctrine\DBAL\Connection;
use Symfony\Component\Uid\Uuid;

/**
 * **E-23 — a change to a principal's own credential writes exactly one
 * `audit_log` row, synchronously, on the request that made the change.**
 *
 * ===========================================================================
 * `erp_app`, and no new grant
 * ===========================================================================
 * E-23 names the principal: `erp_app` already holds `SELECT, INSERT` on
 * `audit_log` (S-9 leaves no role holding `UPDATE` or `DELETE`), which is
 * exactly what an append is and no more. **`erp_authlog` is the
 * pre-authentication path's principal and is deliberately not reachable here** —
 * a credential change happens behind `AuthenticationGate`, and a class able to
 * put a "this credential moved" row on the trail from an unauthenticated path
 * would be a worse thing to own than the convenience of reusing
 * `AuthTrailWriter`. That is also why `AuthTrailWriter`'s guard was narrowed to
 * `auth.login.` in the same pass: before it, this event satisfied its `auth.`
 * prefix and its `SUBJECTLESS` membership and the pre-auth writer would have
 * accepted it.
 *
 * ===========================================================================
 * Synchronous, and inside the caller's transaction
 * ===========================================================================
 * `API.md` §9.2 and `ARCHITECTURE.md` §6.1: audit is synchronous and its failure
 * must roll back the change it records. `TenantContext::run()` is re-entrant for
 * the same tenant, so calling it here **joins** the transaction
 * `App\Auth\Application\ChangePassword` already opened around the `UPDATE` —
 * one `COMMIT` covers the new hash and the row that says it moved, and neither
 * can survive the other. Called on its own (as `AuditWriterTest` calls it) it
 * opens its own transaction instead, which is why the method is written this way
 * rather than taking an already-open `EntityManagerInterface`.
 *
 * **There is no asynchronous form and there cannot be one:** `symfony/messenger`
 * is not installed (`API.md` §7.5, verified against `composer.lock`), and E-23
 * names a deferred write as a violation for a reason that survives the package
 * arriving — a credential change recorded only if a queue drains is recorded on
 * condition.
 *
 * ===========================================================================
 * The parameter list is the control
 * ===========================================================================
 * There is **no `$eventType`** — this class can write one event, exactly as
 * `DenialAuditWriter` can write one. There is **no `$metadata`**: E-23 forbids
 * any credential-derived value there — the password, a hash, a prefix, a length,
 * a strength score, a *"same as previous"* flag — and `metadata` is `jsonb` on an
 * append-only table no role can `UPDATE` or `DELETE`, so a value written there
 * is written permanently. A literal `'{}'::jsonb` with no parameter to fill it is
 * stronger than a validated one. And there is **no `$subjectType`/`$subjectId`**:
 * E-13 and `AuditEvent::SUBJECTLESS` put both at `NULL`, which is one of the two
 * shapes `audit_log_subject_pair` permits, and having no parameter enforces it
 * more firmly than a check would.
 *
 * **No `permission_code` either.** `audit_log_permission_code_scope` permits a
 * non-null code only on `authz.permission.denied`, so writing one here would
 * raise `23514`; there is nothing to write and no parameter that could.
 *
 * ===========================================================================
 * No cap, and this is the one place that difference is code rather than prose
 * ===========================================================================
 * `DenialAuditWriter` reads back before it writes because E-3a caps its event.
 * This class does not, and E-23 says why: E-3a caps a *record* because the server
 * cannot refuse the *action*, and a credential change **can** be refused. The
 * limit therefore lives on the operation
 * (`App\Auth\Application\PasswordChangeThrottle`, which refuses and writes
 * nothing) and the record of a change that actually happened is never suppressed.
 * A daily cap on this event is a violation of E-23 in terms.
 *
 * ===========================================================================
 * A failed audit write is a failed request
 * ===========================================================================
 * Nothing is caught here. Joining the caller's transaction means a failure rolls
 * the new password back with it, so the outcome is a 500 and an unchanged
 * credential rather than a changed credential nobody recorded. That is the trade
 * §9.2 requires, and it is the right way round: the user can try again; the
 * missing row cannot be reconstructed.
 *
 * @see SECURITY_MODEL.md §5 E-23, §5.1.4
 * @see \App\Audit\AuditEvent::AUTH_PASSWORD_CHANGED
 */
final readonly class PasswordChangeAuditWriter
{
    public function __construct(private TenantContext $tenants, private Connection $app)
    {
    }

    /**
     * Scalars rather than an `App\Api\Security\Identity`, for the reason
     * `DenialAuditWriter` gives: `App\Audit` does not acquire a dependency on
     * `App\Api`, and the HTTP caller is only the first one.
     *
     * `$actorLabel` is `app_user.display_name`, captured by the caller.
     * `audit_log.actor_label` is `NOT NULL` because the trail has to stay
     * readable after the principal is deleted.
     */
    public function record(string $tenantId, string $actorUserId, string $actorLabel, ?string $ipAddress): void
    {
        $this->tenants->run($tenantId, function () use ($tenantId, $actorUserId, $actorLabel, $ipAddress): void {
            $this->app->executeStatement(<<<'SQL'
                INSERT INTO audit_log
                    (id, tenant_id, occurred_at, event_type, actor_user_id, actor_label,
                     subject_type, subject_id, ip_address, metadata)
                VALUES
                    (?::uuid, ?::uuid, now(), ?, ?::uuid, ?, NULL, NULL, ?::inet, '{}'::jsonb)
                SQL, [
                    Uuid::v4()->toRfc4122(),
                    $tenantId,
                    AuditEvent::AUTH_PASSWORD_CHANGED,
                    $actorUserId,
                    $actorLabel,
                    $ipAddress,
                ]);
        });
    }
}
