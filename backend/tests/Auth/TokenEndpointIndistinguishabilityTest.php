<?php

declare(strict_types=1);

namespace App\Tests\Auth;

use App\SharedKernel\ValueObject\AuditEvent;
use App\Platform\Tenancy\Entity\Tenant;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\PasswordHasher\PasswordHasherInterface;

/**
 * **E-7 made concrete** — `API.md` §7.3 and §7.6.
 *
 * The requirement is that refusing a non-active tenant is indistinguishable from
 * a wrong password across **six observables**: body, body length, status,
 * headers, timing and statement count. Four of them are asserted here as bytes;
 * **statement count is `PreAuthPrincipalTest`'s**, because it is a property of
 * the SQL rather than of the response; and **timing is deliberately not asserted
 * as a duration** — T-6's own check is deterministic rather than a timing test,
 * and what stands in for the clock is `theHasherIsInvokedExactlyOnceOnEveryPath`
 * plus the absence of any branch that skips work.
 *
 * ---------------------------------------------------------------------------
 * The value set is DERIVED, and the derivation is the point
 * ---------------------------------------------------------------------------
 * §7.3: *"a test that covers only `active` and `suspended` leaves three values
 * unproven"*, so the statuses come from `Tenant`'s own `STATUS_*` constants by
 * reflection and a sixth status added tomorrow is covered without anybody
 * remembering this file. §7.3's second axis is `app_user.is_active`, because
 * §7.6 rules that branch identical too and *"a test that varies only the first
 * axis proves nothing about the second"*.
 *
 * ---------------------------------------------------------------------------
 * Anti-vacuity
 * ---------------------------------------------------------------------------
 * *"Every response in this set is identical"* is trivially true of a set in
 * which everything fails for an unrelated reason — a broken fixture, a 500, a
 * route that stopped existing. So the one combination that is **not** a refusal
 * — `active` × `is_active = true` × the correct password — is driven in the same
 * method and asserted to be a 200 carrying a token. Without it this file would
 * be green with the endpoint deleted.
 */
final class TokenEndpointIndistinguishabilityTest extends AuthTestCase
{
    private const PASSWORD = 'correct horse battery staple';

    private const EMAIL = 'alice@example.com';

    /**
     * *Mutation, run:* add `instance` to `ApiProblem::authenticationFailed()`'s
     * problem body — a member `getInstance()` returns non-null. Every byte
     * comparison in the set still matches (it is constant), so the mutation that
     * actually bites is a **varying** member: returning `$this->getMessage()` as
     * `detail`, which differs per branch. Both were run; the second is red.
     *
     * *Second mutation, and it is the one that matters:* move the
     * `Tenant::statusPermitsLogin()` check in `SignIn::classify()` ahead of the
     * `!$verified` branch. The four non-active statuses then answer before the
     * credential is considered — the response is unchanged, so **this** test
     * stays green and `theHasherIsInvokedExactlyOnceOnEveryPath` does not: see
     * there.
     */
    #[Test]
    public function everyRefusalIsByteIdenticalToAWrongPassword(): void
    {
        $responses = [];

        foreach ($this->refusalCases() as $label => $case) {
            $case();
            $responses[$label] = $this->observable();
        }

        self::assertGreaterThanOrEqual(14, \count($responses),
            'The derived case set collapsed. Tenant::STATUS_* × app_user.is_active plus the five '
            .'credential cases is fourteen refusals; fewer means the derivation found less than it should '
            .'and every comparison below would be a comparison of almost nothing.');

        $distinct = array_unique(array_map(
            static fn (array $observable): string => json_encode($observable, \JSON_THROW_ON_ERROR),
            $responses,
        ));

        self::assertCount(1, $distinct, "Refusals are distinguishable:\n".print_r($responses, true));

        [$first] = array_values($responses);
        self::assertSame(401, $first['status']);
        self::assertSame('urn:erp:problem:authentication-failed', $first['body']['type']);
        self::assertArrayNotHasKey('instance', $first['body'], 'A member that varies is a channel, and `instance` is the one a framework adds by default.');
        self::assertArrayNotHasKey('detail', $first['body']);
        self::assertArrayNotHasKey('attempts_remaining', $first['body']);
        self::assertArrayNotHasKey('retry-after', $first['headers'], '§5.2a: Retry-After is the remaining time and is never sent.');

        // Anti-vacuity: the one combination that is not a refusal.
        $tenantId = $this->seedTenant(self::SLUG_PREFIX.'anti-vacuity', Tenant::STATUS_ACTIVE);
        $this->seedUser($tenantId, self::EMAIL, self::PASSWORD, true);
        $this->signIn(self::SLUG_PREFIX.'anti-vacuity', self::EMAIL, self::PASSWORD);

        self::assertSame(200, $this->httpStatus(),
            'The combination this set excludes is not a success, so "every case refused identically" says nothing.');
    }

