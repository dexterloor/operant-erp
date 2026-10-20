<?php

declare(strict_types=1);

namespace App\Tests\Auth;

use App\Modules\UserManagement\Infrastructure\AuthTrailWriter;
use App\Modules\UserManagement\Infrastructure\EmailHasher;
use App\Modules\UserManagement\Infrastructure\LockoutStore;
use App\Platform\Tenancy\Entity\Tenant;
use App\SharedKernel\ValueObject\AuditEvent;
use App\Tests\Support\SecurityModelDocument;
use App\Tests\Support\UtcMidnight;
use Doctrine\DBAL\ParameterType;
use PHPUnit\Framework\Attributes\Test;

/**
 * **E-12's daily cap on the login-failure trail** — ADR-006 §2.2 and Amendment 1,
 * A1.3; `SCHEMA.md` §6.2; `DELIVERY_LEDGER.md` E4-4 as amended; plan v3 C1 and
 * its §5.1 *fails if* clauses.
 *
 * The key is `(slug_normalised, email_hash)` per UTC day. The counter is
 * `auth_lockout.suppression_count`, beside `suppression_day`, on
 * `erp_ratelimit`, separate from `failure_count`, and it advances inside step
 * 7's one upsert and only on the two branches that write the trail
 * (`auth.login.failure`, `auth.login.tenant_inactive`). Rows 1–20 are ordinary;
 * row 21 is ordinary and carries `metadata.suppression_started = true`; from
 * attempt 22 nothing reaches `audit_log`. Only the UTC day boundary resets it.
 *
 * ---------------------------------------------------------------------------
 * How the day and the pauses are driven — stated, because both are shortcuts
 * ---------------------------------------------------------------------------
 * Reaching attempt 21 honestly passes through two E-22 pauses. The one test
 * that drives every attempt over HTTP expires each pause by **ageing
 * `locked_until` as the owner** — the move `AuthLockoutThresholdTest` already
 * uses, because expiry is decided by Postgres' `now()` inside the upsert and no
 * PHP clock reaches it. Every other test **seeds the counter row as the owner**
 * at the state it needs. The day boundary is moved the same way — by writing
 * yesterday's UTC date into `suppression_day` — and the *UTC* half is proven by
 * running the request inside a transaction on the `ratelimit` connection whose
 * `TimeZone` is set far enough from UTC that the local date differs.
 */
final class LoginAuditCapTest extends AuthTestCase
{
    /**
     * **C, pinned here and not read from the code under test.** A loop bounded by
     * the constant shortens with it — `AuthLockoutThresholdTest` measured that
     * shape once already — so the boundaries below are literals.
     */
    private const PINNED_CEILING = 20;

    private const PASSWORD = 'correct horse battery staple';

    private const EMAIL = 'alice@example.com';

    /**
     * The longest a test here may take from its seed to its last request, in
     * seconds. Measured 2026-09-16: the longest method (23 sign-ins over HTTP)
     * ran in 1.1 s; 30 allows a machine many times slower.
     */
    private const DAY_BUDGET_SECONDS = 30;

    /**
     * **No UTC midnight inside a test** (V1-R-16, V4 B4d). Every test here seeds or
     * reads "today's UTC day" and then drives requests; crossing 00:00 UTC between
     * the two resets `suppression_count` and turns a correct run red. The time is
     * the database's (`now()` decides the day), and when the boundary is within
     * the budget the test sleeps past it. No skip, no retry.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $nowUtc = new \DateTimeImmutable((string) $this->owner()->fetchOne("SELECT to_char(now() AT TIME ZONE 'UTC', 'YYYY-MM-DD\"T\"HH24:MI:SS.US\"+00:00\"')"));
        $wait = UtcMidnight::secondsToWaitClearOfUtcMidnight($nowUtc, self::DAY_BUDGET_SECONDS);
        if ($wait > 0) {
            sleep($wait);
        }
    }

    /**
     * §5.1 clause 4, *"C ≠ 20"*, against the constant and against E-12's own row.
     *
     * *Mutation, run:* `AuthTrailWriter::DAILY_CEILING = 19`; red here **and** in
     * `theTrailHoldsTwentyOrdinaryRowsThenTheMarkerThenNothing`.
     */
    #[Test]
    public function theCeilingIsTheOneTheOwnerAccepted(): void
    {
        self::assertSame(self::PINNED_CEILING, AuthTrailWriter::DAILY_CEILING);

        if (!SecurityModelDocument::isAvailable()) {
            self::markTestSkipped('docs/SECURITY_MODEL.md is gitignored and absent from this checkout, so the pin is checked against the code only.');
        }

        $row = SecurityModelDocument::enforcementRow('E-12');
        self::assertNotSame('', $row, 'E-12 has left the register, so the pin above is unanchored.');
        self::assertStringContainsString('The ceiling is C = 20 per pair per UTC day', $row);
    }

