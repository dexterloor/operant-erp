<?php

declare(strict_types=1);

namespace App\Modules\UserManagement\Application;

use App\SharedKernel\ValueObject\AuditEvent;
use App\Platform\Tenancy\TenantContext;
use Doctrine\DBAL\Connection;

/**
 * **E-23's *"throttle the action; never suppress the record"*, and it is the
 * half that is easy to get backwards.**
 *
 * ===========================================================================
 * What is being bounded, in E-23's own words
 * ===========================================================================
 * *"An authenticated caller can loop a change-password endpoint — each change
 * sets a password the caller then knows — so this is an unbounded write path
 * into an unpartitioned, `DELETE`-less `audit_log` (SEC-O-3), which is E-3a's
 * growth vector arriving after authentication."*
 *
 * So the quantity to bound is **rows written**, which is the count of
 * *successful* changes. A refused change writes no row and needs no bound from
 * this class; a throttled one is refused before the operation begins and writes
 * no row either. **The thing counted and the thing bounded are the same thing**,
 * which is what makes the bound tight rather than approximate.
 *
 * ===========================================================================
 * Why this is NOT E-3a's mechanism, restated because the shapes look alike
 * ===========================================================================
 * Both read `audit_log` back with a `LIMIT` before deciding. The difference is
 * what the decision does. **E-3a suppresses the record and lets the action
 * proceed**, because a permission denial happens whether anybody wants it to and
 * the server cannot refuse it. **This refuses the action and never touches the
 * record**: the caller gets `too-many-attempts`, no password moves, and no row is
 * written *because nothing happened*. E-23 names a daily cap on this event as a
 * violation in terms — *"the one row you must never lose is the one saying a
 * credential moved"*.
 *
 * ===========================================================================
 * The counter store is `audit_log` itself, and that is a ruling
 * ===========================================================================
 * **`API.md` §12.4 declined a post-auth counter store, in writing**, and E4-1's
 * migration slot is scoped to the catalogue extension — it does not authorise a
 * table. So a throttle that needs new storage could not be built this pass, and
 * a throttle that is not built is E-23 unhonoured.
 *
 * `audit_log` serves without new storage or a new grant: `erp_app` already holds
 * `SELECT` on it, the rows this counts are the rows this operation writes, and
 * §12.4's own objections to a counter store — *"a new tenant-scoped, high-write,
 * permanently-growing table"* — are objections to adding a **second** unpruned
 * table, not to reading the one that already exists. The three alternatives were
 * considered and each fails on a specific:
 *
 *   - **`auth_ip_budget`** (E-21's `(ip_prefix)` budget) — keyed on the address,
 *     so an authenticated caller looping this endpoint would consume the
 *     *sign-in* budget for everyone behind their NAT. That converts an audit-growth
 *     problem into a denial of service against colleagues.
 *   - **`auth_attempt`** — same defect, keyed `(slug_normalised, ip_prefix)`.
 *   - **`auth_lockout`** — a refusal here would pause the user's **sign-in**
 *     under ST-8b, which is a different rule with a published duration and a
 *     different meaning. Reusing it would make one table answer two questions.
 *
 * All three also live on **`erp_ratelimit`**, the pre-authentication principal;
 * reaching them from an authenticated path would widen that principal's blast
 * radius for no gain.
 *
 * ===========================================================================
 * The probe is bounded, and the bound is stated rather than implied
 * ===========================================================================
 * `audit_log_tenant_actor_idx` is `(tenant_id, actor_user_id, occurred_at)`.
 * `tenant_id` is supplied by the RLS policy, exactly as `DenialAuditWriter`'s
 * read-back leaves it (writing it explicitly folds into a `Result` node carrying
 * `One-Time Filter:`, measured in `DenialCapStorageTest`); `actor_user_id` and
 * the `occurred_at` lower bound are the query's own. `event_type` is a `Filter`
 * on the scan node and **not** an index column, so the honest statement of the
 * bound is: *the rows this actor wrote in the last fifteen minutes*, not *the
 * rows in the table*. That is bounded by construction — every row in that range
 * was written by an authenticated request from this principal, and the two
 * post-authentication writers are this one and `DenialAuditWriter`, which E-3a
 * caps at two per code per day.
 *
 * **No plan-shape assertion is made here, and that is deliberate.** §9.4a's
 * `Index Scan … no Filter:` requirement is E-3a's, bought with a purpose-built
 * partial index that `Version20260909000200` added under E4-2. E-23 asks for a
 * throttle, not for a bounded probe, and manufacturing a second partial index
 * would be a migration E4-1 does not authorise. Claiming §9.4a's property for
 * this query would be the stronger-sounding claim and it would be false.
 *
 * ===========================================================================
 * The two figures, derived rather than chosen
 * ===========================================================================
 * **The window is ST-8b's published fifteen minutes**, reused rather than
 * invented — the same move E-21 makes for its `T`, and for the same reason: one
 * duration for the product to explain rather than three.
 *
 * **The ceiling is three**, and it is `a` from E-21's arithmetic — *"one call
 * plus two retries"* — applied here because the retry shapes are identical: a
 * 5xx, a reset connection, and on this stack a cold start. A user who changes
 * their password, does not see the response, and retries has changed it twice;
 * a third is the retry of the retry. Beyond three **successful** changes in
 * fifteen minutes there is no legitimate client shape, and the fourth is the
 * loop E-23 names.
 *
 * ===========================================================================
 * What this does NOT bound — named rather than left to be found
 * ===========================================================================
 * **Failed attempts.** A caller presenting the wrong current password is refused,
 * writes no row, and accrues nothing here, so an authenticated attacker holding a
 * stolen token can guess the current password without limit. It costs one KDF per
 * attempt and discloses nothing they cannot already read from the status code,
 * and E-21's budgets are pre-authentication only. Routed as **API-O-25**; not
 * closed here, because closing it needs either the counter store §12.4 declined
 * or a post-auth limiter nobody has ruled on.
 *
 * @see SECURITY_MODEL.md §5 E-23, §5.1.2 (the `a` and `T` figures), §5.1.3 (ST-8b)
 * @see API.md §12.4 for the declined counter store; §9.4a for the property this does not claim
 */
final readonly class PasswordChangeThrottle
{
    /** ST-8b's published duration, reused. See the class docblock. */
    public const WINDOW_SECONDS = 900;

    /** E-21's `a` — one call plus two retries. See the class docblock. */
    public const CHANGES_PER_WINDOW = 3;

    public function __construct(private TenantContext $tenants, private Connection $app)
    {
    }

    /**
     * True when this principal may change its credential now.
     *
     * The `LIMIT` is the ceiling itself rather than a transcription of it, so the
     * statement and the comparison cannot drift — `DenialAuditWriter`'s
     * `DAILY_ROWS_PER_KEY` is the same construction one rule over.
     *
     * `tenant_id` is left to the RLS policy; see the class docblock.
     */
    public function admits(string $tenantId, string $actorUserId): bool
    {
        $seen = $this->tenants->run($tenantId, fn (): array => $this->app->fetchAllAssociative(
            \sprintf(<<<'SQL'
                SELECT id FROM audit_log
                WHERE actor_user_id = ?::uuid
                  AND event_type = ?
                  AND occurred_at >= now() - make_interval(secs => %d)
                LIMIT %d
                SQL, self::WINDOW_SECONDS, self::CHANGES_PER_WINDOW),
            [$actorUserId, AuditEvent::AUTH_PASSWORD_CHANGED],
        ));

        \assert(\is_array($seen));

        return \count($seen) < self::CHANGES_PER_WINDOW;
    }
}
