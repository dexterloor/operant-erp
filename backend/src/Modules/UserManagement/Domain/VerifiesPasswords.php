<?php

declare(strict_types=1);

namespace App\Modules\UserManagement\Domain;

/**
 * The credential-comparison port.
 *
 * It is a `Domain` interface because A-7 forbids `Application` from naming an
 * `Infrastructure` class, and `SignIn` and `ChangePassword` both need one:
 * `PasswordVerifier` is what implements it.
 *
 * **This interface is no longer the counting seam, and the correction is worth
 * recording rather than quietly rewriting.** It used to say it existed *"for one
 * reason, and it is a testing reason"* — a decorator counting calls here. That
 * decorator, `CountingPasswordVerifier`, wrapped `PasswordVerifier` from
 * **outside**, so it counted calls to this method and not KDF executions, and
 * `NativePasswordHasher::verify()` returns `false` at zero rounds for an empty
 * or over-long plaintext. The counter read `1` while nothing ran. The count now
 * lives one level down, at the `PasswordHasherInterface` boundary inside
 * `PasswordVerifier` (`App\Tests\Support\CountingKdf`), where the invariant that
 * makes *one call = one KDF execution* true is also enforced.
 *
 * `API.md` §7.3's second mutation — *"move the `permitsLogin()` check ahead of
 * the KDF; the hasher-invocation assertion on the non-active branch goes to
 * zero"* — is still runnable, and is now runnable for the right reason.
 */
interface VerifiesPasswords
{
    /**
     * @param string|null $hash      the stored hash, or null when nothing resolved —
     *                               in which case the implementation must still run
     *                               the KDF, against a dummy at the same parameters
     * @param string      $plaintext any value at all, **including the empty string
     *                               and one longer than the component's ceiling**:
     *                               the implementation must run the KDF for those
     *                               too rather than letting them answer `false` for
     *                               free, or the endpoint's cost depends on a value
     *                               the caller picks
     */
    public function verify(?string $hash, string $plaintext): bool;

    /** True when `$hash` was produced at parameters weaker than the ones now configured. */
    public function needsRehash(string $hash): bool;

    /**
     * For seeding **and for `ChangePassword`** (E-23), which does ship — the
     * earlier wording said *"the credential-change path this wave does not
     * ship"* and stopped being true on 2026-09-09.
     *
     * Deliberately **not** counted by `CountingKdf`: a fixture that hashes a
     * password would otherwise move the counter, and every *"the KDF ran exactly
     * once"* assertion would become a statement about the fixture. The
     * consequence is worth stating, because a change-password request runs the
     * KDF **twice** — once here and once through `verify()` — and only the second
     * is observable to those assertions.
     */
    public function hash(string $plaintext): string;
}
