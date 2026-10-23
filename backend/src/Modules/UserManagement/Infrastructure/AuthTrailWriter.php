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
 * The daily cap — E-12, ADR-006 §2.2 and Amendment 1 A1.3 (built at plan step C1)
 * ---------------------------------------------------------------------------
 * **C = 20 rows per `(slug_normalised, email_hash)` per UTC day**, counted over
 * the union of `auth.login.failure` and `auth.login.tenant_inactive`, so the two
 * branches fall silent together. `recordFailure()` is handed the count that
 * `LockoutStore::recordFailure()`'s step-7 upsert returned (`RETURNING`, on
 * `erp_ratelimit`), and decides from it alone:
 *
 * - attempts **1–20** write the ordinary row;
 * - attempt **21** writes the ordinary row with `metadata` = `{"suppression_started": true}`,
 *   server-set, through the **same statements** — the flag is a bound
 *   value, not a statement;
 * - from attempt **22** it issues **nothing**, on any connection.
 *
 * So at most 21 rows per pair per day, and the cap reads nothing: `erp_authlog`
 * still holds `INSERT` and no `SELECT`, and no statement is added anywhere
 * (`LoginAuditCapTest::theCeilingAddsNoStatementOnEitherMissPath`). Counting
 * continues past the ceiling on `auth_lockout`, and the day's suppressed total is
 * lost at the UTC reset — no statement writes it to the trail, because one would
 * be a statement on the capped branch (A1.3).
 *
 * **Successful sign-ins stay uncapped** (A1.3 residual 5): `record()` has no
 * ceiling, and `SignIn` calls it on the success branch only.
 *
 * *(This section read "The daily cap is NOT implemented here, and that is a
 * named gap", with both branches writing uncapped, until C1 built the storage
 * E4-4 authorised and the key X-5 agreed.)*
 */
final readonly class AuthTrailWriter
{
    /**
     * The owner-accepted ceiling (E-12, ADR-006 A1.3, 2026-09-14). The row at
     * `DAILY_CEILING + 1` is the marker; nothing is written after it.
     */
    public const DAILY_CEILING = 20;

    /** The server-set `metadata` key the marker row carries. */
    public const SUPPRESSION_STARTED = 'suppression_started';

    /** E-12's capped members: the wrong password, the non-active tenant, and (Cred-C1) the seal that did not verify. */
    public const CAPPED = [AuditEvent::AUTH_LOGIN_FAILURE, AuditEvent::AUTH_LOGIN_TENANT_INACTIVE, AuditEvent::AUTH_LOGIN_CREDENTIAL_MISMATCH];

    public function __construct(private Connection $authLog, private TenantContext $tenants)
    {
    }

    /**
     * Step 8 on the two capped branches: one row while `$attemptToday` is within
     * the ceiling, the marker row at `DAILY_CEILING + 1`, and nothing after.
     *
     * **Cred-C1:** `auth.login.credential_mismatch` (the KDF passed, the seal did not) is the third capped member, through
     * the same statements, so a wrong key on a server writes at most C + 1 rows per pair per day and the branch adds no
     * statement (E-7). `$flags` is server-set only — today `SealsCredentials::UNKNOWN_KEY_ID` on that event (final RED
     * M-1) — and the marker row keeps it beside `suppression_started`.
     *
     * @param int                $attemptToday `LockoutAccrual::$suppressionCount` from this request's step-7 upsert
     * @param array<string, true> $flags       server-set metadata flags, never client input
     */
    public function recordFailure(string $eventType, string $tenantId, string $actorUserId, string $actorLabel, ?string $ipAddress, int $attemptToday, array $flags = []): void
    {
        if (!\in_array($eventType, self::CAPPED, true)) {
            throw new \InvalidArgumentException(sprintf('E-12: "%s" is not one of the three capped login-failure events.', $eventType));
        }

        if ($attemptToday > self::DAILY_CEILING + 1) {
            return;
        }

        $metadata = self::DAILY_CEILING + 1 === $attemptToday ? [...$flags, self::SUPPRESSION_STARTED => true] : $flags;

        $this->write($eventType, $tenantId, $actorUserId, $actorLabel, $ipAddress, $metadata);
    }

    /**
     * One row, synchronously, uncapped — the success branch's writer.
     */
    public function record(string $eventType, string $tenantId, string $actorUserId, string $actorLabel, ?string $ipAddress): void
    {
        $this->write($eventType, $tenantId, $actorUserId, $actorLabel, $ipAddress, []);
    }

    /**
     * One row, synchronously, under tenant context from the **resolved** id.
     *
     * `BEGIN` → `set_config` → `INSERT` → `COMMIT`, and the same statements on
     * every branch that reaches the trail, the C+1 marker included. E-12 requires
     * the tenant-inactive row to be written *"at the same branch point and with the
     * same statement count as the `auth.login.failure` row"*, and one method
     * with one caller per branch is how that stops being a promise.
     *
     * @param array<string, true> $metadata server-set only; no caller passes client input
     */
    private function write(string $eventType, string $tenantId, string $actorUserId, string $actorLabel, ?string $ipAddress, array $metadata): void
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
                VALUES (?::uuid, ?::uuid, now(), ?, ?::uuid, ?, NULL, NULL, ?::inet, ?::jsonb)
                SQL, [Uuid::v4()->toRfc4122(), $tenantId, $eventType, $actorUserId, $actorLabel, $ipAddress,
                    [] === $metadata ? '{}' : json_encode($metadata, \JSON_THROW_ON_ERROR)]);
            $this->authLog->commit();
        } catch (\Throwable $e) {
            $this->authLog->rollBack();

            throw $e;
        }
    }
}
