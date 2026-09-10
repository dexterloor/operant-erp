<?php

declare(strict_types=1);

namespace App\Modules\UserManagement\Infrastructure;

use App\Modules\UserManagement\Domain\VerifiesPasswords;
use Symfony\Component\PasswordHasher\Hasher\NativePasswordHasher;
use Symfony\Component\PasswordHasher\PasswordHasherInterface;

/**
 * `API.md` §7.1 step 5 — *"Outside every transaction: verify the password —
 * **always, exactly once**, against a fixed dummy hash of the same cost factor
 * on either miss path."*
 *
 * ---------------------------------------------------------------------------
 * Outside the transaction, and that is ADR-006 §6.1 rather than tidiness
 * ---------------------------------------------------------------------------
 * `ARCHITECTURE.md` §3 originally placed the verification between the `app_user`
 * `SELECT` and `COMMIT`, which pins a pooled backend across ~200 ms of
 * deliberately-slow CPU work **on the one path an attacker can drive for free**.
 * `CredentialLookup` commits before this class is called, and nothing here
 * touches a connection.
 *
 * ---------------------------------------------------------------------------
 * The KDF is Argon2id, and there is deliberately no bcrypt fallback
 * ---------------------------------------------------------------------------
 * Switched 2026-09-10, user-approved. The hazard the switch closes is a **mixed
 * store**: a bcrypt-hashed user beside an Argon2id dummy makes the two miss paths
 * measurably cheaper than the wrong-password path, which rebuilds the
 * user-existence oracle E-7 exists to remove. There was no migration window to
 * arrange because there was nothing to migrate — `app_user` held zero rows and
 * the only bcrypt strings in the tree were the two dummies replaced below
 * (grepped: no `$2y$` in `migrations/`, and no seed writes `app_user`).
 *
 * **A verifier that accepted both formats would *be* that mixed store, at
 * runtime, forever**, so this class refuses anything but Argon2id at
 * construction rather than falling back.
 *
 * ---------------------------------------------------------------------------
 * One configured value, so the dummy and the live hashes cannot drift
 * ---------------------------------------------------------------------------
 * *"A dummy hash of the same cost factor"* is a requirement about **two** values,
 * and two configured numbers drift. So there is one configured value — the dummy
 * hash itself, `%app.auth.dummy_password_hash%` — and the KDF's parameters are
 * **read out of it**. bcrypt had one knob (`cost`); Argon2id reports three
 * (`memory_cost`, `time_cost`, `threads`), so the same construction now carries
 * three, and the agreement between the dummy and the hasher is checked
 * **behaviourally** rather than by comparing numbers: `needsRehash($dummyHash)`
 * is the component's own answer to *"would I re-hash this?"*, and it is true
 * exactly when one of the three has drifted.
 *
 * `threads` is pinned to 1 because `NativePasswordHasher` pins it to 1 and takes
 * no argument for it — a dummy at any other value is unbuildable here, so it is
 * refused loudly instead of being silently ignored.
 *
 * The dummy is an Argon2id hash of 32 random bytes nobody recorded. It is not a
 * secret — it matches no password by construction — which is why it sits in
 * `services.yaml` rather than in the environment (Standing Rule 10 governs
 * values that *are* secret, and hiding a non-secret makes the real ones harder
 * to find).
 *
 * ---------------------------------------------------------------------------
 * The floor, and the short-circuit it exists to close (fake-green eighteen)
 * ---------------------------------------------------------------------------
 * `NativePasswordHasher::verify()` opens with
 * `if ('' === $plainPassword || $this->isPasswordTooLong($plainPassword)) return false;`
 * — **above every algorithm branch**, so it returns `false` at **zero KDF rounds**
 * for the empty string and for anything over
 * `PasswordHasherInterface::MAX_PASSWORD_LENGTH`. Measured at production cost:
 *
 * ```
 * wrong password -> false in 223.265 ms   (Argon2id m=65536,t=4,p=1)
 * EMPTY          -> false in   0.000 ms
 * OVER-LONG      -> false in   0.000 ms
 * ```
 *
 * Measured again after the switch, because *"Argon2id fixes it"* was a guess
 * worth refuting: **it does not** — the short-circuit is in the wrapper, not in
 * the algorithm, and bcrypt at cost 4 and Argon2id at every parameter set tried
 * behave identically on those two inputs.
 *
 * That is **not** an E-7 break: an empty password is attacker-chosen and
 * identical for every account, so the branch discloses nothing about a tenant or
 * a user. It destroys the **constant-cost premise** instead, and it made
 * `API.md` §7.3's *"exactly one KDF invocation on every path"* false as written.
 *
 * The floor below is the fix: an unusable plaintext is replaced by a fixed,
 * non-empty, in-range candidate **before** the delegate sees it, so the KDF runs
 * on every path, and the result is conjoined with `$usable` so the substitution
 * can never authenticate anything — the same shape as the `null !== $hash`
 * conjunction that makes the dummy-hash substitution safe.
 *
 * **It opens no denial-of-service surface, and the reason is worth stating
 * rather than assumed.** The floor turns two free refusals into paid ones, so
 * the obvious worry is an attacker sending megabyte passwords. But the price is
 * *one* KDF run — exactly what a ten-character wrong password already costs, and
 * the candidate substituted is a short constant, so the work does not scale with
 * the input. The server's cost per request is unchanged; what changed is that it
 * is now the **same** on every branch, which is the whole point. Argon2id's
 * memory footprint per concurrent verify is a separate question and a real one:
 * see the report accompanying this change.
 *
 * @see VerifiesPasswords for why the KDF is behind a seam at all
 */
