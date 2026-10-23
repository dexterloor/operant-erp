<?php

declare(strict_types=1);

namespace App\Tests\Runtime;

use App\Platform\Runtime\ServingCredentialGuard;
use App\Platform\Tenancy\DatabaseRoles;
use Doctrine\Bundle\DoctrineBundle\ConnectionFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The rules of the front-controller guard, one row per rule and per shape (V4 batch B2b-2; draft rev 3 §4.1).
 *
 * Pure: no process, no container, no database. The subprocess test proves the wiring; this test is what turns red
 * when ONE rule is weakened, which the subprocess cases cannot see (RED `red-b2b2-guard-attack.md` BLK-5).
 *
 * Every row names the mutation of `ServingCredentialGuard` that turns it red. Each was applied and shown red at build
 * (`docs/session10/progress-B2.md`). Where the draft's value could not go red under its named mutation — because
 * another rule masks it — the row carries a second value that can, and says so; the original value is kept.
 *
 * Values are fixtures. `neondb_owner` stands for any non-application login; the real owner name is derived, never
 * typed, in the subprocess test.
 */
final class ServingCredentialGuardTest extends TestCase
{
    private const OWNER = 'neondb_owner';

    /**
     * @return iterable<string, array{0: string, 1: bool}>
     */
    public static function values(): iterable
    {
        // U1 — mutation: parse with `parse_url` instead of `DsnParser` (DBAL merges ?user= over the userinfo).
        yield 'U1 query user overrides the userinfo' => ['postgresql://erp_app:x@h:1/erp?user='.self::OWNER.'&password=y', true];
        // U2 — mutation: take the driver from the URL scheme instead of DBAL's effective `driver`.
        //   The draft's `mysql://` value stays refused under that mutation (pdo_mysql is itself a DBAL driver, so the value is
        //   still examined); the second value, with a non-DBAL scheme, is the one the mutation turns red.
        yield 'U2 query driver on a mysql scheme' => ['mysql://erp_app@h/erp?driver=pdo_pgsql&user='.self::OWNER, true];
        yield 'U2 query driver on a non-DBAL scheme' => ['redis://erp_app@h/erp?driver=pdo_pgsql&user='.self::OWNER, true];
        // U3 — mutation: require a scheme.
        yield 'U3 schemeless with a driver query' => ['//h:1/erp?driver=pdo_pgsql&user='.self::OWNER, true];
        // U4 — mutation: drop rule 1(c).
        yield 'U4 libpq keyword string' => ['host=h user='.self::OWNER.' password=x', true];
        // U5 — mutation: drop rule 1(b). `pgsql:host=h;user=<owner>` is also matched by 1(c)'s keyword regex, so it stays
        //   refused; the no-user PDO string is refused by 1(b) alone and is the one that goes red.
        yield 'U5 PDO string naming a non-application user' => ['pgsql:host=h;user='.self::OWNER, true];
        yield 'U5 PDO string naming no user' => ['pgsql:host=h;dbname=erp', true];
        // U6 — mutation: compare the encoded user. An encoded owner stays refused either way; an encoded APPLICATION role
        //   is the value that distinguishes decoded from encoded comparison.
        yield 'U6 URL-encoded owner' => ['postgresql://neondb%5Fowner:x@h/erp', true];
        yield 'U6 URL-encoded application role' => ['postgresql://erp%5Fapp:x@h/erp', false];
        // U7 — mutation: compare the effective driver case-sensitively without mapping.
        yield 'U7 upper-case scheme and user' => ['POSTGRESQL://NEONDB_OWNER@h/erp', true];
        // U8 — mutation: accept an absent user.
        yield 'U8 no user' => ['postgresql://h/erp', true];
        // U9 — mutation: treat `serverVersion` as forbidden.
        yield 'U9 application DSN with serverVersion and charset' => ['postgres://erp_app:x@h/erp?serverVersion=17&charset=utf8', false];
        // U11 — mutation: add `driverClass` to DSN_KEYS.
        yield 'U11 driverClass key' => ['pdo-pgsql://erp_app@h/erp?driverClass=X', true];
        // U22 — mutation: treat `scheme:` as URL-shaped.
        yield 'U22 image reference' => ['postgres:17-alpine', false];
        // U28 — mutation: treat every `//`-prefixed value as URL-shaped.
        yield 'U28 base64 secret starting with //' => ['//AbC+dEf/gh==', false];
        // U29 — mutation: check only the first `user=`, or skip unquoting. The quoted application role is the value
        //   skip-unquoting turns red; the two duplicates are the values first-only turns red.
        yield 'U29 libpq duplicate user' => ['host=h user=erp_app user='.self::OWNER, true];
        yield 'U29 PDO duplicate quoted user' => ["pgsql:host=h;user=erp_app;user='".self::OWNER."'", true];
        yield 'U29 libpq quoted application user' => ["host=h user='erp_app'", false];
        // U30 — mutation: reintroduce the raw-query key blocklist in place of the effective-key allowlist.
        yield 'U30 double-encoded wrapperClass with a primary owner' => ['postgresql://erp_app:x@h:1/erp?%2577rapperClass=Doctrine%255CDBAL%255CConnections%255CPrimaryReadReplicaConnection&primary%255Buser%255D='.self::OWNER.'&primary%255Bhost%255D=h&replica%255B0%255D%255Buser%255D=erp_app', true];
        // U31 — mutation: allow `wrapperClass` when the top-level user is an application role.
        yield 'U31 single-encoded wrapperClass with a primary owner' => ['postgresql://erp_app:x@h/erp?wrapperClass=Doctrine%5CDBAL%5CConnections%5CPrimaryReadReplicaConnection&primary[user]='.self::OWNER.'&primary[host]=h&replica[0][user]=erp_app', true];
        // U32 — mutation: allow any key that is not a credential.
        yield 'U32 driverOptions' => ['postgresql://erp_app:x@h/erp?driverOptions[12]=1', true];
        yield 'U32 persistent' => ['postgresql://erp_app:x@h/erp?persistent=1', true];
        // U33 — mutation: drop the value constraint on `charset`.
        yield 'U33 charset carrying SQL' => ["postgresql://erp_app:x@h/erp?charset=utf8';SELECT 1", true];
    }

