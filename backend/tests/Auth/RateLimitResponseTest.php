<?php

declare(strict_types=1);

namespace App\Tests\Auth;

use App\Modules\UserManagement\Infrastructure\LockoutStore;
use App\Modules\UserManagement\Infrastructure\PreAuthBudgets;
use App\Platform\Tenancy\Entity\Tenant;
use PHPUnit\Framework\Attributes\Test;
use Psr\Clock\ClockInterface;

/**
 * `API.md` §5.2a — ***`Retry-After` is never sent, on either 429.***
 *
 * ST-8a withholds the count and the window — *"both are reconnaissance"* — and
 * ST-8b discloses the **policy** duration in the on-screen copy while withholding
 * the **remaining** time. `Retry-After` is the remaining time.
 *
 * §5.2a names this *"the kind of header a framework adds by default"*, and it
 * carried an **UNVERIFIED** note: the mutation it described assumed
 * `symfony/rate-limiter` would emit the header. That package is **still not
 * installed** — this slice implements both budgets against the two counter
 * tables `erp_ratelimit` owns, because a component-backed limiter would need
 * storage on a principal that has none. The rule is unchanged; the mutation is
 * the one below.
 */
final class RateLimitResponseTest extends AuthTestCase
{
    private const PASSWORD = 'correct horse battery staple';

    private const EMAIL = 'alice@example.com';

    /**
     * Both 429 paths, driven — a budget refusal and a lockout pause — because
     * they are produced by different code and only one of them would be caught
     * by testing either alone.
     *
     * *Mutation, run:* add `headers: ['Retry-After' => '900']` to
     * `ApiProblem::tooManyAttempts()`; the first case is red. *Second mutation,
     * run:* the same on `signInPaused()`; the second is red.
     */
    #[Test]
    public function noRateLimitResponseCarriesRetryAfter(): void
    {
        $slug = self::SLUG_PREFIX.'retry-after';
        $tenantId = $this->seedTenant($slug, Tenant::STATUS_ACTIVE);
        $this->seedUser($tenantId, self::EMAIL, self::PASSWORD);

        // --- ST-8b, the lockout pause -------------------------------------
        for ($attempt = 0; $attempt < LockoutStore::THRESHOLD; ++$attempt) {
            $this->signIn($slug, self::EMAIL, 'wrong');
        }
        $this->signIn($slug, self::EMAIL, 'wrong');

        self::assertSame(429, $this->httpStatus());
        self::assertSame('urn:erp:problem:sign-in-paused', $this->json()['type']);
        self::assertNull($this->client()->getResponse()->headers->get('Retry-After'));
        self::assertSame(['type', 'title', 'status'], array_keys($this->json()),
            'The 429 body carries a member beyond the three §5.2 declares — a count or a window is reconnaissance.');

        // --- ST-8a, the budget --------------------------------------------
        $budgets = self::getContainer()->get('test.auth.budgets');
        \assert($budgets instanceof PreAuthBudgets);
        $clock = self::getContainer()->get(ClockInterface::class);
        \assert($clock instanceof ClockInterface);

        // The `(slug, ip_prefix)` budget rather than the `(ip_prefix)` one:
        // both answer `too-many-attempts` and §5.2 gives them one row, so the
        // cheaper ceiling is the one to drive here. Which budget refuses is
        // `PreAuthBudgetTest`'s subject, not this file's.
        for ($i = 0; $i < PreAuthBudgets::SLUG_CEILING; ++$i) {
            $budgets->consumeSlugBudget($slug, '203.0.113.7/32', $clock->now());
        }

        $this->signIn($slug, 'someone-else@example.com', 'wrong');

        self::assertSame(429, $this->httpStatus());
        self::assertSame('urn:erp:problem:too-many-attempts', $this->json()['type']);
        self::assertNull($this->client()->getResponse()->headers->get('Retry-After'));
        self::assertSame(['type', 'title', 'status'], array_keys($this->json()));
    }

    /**
     * `symfony/rate-limiter` is not a dependency, and that is a decision rather
     * than an omission: E-21's budgets live in `auth_ip_budget` and
     * `auth_attempt`, which only `erp_ratelimit` can reach, and the component's
     * storage abstraction names a cache pool no pre-authentication principal has
     * — `ARCHITECTURE.md` rules out a Redis cache (Redis exists from Mail-C0, 2026-10-23,
     * as the MAIL QUEUE only — ADR-005 Amendment 4 — and it is not a budget store)
     * and `cache.adapter.doctrine_dbal` names
     * a table that is in neither the manifest nor `backend/config/` (SEC-O-16).
     *
     * *Mutation, run:* `composer require symfony/rate-limiter`; red, and
     * deliberately — installing it is a storage decision, not a convenience.
     */
    #[Test]
    public function theLimiterIsTheTwoCounterTablesAndNotAComponentWithNowhereToStore(): void
    {
        /** @var array{require?: array<string, string>, require-dev?: array<string, string>} $composer */
        $composer = json_decode((string) file_get_contents(\dirname(__DIR__, 2).'/composer.json'), true, 512, \JSON_THROW_ON_ERROR);

        $declared = array_merge(array_keys($composer['require'] ?? []), array_keys($composer['require-dev'] ?? []));
        self::assertNotSame([], $declared, 'composer.json declares no packages, so this check has no subject.');

        self::assertNotContains('symfony/rate-limiter', $declared);
    }
}