    /**
     * T-6 and §7.3, the KDF row: *"exactly one, on every path, against a fixed
     * dummy hash on either miss."*
     *
     * *Mutation, run:* move the `Tenant::statusPermitsLogin()` check in
     * `SignIn::classify()` ahead of the credential check — the shape §7.3 calls
     * *"the one that matters"*. The hasher still runs, because step 5 is
     * unconditional, so the count does **not** drop; what does is the ordering,
     * and that is caught instead by moving the check ahead of
     * `$this->passwords->verify(...)` in `SignIn::attempt()` and returning early:
     * the non-active branches then report **zero** and this is red.
     *
     * *Second mutation, run:* pass `$row->passwordHash` straight through with no
     * dummy substitution and skip `verify()` when it is null; the two miss paths
     * report zero and this is red.
     */
    #[Test]
    public function theHasherIsInvokedExactlyOnceOnEveryPath(): void
    {
        $counts = [];

        foreach ($this->refusalCases() as $label => $case) {
            $this->hasher()->reset();
            $case();
            $counts[$label] = $this->hasher()->invocations();
        }

        self::assertNotSame([], $counts);
        self::assertSame([1], array_values(array_unique($counts)),
            "The KDF did not run exactly once on every path:\n".print_r($counts, true));
    }

    /**
     * §7.6 — a correct credential for a deactivated user in an **active** tenant
     * is byte-identical to a wrong password, writes one `auth_lockout` upsert,
     * and writes **`auth.login.failure`** rather than a new catalogue member.
     *
     * *Mutation, run:* hoist the `is_active` check in `SignIn::classify()` above
     * the `!$verified` branch; the response is unchanged but the trail row
     * appears for a wrong password too — caught by the count below only in the
     * ordering sense, so the mutation that is genuinely red here is returning
     * `null` from `classify()` for the deactivated branch: the trail row
     * disappears and the wrong-password comparison breaks.
     */
    #[Test]
    public function aDeactivatedUserIsByteIdenticalToAWrongPassword(): void
    {
        $deactivated = $this->seedTenant(self::SLUG_PREFIX.'deactivated', Tenant::STATUS_ACTIVE);
        $this->seedUser($deactivated, self::EMAIL, self::PASSWORD, false);

        $this->signIn(self::SLUG_PREFIX.'deactivated', self::EMAIL, self::PASSWORD);
        $deactivatedResponse = $this->observable();
        $deactivatedTrail = $this->trailRows($deactivated);
        $deactivatedLockout = $this->lockoutFor(self::SLUG_PREFIX.'deactivated', self::EMAIL);

        $live = $this->seedTenant(self::SLUG_PREFIX.'live', Tenant::STATUS_ACTIVE);
        $this->seedUser($live, self::EMAIL, self::PASSWORD, true);

        $this->signIn(self::SLUG_PREFIX.'live', self::EMAIL, 'wrong');
        $wrongPasswordResponse = $this->observable();
        $wrongPasswordTrail = $this->trailRows($live);
        $wrongPasswordLockout = $this->lockoutFor(self::SLUG_PREFIX.'live', self::EMAIL);

        self::assertSame($wrongPasswordResponse, $deactivatedResponse);

        self::assertCount(1, $deactivatedTrail);
        self::assertCount(1, $wrongPasswordTrail);
        self::assertSame(AuditEvent::AUTH_LOGIN_FAILURE, $deactivatedTrail[0]['event_type'],
            '§7.6 reuses the existing member: minting auth.login.user_inactive is a SEC decision under PC-11 (4a).');
        self::assertSame($wrongPasswordTrail[0]['event_type'], $deactivatedTrail[0]['event_type']);

        self::assertSame(['failure_count' => 1, 'locked' => false], $deactivatedLockout,
            '§7.6: the branch writes one auth_lockout upsert, exactly as a wrong password.');
        self::assertSame($wrongPasswordLockout, $deactivatedLockout);
    }

