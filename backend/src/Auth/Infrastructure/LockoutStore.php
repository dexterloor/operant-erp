<?php

declare(strict_types=1);

namespace App\Auth\Infrastructure;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;

/**
 * `SECURITY_MODEL.md` **E-8**, **E-9** and **E-22** — `auth_lockout`, on
 * `erp_ratelimit`.
 *
 * ---------------------------------------------------------------------------
 * The three rules this class is the only implementation of
 * ---------------------------------------------------------------------------
 * **E-8 — the counter is about a *pair*, not about a person.** It increments on
 * every failed attempt that survives the `(ip_prefix)` budget, *including the
 * unresolved-workspace and unresolved-email paths*. Incrementing only on a
 * resolved failure would make ST-8b's published message *"sign-in with this
 * workspace and email is paused"* a user-existence oracle, which ST-7.1 marks
 * as the one absolutely confidential axis. Nothing in this class knows whether
 * the pair resolved, and that is the design rather than an omission.
 *
 * **E-9 — the upsert is gated by the IP budget before the row is written.**
 * Both primary-key columns are attacker-controlled, so 10,000 random pairs from
 * one source would otherwise be 10,000 rows. The gating lives in
 * `App\Auth\Application\SignIn`, which returns before reaching this class.
 *
 * **E-22 — ten failures, a fifteen-minute pause, and a reset on a success for
 * that same pair or on the expiry of a pause, and on nothing else.** In
 * particular *not* on any success at the prefix: an attacker holding one valid
 * credential in the workspace would otherwise clear a victim's counter at will.
 * The reset is therefore keyed on the pair, in `clear()`, and there is no
 * method here that takes a prefix at all.
 *
 * ---------------------------------------------------------------------------
 * Why the accrual is one statement
 * ---------------------------------------------------------------------------
 * `API.md` §7.1 gives step 7 one statement and E-7 makes the count observable.
 * A read-modify-write would be two, would be a lost-update race between
 * concurrent attempts on the same pair, and would put the threshold decision in
 * PHP where a second request cannot see it. The `CASE` below is repeated because
 * it has to be evaluated twice in one `UPDATE` — once for the new count, once
 * for the pause — and Postgres has no way to name it: `auth_lockout.locked_until`
 * refers to the *pre-update* row, which is what makes the expiry reset work.
 *
 * `erp_ratelimit` holds `SELECT, INSERT, UPDATE` here and **no `DELETE`**:
 * retention is `DATA`'s purge under `API.md` **API-24**, and this class asks for
 * no grant it does not have.
 */
final readonly class LockoutStore
{
    /** E-22, §5.1.3: ten, trapped between a misconfigured client and a distributed spray. */
    public const THRESHOLD = 10;

    /** E-22 / ST-8b — the duration the product publishes on screen. */
    public const PAUSE_SECONDS = 900;

    public function __construct(private Connection $rateLimit)
    {
    }

    /**
     * `API.md` §7.1 step 3 — **this step reads and decides nothing.** The answer
     * it enables is emitted after the KDF has run, which is why the read is here
     * and the decision is not: a locked request that returned early would be
     * measurably faster than a failed one, rebuilding the oracle E-8 exists to
     * remove (ST-8b, *"Ordering, so the state cannot leak through timing"*).
     *
     * Issued on **every** path, so the statement count does not branch here.
     */
    public function pausedUntil(string $slug, string $emailHash, \DateTimeImmutable $now): ?\DateTimeImmutable
    {
        $lockedUntil = $this->rateLimit->fetchOne(
            'SELECT locked_until FROM auth_lockout WHERE slug_normalised = ? AND email_hash = ?',
            [$slug, $emailHash],
            [ParameterType::STRING, ParameterType::BINARY],
        );

        if (!\is_string($lockedUntil)) {
            return null;
        }

        $expiry = new \DateTimeImmutable($lockedUntil);

        return $expiry > $now ? $expiry : null;
    }

    /**
     * `API.md` §7.1 step 7 — one upsert, on every failure that survived the IP
     * budget.
     *
     * @return int the pair's accrued failure count after this attempt
     */
    public function recordFailure(string $slug, string $emailHash): int
    {
        $expired = 'auth_lockout.locked_until IS NOT NULL AND auth_lockout.locked_until <= now()';
        $accrued = "CASE WHEN $expired THEN 1 ELSE auth_lockout.failure_count + 1 END";

        // The threshold and the pause are interpolated rather than bound. They
        // are class constants, not input -- and DBAL refuses a statement that
        // mixes named and positional parameters, which is what a bound version
        // would need here beside the two positional key columns. Cast through
        // (int) so the interpolation cannot become an injection point even if a
        // later edit made the constants configurable.
        $threshold = (int) self::THRESHOLD;
        $pause = (int) self::PAUSE_SECONDS;

        return (int) $this->rateLimit->fetchOne(<<<SQL
            INSERT INTO auth_lockout (slug_normalised, email_hash, failure_count, first_failure_at, last_failure_at, locked_until)
            VALUES (?, ?, 1, now(), now(), NULL)
            ON CONFLICT (slug_normalised, email_hash) DO UPDATE SET
                failure_count    = $accrued,
                first_failure_at = CASE WHEN $expired THEN now() ELSE auth_lockout.first_failure_at END,
                last_failure_at  = now(),
                locked_until     = CASE WHEN ($accrued) >= $threshold THEN now() + interval '$pause seconds' ELSE NULL END
            RETURNING failure_count
            SQL,
            [$slug, $emailHash],
            [ParameterType::STRING, ParameterType::BINARY],
        );
    }

    /**
     * E-22's reset, and the **only** one: a success for this pair.
     *
     * `UPDATE` and not `DELETE` — the principal holds no `DELETE` here, and the
     * row carries API-O-6's future cap columns, which a delete would re-arm
     * (API-24 (ii)).
     */
    public function clear(string $slug, string $emailHash): void
    {
        $this->rateLimit->executeStatement(
            'UPDATE auth_lockout SET failure_count = 0, locked_until = NULL WHERE slug_normalised = ? AND email_hash = ?',
            [$slug, $emailHash],
            [ParameterType::STRING, ParameterType::BINARY],
        );
    }
}
