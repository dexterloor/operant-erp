<?php

declare(strict_types=1);

namespace App\Tests\Auth;

use App\Platform\Tenancy\DatabaseRoles;
use App\Platform\Tenancy\Entity\Tenant;
use App\Platform\Tenancy\SchemaManifest;
use App\Tests\Api\TransactionBoundaryTest;
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
     * A finding, pinned so it cannot be shipped past.
     *
     * `erp_auth`'s column grant on `app_user` is `(id, tenant_id, email,
     * password_hash, is_active)` — **`deleted_at` is not in it**, and Postgres
     * requires the privilege for a reference in `WHERE` as much as in the select
     * list. So `CredentialLookup` cannot exclude a soft-deleted user, and
     * `app_user` is deletion class `soft`: a soft-deleted principal's password
     * would still authenticate.
     *
     * **What keeps it unreachable this wave is that no shipped operation can
     * create the state** — §4.2's `/users` rows are not implemented, so nothing
     * calls `AppUser::delete()`. That is a fact about the registry, and the
     * registry is where this reads it from. The day `DeleteUser` lands, this goes
     * red and the grant decision — `SEC` + `DATA`'s, not `BE-LEAD`'s — has to be
     * taken before it can go green again.
     *
     * *Mutation, run:* add a `Delete` operation at `/users/{id}` to any resource;
     * red. *Second mutation, run:* add `deleted_at` to the manifest's `erp_auth`
     * column grant; the premise no longer holds, the guard stops applying, and
     * it is red until the predicate is added — which is the order the two changes
     * have to happen in.
     */
    /** `/users` operations that write no `app_user` row, each by name at its exact path (see the method below). */
    private const USER_PATHS_THAT_CANNOT_SOFT_DELETE_A_USER = [
        'RevokeRole' => '/users/{id}/roles/{roleId}',
    ];

    #[Test]
    public function noShippedOperationCanSoftDeleteAnAppUserWhileTheAuthPrincipalCannotReadDeletedAt(): void
    {
        $readable = SchemaManifest::TABLES['app_user']['column_grants'][DatabaseRoles::AUTH]['SELECT'] ?? [];
        self::assertNotSame([], $readable, 'erp_auth holds no column grant on app_user, so the pre-auth lookup could not run at all.');

        if (\in_array('deleted_at', $readable, true)) {
            self::assertStringContainsString(
                'deleted_at IS NULL',
                (string) file_get_contents(\dirname(__DIR__, 2).'/src/Modules/UserManagement/Infrastructure/CredentialLookup.php'),
                'erp_auth can now read deleted_at, so §7.1 step 4 must exclude a soft-deleted user. '
                .'The grant landed before the predicate did.',
            );

            return;
        }

        self::assertSame(SchemaManifest::SOFT, SchemaManifest::TABLES['app_user']['deletion'],
            'app_user stopped being soft-deleted, which would close this finding — reword the test rather than deleting it.');

        $paths = array_map(
            static fn (\ApiPlatform\Metadata\HttpOperation $operation): string => (string) $operation->getUriTemplate(),
            TransactionBoundaryTest::contractOperations(),
        );
        self::assertNotSame([], $paths, 'The operation registry is empty, so this guard has no domain.');

        $userOperations = array_filter($paths, static fn (string $path): bool => 1 === preg_match('#/users(/|$)#', $path));

        // Roles slice 2, C2 (2026-09-29): `RevokeRole` lives under `/users/{id}/roles/{roleId}` and cannot create the state
        // this guard is about — it deletes one `user_role` row and never writes `app_user` (its writer is
        // `RoleRevocation`, whose only write statement is `DELETE FROM user_role`; `PurchaseOrderRowLockTest`'s role scan
        // derives every writer of `app_user.is_active`/`deleted_at` from `src/` and would name it). Exempted BY NAME and
        // AT ITS PATH, so any other `/users` operation — `DeleteUser`, `UpdateUser`, `RestoreUser` — is still red here.
        // Implementer's reading, flagged to `SEC` (the guard's owner) for acknowledgement.
        foreach (self::USER_PATHS_THAT_CANNOT_SOFT_DELETE_A_USER as $name => $path) {
            self::assertSame($path, $userOperations[$name] ?? null, $name.' is exempted here but is not shipped at '.$path.'; the exemption is stale.');
            unset($userOperations[$name]);
        }

        self::assertSame([], $userOperations,
            'An operation addressing /users is now shipped, so app_user rows can be soft-deleted — and the '
            ."pre-authentication lookup cannot see deleted_at, so a deleted user's password still signs in. "
            .'Widen erp_auth\'s column grant and add the predicate before shipping it: '.implode(', ', $userOperations));
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
