<?php

declare(strict_types=1);

namespace App\Modules\UserManagement\Application;

use App\Modules\AuditLog\Infrastructure\PasswordChangeAuditWriter;
use App\Modules\UserManagement\Domain\SealCheck;
use App\Modules\UserManagement\Domain\SealsCredentials;
use App\Modules\UserManagement\Domain\VerifiesPasswords;
use App\Modules\UserManagement\Infrastructure\Entity\AppUser;
use App\Platform\Tenancy\TenantContext;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\Uid\Uuid;

/**
 * `ChangePassword` — `POST /api/v1/me/password`, §4.2's Authentication block.
 *
 * ===========================================================================
 * The sequence, and which of it is inside a transaction
 * ===========================================================================
 *  1  throttle probe: E-23's bound on the action     — `erp_app`, txn A (read)
 *  2  read this principal's stored hash and seal     — `erp_app`, txn B (read)
 *  3  verify the current password and the seal       — **no statement, no txn**
 *  4  the new-password checks                        — no statement
 *  5  refuse on either, with every applicable pointer— no statement
 *  6  hash the new password                          — **no statement, no txn**
 *  7  `UPDATE app_user` + E-23's `audit_log` row     — `erp_app`, txn C (write)
 *
 * **Steps 3 and 6 are outside every transaction, and that is ADR-006 §6.1 rather
 * than tidiness.** O-1 was closed on measurement: 1505 ms of pooled-backend
 * pinning. This operation runs the KDF **twice** — once to verify, once to hash —
 * so holding a PgBouncer backend across both would be twice the cost §7.1 step 5
 * already refuses to pay on the sign-in path. `PasswordVerifier` touches no
 * connection, which is what makes the split possible.
 *
 * Step 7 is **one** transaction covering both writes, because `API.md` §9.2 and
 * `ARCHITECTURE.md` §6.1 require the audit write to be synchronous and its
 * failure to roll back the change it records. `PasswordChangeAuditWriter` calls
 * `TenantContext::run()`, which is re-entrant for the same tenant and therefore
 * **joins** this transaction rather than opening a second one.
 *
 * ===========================================================================
 * E-7's discipline at this endpoint — ruled, not inherited
 * ===========================================================================
 * Verifying the current password is a **second credential-comparison surface**,
 * so the question has to be answered rather than skipped.
 *
 * **What is NOT carried over: indistinguishability.** E-7 makes every sign-in
 * refusal one object because the caller might be anybody and the branches
 * disclose whether a workspace, an address or a password exists. Here
 * `AuthenticationGate` has already resolved this caller as this principal from a
 * signed token. There is no third party to protect: the current password is a
 * secret the caller either holds or does not, and the response has to say which
 * of the caller's **own** two inputs was wrong or the user cannot act on it. A
 * masked refusal would also be self-defeating — the status code already
 * separates 200 from 422 — so it would buy silence on a channel that is not
 * silent anyway.
 *
 * **What IS carried over, and it is the half with teeth: the KDF runs exactly
 * once on every path that reaches step 3, and is never short-circuited.** Step 4
 * cannot return ahead of step 3, and step 5 reports *both* refusals together
 * rather than the first one found. Three things follow:
 *
 *   1. A wrong-current-password request costs the server exactly what a correct
 *      one does, so this endpoint is not a cheap oracle even in aggregate, and
 *      the throttle's arithmetic is over a constant-cost operation.
 *   2. The obvious "optimisation" — check the new password first, it is free —
 *      is the one that would make the branches separable by the clock, and it is
 *      structurally unavailable rather than discouraged.
 *   3. If anybody later rules that these branches **should** be
 *      indistinguishable (an administrative reset arriving under API-O-12 would
 *      be the trigger, because then the actor and the subject differ), they
 *      inherit a path with no timing difference already built into it.
 *
 * `PasswordChangeTest::theKdfRunsExactlyOnceOnEveryPathThatReachesIt` drives all
 * five combinations through `CountingKdf`. **Mutation:** move the
 * new-password checks ahead of the verify and return early; the two
 * bad-new-password cases go to zero invocations; red.
 *
 * ===========================================================================
 * The strength policy is **E-24**, and it is still not this class's to mint
 * ===========================================================================
 * This docblock previously read *"there is no password strength policy, and
 * inventing one here was refused"*, and the refusal was right: `SECURITY_MODEL.md`
 * §5 is *"the single register for `E-*`"*. **E-24 now exists** — a minimum of
 * **twelve characters**, the user's decision of 2026-09-10 — so the rule is
 * enforced here and **decided there**. `PasswordChangeTest` reconciles
 * `self::MINIMUM_LENGTH` against a literal pinned in the test file and against
 * E-24's own row, so a figure changed in one place and not the other is red.
 *
 * The other two checks remain **structural**, derived from what the KDF and the
 * column require rather than from a strength opinion: an empty string hashes to
 * a perfectly valid digest and is a credential nobody chose; and
 * `NativePasswordHasher` **throws** above `PasswordHasherInterface::MAX_PASSWORD_LENGTH`,
 * which would be a 500 on a request that is simply invalid, so the limit is read
 * off the component rather than written down here. Empty keeps its own code
 * rather than folding into *too short*: nothing sent and eleven characters sent
 * are different mistakes, and the client's message differs.
 *
 * **The minimum counts characters; the maximum counts bytes**, and the asymmetry
 * is deliberate. Twelve *characters* is what E-24 decides and what a person
 * choosing a password understands; 4096 *bytes* is what the component refuses,
 * so it is read in the component's own unit.
 *
 * **No maximum below the component's ceiling, and that was measured rather than
 * assumed.** A 72-byte cap was proposed on the grounds that bcrypt truncates
 * there. It does not, on this code path: `NativePasswordHasher` pre-hashes with
 * `base64(sha512())` above 72 bytes on **both** the hash and the verify side, so
 * an 80-byte pair sharing a 72-byte prefix returns `false` — and since
 * 2026-09-10 the KDF is Argon2id, which does not truncate at all. The cap would
 * have rejected working passphrases to close a hole that was already shut.
 * **API-O-24 asserted the opposite and has been corrected as a stale-claim row.**
 * A breach-list check and a reuse rule remain unowned by this class and unwritten.
 *
 * **All three checks run after the verify and none of them returns ahead of it** —
 * see the E-7 ruling above. A length rule is the cheapest possible early return
 * and therefore the most tempting one to hoist, which is exactly why it is not.
 *
 * ===========================================================================
 * A password change does not end other sessions — by choice, not impossibility
 * ===========================================================================
 * **The token the caller used to make this change keeps working after it, and so
 * does any other live token for this principal**, for up to E-20's eight hours.
 * This docblock used to say revocation "needs storage that D-14 declined"; that
 * has been false since G4-C0 built `app_user.sessions_valid_after`, which
 * `IdentityReader` honours on every request. So it is now a choice: this class
 * does **not** raise that floor, while a redeemed reset link does (Mail-C2,
 * `PasswordResetRedemption`). The asymmetry is recorded (`RESIDUAL_GAPS.md`
 * G-84, Mail-C2's R-C2-5; owner notice N-7) — someone who changes a password because
 * they suspect an intruder keeps that intruder's session — and a later small
 * commit can raise the floor here too. It is stated on `PasswordResource`, where
 * a client reads it, and in §4.4.3's additions row.
 *
 * @see SECURITY_MODEL.md §5 E-23, §5.1.4; E-20; API.md §4.2, §4.4.3, §9.2, §12.4
 */