    /**
     * The whole day for one pair, driven over HTTP: plan C1's *"removing the cap
     * leaves the suite green"*, §5.1 clause 3's marker, and *"at most 21 rows per
     * pair per day"*. Every pause on the way is expired by ageing `locked_until`,
     * so this is also the pause-expiry half of §5.1 clause 1: the count arrives at
     * 23 through two expiries.
     *
     * *Mutations, run:* (a) write every failure row regardless of the count — 23
     * rows, red; (b) drop the marker — row 21's `metadata` is `{}`, red; (c) reset
     * `suppression_count` in the pause-expiry arm — the count restarts, red.
     */
    #[Test]
    public function theTrailHoldsTwentyOrdinaryRowsThenTheMarkerThenNothing(): void
    {
        $slug = self::SLUG_PREFIX.'cap';
        $tenantId = $this->seedTenant($slug, Tenant::STATUS_ACTIVE);
        $userId = $this->seedUser($tenantId, self::EMAIL, self::PASSWORD);

        $attempts = self::PINNED_CEILING + 3;
        for ($attempt = 1; $attempt <= $attempts; ++$attempt) {
            $this->expirePause($slug, self::EMAIL);
            $this->signIn($slug, self::EMAIL, 'wrong');
            self::assertSame(401, $this->httpStatus(), "Attempt $attempt was not answered as a wrong password.");
        }

        $rows = $this->trailWithMetadata($tenantId);

        self::assertCount(self::PINNED_CEILING + 1, $rows,
            'The trail does not hold exactly C ordinary rows plus the marker for one pair in one UTC day.');

        foreach ($rows as $i => $row) {
            self::assertSame(AuditEvent::AUTH_LOGIN_FAILURE, $row['event_type']);
            self::assertSame($userId, $row['actor_user_id']);
            $expected = $i < self::PINNED_CEILING ? [] : ['suppression_started' => true];
            self::assertSame($expected, $row['metadata'], sprintf('Row %d carries the wrong metadata.', $i + 1));
        }

        self::assertSame($attempts, $this->suppressionCount($slug, self::EMAIL),
            'Counting stopped past the ceiling, or a pause expiry reset it, so the day\'s suppressed total is not held.');
    }

