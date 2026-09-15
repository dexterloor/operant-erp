<?php

declare(strict_types=1);

namespace App\Modules\UserManagement\Infrastructure;

/**
 * `auth_lockout.email_hash`, which the migration's own comment defines: *"a
 * keyed hash, never the address: the table would otherwise be an enumerable
 * directory of who has ever tried to log in."*
 *
 * Keyed rather than plain, because both primary-key columns of `auth_lockout`
 * are attacker-supplied: an unkeyed digest is a dictionary attack away from the
 * address it hides, and the table is readable by `erp_ratelimit` alone but
 * survives every backup that principal's rows land in.
 *
 * The key is `%env(AUTH_EMAIL_HASH_KEY)%` and is referenced by name only
 * (Standing Rule 10). Rotating it does not corrupt anything: the old rows stop
 * being addressable, which expires every lockout once — a fail-open on
 * *rotation day* that is bounded by both `E-21` budgets, and the alternative
 * (a rotation that keeps counters live) needs the address, which is the thing
 * this class exists not to store.
 *
 * **A 32-byte key floor (V1-S-12, 2026-09-16)**, `AccessTokenVerifier`'s for the same reason: an empty or short key makes
 * every stored hash a dictionary attack away from the address it hides. The refusal names the variable, never the value.
 */
final readonly class EmailHasher
{
    /** The HMAC-SHA256 digest size, as `AccessTokenVerifier::MINIMUM_KEY_BYTES`. */
    public const MINIMUM_KEY_BYTES = 32;

    public function __construct(private string $key)
    {
        if (self::MINIMUM_KEY_BYTES > \strlen($key)) {
            throw new \InvalidArgumentException(
                'AUTH_EMAIL_HASH_KEY is shorter than 32 bytes. Below the digest size the keyed hash in auth_lockout '
                .'is a dictionary attack away from every address that was ever tried.'
            );
        }
    }

    /** @return string 32 raw bytes, for the `bytea` column */
    public function hash(string $normalisedEmail): string
    {
        return hash_hmac('sha256', $normalisedEmail, $this->key, true);
    }
}
