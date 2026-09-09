<?php

declare(strict_types=1);

namespace App\Auth\Application;

use App\Audit\AuditEvent;
use App\Auth\Domain\PreAuthKeys;
use App\Auth\Domain\VerifiesPasswords;
use App\Auth\Infrastructure\AccessTokens;
use App\Auth\Infrastructure\AuthTrailWriter;
use App\Auth\Infrastructure\CredentialLookup;
use App\Auth\Infrastructure\CredentialRow;
use App\Auth\Infrastructure\EmailHasher;
use App\Auth\Infrastructure\LockoutStore;
use App\Auth\Infrastructure\PreAuthBudgets;
use App\Entity\Tenant;
use Psr\Clock\ClockInterface;

/**
 * `API.md` §7.1 — the sign-in sequence, with one collaborator per database
 * principal.
 *
 * ===========================================================================
 * The sequence, and which principal issues what
 * ===========================================================================
 *  1  normalise the slug and derive `ip_prefix`            — no statement
 *  2  consume the `(ip_prefix)` budget, then `(slug, ip)`  — **erp_ratelimit**
 *  3  read `auth_lockout` by primary key; decide nothing   — **erp_ratelimit**
 *  4  tenant → set_config → app_user, one transaction      — **erp_auth**
 *  5  verify the password, always, exactly once            — no statement
 * 6b  emit `sign-in-paused` if step 3's lock is live       — no statement
 *  6  `Tenant::permitsLogin()` on the status read at 4     — **no statement**
 * 6a  `is_active` on the value read at 4                   — **no statement**
 *  7  upsert `auth_lockout` on every surviving failure     — **erp_ratelimit**
 *  8  one `audit_log` row on the branches that reach it    — **erp_authlog**
 *  9  `last_login_at`, success only, after the JWT         — `erp_app`
 *
 * **No statement before step 9 is issued on `erp_app`.** That is B-1's second
 * half and it is not a convention: `erp_app` holds nothing at all on
 * `auth_attempt`, `auth_ip_budget` or `auth_lockout`, so a collaborator wired to
 * the wrong connection fails on its first statement rather than working while
 * the pre-authentication path quietly keeps everything else `erp_app` can reach.
 * `PreAuthPrincipalTest::theTokenPathIssuesNothingOnTheApplicationConnection`
 * asserts it per connection.
 *
 * ===========================================================================
 * Where this deviates from §7.1's numbering, and why — read this before editing
 * ===========================================================================
 * §7.1 lists **6b after 6 and 6a**. Taken as an emission order that makes the
 * *eleventh* failed attempt against a locked pair answer `authentication-failed`,
 * because the wrong-password branch is decided at step 5's result and would fire
 * first — and `SECURITY_MODEL.md` §5.1.3's *fails if* is explicit the other way:
 * *"the eleventh with anything but `sign-in-paused`"*. So the **emission** of 6b
 * is ordered ahead of every other refusal, while its **position relative to the
 * KDF** — the thing §7.1's own revision-2 note says the split exists for — is
 * unchanged: step 5 has run.
 *
 * Nothing else moves. E-7's ordering is intact (the status question is asked
 * only after the credential check) and so is §7.6's precedence (tenant status
 * before `is_active`). Recorded in `API.md` §14.2 rather than resolved silently.
 *
 * ===========================================================================
 * What makes E-7 hold, in one place
 * ===========================================================================
 * Every refusal returns `SignInOutcome::Refused` and the boundary renders one
 * constant problem for it, so **body, body length, status and headers are the
 * same object**. The KDF runs on every path exactly once, including both miss
 * paths. Steps 6 and 6a cost no statement because `CredentialRow` carried the
 * status and the flag out of step 4's transaction. And the two branches E-12
 * pairs — a wrong password and a verified credential against a non-active tenant
 * — write **one** `audit_log` row each, through the same method, at the same
 * point in this function.
 *
 * The residual §7.4 names is unclosed and is not disguised: the **unknown-email**
 * branch writes no trail row where the **wrong-password** branch writes one, so
 * the two differ by one `INSERT` on a separate connection. ADR-006 §2.2 requires
 * that difference (there is no principal to name on a miss), the KDF dominates
 * it by roughly two orders of magnitude, and **no timing pad is introduced here**
 * — a pad is a control this project has not chosen.
 */
