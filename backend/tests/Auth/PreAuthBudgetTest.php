<?php

declare(strict_types=1);

namespace App\Tests\Auth;

use App\Modules\UserManagement\Infrastructure\PreAuthBudgets;
use App\Platform\Tenancy\Entity\Tenant;
use App\Tests\Support\SecurityModelDocument;
use PHPUnit\Framework\Attributes\Test;
use Psr\Clock\ClockInterface;

/**
 * **E-21** — the two pre-authentication budgets, and `SECURITY_MODEL.md` §5.1.2's
 * *fails if* clause by clause.
 *
 * ---------------------------------------------------------------------------
 * Both sides of every boundary
 * ---------------------------------------------------------------------------
 * §5.1.2 is explicit: *"a test that asserts only the refusal passes at every
 * lower threshold as well."* So each ceiling is driven twice — the ceiling
 * itself admitted, the next attempt refused — and the two assertions sit in one
 * method so neither can be deleted without the other going with it.
 *
 * ---------------------------------------------------------------------------
 * How the counter gets to the boundary, and why it is not a hand-written row
 * ---------------------------------------------------------------------------
 * Driving 300 sign-ins over HTTP would spend three hundred KDF invocations to
 * assert something about two. The counter is filled by calling **the production
 * service** instead — never by inserting rows here — so the window this test
 * fills is by construction the window the request will read. An `INSERT` written
 * in this file could disagree with `PreAuthBudgets::windowStart()` and the test
 * would then pass by creating a second row nobody consulted, which is the
 * fake-green shape this project keeps finding. The last two attempts always go
 * over HTTP, because the ceiling is a claim about a **response**.
 */
final class PreAuthBudgetTest extends AuthTestCase
{
    private const PASSWORD = 'correct horse battery staple';

    private const EMAIL = 'alice@example.com';

    /**
     * **E-21's two figures, pinned here and not read from the code under test.**
     *
     * The first version of this file drove its loops from
     * `PreAuthBudgets::SLUG_CEILING`. Lowering that constant to 50 left the whole
     * suite **green** — measured, not supposed — because the fill moved with the
     * ceiling and the boundary landed in the same place relative to it. A test
     * whose expectation is the thing it is testing asserts nothing at all, and
     * this is that defect closed: the literals below are the register's, the
     * constants are compared against them, and where `docs/SECURITY_MODEL.md` is
     * present the literals are compared against E-21's row as well.
     */
    private const PINNED_SLUG_CEILING = 100;

    private const PINNED_IP_CEILING = 300;

    /**
     * The pin, in three directions: the constants, the literals above, and —
     * where the document is present — E-21's own row.
     *
     * *Mutation, run:* change `SLUG_CEILING` to 50; red here **and** on the
     * boundary below, which is the pair of failures the first version of this
     * file did not produce.
     */
    #[Test]
    public function theCeilingsAreTheOnesTheEnforcementRegisterSets(): void
    {
        self::assertSame(self::PINNED_SLUG_CEILING, PreAuthBudgets::SLUG_CEILING);
        self::assertSame(self::PINNED_IP_CEILING, PreAuthBudgets::IP_CEILING);
        self::assertSame(15 * 60, PreAuthBudgets::WINDOW_SECONDS, 'E-21 fixes the window at fifteen minutes, which is also ST-8b\'s published pause.');

        if (!SecurityModelDocument::isAvailable()) {
            self::markTestSkipped('docs/SECURITY_MODEL.md is gitignored and absent from this checkout, so the pin is checked against the code only.');
        }

        $row = SecurityModelDocument::enforcementRow('E-21');
        self::assertNotSame('', $row, 'E-21 has left the register, so the pin above is unanchored.');

        foreach ([self::PINNED_SLUG_CEILING, self::PINNED_IP_CEILING] as $figure) {
            self::assertStringContainsString((string) $figure, $row,
                'E-21 no longer names '.$figure.'. The register is the authority; this pin has to be edited to follow it.');
        }
    }

