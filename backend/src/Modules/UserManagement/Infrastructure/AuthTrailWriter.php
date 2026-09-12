<?php

declare(strict_types=1);

namespace App\Modules\UserManagement\Infrastructure;

use App\Platform\Tenancy\TenantContext;
use App\SharedKernel\ValueObject\AuditEvent;
use Doctrine\DBAL\Connection;
use Symfony\Component\Uid\Uuid;

/**
 * `API.md` §7.1 step 8 — the **first writer `audit_log` has ever had**, on
 * `erp_authlog`, which holds `INSERT` on that table and **no `SELECT`** and
 * nothing else anywhere.
 *
 * ---------------------------------------------------------------------------
 * Why this is DBAL and not the entity manager
 * ---------------------------------------------------------------------------
 * `AuditLogEntry` is mapped on the `default` entity manager, which is pinned to
 * the `app` connection. Persisting one would issue the `INSERT` as **`erp_app`**
 * — the one thing `API.md` §7.2's *fails if* forbids on this path, and the thing
 * `GrantFootprintTest::aRateLimiterWiredToTheWrongConnectionFailsImmediately`
 * exists to make loud. The entity's two construction-time guards are therefore
 * re-applied here rather than lost: the catalogue-membership check via
 * `AuditEvent::isKnown()`, and E-13's subject rule.
 *
 * ---------------------------------------------------------------------------
 * What is written, and what cannot be
 * ---------------------------------------------------------------------------
 * **E-13** — `subject_type` and `subject_id` are `NULL` on every `auth.*` event.
 * The event is about a principal and the principal is already `actor_user_id`;
 * `audit_log_subject_pair` permits exactly this shape. Enforced here by there
 * being no parameter for a subject, which is stronger than a check.
 *
 * **API-16** — `auth.*` are all in `AuditEvent::SECURITY`, so the row carries the
 * observed address. It is the address, not the `/64` limiter prefix: the prefix
 * is a bucketing key and would throw away the forensic value the column exists
 * for.
 *
 * **`actor_label` is the address `app_user` stores, never the one the client
 * sent.** `AuditLogEntry`'s docblock states the property this preserves — *"there
 * is no user-controlled string to escape because there is no user-controlled
 * string"* — and `erp_auth` holds no column grant on `display_name`, so the
 * ordinary label is unreachable from the pre-auth path. Using the request's
 * `email` verbatim would look identical and would not have that property; using
 * the **stored** `email` from the row that resolved does.
 *
 * ---------------------------------------------------------------------------
 * The daily cap is NOT implemented here, and that is a named gap
 * ---------------------------------------------------------------------------
 * §9.3 keys it `(tenant_id, user_id)` per UTC day over the **union** of
 * `auth.login.failure` and `auth.login.tenant_inactive`. §9.5 (b) answers where
 * the counter lives: nowhere yet — `erp_authlog` cannot `SELECT` the trail, and
 * the recommended column on `auth_lockout` is **API-O-6**, a `DATA` schema ask
 * that has not landed. So both branches write, uncapped.
 *
 * That is the E-7-safe direction and the reasoning is worth keeping: capped or
 * uncapped, the requirement is that the two branches behave *identically*, and
 * *"both always write one row"* satisfies it exactly as *"both fall silent"*
 * does. What is lost is ADR-006 §2.2's flooding bound — an attacker holding one
 * valid `(slug, email)` pair can still generate rows for that tenant — bounded
 * meanwhile by both E-21 budgets and by E-22. Routed in `API.md` §14.2.
 */
final readonly class AuthTrailWriter
{
    public function __construct(private Connection $authLog, private TenantContext $tenants)
    {
    }

    /**
     * One row, synchronously, under tenant context from the **resolved** id.
     *
     * `BEGIN` → `set_config` → `INSERT` → `COMMIT`, and the same three
     * statements on every branch that reaches the trail. E-12 requires the
     * tenant-inactive row to be written *"at the same branch point and with the
     * same statement count as the `auth.login.failure` row"*, and one method
     * with one caller per branch is how that stops being a promise.
     */
    public function record(string $eventType, string $tenantId, string $actorUserId, string $actorLabel, ?string $ipAddress): void
    {
        // `SUBJECTLESS` is derived from the catalogue and is E-13's half: it is
        // where the catalogue records that this event has no subject to declare.
        //
        // **The prefix is `auth.login.` and not `auth.`, and it was narrowed on
        // 2026-09-09 when E-23 added `auth.password.changed`.** The wider prefix
        // was correct while every `auth.*` member was a sign-in event; it stopped
        // being correct the moment the catalogue held an `auth.*` member that is
        // *subjectless* and belongs to the **authenticated** path. E-23 names
        // `erp_app` as that event's principal and says the `erp_authlog`
        // principal "is not reachable here" -- but this class satisfied both
        // halves of the old guard, so nothing but the absence of a caller stopped
        // a credential-change row being written from the pre-authentication path.
        // A guard that holds only because nobody has called it wrongly yet is not
        // a guard.
        //
        // This is still an allow-list on a prefix, not the `NOT LIKE` denylist
        // PC-11 forbids: it names what may be written and fails closed on
        // everything else, including on every event added tomorrow.
        if (!str_starts_with($eventType, 'auth.login.') || !\in_array($eventType, AuditEvent::SUBJECTLESS, true)) {
            throw new \InvalidArgumentException(sprintf(
                'E-13: "%s" is not an auth.login.* event declared in AuditEvent::SUBJECTLESS, so the '
                .'pre-authentication writer must not write it. A credential-change event is E-23\'s and '
                .'is written by erp_app on the authenticated path.',
                $eventType,
            ));
        }

        $this->authLog->beginTransaction();

        try {
            $this->tenants->establishOn($this->authLog, $tenantId);
            $this->authLog->executeStatement(<<<'SQL'
                INSERT INTO audit_log (id, tenant_id, occurred_at, event_type, actor_user_id, actor_label, subject_type, subject_id, ip_address, metadata)
                VALUES (?::uuid, ?::uuid, now(), ?, ?::uuid, ?, NULL, NULL, ?::inet, '{}'::jsonb)
                SQL, [Uuid::v4()->toRfc4122(), $tenantId, $eventType, $actorUserId, $actorLabel, $ipAddress]);
            $this->authLog->commit();
        } catch (\Throwable $e) {
            $this->authLog->rollBack();

            throw $e;
        }
    }
}
