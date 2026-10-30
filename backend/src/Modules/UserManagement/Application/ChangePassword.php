<?php

declare(strict_types=1);

namespace App\Modules\UserManagement\Application;

use App\Modules\AuditLog\Infrastructure\PasswordChangeAuditWriter;
use App\Modules\UserManagement\Domain\SealCheck;
use App\Modules\UserManagement\Domain\SealsCredentials;
use App\Modules\UserManagement\Domain\VerifiesPasswords;
use App\Modules\UserManagement\Infrastructure\Entity\AppUser;
use App\Platform\Http\Problem\ApiProblem;
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
 *  7  `$at` (first statement); `SELECT … FOR NO KEY UPDATE` on `app_user` (live +
 *     active + unchanged hash, else 401 / 422); refresh; `UPDATE app_user` (hash,
 *     seal, `sessions_valid_after`, `updated_at`) + E-23's `audit_log` row
 *                                                    — `erp_app`, txn C (write)
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
 * so it is read in the component's own unit. The message names neither the unit nor the
 * figure (`UX`, `ux-copy-frontend.md` §1): a person cannot act on 'bytes'.
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
 * A password change ends every session, the calling one included
 * ===========================================================================
 * Owner ruling P-A (2026-10-27; `RESIDUAL_GAPS.md` G-84 closed by W1-b). In the
 * step-7 transaction that writes the new hash, this class raises
 * `app_user.sessions_valid_after` (monotone, `AppUser::raiseSessionStamp`) to the
 * change second, and `IdentityReader` refuses every token minted at or before it —
 * **including the token that made this call**. The 200 is still answered; the
 * caller's next request is the constant 401. No individual token is listed or
 * revoked (there is still no session store, API-O-13); the principal's floor moves.
 *
 * **The floor `$at`** is the first statement inside the step-7 callback, after the
 * KDF, and is the latest of three seconds: the request start (a PHP clock stepping
 * back during the KDF cannot lower it), the clock now, and the calling token's
 * `iat` (`ChangePasswordCommand::$callingTokenIssuedAt` — that token always dies,
 * even when the application server's clock stepped back between its sign-in and
 * this change). Whole seconds, from the injected PHP PSR clock — **never the
 * database clock**, because `iat` is that PHP clock's second (Mail-C2's rule, MC-25).
 *
 * **The DBAL `SELECT … FOR NO KEY UPDATE` is the lock and the liveness check**, not
 * the ORM `refresh()`: the soft-delete filter would make a refresh of a deleted row
 * silent and lockless (`UserLiveness::loadUnderTheLock()`'s precondition). The read
 * requires the row live and active and still holding the hash step 3 verified:
 *   - no live active row → the gate's own constant 401 (`ApiProblem::
 *     authenticationFailed()`, E-7): a person switched off or deleted at the moment
 *     of the write is no longer a principal. *(Before W1-b this was a shipped
 *     defect: the step-7 `find()` hit the identity map, so the old
 *     `RuntimeException` guard was unreachable and a change on a person deleted or
 *     switched off mid-request answered 200, rewriting the row.)* Named limit: a
 *     switch-off and switch-back (or delete and restore) completed inside the KDF
 *     window is not seen — the lock read finds the row live again.
 *   - a different hash → 422 `current_password_invalid`, nothing written (a reset
 *     redeemed while ours was being hashed is not overwritten). `$stored` may come
 *     from the identity map; the compare is against the locked row, so a stale read
 *     is refused, never written over.
 * Lock strength: `FOR NO KEY UPDATE` — the strength the shipped plain `UPDATE`
 * already took, so a concurrent FK `KEY SHARE` check is not blocked; it still
 * conflicts with every other `app_user` writer (`UserUpdate`/`UserDeletion`'s
 * `FOR UPDATE`, the redeem's `UPDATE`), so serialisation is unchanged. The entity is
 * then refreshed (the step-2 instance is stale — a floor raised meanwhile must not
 * be overwritten lower) and written through `AppUser::replaceCredential()`.
 *
 * **Named costs:** a sign-in in the change's own second is refused once (the
 * whole-second floor G4 and Mail-C2 carry); a floor taken from a calling token's
 * `iat` ahead of a stepped-back server clock refuses a fresh sign-in for at most
 * that step. **Residual:** a sign-in that starts in a later second than `$at` and
 * reads the old credential before step 7 commits keeps its session — the window
 * is the step-7 transaction, not the KDF.
 *
 * @see SECURITY_MODEL.md §5 E-23, §5.1.4; E-20; API.md §4.2, §4.4.3, §9.2, §12.4
 */
final readonly class ChangePassword
{
    /** §5.2's `errors[]` code for the branch that says the current password did not verify. */
    public const CURRENT_PASSWORD_INVALID = 'current_password_invalid';

    /** The one copy of that branch's sentence: step 5 and the step-7 credential-moved refusal share it. */
    private const CURRENT_PASSWORD_INVALID_MESSAGE = 'The current password is not correct.';

    /** Step 7's three outcomes, returned out of the transaction callback. */
    private const WRITTEN = 'written';

    private const GONE = 'gone';

    private const MOVED = 'moved';

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
        // The request start: the floor below is never earlier than this (Mail-C2's `$now`).
        $start = $this->clock->now();

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
                'message' => self::CURRENT_PASSWORD_INVALID_MESSAGE,
            ]);
        }

        if ([] !== $errors) {
            return ChangePasswordResult::refused($errors);
        }

        // --- 6 ------------------------------------------------------------
        $hash = $this->passwords->hash($command->newPassword);
        $mac = $this->seals->seal($command->tenantId, $command->actorUserId, $hash);

        // --- 7 ------------------------------------------------------------
        $at = null;
        $outcome = $this->tenants->run($command->tenantId, function (EntityManagerInterface $em) use ($command, $hash, $mac, $stored, $start, &$at): string {
            // First statement inside the held transaction, after the KDF; floored at the request start and at the
            // calling token's iat; whole seconds; the PHP PSR clock (class docblock).
            $at = self::floorInstant($start, $this->clock->now(), $command->callingTokenIssuedAt);

            // The lock and the liveness check (class docblock): a DBAL read on `erp_app`, never the refresh.
            $locked = $em->getConnection()->fetchOne(
                'SELECT password_hash FROM app_user WHERE id = ?::uuid AND deleted_at IS NULL AND is_active FOR NO KEY UPDATE',
                [$command->actorUserId],
            );
            if (false === $locked) {
                return self::GONE;
            }
            if ($locked !== $stored) {
                return self::MOVED;
            }

            $user = $em->find(AppUser::class, Uuid::fromString($command->actorUserId))
                ?? throw new \LogicException('A row locked live a statement ago is not loadable.');
            // Re-read only, under the lock already held: the step-2 instance is stale.
            $em->refresh($user);
            $user->replaceCredential($hash, $mac, $at);
            $em->flush();

            // Inside this transaction, by re-entrancy. §9.2: audit is synchronous
            // and its failure rolls back the change it records.
            $this->trail->record(
                tenantId: $command->tenantId,
                actorUserId: $command->actorUserId,
                actorLabel: $command->actorLabel,
                ipAddress: $command->ipAddress,
            );

            return self::WRITTEN;
        });

        // Decided outside `run()`: an exception thrown inside it would close the shared entity manager.
        if (self::GONE === $outcome) {
            // Deleted or switched off since the gate: no longer a principal — the gate's own constant 401 (E-7).
            throw ApiProblem::authenticationFailed();
        }
        if (self::MOVED === $outcome) {
            // The credential was replaced while ours was being hashed: what the caller proved is no longer the
            // stored one, so nothing was written.
            return ChangePasswordResult::refused([[
                'pointer' => '/current_password',
                'code' => self::CURRENT_PASSWORD_INVALID,
                'message' => self::CURRENT_PASSWORD_INVALID_MESSAGE,
            ]]);
        }
        \assert($at instanceof \DateTimeImmutable);

        return ChangePasswordResult::changed($at);
    }

    /** The latest of the request start, the clock now and the calling token's `iat`, as a whole second. */
    private static function floorInstant(\DateTimeImmutable $start, \DateTimeImmutable $now, ?int $issuedAt): \DateTimeImmutable
    {
        return new \DateTimeImmutable('@'.max($start->getTimestamp(), $now->getTimestamp(), $issuedAt ?? \PHP_INT_MIN));
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