    #[Test]
    #[DataProvider('values')]
    public function aValueIsRefusedExactlyWhenItIsANonApplicationCredentialInARecognisedForm(string $value, bool $refused): void
    {
        self::assertSame(
            $refused ? ['LEGACY_REPORTING_DSN'] : [],
            ServingCredentialGuard::offendingNames(['LEGACY_REPORTING_DSN' => $value], [], []),
        );
    }

    /**
     * U10 — mutation: name-based rule 1 (refuse `DATABASE_URL` by name). An application DSN is allowed under the owner's
     * old variable name and under a non-standard one.
     */
    #[Test]
    public function u10AnApplicationDsnIsAllowedWhateverItsName(): void
    {
        $dsn = 'postgresql://erp_app:x@127.0.0.1:1/erp?serverVersion=17&charset=utf8';

        self::assertSame([], ServingCredentialGuard::offendingNames(['DATABASE_URL' => $dsn, 'LEGACY_REPORTING_DSN' => $dsn], [], []));
    }

    /**
     * @return iterable<string, array{0: string}>
     */
    public static function credentialNames(): iterable
    {
        // U12–U20 — mutation: drop each token or name in turn. `NEON_PASSWD` and `NEON_API_KEY` each match two rules, so
        // `DB_PASSWD` (PASSWD only) and `NEON_ORG_ID` (NEON_* only) are the rows dropping those rules turns red.
        foreach (['DB_OWNER_PASSWORD', 'DB_PASS', 'OWNER_PWD', 'NEON_PASSWD', 'DB_PASSWD', 'DB_OWNER_PASSPHRASE', 'NEON_API_KEY',
            'NEON_ORG_ID', 'STRIPE_API_KEY', 'PGPASSWORD', 'PGUSER', 'PGSERVICE', 'PGSERVICEFILE', 'PGPASSFILE', 'PGSYSCONFDIR',
            'PGSSLCERT', 'PGSSLKEY'] as $name) {
            yield 'U12-U20 '.$name => [$name];
        }
    }

    #[Test]
    #[DataProvider('credentialNames')]
    public function aCredentialShapedNameIsRefusedWhateverItsValue(string $name): void
    {
        self::assertSame([$name], ServingCredentialGuard::offendingNames([$name => '1'], [], []));
    }

    /**
     * U21 — mutation: remove the `PWD`/`OLDPWD` exclusion (red on `PWD`), or empty ALLOWED_NAMES. The second is vacuous
     * today: none of the six reviewed application secrets matches a credential rule, so ALLOWED_NAMES excludes nothing.
     */
    #[Test]
    public function u21ShellAndApplicationNamesAreAllowed(): void
    {
        $names = ['PWD', 'OLDPWD', 'GIT_ASKPASS', 'SSH_ASKPASS', 'APP_SECRET', 'AUTH_TOKEN_SIGNING_KEY', 'AUTH_EMAIL_HASH_KEY', 'AUTH_CREDENTIAL_MAC_KEY', 'DEFAULT_URI'];

        self::assertSame([], ServingCredentialGuard::offendingNames(array_fill_keys($names, '/tmp'), [], []));
    }