final class PasswordVerifier implements VerifiesPasswords
{
    /**
     * The candidate a plaintext the delegate would refuse to process is replaced
     * by. Fixed, non-empty and well inside the length ceiling, so it always
     * reaches the KDF; the caller's answer is `false` regardless, by the
     * conjunction in `verify()`.
     */
    private const UNUSABLE_CANDIDATE = 'ELpJ2fbnvhaz2hkbjPkqYmDs+t3lLNBOMg0Wd6vw9tk=';

    /** @var array{memory_cost: int, time_cost: int, threads: int} */
    private readonly array $parameters;

    private readonly PasswordHasherInterface $kdf;

    /**
     * @param PasswordHasherInterface|null $kdf the KDF, defaulting to the one
     *                                          `kdfFor()` builds from the dummy.
     *                                          It is an argument so the test
     *                                          environment can decorate it and
     *                                          count invocations **at the KDF
     *                                          boundary** rather than at this
     *                                          class's own surface; it can never
     *                                          become a second source of truth,
     *                                          because a hasher that disagrees
     *                                          with the dummy is refused below
     */
    public function __construct(private readonly string $dummyHash, ?PasswordHasherInterface $kdf = null)
    {
        $this->parameters = self::parametersOf($dummyHash);
        $this->kdf = $kdf ?? self::kdfFor($dummyHash);

        if ($this->kdf->needsRehash($dummyHash)) {
            throw new \InvalidArgumentException(
                'The injected KDF disagrees with app.auth.dummy_password_hash on at least one of '
                .'memory_cost, time_cost and threads — it would re-hash the dummy on sight. §7.1 step 5 '
                .'requires the dummy and the live hashes to share their parameters, and this parameter is '
                .'the single place that is decided.'
            );
        }
    }

    /**
     * The KDF the dummy describes. A named factory rather than a `new` in the
     * container, because the parameters are **read off the dummy** and that
     * derivation must exist in exactly one place.
     *
     * `NativePasswordHasher` takes `$memLimit` in **bytes** and stores
     * `memory_cost` as `$memLimit >> 10`, so the shift here is the exact inverse
     * of the one the component applies.
     */
    public static function kdfFor(string $dummyHash): NativePasswordHasher
    {
        $parameters = self::parametersOf($dummyHash);

        return new NativePasswordHasher(
            $parameters['time_cost'],
            $parameters['memory_cost'] << 10,
            null,
            \PASSWORD_ARGON2ID,
        );
    }

    public function verify(?string $hash, string $plaintext): bool
    {
        // The dummy substitution is here and not at the call site on purpose:
        // a caller that could skip the KDF is a caller that will, and the branch
        // it would skip on is the one E-7 forbids distinguishing.
        //
        // The candidate substitution below is the same argument applied to the
        // *plaintext*: `NativePasswordHasher::verify()` short-circuits an empty
        // or over-long password to `false` at zero rounds, which would make this
        // method's cost depend on a value the attacker chooses.
        $usable = '' !== $plaintext && \strlen($plaintext) <= PasswordHasherInterface::MAX_PASSWORD_LENGTH;
        $candidate = $usable ? $plaintext : self::UNUSABLE_CANDIDATE;

        return $this->kdf->verify($hash ?? $this->dummyHash, $candidate) && $usable && null !== $hash;
    }

    public function needsRehash(string $hash): bool
    {
        return $this->kdf->needsRehash($hash);
    }

    public function hash(string $plaintext): string
    {
        return $this->kdf->hash($plaintext);
    }

    /**
     * The three Argon2id parameters in force, read off the dummy. Asserted by
     * `PasswordVerifierTest`.
     *
     * @return array{memory_cost: int, time_cost: int, threads: int}
     */
    public function parameters(): array
    {
        return $this->parameters;
    }

    /**
     * @return array{memory_cost: int, time_cost: int, threads: int}
     */
    private static function parametersOf(string $dummyHash): array
    {
        $info = password_get_info($dummyHash);
        $options = $info['options'] ?? [];

        if (\PASSWORD_ARGON2ID !== ($info['algo'] ?? null)) {
            throw new \InvalidArgumentException(
                'app.auth.dummy_password_hash is not an Argon2id hash, so the parameters the miss paths '
                .'run at cannot be read off it. §7.1 step 5 requires the dummy and the live hashes to '
                .'share them, and this parameter is the single place that is decided. There is no bcrypt '
                .'fallback by ruling: a verifier accepting two formats is the mixed store the switch to '
                .'Argon2id closed.'
            );
        }

        foreach (['memory_cost', 'time_cost', 'threads'] as $knob) {
            if (!isset($options[$knob])) {
                throw new \InvalidArgumentException(\sprintf(
                    'app.auth.dummy_password_hash declares no %s, so the KDF cannot be built to match it.',
                    $knob,
                ));
            }
        }

        if (1 !== (int) $options['threads']) {
            throw new \InvalidArgumentException(
                'app.auth.dummy_password_hash declares threads other than 1. NativePasswordHasher pins '
                .'threads to 1 and takes no argument for it, so such a dummy is unbuildable here and is '
                .'refused rather than silently verified against a hasher that does not match it.'
            );
        }

        return [
            'memory_cost' => (int) $options['memory_cost'],
            'time_cost' => (int) $options['time_cost'],
            'threads' => 1,
        ];
    }
}
