<?php

declare(strict_types=1);

namespace App\Tests\Auth;

use App\Platform\Runtime\ServingCredentialGuard;
use App\Platform\Tenancy\DatabaseRoles;
use App\Platform\Tenancy\Entity\Tenant;
use App\Platform\Tenancy\SchemaManifest;
use App\Tests\Support\PhpSourceScan;
use PHPUnit\Framework\Attributes\Test;

/**
 * `API.md` §7.2 — **B-1's second half**: which principal issues what, asserted
 * per connection, and the statement-count row of E-7's table.
 *
 * ADR-006 §2.1 step 1 says the limiter runs on `erp_app`. It cannot: `erp_app`
 * holds **nothing** on `auth_attempt`, `auth_ip_budget` or `auth_lockout`, so a
 * limiter pointed there fails on its first statement. §7.1 is the corrected
 * sequence and this file is what holds the correction.
 */
final class PreAuthPrincipalTest extends AuthTestCase
{
    private const PASSWORD = 'correct horse battery staple';

    private const EMAIL = 'alice@example.com';

    /**
     * §7.2's *fails if*: *"any statement on the token-issuing path before step 9
     * is issued on the `erp_app` connection."*
     *
     * Recorded per connection, because the suite's shared recorder is wired to
     * every connection at once and can say what ran but not where.
     *
     * *Mutation, run:* point `App\Modules\UserManagement\Infrastructure\PreAuthBudgets` at
     * `@doctrine.dbal.app_connection` in `config/services.yaml`. `erp_app`'s log
     * becomes non-empty **and** the statement raises `permission denied` — red
     * twice over, which is the property B-1's design bought.
     */
    #[Test]
    public function theTokenPathIssuesNothingOnTheApplicationConnection(): void
    {
        $tenantId = $this->seedTenant(self::SLUG_PREFIX.'live', Tenant::STATUS_ACTIVE);
        $this->seedUser($tenantId, self::EMAIL, self::PASSWORD);

        // The instrument, proven live before it is trusted: without this an
        // unwired recorder reports zero statements and the assertion below is
        // the comfortable green API-10a names.
        $this->recorderFor('app')->start();
        $this->app()->fetchOne('SELECT 1');
        $this->recorderFor('app')->stop();
        self::assertCount(1, $this->recorderFor('app')->statements(), 'The per-connection SQL recorder is not wired.');

        foreach (['app', 'auth', 'authlog', 'ratelimit'] as $connection) {
            $this->recorderFor($connection)->start();
        }

        $this->signIn(self::SLUG_PREFIX.'live', self::EMAIL, 'wrong');
        self::assertSame(401, $this->httpStatus());

        foreach (['app', 'auth', 'authlog', 'ratelimit'] as $connection) {
            $this->recorderFor($connection)->stop();
        }

        self::assertSame([], $this->recorderFor('app')->statements(),
            "A failed sign-in reached erp_app:\n".implode("\n", $this->recorderFor('app')->statements()));

        // And the positive half, so the assertion above is not passing because
        // nothing ran at all.
        self::assertNotSame([], $this->recorderFor('ratelimit')->statements(), 'The limiter issued nothing.');
        self::assertNotSame([], $this->recorderFor('auth')->statements(), 'The credential lookup issued nothing.');
        self::assertNotSame([], $this->recorderFor('authlog')->statements(), 'The trail writer issued nothing.');
    }