    /**
     * **V1-S-5b's reader half** (audit reads C1, 2026-10-21): the E-12 marker row is read back through
     * `ListAuditLogEntries`, as the owner and admins will read it — `metadata.suppression_started === true` on the newest
     * of the day's twenty-one rows, `{}` on the other twenty, newest first, filtered by `event_type`. The alert half (a
     * job that raises it to someone) still waits for a job runner (`API.md` §15.1). *Mutation M8 (strip `metadata` from the
     * entry): red here.*
     */
    #[Test]
    public function theMarkerIsReadBackThroughTheAuditTrail(): void
    {
        $slug = self::SLUG_PREFIX.'reader';
        $tenantId = $this->seedTenant($slug, Tenant::STATUS_ACTIVE);
        $userId = $this->seedUser($tenantId, self::EMAIL, self::PASSWORD);
        $this->spendTheCap($slug, 'wrong');
        $this->expirePause($slug, self::EMAIL);
        $this->signIn($slug, self::EMAIL, 'wrong'); // C+2: silent, no row.

        $this->actAs($tenantId, ['audit_log.view']);
        $this->send('GET', '/audit-log?event_type='.AuditEvent::AUTH_LOGIN_FAILURE);

        self::assertSame(200, $this->httpStatus(), (string) $this->client()->getResponse()->getContent());
        $items = $this->json()['items'];
        self::assertCount(self::PINNED_CEILING + 1, $items, 'The trail does not serve C ordinary rows plus the marker.');
        self::assertSame(['suppression_started' => true], $items[0]['metadata'], 'The newest row of the day is not the marker.');
        foreach (\array_slice($items, 1) as $i => $row) {
            self::assertSame([], $row['metadata'], sprintf('Row %d below the marker carries metadata.', $i + 2));
        }
        foreach ($items as $row) {
            self::assertSame(AuditEvent::AUTH_LOGIN_FAILURE, $row['event_type']);
            self::assertSame($userId, $row['actor']['id'] ?? null);
            self::assertNotNull($row['ip_address'], 'A sign-in failure is a security event; its address is served on the trail.');
        }
    }

    /**
     * E-12: *"counted over the union of `auth.login.failure` and
     * `auth.login.tenant_inactive`"*, so the two branches fall silent together.
     *
     * *Mutation, run:* advance the counter only for `auth.login.failure`; the
     * tenant-inactive attempts write past the ceiling and this is red.
     */
    #[Test]
    public function aWrongPasswordAndAnInactiveTenantShareOneCounter(): void
    {
        $slug = self::SLUG_PREFIX.'union';
        $tenantId = $this->seedTenant($slug, Tenant::STATUS_SUSPENDED);
        $this->seedUser($tenantId, self::EMAIL, self::PASSWORD);
        $this->seedCounter($slug, self::EMAIL, self::PINNED_CEILING - 1);

        $this->signIn($slug, self::EMAIL, 'wrong');          // attempt C
        $this->signIn($slug, self::EMAIL, self::PASSWORD);   // attempt C+1
        $this->signIn($slug, self::EMAIL, 'wrong');          // C+2
        $this->signIn($slug, self::EMAIL, self::PASSWORD);   // C+3

        self::assertSame([
            [AuditEvent::AUTH_LOGIN_FAILURE, []],
            [AuditEvent::AUTH_LOGIN_TENANT_INACTIVE, ['suppression_started' => true]],
        ], array_map(static fn (array $r): array => [$r['event_type'], $r['metadata']], $this->trailWithMetadata($tenantId)));

        self::assertSame(self::PINNED_CEILING + 3, $this->suppressionCount($slug, self::EMAIL));
    }

    /**
     * `API.md` §9.3's first named phase-2 test, keyed as ADR-006 A1.3 now keys it.
     * Failed sign-ins for one pair until the cap is spent — C ordinary rows and
     * the C+1 marker, every attempt over HTTP with each pause expired by ageing
     * `locked_until` — then that user's **correct** credential against the
     * non-active tenant: **zero** further `audit_log` rows.
     *
     * *Mutation, run:* advance `suppression_count` only for
     * `auth.login.tenant_inactive` (the per-event-type budget §9.3 names); the
     * wrong passwords never spend it, all 21 of them plus the final attempt write,
     * red.
     */
    #[Test]
    public function exhaustingTheCapOnWrongPasswordsAlsoSilencesTheTenantInactiveBranch(): void
    {
        $slug = self::SLUG_PREFIX.'union-wrong-first';
        $tenantId = $this->seedTenant($slug, Tenant::STATUS_SUSPENDED);
        $this->seedUser($tenantId, self::EMAIL, self::PASSWORD);

        $this->spendTheCap($slug, 'wrong');
        self::assertCount(self::PINNED_CEILING + 1, $this->trailWithMetadata($tenantId), 'The cap was not spent on wrong passwords, so the assertion below proves nothing.');
        self::assertSame(array_fill(0, self::PINNED_CEILING + 1, AuditEvent::AUTH_LOGIN_FAILURE), array_column($this->trailWithMetadata($tenantId), 'event_type'));

        $this->expirePause($slug, self::EMAIL);
        $this->signIn($slug, self::EMAIL, self::PASSWORD);
        self::assertSame(401, $this->httpStatus());

        self::assertCount(self::PINNED_CEILING + 1, $this->trailWithMetadata($tenantId),
            'Past the ceiling spent on wrong passwords, the tenant-inactive branch still wrote: the cap is not one union budget.');
    }