    /**
     * §7.6's precedence clause: *"a deactivated user in a non-active tenant takes
     * step 6's branch — `auth.login.tenant_inactive` — not step 6a's."*
     *
     * *Mutation, run:* swap the two branches in `SignIn::classify()`; this sees
     * `auth.login.failure` where it requires `auth.login.tenant_inactive`; red.
     */
    #[Test]
    public function theTenantStatusBranchWinsOverTheDeactivatedUserBranch(): void
    {
        $tenantId = $this->seedTenant(self::SLUG_PREFIX.'both', Tenant::STATUS_SUSPENDED);
        $this->seedUser($tenantId, self::EMAIL, self::PASSWORD, false);

        $this->signIn(self::SLUG_PREFIX.'both', self::EMAIL, self::PASSWORD);

        $trail = $this->trailRows($tenantId);
        self::assertCount(1, $trail);
        self::assertSame(AuditEvent::AUTH_LOGIN_TENANT_INACTIVE, $trail[0]['event_type'],
            'Putting the finer branch first makes E-12 unreachable for any deactivated user, which is a silent hole in its coverage.');
    }

    /**
     * E-12's statement-count clause, on the trail rather than on the wire: the
     * tenant-inactive row is written *"at the same branch point and with the same
     * statement count as the `auth.login.failure` row"*.
     *
     * *Mutation, run:* make `AuthTrailWriter::record()` a no-op for
     * `AUTH_LOGIN_TENANT_INACTIVE`; the row counts diverge and this is red.
     */
    #[Test]
    public function aVerifiedCredentialAgainstANonActiveTenantWritesExactlyOneSubjectlessRow(): void
    {
        foreach ($this->nonActiveStatuses() as $status) {
            $slug = self::SLUG_PREFIX.'e12-'.$status;
            $tenantId = $this->seedTenant($slug, $status);
            $this->seedUser($tenantId, self::EMAIL, self::PASSWORD, true);

            $this->signIn($slug, self::EMAIL, self::PASSWORD);

            $trail = $this->trailRows($tenantId);
            self::assertCount(1, $trail, "status $status wrote ".\count($trail).' audit rows, not one.');
            self::assertSame(AuditEvent::AUTH_LOGIN_TENANT_INACTIVE, $trail[0]['event_type'], "status $status");
            self::assertNull($trail[0]['subject_type'], 'E-13.');
        }
    }

    /**
     * ADR-006 §2.2 rows 1 and 2 — the two miss paths reach the counters and not
     * the trail, *"there is no tenant to own the event"* and *"the principal does
     * not exist"*.
     *
     * This is also where ST-7.3's sentinel rule is visible: an unresolved slug
     * runs `set_config` against the fixed sentinel row and the `app_user` SELECT
     * still runs, so the miss looks like a hit to everything downstream.
     *
     * *Mutation, run:* return `AuditEvent::AUTH_LOGIN_FAILURE` from
     * `SignIn::classify()` for an unresolved pair; `AuthTrailWriter` is handed a
     * null actor and the insert fails on `actor_user_id`'s composite foreign key
     * — red, loudly, which is the right way for that mistake to fail.
     */
    #[Test]
    public function neitherMissPathReachesTheTrail(): void
    {
        $tenantId = $this->seedTenant(self::SLUG_PREFIX.'live', Tenant::STATUS_ACTIVE);
        $this->seedUser($tenantId, self::EMAIL, self::PASSWORD, true);

        $this->signIn(self::SLUG_PREFIX.'no-such-workspace', self::EMAIL, self::PASSWORD);
        self::assertSame(401, $this->httpStatus());

        $this->signIn(self::SLUG_PREFIX.'live', 'nobody@example.com', self::PASSWORD);
        self::assertSame(401, $this->httpStatus());

        self::assertSame([], $this->trailRows($tenantId));

        // But both were metered, which is what makes a slug scrape loud
        // (ADR-006 §2.3) and what E-8 requires of the lockout counter.
        self::assertSame(2, (int) $this->owner()->fetchOne('SELECT count(*) FROM auth_attempt'));
        self::assertSame(2, (int) $this->owner()->fetchOne('SELECT count(*) FROM auth_lockout'));
    }