    /**
     * The same rule on the success branch, where §7.1 step 9 is the **only**
     * `erp_app` statement and it lands after the JWT is minted.
     *
     * *Mutation, run:* move the `LoginRecorder::record()` call in
     * `SignIn::attempt()` above `$this->tokens->issue(...)`. The statement count
     * is unchanged, so what this catches is the *set*: adding a second `erp_app`
     * read before the mint — the shape a "load the user entity first"
     * refactor takes — makes the count rise and this red.
     */
    #[Test]
    public function theOnlyApplicationConnectionStatementsOnASuccessAreStepNines(): void
    {
        $tenantId = $this->seedTenant(self::SLUG_PREFIX.'live', Tenant::STATUS_ACTIVE);
        $this->seedUser($tenantId, self::EMAIL, self::PASSWORD);

        $this->recorderFor('app')->start();
        $this->signIn(self::SLUG_PREFIX.'live', self::EMAIL, self::PASSWORD);
        $this->recorderFor('app')->stop();

        self::assertSame(200, $this->httpStatus());

        $statements = $this->recorderFor('app')->statements();
        self::assertNotSame([], $statements, 'Step 9 issued nothing, so this test has no subject.');

        // Transaction control is not a statement about a table: TenantContext
        // opens the transaction step 9 needs and the ORM's flush nests inside it
        // as a savepoint. Named rather than filtered by a catch-all, so a real
        // statement cannot hide behind the exclusion.
        $touching = array_values(array_filter(
            $statements,
            static fn (string $sql): bool => 1 !== preg_match('/^\s*(BEGIN|COMMIT|ROLLBACK|SAVEPOINT|RELEASE SAVEPOINT|ROLLBACK TO SAVEPOINT)\b/i', $sql),
        ));

        self::assertNotSame([], $touching, 'Step 9 issued no statement against a table.');

        foreach ($touching as $sql) {
            self::assertMatchesRegularExpression('/\bapp_user\b|set_config/i', $sql,
                'A statement on erp_app touches something other than step 9: '.$sql);
        }
    }

    /**
     * E-7's **statement count** row, which no response assertion can reach:
     * *"step 6 adds none; step 8 writes one row on both branches."*
     *
     * The comparison is between a wrong password and a verified credential
     * against a non-active tenant — E-12's pair, and the one E-7 exists for.
     *
     * *Mutation, run:* re-read `tenant` inside `SignIn::classify()` to check the
     * status instead of using the value `CredentialRow` carries. The
     * tenant-inactive branch gains one statement on `erp_auth` and this is red —
     * which is exactly *"an implementation that re-reads `tenant` to check status
     * adds a statement to one branch and breaks E-7 by the clock"*.
     */
    #[Test]
    public function theStatementSequenceIsIdenticalOnBothOfEminusTwelvesBranches(): void
    {
        $wrongPassword = $this->measure(function (): void {
            $slug = self::SLUG_PREFIX.'wrong';
            $tenantId = $this->seedTenant($slug, Tenant::STATUS_ACTIVE);
            $this->seedUser($tenantId, self::EMAIL, self::PASSWORD);
            $this->signIn($slug, self::EMAIL, 'not the password');
        });

        $tenantInactive = $this->measure(function (): void {
            $slug = self::SLUG_PREFIX.'suspended';
            $tenantId = $this->seedTenant($slug, Tenant::STATUS_SUSPENDED);
            $this->seedUser($tenantId, self::EMAIL, self::PASSWORD);
            $this->signIn($slug, self::EMAIL, self::PASSWORD);
        });

        self::assertNotSame([], array_filter($wrongPassword), 'Nothing was recorded, so the comparison below is vacuous.');
        self::assertSame($wrongPassword, $tenantInactive,
            "E-12's two branches issue different statements:\n".print_r(['wrong password' => $wrongPassword, 'tenant inactive' => $tenantInactive], true));
    }

