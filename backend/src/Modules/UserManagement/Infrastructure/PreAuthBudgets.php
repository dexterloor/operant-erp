<?php

declare(strict_types=1);

namespace App\Modules\UserManagement\Infrastructure;

use Doctrine\DBAL\Connection;

/**
 * `SECURITY_MODEL.md` **E-21** — the two pre-authentication budgets, on
 * `erp_ratelimit` and on nothing else.
 *
 * ---------------------------------------------------------------------------
 * Why the figures are constants here and the *window* is not
 * ---------------------------------------------------------------------------
 * `WINDOW_SECONDS` is **not** the authority for the window. `auth_ip_budget`
 * and `auth_attempt` both carry `<table>_window_aligned`, a `CHECK` pinning
 * `window_start` to a 900-second UTC grid, so a value computed here that
 * disagrees with the grid is refused by the database rather than quietly given
 * every request its own row. `PreAuthBudgetStorageTest` asserts the constraint;
 * this constant only has to agree with it, and if it does not, every write here
 * raises 23514.
 *
 * The two ceilings are E-21's and their arithmetic is `SECURITY_MODEL.md`
 * §5.1.2. They are figures, and a figure with no enforcing check is the shape
 * this project keeps finding at its gates — `PreAuthBudgetTest` asserts **both
 * sides of each boundary**, because a test that asserts only the refusal passes
 * at every lower threshold as well.
 *
 * ---------------------------------------------------------------------------
 * Order is a rule, not an implementation detail
 * ---------------------------------------------------------------------------
 * E-9 and `API.md` §7.1 step 2: the `(ip_prefix)` budget is consumed **first**,
 * and `auth_attempt` — whose primary key leads with the attacker-supplied
 * `slug_normalised` — is written only after it has been. 10,000 random slugs
 * from one address are otherwise 10,000 fresh buckets (ADR-006 §2.3). The
 * caller cannot get this wrong by accident: `consumeSlugBudget()` is unusable
 * without the boolean `consumeIpBudget()` returned.
 *
 * **Successes consume and nothing is refunded** (E-21, §5.1.2): the limiter runs
 * at step 2 and success is decided at step 5, so a refund is a second write on
 * the success branch alone — a statement-count difference deliberately added one
 * section after `API.md` §7.4 records an accidental one as an open residual —
 * and it would make the remaining headroom a failure count legible to anyone
 * sharing the prefix.
 */
final readonly class PreAuthBudgets
{
    /** E-21 / ST-8b — one duration for the product to explain rather than three. */
    public const WINDOW_SECONDS = 900;

    /** E-21: `(ip_prefix)`, `W ×` the slug ceiling on §5.1.2's model. */
    public const IP_CEILING = 300;

    /** E-21: `(slug_normalised, ip_prefix)`. `F × a` = 75 with 1.33× headroom. */
    public const SLUG_CEILING = 100;

    public function __construct(private Connection $rateLimit)
    {
    }

    /** @return bool false when this attempt is past E-21's `(ip_prefix)` ceiling */
    public function consumeIpBudget(string $ipPrefix, \DateTimeImmutable $at): bool
    {
        $count = (int) $this->rateLimit->fetchOne(<<<'SQL'
            INSERT INTO auth_ip_budget (ip_prefix, window_start, attempt_count)
            VALUES (?::cidr, ?::timestamptz, 1)
            ON CONFLICT (ip_prefix, window_start)
            DO UPDATE SET attempt_count = auth_ip_budget.attempt_count + 1
            RETURNING attempt_count
            SQL, [$ipPrefix, $this->windowStart($at)]);

        return $count <= self::IP_CEILING;
    }

    /**
     * @return bool false when this attempt is past E-21's `(slug_normalised, ip_prefix)` ceiling
     */
    public function consumeSlugBudget(string $slug, string $ipPrefix, \DateTimeImmutable $at): bool
    {
        $count = (int) $this->rateLimit->fetchOne(<<<'SQL'
            INSERT INTO auth_attempt (slug_normalised, ip_prefix, window_start, attempt_count, last_attempt_at)
            VALUES (?, ?::cidr, ?::timestamptz, 1, now())
            ON CONFLICT (slug_normalised, ip_prefix, window_start)
            DO UPDATE SET attempt_count = auth_attempt.attempt_count + 1, last_attempt_at = now()
            RETURNING attempt_count
            SQL, [$slug, $ipPrefix, $this->windowStart($at)]);

        return $count <= self::SLUG_CEILING;
    }

    /**
     * The start of the fixed window `$at` falls in, on the same UTC grid the
     * `CHECK` constraints pin. Fixed rather than sliding, so the window never
     * straddles a boundary of `auth_attempt`'s daily range partition.
     */
    private function windowStart(\DateTimeImmutable $at): string
    {
        $epoch = $at->getTimestamp();

        return (new \DateTimeImmutable('@'.($epoch - ($epoch % self::WINDOW_SECONDS))))
            ->setTimezone(new \DateTimeZone('UTC'))
            ->format('Y-m-d H:i:sP');
    }
}