final readonly class SignIn
{
    public function __construct(
        private PreAuthBudgets $budgets,
        private LockoutStore $lockouts,
        private EmailHasher $emailHasher,
        private CredentialLookup $credentials,
        private VerifiesPasswords $passwords,
        private AuthTrailWriter $trail,
        private AccessTokens $tokens,
        private LoginRecorder $loginRecorder,
        private ClockInterface $clock,
    ) {
    }

    public function attempt(SignInCommand $command): SignInResult
    {
        $now = $this->clock->now();

        // --- 1 ------------------------------------------------------------
        $slug = PreAuthKeys::slug($command->workspace);
        $email = PreAuthKeys::email($command->email);
        $ipPrefix = PreAuthKeys::ipPrefix($command->ipAddress);
        $emailHash = $this->emailHasher->hash($email);

        // --- 2 ------------------------------------------------------------
        // The IP budget first, and the early return is E-9: past this line
        // nothing is written to either attacker-keyed table.
        if (!$this->budgets->consumeIpBudget($ipPrefix, $now)) {
            return SignInResult::throttled();
        }

        if (!$this->budgets->consumeSlugBudget($slug, $ipPrefix, $now)) {
            return SignInResult::throttled();
        }

        // --- 3 ------------------------------------------------------------
        $pausedUntil = $this->lockouts->pausedUntil($slug, $emailHash, $now);

        // --- 4 ------------------------------------------------------------
        $row = $this->credentials->resolve($slug, $email);

        // --- 5 ------------------------------------------------------------
        $verified = $this->passwords->verify($row->passwordHash, $command->password);

        // --- 6b -----------------------------------------------------------
        // After the KDF, and ahead of every other refusal. See the deviation
        // note in the class docblock. No step-7 upsert and no step-8 row: the
        // pair is already paused, and re-accruing during a pause would extend it
        // indefinitely under attack, which is the denial of service ST-8b
        // time-boxes rather than accepts.
        if (null !== $pausedUntil) {
            return SignInResult::paused();
        }

        $eventType = $this->classify($row, $verified);

        if (AuditEvent::AUTH_LOGIN_SUCCESS !== $eventType) {
            // --- 7 --------------------------------------------------------
            $this->lockouts->recordFailure($slug, $emailHash);

            // --- 8 --------------------------------------------------------
            if (null !== $eventType) {
                $this->trail->record($eventType, $row->tenantId, (string) $row->userId, (string) $row->storedEmail, $command->ipAddress);
            }

            return SignInResult::refused();
        }

        $token = $this->tokens->issue($row->tenantId, (string) $row->userId, $now);

        $this->lockouts->clear($slug, $emailHash);
        $this->trail->record(AuditEvent::AUTH_LOGIN_SUCCESS, $row->tenantId, (string) $row->userId, (string) $row->storedEmail, $command->ipAddress);

        // --- 9 ------------------------------------------------------------
        // After the JWT is minted, and the only statement on `erp_app`.
        $this->loginRecorder->record($row->tenantId, (string) $row->userId, $now);

        return SignInResult::issued($token);
    }

    /**
     * Steps 6 and 6a, and the whole of §7.6's precedence rule.
     *
     * @return string|null the `AuditEvent` member this branch writes, or null
     *                     for the two miss paths ADR-006 §2.2 keeps off the
     *                     trail — *"there is no tenant to own the event"* and
     *                     *"the principal does not exist"*
     */
    private function classify(CredentialRow $row, bool $verified): ?string
    {
        // The sentinel is decided here, with the unresolved slug, and never
        // reaches the status question below (ST-7.3). `Tenant::permitsLogin()`
        // has no `sentinel` branch precisely so this routing cannot be moved
        // into it by accident.
        if (!$row->isResolvedPair()) {
            return null;
        }

        if (!$verified) {
            return AuditEvent::AUTH_LOGIN_FAILURE;
        }

        // Step 6 — X-2 / ADR-006 V-11, on the status already in memory.
        if (!Tenant::statusPermitsLogin($row->tenantStatus)) {
            return AuditEvent::AUTH_LOGIN_TENANT_INACTIVE;
        }

        // Step 6a — §7.6. Deliberately *after* step 6: tenant status is the
        // coarser fact, and putting the finer branch first would make E-12's row
        // unreachable for any deactivated user.
        if (!$row->userIsActive) {
            return AuditEvent::AUTH_LOGIN_FAILURE;
        }

        return AuditEvent::AUTH_LOGIN_SUCCESS;
    }
}