    /**
     * The §7.4 residual, asserted **as a residual** rather than left to be
     * rediscovered: the unknown-email branch writes no trail row where the
     * wrong-password branch writes one, so the two differ by one `INSERT` on the
     * `authlog` connection and by nothing else.
     *
     * Pinning it means the day somebody closes it — or widens it — this file
     * says so. It is not an assertion that the difference is acceptable; ADR-006
     * §2.2 requires it (there is no principal to name on a miss) and `API.md`
     * §7.4 records the bound.
     *
     * *Mutation, run:* write `auth.login.failure` on the unknown-email path; the
     * `authlog` counts converge and this is red — as is
     * `TokenEndpointIndistinguishabilityTest::neitherMissPathReachesTheTrail`.
     */
    #[Test]
    public function theOnlyDifferenceBetweenAWrongPasswordAndAnUnknownEmailIsOneTrailInsert(): void
    {
        $wrongPassword = $this->measure(function (): void {
            $slug = self::SLUG_PREFIX.'wrong';
            $tenantId = $this->seedTenant($slug, Tenant::STATUS_ACTIVE);
            $this->seedUser($tenantId, self::EMAIL, self::PASSWORD);
            $this->signIn($slug, self::EMAIL, 'not the password');
        });

        $unknownEmail = $this->measure(function (): void {
            $slug = self::SLUG_PREFIX.'unknown';
            $tenantId = $this->seedTenant($slug, Tenant::STATUS_ACTIVE);
            $this->seedUser($tenantId, self::EMAIL, self::PASSWORD);
            $this->signIn($slug, 'nobody@example.com', self::PASSWORD);
        });

        self::assertSame($wrongPassword['auth'], $unknownEmail['auth'], 'ADR-006 V-8: the miss path must run the same lookup.');
        self::assertSame($wrongPassword['ratelimit'], $unknownEmail['ratelimit'], 'E-8: the counter increments on the miss path too.');
        self::assertSame(0, $unknownEmail['authlog']);
        self::assertGreaterThan(0, $wrongPassword['authlog'],
            'The wrong-password branch stopped writing to the trail, which would close §7.4 for the wrong reason.');
    }

    /**
     * **T-0a-3 (G4-C0a):** a soft-deleted person is the unknown-email miss path on every connection — the same **number of
     * statements** on `erp_auth` (ADR-006 V-8), on the counters (E-8), and no trail insert (ADR-006 §2.2). The predicate added
     * a clause, not a statement, and this is what says so. It compares per-connection statement counts, as its siblings in
     * this class do; the statement text is the same by construction (one `CredentialLookup` statement, no branch).
     *
     * *Mutation, run:* resolve a deleted user in PHP (a second `SELECT deleted_at` on `erp_auth`, or a trail row for it) →
     * the sequences diverge, red.
     */
    #[Test]
    public function aDeletedUserRunsTheUnknownEmailStatementSequence(): void
    {
        $unknownEmail = $this->measure(function (): void {
            $slug = self::SLUG_PREFIX.'unknown';
            $tenantId = $this->seedTenant($slug, Tenant::STATUS_ACTIVE);
            $this->seedUser($tenantId, self::EMAIL, self::PASSWORD);
            $this->signIn($slug, 'nobody@example.com', self::PASSWORD);
        });

        $deleted = $this->measure(function (): void {
            $slug = self::SLUG_PREFIX.'deleted';
            $tenantId = $this->seedTenant($slug, Tenant::STATUS_ACTIVE);
            $this->seedUserWithId($tenantId, '00000000-0000-4000-8000-000000000001', self::EMAIL, self::PASSWORD, true);
            $this->signIn($slug, self::EMAIL, self::PASSWORD);
        });

        self::assertNotSame([], array_filter($unknownEmail), 'Nothing was recorded, so the comparison below is vacuous.');
        self::assertSame($unknownEmail, $deleted,
            "A deleted person's sign-in does not run the unknown-email sequence:\n".print_r(['unknown email' => $unknownEmail, 'deleted' => $deleted], true));
    }

    /**
     * The console commands allowed to run with a non-application credential present, pinned (`preauth-derived-check-design.md`
     * §3.3). **Hygiene, not a control** — `ServingCredentialGuard`'s own words: anyone who can run the console with the owner
     * DSN can run `psql`. It notices one thing: a command added to that list cooperatively. Such a command runs as the schema
     * owner and so bypasses every grant check in this suite (`theOwnerProcessConsoleCommandsAreTheReviewedOnes`).
     */
    private const CONSOLE_OWNER_COMMANDS_REVIEWED = [
        'doctrine:migrations:migrate',
        'doctrine:migrations:diff',
        'doctrine:migrations:status',
        'doctrine:migrations:list',
        'doctrine:migrations:up-to-date',
        'doctrine:migrations:current',
        'doctrine:migrations:latest',
        'doctrine:migrations:execute',
    ];