    /**
     * `API.md` §9.3's second named test: the cap spent on the **tenant-inactive**
     * branch first, then a wrong password — silent. *"A counter keyed on the union
     * but incremented on only one of the two events passes the first test and
     * fails the second."*
     *
     * *Mutation, run:* advance `suppression_count` only for `auth.login.failure`;
     * the tenant-inactive attempts never spend it and this is red.
     */
    #[Test]
    public function andTheReverseOrderIsAlsoSilent(): void
    {
        $slug = self::SLUG_PREFIX.'union-inactive-first';
        $tenantId = $this->seedTenant($slug, Tenant::STATUS_SUSPENDED);
        $this->seedUser($tenantId, self::EMAIL, self::PASSWORD);

        $this->spendTheCap($slug, self::PASSWORD);
        self::assertSame(array_fill(0, self::PINNED_CEILING + 1, AuditEvent::AUTH_LOGIN_TENANT_INACTIVE), array_column($this->trailWithMetadata($tenantId), 'event_type'),
            'The cap was not spent on the tenant-inactive branch, so the assertion below proves nothing.');

        $this->expirePause($slug, self::EMAIL);
        $this->signIn($slug, self::EMAIL, 'wrong');
        self::assertSame(401, $this->httpStatus());

        self::assertCount(self::PINNED_CEILING + 1, $this->trailWithMetadata($tenantId),
            'Past the ceiling spent on the tenant-inactive branch, a wrong password still wrote: the cap is not one union budget.');
    }

    /**
     * §5.1 clause 1: *"a success, a pause expiry or `LockoutStore::clear()` resets
     * the suppression counter"* — each of the three is driven, each with a control
     * proving it really reset `failure_count`, and the attempt after the success
     * proves the trail stayed silent.
     *
     * *Mutation, run:* `clear()` also sets `suppression_count = 0`; red on the
     * success half and on the `clear()` half.
     */
    #[Test]
    public function onlyTheDayBoundaryResetsTheSuppressionCounter(): void
    {
        $slug = self::SLUG_PREFIX.'resets';
        $tenantId = $this->seedTenant($slug, Tenant::STATUS_ACTIVE);
        $this->seedUser($tenantId, self::EMAIL, self::PASSWORD);
        $over = self::PINNED_CEILING + 1;

        // --- a success ------------------------------------------------------
        $this->seedCounter($slug, self::EMAIL, $over, failureCount: 3);
        $this->signIn($slug, self::EMAIL, self::PASSWORD);
        self::assertSame(200, $this->httpStatus(), 'The control sign-in did not succeed.');
        self::assertSame(0, $this->lockoutFor($slug, self::EMAIL)['failure_count'], 'The success did not reset failure_count, so this proves nothing.');
        self::assertSame($over, $this->suppressionCount($slug, self::EMAIL), 'A success reset the suppression counter.');

        $this->signIn($slug, self::EMAIL, 'wrong');
        self::assertSame([AuditEvent::AUTH_LOGIN_SUCCESS], array_column($this->trailWithMetadata($tenantId), 'event_type'),
            'After a success the trail wrote a failure row past the ceiling: the success re-armed the cap.');

        // --- LockoutStore::clear() -------------------------------------------
        $this->seedCounter($slug, self::EMAIL, $over, failureCount: 4);
        $this->lockoutStore()->clear(mb_strtolower($slug), $this->hash(self::EMAIL));
        self::assertSame(0, $this->lockoutFor($slug, self::EMAIL)['failure_count'], 'clear() did not reset failure_count, so this proves nothing.');
        self::assertSame($over, $this->suppressionCount($slug, self::EMAIL), 'LockoutStore::clear() reset the suppression counter.');

        // --- a pause expiry ---------------------------------------------------
        $this->seedCounter($slug, self::EMAIL, $over, failureCount: LockoutStore::THRESHOLD, locked: true);
        $this->expirePause($slug, self::EMAIL);
        $this->signIn($slug, self::EMAIL, 'wrong');
        self::assertSame(401, $this->httpStatus());
        self::assertSame(1, $this->lockoutFor($slug, self::EMAIL)['failure_count'], 'The pause expiry did not reset failure_count, so this proves nothing.');
        self::assertSame($over + 1, $this->suppressionCount($slug, self::EMAIL), 'A pause expiry reset the suppression counter.');
        self::assertCount(1, $this->trailWithMetadata($tenantId), 'A failure past the ceiling reached the trail after a pause expiry.');
    }