    /**
     * The observable half of E-7, as a comparable value.
     *
     * `date` is dropped because it is a clock, and the profiler's
     * `x-debug-token*` because they exist only in the test environment. Nothing
     * else is filtered: `content-type`, `cache-control`, `vary` and
     * `www-authenticate` are all compared, and so is the body's exact byte
     * length.
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

        return [
            'status' => $response->getStatusCode(),
            'length' => \strlen($content),
            'body' => (array) json_decode($content, true),
            'headers' => $headers,
        ];
    }

    /**
     * Every refusal §7.3's value set names, as callables so each one is driven
     * inside the method that measures it.
     *
     * The status axis is read off `Tenant` by reflection — never restated — and
     * the `is_active` axis is §7.6's second axis. `active × true × the correct
     * password` is the one combination absent, because it is the success.
     *
     * @return array<string, callable(): void>
     */
    private function refusalCases(): array
    {
        $cases = [];

        foreach (self::declaredStatuses() as $status) {
            foreach ([true, false] as $isActive) {
                if (Tenant::STATUS_ACTIVE === $status && $isActive) {
                    continue;
                }

                $label = 'status='.$status.' is_active='.var_export($isActive, true);
                $slug = self::SLUG_PREFIX.'m'.substr(md5($label), 0, 10);

                $cases[$label] = function () use ($slug, $status, $isActive): void {
                    $tenantId = $this->seedTenant($slug, $status);
                    $this->seedUser($tenantId, self::EMAIL, self::PASSWORD, $isActive);
                    $this->signIn($slug, self::EMAIL, self::PASSWORD);
                };
            }
        }

        $cases['wrong-password'] = function (): void {
            $slug = self::SLUG_PREFIX.'wrong-password';
            $tenantId = $this->seedTenant($slug, Tenant::STATUS_ACTIVE);
            $this->seedUser($tenantId, self::EMAIL, self::PASSWORD, true);
            $this->signIn($slug, self::EMAIL, 'not the password');
        };

        // The two plaintexts `NativePasswordHasher::verify()` answers `false` for
        // at **zero KDF rounds**. Until 2026-09-10 no case in this suite drove
        // either of them, on this path or on the credential-change path, so
        // §7.3's *"exactly one, on every path"* had a hole nothing could see —
        // and §4.3a's specified-but-unbuilt `UserProvisioningTest` names the
        // empty string as one of its three cases, so it would have inherited it.
        // `PasswordVerifier::verify()` substitutes an in-range candidate; these
        // two rows are what makes that visible.
        $cases['empty-password'] = function (): void {
            $slug = self::SLUG_PREFIX.'empty-password';
            $tenantId = $this->seedTenant($slug, Tenant::STATUS_ACTIVE);
            $this->seedUser($tenantId, self::EMAIL, self::PASSWORD, true);
            $this->signIn($slug, self::EMAIL, '');
        };

        $cases['over-long-password'] = function (): void {
            $slug = self::SLUG_PREFIX.'over-long-password';
            $tenantId = $this->seedTenant($slug, Tenant::STATUS_ACTIVE);
            $this->seedUser($tenantId, self::EMAIL, self::PASSWORD, true);
            $this->signIn($slug, self::EMAIL, str_repeat('p', PasswordHasherInterface::MAX_PASSWORD_LENGTH + 1));
        };

        $cases['unknown-email'] = function (): void {
            $slug = self::SLUG_PREFIX.'unknown-email';
            $tenantId = $this->seedTenant($slug, Tenant::STATUS_ACTIVE);
            $this->seedUser($tenantId, self::EMAIL, self::PASSWORD, true);
            $this->signIn($slug, 'nobody@example.com', self::PASSWORD);
        };

        $cases['unknown-workspace'] = function (): void {
            $this->signIn(self::SLUG_PREFIX.'no-such-workspace-at-all', self::EMAIL, self::PASSWORD);
        };

        return $cases;
    }

    /** @return list<string> */
    private function nonActiveStatuses(): array
    {
        return array_values(array_filter(
            self::declaredStatuses(),
            static fn (string $status): bool => Tenant::STATUS_ACTIVE !== $status,
        ));
    }

    /**
     * `Tenant`'s declared value space, read off the class. The entity keeps this
     * derivation private, so it is repeated here rather than exposed — and that
     * is deliberate: a public accessor would let a future edit narrow the domain
     * of this test from inside the class under test.
     *
     * @return list<string>
     */
    private static function declaredStatuses(): array
    {
        $statuses = [];

        /** @var array<string, mixed> $constants */
        $constants = (new \ReflectionClass(Tenant::class))->getConstants();

        foreach ($constants as $name => $value) {
            if (str_starts_with($name, 'STATUS_') && \is_string($value)) {
                $statuses[] = $value;
            }
        }

        self::assertGreaterThanOrEqual(5, \count($statuses),
            'Tenant declares fewer than five statuses, so the derived value set below is not the one §7.3 requires.');

        return $statuses;
    }
}