    /**
     * A finding, pinned so it cannot be shipped past — **and, since G4-C0a (2026-10-22), in its first branch.**
     *
     * Until G4-C0a `erp_auth`'s column grant on `app_user` was `(id, tenant_id, email, password_hash, is_active)`, and
     * Postgres requires the privilege for a reference in `WHERE` as much as in the select list, so `CredentialLookup` could
     * not exclude a soft-deleted user: a soft-deleted principal's password would still have authenticated. G4-C0a
     * (`Version20261022006000`) granted `SELECT (deleted_at)` and added `AND deleted_at IS NULL`, so the server now answers
     * the premise `true` and the method takes branch 1.
     *
     * **Branch 1 (live since G4-C0a):** `CredentialLookup` must carry a `deleted_at IS NULL` SQL literal. That literal check
     * is **not** the evidence on its own — a literal such as `OR deleted_at IS NULL` satisfies the regex — it is backed by
     * the behaviour tests `TokenEndpointIndistinguishabilityTest::aDeletedUsersPasswordNoLongerSignsIn` and
     * `::aLiveUserWithADeletedNamesakeSignsInAsThemselves` (T-0a-1/T-0a-2).
     *
     * **The else branch now retires, it does not guard.** It is the server-derived sweep (roles C0, 2026-10-03,
     * `docs/session13/preauth-derived-check-design.md` FINAL block): while `erp_auth` cannot `SELECT app_user.deleted_at`, no
     * role but the schema owner, superusers and `pg_*` may `UPDATE` or `INSERT` that column, with a planted `erp_ratelimit`
     * grant as its positive control. After G4-C0a it no longer runs, **correctly**: its premise (a deleted user can sign in)
     * has ended, and T-0a-1/T-0a-2 hold what it held. It is kept, not deleted, because it is exactly what must run again if
     * G4-C0a is ever reverted — and from G4-C2 `DeleteUser` holds `UPDATE (deleted_at)`, so a revert of the grant without
     * the writer turns it red at once. Routes it never saw (owner-rights writes through views, rules, referential actions or
     * `SECURITY DEFINER`) stay with `SchemaGuardTest`; superusers are outside every check by design.
     *
     * The console-commands pin that used to end this method lives in `theOwnerProcessConsoleCommandsAreTheReviewedOnes`, so
     * it runs under every grant state (M-2).
     */
    #[Test]
    public function noShippedOperationCanSoftDeleteAnAppUserWhileTheAuthPrincipalCannotReadDeletedAt(): void
    {
        $owner = $this->owner();

        self::assertNotSame([], SchemaManifest::TABLES['app_user']['column_grants'][DatabaseRoles::AUTH]['SELECT'] ?? [],
            'erp_auth holds no column grant on app_user, so the pre-auth lookup could not run at all.');

        // The column exists: `has_column_privilege` errors on a missing column, and a dropped `deleted_at` would mean
        // app_user stopped soft-deleting, which is a different finding.
        self::assertSame(1, (int) $owner->fetchOne(
            "SELECT count(*) FROM pg_attribute WHERE attrelid = 'app_user'::regclass AND attname = 'deleted_at' AND NOT attisdropped"),
            'app_user.deleted_at is gone — reword this test rather than deleting it.');
        self::assertSame(SchemaManifest::SOFT, SchemaManifest::TABLES['app_user']['deletion'],
            'app_user stopped being soft-deleted, which would close this finding — reword the test rather than deleting it.');

        // The premise, from the server (the manifest's agreement is GrantFootprintTest's).
        $authCanRead = (bool) $owner->fetchOne(
            "SELECT has_column_privilege(?, 'app_user', 'deleted_at', 'SELECT')", [DatabaseRoles::AUTH]);
        if ($authCanRead) {
            self::assertTrue(self::credentialLookupExcludesDeletedUsers(),
                'erp_auth can now read deleted_at, so §7.1 step 4 must exclude a soft-deleted user: CredentialLookup carries '
                .'no SQL literal with "deleted_at IS NULL". The grant landed before the predicate did.');

            return;
        }

        // The table's owner, derived and then checked against the owner connection, so `ALTER TABLE … OWNER TO` cannot
        // drop a role out of the domain unseen.
        $tableOwner = (string) $owner->fetchOne("SELECT pg_get_userbyid(relowner) FROM pg_class WHERE oid = 'app_user'::regclass");
        self::assertSame((string) $owner->fetchOne('SELECT current_user'), $tableOwner,
            'app_user is owned by a role other than the owner connection, so the domain below would exclude it unseen.');

        $domain = $owner->fetchFirstColumn(<<<'SQL'
            SELECT rolname FROM pg_roles
            WHERE NOT rolsuper AND rolname NOT LIKE 'pg\_%' AND rolname <> ?
            ORDER BY 1
            SQL, [$tableOwner]);
        self::assertSame([], array_values(array_diff(DatabaseRoles::APPLICATION_ROLES, $domain)),
            'The server-derived domain lost an application role, so the assertion below would be vacuous for it.');

        $holders = static fn (): array => $owner->fetchFirstColumn(<<<'SQL'
            SELECT r.rolname || ' ' || p.privilege
            FROM pg_roles r CROSS JOIN (VALUES ('UPDATE'), ('INSERT')) AS p (privilege)
            WHERE NOT r.rolsuper AND r.rolname NOT LIKE 'pg\_%' AND r.rolname <> ?
              AND has_column_privilege(r.oid, 'app_user'::regclass, 'deleted_at', p.privilege)
            ORDER BY 1
            SQL, [$tableOwner]);

        $found = $holders();
        self::assertSame([], $found,
            "A role can write app_user.deleted_at while erp_auth cannot read it, so a soft-deleted user's password would still "
            .'sign in. SEC + DATA: grant erp_auth SELECT (deleted_at) and add deleted_at IS NULL to CredentialLookup before any '
            .'role may write deleted_at. Holders: '.implode(', ', $found));

        // True-control: the function answers true for a real column grant on this server.
        self::assertTrue((bool) $owner->fetchOne(
            "SELECT has_column_privilege(?, 'app_user', 'password_hash', 'UPDATE')", [DatabaseRoles::APP]),
            'has_column_privilege reports no UPDATE (password_hash) for erp_app, which it holds — the check reads nothing.');

        // Positive control, planted on the real server and rolled back (GrantFootprintTest's TEMPORARY precedent).
        $owner->beginTransaction();
        try {
            $owner->executeStatement('GRANT UPDATE (deleted_at) ON app_user TO '.$owner->quoteSingleIdentifier(DatabaseRoles::RATE_LIMIT));
            self::assertContains(DatabaseRoles::RATE_LIMIT.' UPDATE', $holders(),
                'A planted GRANT UPDATE (deleted_at) to erp_ratelimit was not seen, so the empty result above proves nothing.');
        } finally {
            $owner->rollBack();
        }
        self::assertSame([], $holders(), 'The planted grant survived the rollback.');
    }