    /**
     * §5.1 clause 2 and the second half of clause 3: the `ratelimit` statement
     * count is the same at attempts C and C+1 on **both** miss paths, and the
     * marker row costs no statement on **any** connection.
     *
     * *Mutation, run:* read `auth_lockout` again in `SignIn` when the returned
     * count reaches the ceiling (`$this->lockouts->pausedUntil(...)`); the
     * wrong-password `ratelimit` count rises at C+1 and this is red.
     */
    #[Test]
    public function theCeilingAddsNoStatementOnEitherMissPath(): void
    {
        $slug = self::SLUG_PREFIX.'counts';
        $tenantId = $this->seedTenant($slug, Tenant::STATUS_ACTIVE);
        $this->seedUser($tenantId, self::EMAIL, self::PASSWORD);

        $this->seedCounter($slug, self::EMAIL, self::PINNED_CEILING - 1);
        $wrongAtC = $this->measure(fn () => $this->signIn($slug, self::EMAIL, 'wrong'));
        $wrongAtC1 = $this->measure(fn () => $this->signIn($slug, self::EMAIL, 'wrong'));
        $wrongAtC2 = $this->measure(fn () => $this->signIn($slug, self::EMAIL, 'wrong'));

        $this->seedCounter($slug, 'nobody@example.com', self::PINNED_CEILING - 1);
        $unknownAtC = $this->measure(fn () => $this->signIn($slug, 'nobody@example.com', 'wrong'));
        $unknownAtC1 = $this->measure(fn () => $this->signIn($slug, 'nobody@example.com', 'wrong'));

        self::assertSame(self::PINNED_CEILING + 2, $this->suppressionCount($slug, self::EMAIL), 'The three measured attempts were not C, C+1 and C+2.');
        self::assertCount(2, $this->trailWithMetadata($tenantId), 'Attempts C and C+1 did not both write, so the comparison is not at the ceiling.');
        self::assertGreaterThan(0, $wrongAtC['ratelimit'], 'Nothing was recorded on ratelimit, so the comparison below is vacuous.');

        self::assertSame($wrongAtC, $wrongAtC1,
            "The marker row at C+1 issued a different statement count on some connection:\n".print_r(['C' => $wrongAtC, 'C+1' => $wrongAtC1], true));
        self::assertSame($wrongAtC['ratelimit'], $wrongAtC2['ratelimit'], 'The ratelimit count moved past the ceiling on the wrong-password path.');
        self::assertSame(0, $wrongAtC2['authlog'], 'ADR-006 A1.3 residual 4: past C+1 the wrong-password branch still reached the trail.');
        self::assertSame($unknownAtC['ratelimit'], $unknownAtC1['ratelimit'], 'The ratelimit count differs between C and C+1 on the unknown-email path.');
        self::assertSame($wrongAtC['ratelimit'], $unknownAtC['ratelimit'], 'V-8: the ratelimit count differs between the two miss paths at the ceiling.');
        self::assertSame(self::PINNED_CEILING - 1, $this->suppressionCount($slug, 'nobody@example.com'),
            'An unresolved email advanced the suppression counter, which counts only branches that write the trail.');
    }