final readonly class ChangePassword
{
    /** §5.2's `errors[]` code for the branch that says the current password did not verify. */
    public const CURRENT_PASSWORD_INVALID = 'current_password_invalid';

    /** The two structural new-password codes. See the class docblock. Aliases of `PasswordRules`' (Mail-C2). */
    public const NEW_PASSWORD_EMPTY = PasswordRules::EMPTY;

    public const NEW_PASSWORD_TOO_LONG = PasswordRules::TOO_LONG;

    /** E-24's code. The figure below is the register's, not this class's. */
    public const NEW_PASSWORD_TOO_SHORT = PasswordRules::TOO_SHORT;

    /**
     * `SECURITY_MODEL.md` §5, **E-24** — twelve **characters**, counted as UTF-8
     * code points. The user's decision of 2026-09-10, not a derived figure.
     *
     * Reconciled against the register by
     * `PasswordChangeTest::theMinimumLengthIsTheOneTheEnforcementRegisterSets`,
     * which pins 12 as a literal of its own rather than reading this constant
     * into its own expectation — the fake-green `PreAuthBudgetTest` and
     * `AuthLockoutThresholdTest` shipped with first time.
     */
    public const MINIMUM_LENGTH = PasswordRules::MINIMUM_LENGTH;

    public function __construct(
        private TenantContext $tenants,
        private EntityManagerInterface $em,
        private VerifiesPasswords $passwords,
        private PasswordChangeThrottle $throttle,
        private PasswordChangeAuditWriter $trail,
        private ClockInterface $clock,
        private SealsCredentials $seals,
    ) {
    }

    public function change(ChangePasswordCommand $command): ChangePasswordResult
    {
        // --- 1 ------------------------------------------------------------
        // Ahead of the KDF, so a throttled caller costs nothing to refuse. It
        // refuses the *action* and writes no row; E-23 forbids capping the
        // record of a change that happened.
        if (!$this->throttle->admits($command->tenantId, $command->actorUserId)) {
            return ChangePasswordResult::throttled();
        }

        // --- 2 ------------------------------------------------------------
        [$stored, $storedMac] = $this->storedCredentialFor($command->tenantId, $command->actorUserId);

        // --- 3 ------------------------------------------------------------
        // Always, exactly once, and never behind step 4. `null` is reachable —
        // the principal was soft-deleted between the gate and here — and the
        // verifier runs the dummy hash for it rather than short-circuiting,
        // which is the same rule §7.1 step 5 applies on the miss paths.
        $verified = $this->passwords->verify($stored, $command->currentPassword);
        // Cred-C1: a stored credential whose seal does not verify — a hash copied or transplanted by SQL, a NULLed or forged
        // seal — cannot be changed: the ordinary `current_password_invalid`, with no distinct trail (design §3.3; the
        // caller is this principal, so there is no third party to tell). Computed on every path, never short-circuited.
        $sealed = SealCheck::Verified === $this->seals->check(
            $command->tenantId,
            null === $stored ? SealsCredentials::DUMMY_USER_ID : $command->actorUserId,
            $stored ?? SealsCredentials::DUMMY_HASH,
            $storedMac,
        );
        $verified = $verified && $sealed;

        // --- 4 and 5 ------------------------------------------------------
        $errors = $this->newPasswordErrors($command->newPassword);

        if (!$verified) {
            // Prepended rather than appended: it is the refusal a client should
            // surface first, and both are reported together so the response does
            // not depend on which check ran.
            array_unshift($errors, [
                'pointer' => '/current_password',
                'code' => self::CURRENT_PASSWORD_INVALID,
                'message' => 'The current password is not correct.',
            ]);
        }

        if ([] !== $errors) {
            return ChangePasswordResult::refused($errors);
        }

        // --- 6 ------------------------------------------------------------
        $hash = $this->passwords->hash($command->newPassword);
        $mac = $this->seals->seal($command->tenantId, $command->actorUserId, $hash);

        // --- 7 ------------------------------------------------------------
        $at = $this->clock->now();
        $this->tenants->run($command->tenantId, function (EntityManagerInterface $em) use ($command, $hash, $mac): void {
            $user = $em->find(AppUser::class, Uuid::fromString($command->actorUserId));

            // Step 2 already resolved a hash for this principal inside this
            // request, so `null` here is a row that vanished between two
            // transactions. Refusing to write is right and refusing *loudly* is
            // right: unlike the sign-in path there is no response that would be
            // correct, and a silent no-op would answer 200 for a change that did
            // not happen — which is precisely the state E-23 exists to make
            // impossible.
            if (!$user instanceof AppUser) {
                throw new \RuntimeException(
                    'The principal resolved at authentication no longer exists, so no credential was changed. '
                    .'E-23 requires the row and the change to stand or fall together.'
                );
            }

            $user->replaceCredential($hash, $mac);
            $em->flush();

            // Inside this transaction, by re-entrancy. §9.2: audit is synchronous
            // and its failure rolls back the change it records.
            $this->trail->record(
                tenantId: $command->tenantId,
                actorUserId: $command->actorUserId,
                actorLabel: $command->actorLabel,
                ipAddress: $command->ipAddress,
            );
        });

        return ChangePasswordResult::changed($at);
    }

    /**
     * E-24, through the one copy (`PasswordRules`, extracted at Mail-C2 so a reset sets a password under the same rule).
     *
     * @return list<array{pointer: string, code: string, message: string}>
     */
    private function newPasswordErrors(string $newPassword): array
    {
        return PasswordRules::errors($newPassword, '/new_password');
    }

    /** @return array{0: ?string, 1: ?string} the stored hash and seal, or `[null, null]` when the row has gone */
    private function storedCredentialFor(string $tenantId, string $actorUserId): array
    {
        $stored = $this->tenants->run($tenantId, function (EntityManagerInterface $em) use ($actorUserId): array {
            $user = $em->find(AppUser::class, Uuid::fromString($actorUserId));

            return $user instanceof AppUser ? [$user->getPasswordHash(), $user->getPasswordMac()] : [null, null];
        });

        \assert(\is_array($stored));

        return $stored;
    }
}