    /**
     * *Mutation, run:* change `SLUG_CEILING` to 50; the *ceiling-is-admitted*
     * half is red. *Second mutation, run:* change the comparison in
     * `consumeSlugBudget()` from `<=` to `<`; also red on that half — which is
     * the off-by-one a refusal-only test cannot see.
     */
    #[Test]
    public function eachBudgetRefusesOnePastItsCeilingAndAdmitsTheCeiling(): void
    {
        $slug = self::SLUG_PREFIX.'ceilings';
        $tenantId = $this->seedTenant($slug, Tenant::STATUS_ACTIVE);
        $this->seedUser($tenantId, self::EMAIL, self::PASSWORD);

        // --- the (slug, ip_prefix) ceiling --------------------------------
        $this->fill(self::PINNED_SLUG_CEILING - 1, fn (PreAuthBudgets $b, \DateTimeImmutable $now) => $b->consumeSlugBudget($slug, '203.0.113.7/32', $now));

        $this->signIn($slug, self::EMAIL, 'wrong');
        self::assertSame(401, $this->httpStatus(),
            'The '.self::PINNED_SLUG_CEILING.'th attempt was refused. The ceiling is admitted; one past it is not.');

        $this->signIn($slug, self::EMAIL, 'wrong');
        self::assertSame(429, $this->httpStatus());
        self::assertSame('urn:erp:problem:too-many-attempts', $this->json()['type']);

        // --- the (ip_prefix) ceiling --------------------------------------
        // A second slug, so the per-slug budget above cannot be what refuses.
        $other = self::SLUG_PREFIX.'ceilings-ip';
        $otherTenant = $this->seedTenant($other, Tenant::STATUS_ACTIVE);
        $this->seedUser($otherTenant, self::EMAIL, self::PASSWORD);

        $consumed = (int) $this->owner()->fetchOne("SELECT coalesce(sum(attempt_count), 0) FROM auth_ip_budget WHERE ip_prefix = '203.0.113.7/32'::cidr");
        self::assertGreaterThan(0, $consumed, 'The IP budget recorded nothing, so the fill below would start from the wrong place.');

        $this->fill(self::PINNED_IP_CEILING - $consumed - 1, fn (PreAuthBudgets $b, \DateTimeImmutable $now) => $b->consumeIpBudget('203.0.113.7/32', $now));

        $this->signIn($other, self::EMAIL, 'wrong');
        self::assertSame(401, $this->httpStatus(),
            'The '.self::PINNED_IP_CEILING.'th attempt from this prefix was refused. The ceiling is admitted.');

        $this->signIn($other, self::EMAIL, 'wrong');
        self::assertSame(429, $this->httpStatus());
    }

    /**
     * E-21: *"Every attempt consumes, successes included, and nothing is
     * refunded."*
     *
     * *Mutation, run:* skip consumption when the response is a 200 — i.e. move
     * both `consume*` calls after the credential check in `SignIn::attempt()` and
     * return early on success. Both counters read 0 and this is red. (It also
     * breaks E-9, which is the second thing that ordering buys.)
     */
    #[Test]
    public function aSuccessfulSignInConsumesBothBudgets(): void
    {
        $slug = self::SLUG_PREFIX.'success';
        $tenantId = $this->seedTenant($slug, Tenant::STATUS_ACTIVE);
        $this->seedUser($tenantId, self::EMAIL, self::PASSWORD);

        $this->signIn($slug, self::EMAIL, self::PASSWORD);
        self::assertSame(200, $this->httpStatus());

        self::assertSame(1, (int) $this->owner()->fetchOne('SELECT sum(attempt_count) FROM auth_ip_budget'));
        self::assertSame(1, (int) $this->owner()->fetchOne('SELECT sum(attempt_count) FROM auth_attempt'));
    }