    /**
     * The owner-process console commands, pinned in a test of their own so the pin runs **whatever the grant state**
     * (G4-C0a, `g4-users-design.md` R1.2 M-2 / D-14; message reworded per `g4-users-final-red.md` L-4).
     *
     * Until G4-C0a this assertion sat at the end of
     * `noShippedOperationCanSoftDeleteAnAppUserWhileTheAuthPrincipalCannotReadDeletedAt`, after that method's branch-1
     * `return` — so the moment `erp_auth` could read `deleted_at` it would have silently stopped running. The assertion is
     * unchanged byte for byte; only its home and its message moved. **Its job now is owner-process hygiene:** a command on
     * `ServingCredentialGuard::CONSOLE_OWNER_COMMANDS` runs application code on the schema-owner connection, outside every
     * grant check this suite makes. G8's owner-set-password command (`remaining-ops-final-red.md` L-6) relies on this pin to
     * be noticed when it is added. Hygiene, not a control: anyone who can run the console with the owner DSN can run `psql`.
     *
     * *Mutation M-0a-4, run:* add a command to `ServingCredentialGuard::CONSOLE_OWNER_COMMANDS` → red.
     */
    #[Test]
    public function theOwnerProcessConsoleCommandsAreTheReviewedOnes(): void
    {
        self::assertSame(self::CONSOLE_OWNER_COMMANDS_REVIEWED, ServingCredentialGuard::CONSOLE_OWNER_COMMANDS,
            'An owner-process command was added to ServingCredentialGuard::CONSOLE_OWNER_COMMANDS. It runs application code as '
            .'the schema owner, outside every grant check in this suite (it can write any column of any table, app_user '
            .'included). Before it ships: SEC reviews what it writes and which audit row records it (G8: an owner-set password '
            .'needs its own auth.-family event, remaining-ops-final-red.md L-6), and the MAC key stays out of the serving '
            .'env. Then add it here.');
    }

