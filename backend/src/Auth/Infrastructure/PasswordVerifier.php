<?php

declare(strict_types=1);

namespace App\Auth\Infrastructure;

use App\Auth\Domain\VerifiesPasswords;
use Symfony\Component\PasswordHasher\Hasher\NativePasswordHasher;

/**
 * `API.md` §7.1 step 5 — *"Outside every transaction: verify the password —
 * **always, exactly once**, against a fixed dummy hash of the same cost factor
 * on either miss path."*
 *
 * ---------------------------------------------------------------------------
 * Outside the transaction, and that is ADR-006 §6.1 rather than tidiness
 * ---------------------------------------------------------------------------
 * `ARCHITECTURE.md` §3 originally placed the verification between the `app_user`
 * `SELECT` and `COMMIT`, which pins a pooled backend across ~100 ms of
 * deliberately-slow CPU work **on the one path an attacker can drive for free**.
 * `CredentialLookup` commits before this class is called, and nothing here
 * touches a connection.
 *
 * ---------------------------------------------------------------------------
 * One knob, so the dummy and the real hashes cannot drift
 * ---------------------------------------------------------------------------
 * *"A dummy hash of the same cost factor"* is a requirement about **two**
 * values, and two configured numbers drift. So there is one configured value —
 * the dummy hash itself, `%app.auth.dummy_password_hash%` — and the cost the
 * hasher is built with is **read out of it**. A dummy at a lower cost than the
 * live hashes would make the miss paths measurably faster than the wrong-password
 * path, which is T-6's timing axis; here that is not a thing anybody can
 * misconfigure, because there is nothing to keep in step.
 *
 * The dummy is a bcrypt hash of 32 random bytes nobody recorded. It is not a
 * secret — it matches no password by construction — which is why it sits in
 * `services.yaml` rather than in the environment (Standing Rule 10 governs
 * values that *are* secret, and hiding a non-secret makes the real ones harder
 * to find).
 */
final class PasswordVerifier implements VerifiesPasswords
{
    private readonly NativePasswordHasher $hasher;

    private readonly int $cost;

    public function __construct(private readonly string $dummyHash)
    {
        $info = password_get_info($dummyHash);

        if (\PASSWORD_BCRYPT !== ($info['algo'] ?? null) || !isset($info['options']['cost'])) {
            throw new \InvalidArgumentException(
                'app.auth.dummy_password_hash is not a bcrypt hash, so the cost factor the miss paths '
                .'run at cannot be read off it. §7.1 step 5 requires the dummy and the live hashes to '
                .'share a cost factor, and this parameter is the single place that is decided.'
            );
        }

        $this->cost = (int) $info['options']['cost'];
        $this->hasher = new NativePasswordHasher(null, null, $this->cost, \PASSWORD_BCRYPT);
    }

    public function verify(?string $hash, string $plaintext): bool
    {
        // The dummy substitution is here and not at the call site on purpose:
        // a caller that could skip the KDF is a caller that will, and the branch
        // it would skip on is the one E-7 forbids distinguishing.
        return $this->hasher->verify($hash ?? $this->dummyHash, $plaintext) && null !== $hash;
    }

    public function needsRehash(string $hash): bool
    {
        return $this->hasher->needsRehash($hash);
    }

    public function hash(string $plaintext): string
    {
        return $this->hasher->hash($plaintext);
    }

    /** The cost factor in force, read off the dummy. Asserted by `PasswordVerifierTest`. */
    public function cost(): int
    {
        return $this->cost;
    }
}