    /**
     * **E-9** — *"the `auth_lockout` upsert is gated by the `(ip_prefix)` limiter
     * before the row is written"*, and ADR-006 §2.3's same finding on
     * `auth_attempt`: both of that table's key columns are attacker-controlled,
     * so 10,000 random slugs from one source would otherwise be 10,000 rows.
     *
     * *Mutation, run:* swap the two consumptions in `SignIn::attempt()` so the
     * slug budget is consumed first. An `auth_attempt` row appears for a request
     * past the IP ceiling and this is red.
     */
    #[Test]
    public function nothingIsWrittenOnARequestTheIpBudgetRefused(): void
    {
        $this->fill(self::PINNED_IP_CEILING, fn (PreAuthBudgets $b, \DateTimeImmutable $now) => $b->consumeIpBudget('203.0.113.7/32', $now));

        // Cleared *after* the fill, so the two tables below are empty for a
        // reason this test controls rather than because nothing has run yet.
        $this->owner()->executeStatement('TRUNCATE auth_attempt, auth_lockout');

        $this->signIn(self::SLUG_PREFIX.'never-seen-before', 'stranger@example.com', 'anything');

        self::assertSame(429, $this->httpStatus());
        self::assertSame(0, (int) $this->owner()->fetchOne('SELECT count(*) FROM auth_attempt'),
            'A request the IP budget refused still created a row keyed on the slug it supplied.');
        self::assertSame(0, (int) $this->owner()->fetchOne('SELECT count(*) FROM auth_lockout'));
    }

    /**
     * ADR-006 §6.4(b) — *"a single IPv6 /64 yields 2^64 source addresses, so a
     * per-address limiter is no limiter at all."*
     *
     * The two addresses are asserted **distinct before** they are asserted to
     * share a bucket: §5.1.2's third mutation notes that without it the test
     * passes vacuously on a harness that quietly emitted the same address twice.
     *
     * *Mutation, run:* return the full address from `PreAuthKeys::ipPrefix()` for
     * IPv6; the two get a budget each, `auth_ip_budget` holds two rows and this
     * is red.
     */
    #[Test]
    public function twoAddressesInOneSlashSixtyFourShareOneBudget(): void
    {
        $first = '2001:db8:1:2:aaaa:bbbb:cccc:dddd';
        $second = '2001:db8:1:2::1';

        self::assertNotSame(inet_pton($first), inet_pton($second), 'The two generated addresses are the same address.');
        self::assertSame(substr((string) inet_pton($first), 0, 8), substr((string) inet_pton($second), 0, 8), 'The two addresses are not in one /64.');

        $slug = self::SLUG_PREFIX.'v6';
        $tenantId = $this->seedTenant($slug, Tenant::STATUS_ACTIVE);
        $this->seedUser($tenantId, self::EMAIL, self::PASSWORD);

        $this->signIn($slug, self::EMAIL, 'wrong', $first);
        $this->signIn($slug, self::EMAIL, 'wrong', $second);

        $rows = $this->owner()->fetchAllAssociative('SELECT ip_prefix, attempt_count FROM auth_ip_budget');
        self::assertCount(1, $rows, 'Two addresses inside one /64 received separate budgets.');
        self::assertSame(2, (int) $rows[0]['attempt_count']);
        self::assertSame('2001:db8:1:2::/64', (string) $rows[0]['ip_prefix']);
    }

    /**
     * Fills a budget by calling the production service, `$times` times.
     *
     * @param callable(PreAuthBudgets, \DateTimeImmutable): bool $consume
     */
    private function fill(int $times, callable $consume): void
    {
        self::assertGreaterThan(0, $times, 'Nothing to fill, so the boundary below is not the boundary.');

        $budgets = self::getContainer()->get('test.auth.budgets');
        \assert($budgets instanceof PreAuthBudgets);

        $clock = self::getContainer()->get(ClockInterface::class);
        \assert($clock instanceof ClockInterface);
        $now = $clock->now();

        for ($i = 0; $i < $times; ++$i) {
            $consume($budgets, $now);
        }
    }
}
