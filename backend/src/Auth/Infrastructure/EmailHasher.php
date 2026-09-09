<?php

declare(strict_types=1);

namespace App\Auth\Infrastructure;

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
 */
final readonly class EmailHasher
{
    public function __construct(private string $key)
    {
    }

    /** @return string 32 raw bytes, for the `bytea` column */
    public function hash(string $normalisedEmail): string
    {
        return hash_hmac('sha256', $normalisedEmail, $this->key, true);
    }
}