    /**
     * **V1-S-5a (V4 B4d): the response does not change at the cap.** Attempts C,
     * C+1 (the marker row) and C+2 (silent) are compared byte for byte — status,
     * body length, decoded body and every header but `date` / `x-debug-token*`,
     * the observable `TokenEndpointIndistinguishabilityTest` compares — and with an
     * ordinary wrong-password refusal well below the ceiling. Controls inside: the
     * three attempts really are C, C+1 and C+2 (the counter), and exactly two of
     * them wrote (so the comparison straddles the marker and the silence).
     *
     * The marker's **reader** is `ListAuditLogEntries` since audit reads C1
     * ({@see self::theMarkerIsReadBackThroughTheAuditTrail()}); its **alert** is
     * still V1-S-5b's, owed to a job runner. This test pins only that the caller
     * cannot see the cap.
     *
     * *Mutations (cache deleted before and after):* a header (`X-B4-Marker`) and,
     * separately, a `detail` member added to the refusal at C+1 only — each GREEN
     * across the suite's auth tests before this test existed, each RED here.
     */
    #[Test]
    public function theRefusalIsByteIdenticalAtTheCeilingTheMarkerAndPastIt(): void
    {
        $slug = self::SLUG_PREFIX.'bytes';
        $tenantId = $this->seedTenant($slug, Tenant::STATUS_ACTIVE);
        $this->seedUser($tenantId, self::EMAIL, self::PASSWORD);

        $this->signIn($slug, self::EMAIL, 'wrong');
        $ordinary = $this->observable();
        self::assertSame(401, $ordinary['status'], 'The ordinary refusal below the ceiling was not a 401.');

        $this->seedCounter($slug, self::EMAIL, self::PINNED_CEILING - 1);
        $before = \count($this->trailWithMetadata($tenantId));
        $observed = [];
        foreach (['C', 'C+1', 'C+2'] as $attempt) {
            $this->signIn($slug, self::EMAIL, 'wrong');
            $observed[$attempt] = $this->observable();
        }

        self::assertSame(self::PINNED_CEILING + 2, $this->suppressionCount($slug, self::EMAIL), 'The three attempts were not C, C+1 and C+2.');
        self::assertSame(2, \count($this->trailWithMetadata($tenantId)) - $before, 'C and C+1 did not both write, so the comparison does not straddle the marker.');

        foreach ($observed as $attempt => $response) {
            self::assertSame($ordinary, $response, "The refusal at attempt $attempt differs from an ordinary wrong password: the cap is observable.");
        }
    }

    /**
     * Status, body length, decoded body and headers (less `date` and
     * `x-debug-token*`) of the last response.
     *
     * @return array{status: int, length: int, body: array<string, mixed>, headers: array<string, string>}
     */
    private function observable(): array
    {
        $response = $this->client()->getResponse();
        $content = (string) $response->getContent();

        $headers = [];
        foreach ($response->headers->all() as $name => $values) {
            if ('date' === $name || str_starts_with($name, 'x-debug-token')) {
                continue;
            }
            $headers[$name] = implode(', ', array_map(strval(...), $values));
        }
        ksort($headers);

        return ['status' => $response->getStatusCode(), 'length' => \strlen($content), 'body' => (array) json_decode($content, true), 'headers' => $headers];
    }

    /**
     * The day boundary, as the only reset: yesterday's UTC count is replaced, the
     * next row is an ordinary one again, and the day is today's UTC date.
     *
     * *Mutation, run:* drop the `suppression_day = today` arm (keep adding); the
     * count continues from 30 and no row is written — red.
     */
    #[Test]
    public function aNewUtcDayReArmsTheCap(): void
    {
        $slug = self::SLUG_PREFIX.'boundary';
        $tenantId = $this->seedTenant($slug, Tenant::STATUS_ACTIVE);
        $this->seedUser($tenantId, self::EMAIL, self::PASSWORD);
        $this->seedCounter($slug, self::EMAIL, 30, day: "(now() AT TIME ZONE 'UTC')::date - 1");

        $this->signIn($slug, self::EMAIL, 'wrong');

        self::assertSame([[AuditEvent::AUTH_LOGIN_FAILURE, []]],
            array_map(static fn (array $r): array => [$r['event_type'], $r['metadata']], $this->trailWithMetadata($tenantId)),
            'The first failure of a new UTC day did not write an ordinary row.');
        self::assertSame(1, $this->suppressionCount($slug, self::EMAIL));
        self::assertTrue((bool) $this->owner()->fetchOne(
            "SELECT suppression_day = (now() AT TIME ZONE 'UTC')::date FROM auth_lockout WHERE slug_normalised = ?", [mb_strtolower($slug)]),
            'suppression_day is not today\'s UTC date.');
    }