    /** The premise branch, matched on `CredentialLookup`'s SQL literals so a docblock or comment cannot satisfy it. */
    private static function credentialLookupExcludesDeletedUsers(): bool
    {
        $scan = PhpSourceScan::scan((string) file_get_contents(
            \dirname(__DIR__, 2).'/src/Modules/UserManagement/Infrastructure/CredentialLookup.php'));
        $literals = array_merge(
            ...array_map(static fn (array $method): array => $method['literals'], array_values($scan['methods'])),
            ...array_map(static fn (array $constant): array => $constant['literals'], array_values($scan['constants'])),
        );
        $literals = array_merge($literals, $scan['outside']);
        foreach ($literals as $literal) {
            if (1 === preg_match('/\bdeleted_at\s+IS\s+NULL\b/i', $literal['value'])) {
                return true;
            }
        }

        return false;
    }

    /**
     * **Mail-C2 T-R3 — the redeem path issues nothing on `erp_app` until the link verifies** (design §2.5 row E). A real
     * link's selector with a wrong verifier is the closest refusal to success: it reaches the lookup, computes both seal
     * checks, and must still stop before the application connection. Positive halves: the budget and the lookup ran on the
     * refusal, and the success DID reach `erp_app` (7a/7b/7c), so the zero is not an unwired recorder. Mutation MC-9 (consume
     * at any selector hit) and MC-23 (the lookup on the `app` connection) → red.
     */
    #[Test]
    public function theRedeemPathIssuesNothingOnTheApplicationConnectionUntilTheTokenVerifies(): void
    {
        $tenantId = $this->seedTenant(self::SLUG_PREFIX.'redeem', Tenant::STATUS_ACTIVE);
        $userId = $this->seedUser($tenantId, self::EMAIL, self::PASSWORD);
        $token = $this->mintResetLink($tenantId, $userId);
        $wrong = substr($token, 0, 60).('A' === $token[60] ? 'B' : 'A').substr($token, 61);

        $refusal = $this->measure(function () use ($wrong): void {
            $this->send('POST', '/auth/password-resets/redemption', ['token' => $wrong, 'password' => 'a brand new passphrase']);
            self::assertSame(401, $this->httpStatus());
        });
        self::assertSame(0, $refusal['app'], 'A refused redeem reached erp_app.');
        self::assertGreaterThan(0, $refusal['auth'], 'The token lookup issued nothing.');
        self::assertGreaterThan(0, $refusal['ratelimit'], 'The budget issued nothing.');

        $success = $this->measure(function () use ($token): void {
            $this->send('POST', '/auth/password-resets/redemption', ['token' => $token, 'password' => 'a brand new passphrase']);
            self::assertSame(200, $this->httpStatus());
        });
        self::assertGreaterThan(0, $success['app'], 'Control: the success never reached erp_app, so the zero above proves nothing.');
    }

    /**
     * @param callable(): void $attempt
     *
     * @return array{app: int, auth: int, authlog: int, ratelimit: int} statements per connection
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
}
