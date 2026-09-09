<?php

declare(strict_types=1);

namespace App\Auth\Domain;

/**
 * The KDF seam.
 *
 * It exists as an interface for one reason, and it is a testing reason stated
 * rather than disguised as a design one: **T-6 and E-7 make the number of
 * invocations an observable**, and *"the hasher ran exactly once on this path"*
 * is not decidable from a response body, a status code or a SQL log. A
 * decorator in the test environment counts calls; production wires the
 * implementation directly.
 *
 * `API.md` §7.3's second mutation — *"move the `permitsLogin()` check ahead of
 * the KDF; the hasher-invocation assertion on the non-active branch goes to
 * zero"* — is the mutation this seam exists to make runnable.
 */
interface VerifiesPasswords
{
    /**
     * @param string|null $hash the stored hash, or null when nothing resolved —
     *                          in which case the implementation must still run
     *                          the KDF, against a dummy of the same cost factor
     */
    public function verify(?string $hash, string $plaintext): bool;

    /** True when `$hash` was produced at a weaker cost than the one now configured. */
    public function needsRehash(string $hash): bool;

    /** For seeding and for the credential-change path this wave does not ship. */
    public function hash(string $plaintext): string;
}