    /**
     * *"the day must be computed in SQL as UTC"* — `(now() AT TIME ZONE
     * 'UTC')::date`, not the session's `CURRENT_DATE`. The server runs in UTC, so
     * the two agree unless the session is moved: the request runs inside a
     * transaction on the `ratelimit` connection with `SET LOCAL TimeZone` at a zone
     * whose local date differs from the UTC date right now, and the positive
     * control asserts that difference in the same transaction.
     *
     * *Mutation, run:* compute the day as `CURRENT_DATE` in `LockoutStore`; the
     * seeded UTC-today row is read as a stale day, the count restarts at 1, red.
     */
    #[Test]
    public function theDayIsTheUtcDateWhateverTheSessionTimeZone(): void
    {
        $slug = self::SLUG_PREFIX.'utc';
        $tenantId = $this->seedTenant($slug, Tenant::STATUS_ACTIVE);
        $this->seedUser($tenantId, self::EMAIL, self::PASSWORD);
        $this->seedCounter($slug, self::EMAIL, 5);

        $rateLimit = $this->rateLimit();
        $utcHour = (int) $this->owner()->fetchOne("SELECT extract(hour FROM now() AT TIME ZONE 'UTC')");
        // UTC-12 is yesterday before noon UTC; UTC+14 is tomorrow from 10:00 UTC.
        $zone = $utcHour < 12 ? 'Etc/GMT+12' : 'Etc/GMT-14';

        $rateLimit->beginTransaction();
        try {
            $rateLimit->executeStatement("SET LOCAL TimeZone = '$zone'");
            self::assertTrue((bool) $rateLimit->fetchOne("SELECT CURRENT_DATE <> (now() AT TIME ZONE 'UTC')::date"),
                "The session date equals the UTC date under $zone, so this test cannot tell them apart.");

            $this->signIn($slug, self::EMAIL, 'wrong');
            self::assertSame(401, $this->httpStatus());

            $row = $rateLimit->fetchAssociative(
                "SELECT suppression_count, suppression_day = (now() AT TIME ZONE 'UTC')::date AS is_utc_today FROM auth_lockout WHERE slug_normalised = ? AND email_hash = ?",
                [mb_strtolower($slug), $this->hash(self::EMAIL)],
                [ParameterType::STRING, ParameterType::BINARY],
            );
        } finally {
            $rateLimit->rollBack();
        }

        self::assertIsArray($row);
        self::assertSame(6, (int) $row['suppression_count'], "Under $zone the counter restarted: the day was not computed as UTC.");
        self::assertTrue((bool) $row['is_utc_today']);
    }

    /**
     * §5.1's *"an E-11 column exists"* and E4-4's scope: the table's columns are
     * exactly E-22's and E-12's, in that order.
     *
     * *Mutation, run:* add `status_email_sent_at timestamptz` to the migration;
     * red.
     */
    #[Test]
    public function authLockoutCarriesE12sStorageAndNoE11Column(): void
    {
        $columns = $this->owner()->fetchFirstColumn(<<<'SQL'
            SELECT a.attname FROM pg_attribute a
            WHERE a.attrelid = 'auth_lockout'::regclass AND a.attnum > 0 AND NOT a.attisdropped
            ORDER BY a.attnum
            SQL);

        self::assertSame([
            'slug_normalised', 'email_hash', 'failure_count', 'first_failure_at', 'last_failure_at', 'locked_until',
            'suppression_count', 'suppression_day',
        ], $columns);
    }