    /** U23 — mutation: keyed union of the three sources. */
    #[Test]
    public function u23SourcesAreCheckedSeparatelyAndTheNameIsReportedOnce(): void
    {
        self::assertSame(['APP_DATABASE_URL'], ServingCredentialGuard::offendingNames(
            ['APP_DATABASE_URL' => 'postgresql://'.self::OWNER.':x@h/erp'],
            ['APP_DATABASE_URL' => 'postgresql://erp_app:x@h/erp'],
            ['APP_DATABASE_URL' => 'postgresql://'.self::OWNER.':x@h/erp'],
        ));
    }

    /** U24 — mutation: drop the `$_SERVER` filter. The same names are still read from `$_ENV` and `getenv()`. */
    #[Test]
    public function u24RequestDerivedServerKeysAreNotTheEnvironment(): void
    {
        $request = ['HTTP_X_DB_PASSWORD' => '1', 'QUERY_STRING' => 'postgresql://x@h', 'REDIRECT_DB_PASSWORD' => '1', 'PHP_AUTH_PW' => 'x'];

        self::assertSame([], ServingCredentialGuard::offendingNames([], $request, []));
        self::assertSame(['HTTP_X_DB_PASSWORD'], ServingCredentialGuard::offendingNames(['HTTP_X_DB_PASSWORD' => '1'], [], []));
        self::assertSame(['QUERY_STRING'], ServingCredentialGuard::offendingNames([], [], ['QUERY_STRING' => 'postgresql://x@h']));
    }

    /** U25 — mutation: drop rule 5. */
    #[Test]
    public function u25TheWorkerRuntimeIsRefused(): void
    {
        self::assertSame(['FRANKENPHP_WORKER ('.ServingCredentialGuard::WORKER_MODE_UNDESIGNED.')'], ServingCredentialGuard::offendingNames([], ['FRANKENPHP_WORKER' => '1'], []));
    }

    /** U26 — mutation: edit the copy. */
    /**
     * **T-W1 (Mail-C1): the web subset.** An `erp_worker` DSN is refused under `DatabaseRoles::SERVING_WEB_ROLES` (what
     * `public/index.php` passes) and accepted under `APPLICATION_ROLES` (what `bin/console` uses — the worker runs through
     * it). The subset is exactly the application roles minus the worker, written out. A `redis://` queue DSN, which the web
     * process now holds, is not DBAL-shaped and is accepted under both. Mutation: drop the `$roles` threading (L-10) →
     * the worker DSN is accepted under the subset → red.
     */
    #[Test]
    public function u31TheWebSubsetRefusesTheWorkerLoginAndTheConsoleAcceptsIt(): void
    {
        $worker = ['WORKER_DATABASE_URL' => 'postgresql://erp_worker:x@127.0.0.1:1/erp?serverVersion=17&charset=utf8'];
        self::assertSame(['WORKER_DATABASE_URL'], ServingCredentialGuard::offendingNames($worker, [], [], DatabaseRoles::SERVING_WEB_ROLES));
        self::assertSame([], ServingCredentialGuard::offendingNames($worker, [], [], DatabaseRoles::APPLICATION_ROLES));
        self::assertSame([], ServingCredentialGuard::offendingNames($worker, [], []), 'The default set is not every application role.');
        $keyword = ['WORKER_PDO' => 'pgsql:host=127.0.0.1;dbname=erp;user=erp_worker'];
        self::assertSame(['WORKER_PDO'], ServingCredentialGuard::offendingNames($keyword, [], [], DatabaseRoles::SERVING_WEB_ROLES), 'The subset does not reach the PDO/keyword rule.');
        self::assertSame([DatabaseRoles::WORKER], array_values(array_diff(DatabaseRoles::APPLICATION_ROLES, DatabaseRoles::SERVING_WEB_ROLES)));
        self::assertSame([], array_diff(DatabaseRoles::SERVING_WEB_ROLES, DatabaseRoles::APPLICATION_ROLES));
        $queue = ['MESSENGER_TRANSPORT_DSN' => 'redis://:x@127.0.0.1:56379'];
        self::assertSame([], ServingCredentialGuard::offendingNames($queue, [], [], DatabaseRoles::SERVING_WEB_ROLES), 'The queue DSN the web process holds was refused.');
    }

    #[Test]
    public function u26TheSchemeMapIsDoctrineBundlesOwn(): void
    {
        self::assertSame(
            (new \ReflectionClass(ConnectionFactory::class))->getConstant('DEFAULT_SCHEME_MAP'),
            ServingCredentialGuard::SCHEME_MAP,
        );
    }

    /** U27 — mutation: cast arrays to string. */
    #[Test]
    public function u27NonStringValuesAreIgnored(): void
    {
        self::assertSame([], ServingCredentialGuard::offendingNames(
            ['LEGACY_LIST' => ['postgresql://'.self::OWNER.'@h/erp'], 'LEGACY_NUMBER' => 5],
            ['argv' => ['bin/console'], 'argc' => 1, 'REQUEST_TIME' => 1],
            [],
        ));
    }
}