    // -----------------------------------------------------------------------

    /**
     * @param callable(): void $attempt
     *
     * @return array{app: int, auth: int, authlog: int, ratelimit: int}
     */
    private function measure(callable $attempt): array
    {
        foreach (['app', 'auth', 'authlog', 'ratelimit'] as $connection) {
            $this->recorderFor($connection)->start();
        }

        $attempt();

        $counts = [];
        foreach (['app', 'auth', 'authlog', 'ratelimit'] as $connection) {
            $this->recorderFor($connection)->stop();
            $counts[$connection] = \count($this->recorderFor($connection)->statements());
        }

        return $counts;
    }

    /** Seeds the pair's row as the owner, replacing any existing one. `$day` is a SQL expression. */
    private function seedCounter(string $slug, string $email, int $suppressionCount, int $failureCount = 0, bool $locked = false, string $day = "(now() AT TIME ZONE 'UTC')::date"): void
    {
        $lockedUntil = $locked ? "now() + interval '1 minute'" : 'NULL';

        $this->owner()->executeStatement(<<<SQL
            INSERT INTO auth_lockout (slug_normalised, email_hash, failure_count, locked_until, suppression_count, suppression_day)
            VALUES (?, ?, ?, $lockedUntil, ?, $day)
            ON CONFLICT (slug_normalised, email_hash) DO UPDATE SET
                failure_count = EXCLUDED.failure_count, locked_until = EXCLUDED.locked_until,
                suppression_count = EXCLUDED.suppression_count, suppression_day = EXCLUDED.suppression_day
            SQL,
            [mb_strtolower($slug), $this->hash($email), $failureCount, $suppressionCount],
            [ParameterType::STRING, ParameterType::BINARY, ParameterType::INTEGER, ParameterType::INTEGER],
        );
    }

    /** C+1 sign-ins with one password for EMAIL at $slug, over HTTP, each pause on the way expired first. */
    private function spendTheCap(string $slug, string $password): void
    {
        for ($attempt = 1; $attempt <= self::PINNED_CEILING + 1; ++$attempt) {
            $this->expirePause($slug, self::EMAIL);
            $this->signIn($slug, self::EMAIL, $password);
            self::assertSame(401, $this->httpStatus(), "Attempt $attempt was not a refusal.");
        }
    }

    private function expirePause(string $slug, string $email): void
    {
        $this->owner()->executeStatement(
            "UPDATE auth_lockout SET locked_until = now() - interval '1 second' WHERE slug_normalised = ? AND email_hash = ? AND locked_until IS NOT NULL",
            [mb_strtolower($slug), $this->hash($email)],
            [ParameterType::STRING, ParameterType::BINARY],
        );
    }

    private function suppressionCount(string $slug, string $email): int
    {
        return (int) $this->owner()->fetchOne(
            'SELECT suppression_count FROM auth_lockout WHERE slug_normalised = ? AND email_hash = ?',
            [mb_strtolower($slug), $this->hash($email)],
            [ParameterType::STRING, ParameterType::BINARY],
        );
    }

    /** @return list<array{event_type: string, actor_user_id: string, metadata: array<string, mixed>}> */
    private function trailWithMetadata(string $tenantId): array
    {
        return array_map(static fn (array $r): array => [
            'event_type' => (string) $r['event_type'],
            'actor_user_id' => (string) $r['actor_user_id'],
            'metadata' => json_decode((string) $r['metadata'], true, flags: \JSON_THROW_ON_ERROR),
        ], $this->fetchAsOwnerForTenant($tenantId,
            'SELECT event_type, actor_user_id, metadata FROM audit_log ORDER BY occurred_at, id'));
    }

    private function hash(string $email): string
    {
        $hasher = self::getContainer()->get('test.auth.email_hasher');
        \assert($hasher instanceof EmailHasher);

        return $hasher->hash(mb_strtolower($email));
    }

    private function lockoutStore(): LockoutStore
    {
        $store = self::getContainer()->get(LockoutStore::class);
        \assert($store instanceof LockoutStore);

        return $store;
    }
}
