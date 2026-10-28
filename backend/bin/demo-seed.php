<?php

declare(strict_types=1);

/**
 * `make demo-seed` — a LOCAL demo workspace with its eight built-in roles and one owner who can sign in.
 *
 * ---------------------------------------------------------------------------
 * What it is, and why it is not in `src/`
 * ---------------------------------------------------------------------------
 * An owner process, like `bin/assert-clean-database.php`: it reads the schema owner's DSN from the root `.env.test` by
 * path (PHP Dotenv, never shell-sourced), opens its own connection and never boots a kernel. Nothing in `src/` can create
 * a workspace, a built-in role or a user with a usable password, and nothing there may bind the owner connection
 * (`ConnectionBindingTest`), so this file writes, as the owner, exactly what the application itself is not allowed to
 * write: `tenant`, `tenant_settings`, the eight built-in `role` rows (`is_system`, `default_key`), their `role_permission`
 * rows (`DefaultRoles::codesFor()`, the one source), ONE `app_user` (the owner), its ONE `user_role`, and the two
 * `audit_log` rows that account for them (`user.created`, `role.assigned`; actor NULL, label "Demo seed (owner process)").
 * `DemoSeedWriteSetTest` pins that set. Everyone else is added through the application, by the owner, under its own rules.
 *
 * ---------------------------------------------------------------------------
 * Where it refuses to run (exit 2, one line, before any write; nothing secret printed)
 * ---------------------------------------------------------------------------
 *  - `APP_ENV` is not `dev` or `test`;
 *  - the owner DSN's effective host (DBAL's `DsnParser`, as `ServingCredentialGuard` parses it) is not loopback;
 *  - the owner bypasses row-level security (a hosted owner does; the local one is NOBYPASSRLS, so every seeded row passes
 *    FORCE RLS's `WITH CHECK`);
 *  - the database's comment is not `erp-local-demo` (written by `infra/postgres/init/10-roles.sh` from `DB_PURPOSE`). The
 *    one exception: `erp-local-gate` under `APP_ENV=test` with a `demo-test-` workspace — the suite's own process test.
 *    **Accident prevention, not a security control:** the owner owns the database and could rewrite the comment, and a
 *    database deliberately prepared with `DB_PURPOSE=demo` anywhere else passes.
 * Exit 3: the workspace already exists (nothing written). Exit 1: the write failed and was rolled back.
 *
 * The password comes from `--password-stdin`, else `DEMO_PASSWORD` in the gitignored root `.env.demo` — read with
 * `Dotenv::parse()` into a variable, never into the environment — and is never printed (Standing Rule 10). It must pass
 * `PasswordRules`. It is hashed at the KDF cost the kernel of the same `APP_ENV` uses (`app.auth.dummy_password_hash`,
 * read from `config/services.yaml`, its `when@<APP_ENV>` value when present) and sealed with the same key.
 *
 * The classes are declared here, in their own namespace, and `main()` runs only when this file is executed directly, so a
 * test can load it for the pure refusal functions.
 */

namespace ErpDemoSeed;

use App\Modules\AuditLog\Infrastructure\UserAuditWriter;
use App\Modules\UserManagement\Application\PasswordRules;
use App\Modules\UserManagement\Domain\DefaultRoles;
use App\Modules\UserManagement\Domain\EmailAddress;
use App\Modules\UserManagement\Infrastructure\CredentialSeal;
use App\Modules\UserManagement\Infrastructure\PasswordVerifier;
use App\Platform\Runtime\ServingCredentialGuard;
use App\SharedKernel\ValueObject\AuditEvent;
use App\SharedKernel\ValueObject\RoleEventMetadata;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception as DbalException;
use Doctrine\DBAL\Exception\DriverException;
use Doctrine\DBAL\Tools\DsnParser;
use Symfony\Component\Dotenv\Dotenv;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Yaml\Yaml;

require_once \dirname(__DIR__).'/vendor/autoload.php';

/** The refusals, as pure functions (unit-tested one by one in `DemoSeedLocalityTest`). */
final class Locality
{
    public const DEMO_PURPOSE = 'erp-local-demo';

    public const GATE_PURPOSE = 'erp-local-gate';

    public const TEST_SLUG_PREFIX = 'demo-test-';

    public const LOOPBACK_HOSTS = ['127.0.0.1', 'localhost', '::1'];

    public const REFUSED_ENVIRONMENT = 'demo-seed: refused: APP_ENV must be dev or test.';

    public const REFUSED_HOST = 'demo-seed: refused: the database is not on this machine (the owner DSN\'s host is not 127.0.0.1, localhost or ::1).';

    public const REFUSED_BYPASS = 'demo-seed: refused: the database owner bypasses row-level security, so this is not the local compose database.';

    public const REFUSED_PURPOSE = 'demo-seed: refused: this database is not prepared as the demo database (its comment is not erp-local-demo). Set DB_PURPOSE=demo in .env.infra and make reset it.';

    private function __construct()
    {
    }

    /** Checked before any connection is opened. */
    public static function beforeConnecting(string $appEnv, string $host): ?string
    {
        if (!\in_array($appEnv, ['dev', 'test'], true)) {
            return self::REFUSED_ENVIRONMENT;
        }
        if (!\in_array(strtolower(trim($host, '[]')), self::LOOPBACK_HOSTS, true)) {
            return self::REFUSED_HOST;
        }

        return null;
    }

    /** Checked on the server, before any write. */
    public static function afterConnecting(string $appEnv, bool $ownerBypassesRls, ?string $databaseComment, string $slug): ?string
    {
        if ($ownerBypassesRls) {
            return self::REFUSED_BYPASS;
        }
        if (self::DEMO_PURPOSE === $databaseComment) {
            return null;
        }
        // The suite's own process test: the gate database, under APP_ENV=test, a `demo-test-` workspace only.
        if (self::GATE_PURPOSE === $databaseComment && 'test' === $appEnv && str_starts_with($slug, self::TEST_SLUG_PREFIX)) {
            return null;
        }

        return self::REFUSED_PURPOSE;
    }

    /** Every refusal in order; null when all pass. */
    public static function refusal(string $appEnv, string $host, bool $ownerBypassesRls, ?string $databaseComment, string $slug): ?string
    {
        return self::beforeConnecting($appEnv, $host) ?? self::afterConnecting($appEnv, $ownerBypassesRls, $databaseComment, $slug);
    }
}

/** The run. */
final class Seeder
{
    public const EXIT_OK = 0;

    public const EXIT_FAILED = 1;

    public const EXIT_REFUSED = 2;

    public const EXIT_EXISTS = 3;

    public const ACTOR_LABEL = 'Demo seed (owner process)';

    public const WORKSPACE_NAME = 'Demo Trading Co.';

    public const DEFAULT_CURRENCY = 'EUR';

    public const OWNER_EMAIL = 'owner@example.com';

    public const OWNER_NAME = 'Olivia Owner';

    /** Display names of the built-in roles (UX may rename; nothing keys on a name — the key is `default_key`). */
    public const ROLE_NAMES = [
        DefaultRoles::OWNER => 'Owner',
        DefaultRoles::ADMIN => 'Admin',
        DefaultRoles::BUYER => 'Buyer',
        DefaultRoles::PURCHASING_MANAGER => 'Purchasing manager',
        DefaultRoles::WAREHOUSE_OPERATOR => 'Warehouse operator',
        DefaultRoles::SALES_REP => 'Sales rep',
        DefaultRoles::FINANCE => 'Finance',
        DefaultRoles::VIEWER => 'Viewer',
    ];

    private const SLUG_SHAPE = '/^[a-z0-9][a-z0-9-]{1,61}[a-z0-9]$/';

    private function __construct()
    {
    }

    /** @param list<string> $argv */
    public static function main(array $argv): int
    {
        // A trace must never carry the password or a hash as an argument (Standing Rule 10).
        ini_set('zend.exception_ignore_args', '1');
        $root = \dirname(__DIR__, 2);

        $slug = 'demo';
        $passwordFromStdin = false;
        foreach (\array_slice($argv, 1) as $argument) {
            if (str_starts_with($argument, '--workspace=')) {
                $slug = substr($argument, \strlen('--workspace='));
            } elseif ('--password-stdin' === $argument) {
                $passwordFromStdin = true;
            } else {
                return self::refuse('demo-seed: refused: unknown argument (use --workspace=<slug> and optionally --password-stdin).');
            }
        }
        if (1 !== preg_match(self::SLUG_SHAPE, $slug)) {
            return self::refuse('demo-seed: refused: the workspace name must be 3-63 lower-case letters, digits or hyphens.');
        }

        foreach (['/.env.test', '/.env'] as $file) {
            if (!is_file($root.$file)) {
                return self::refuse('demo-seed: refused: missing '.$root.$file.' (make env, then fill in every CHANGE_ME).');
            }
        }
        // `load()` never overrides a variable already in the process environment, so the caller's values win.
        (new Dotenv())->load($root.'/.env.test', $root.'/.env');
        $appEnv = self::value('APP_ENV');
        $url = self::value('DATABASE_URL');
        if ('' === $url) {
            return self::refuse('demo-seed: refused: DATABASE_URL (the owner DSN) is not set in .env.test.');
        }

        try {
            $params = (new DsnParser(ServingCredentialGuard::SCHEME_MAP))->parse($url);
        } catch (\Throwable) {
            return self::refuse('demo-seed: refused: DATABASE_URL is not a parseable DSN.');
        }
        $host = \is_string($params['host'] ?? null) ? $params['host'] : '';
        if (null !== ($refusal = Locality::beforeConnecting($appEnv, $host))) {
            return self::refuse($refusal);
        }

        $password = self::password($root, $passwordFromStdin);
        if (!\is_string($password) || '' === $password) {
            return self::refuse(\is_string($password) ? 'demo-seed: refused: no password (pipe one to --password-stdin, or set DEMO_PASSWORD in .env.demo).' : $password[0]);
        }
        if (str_starts_with($password, 'CHANGE_ME')) {
            return self::refuse('demo-seed: refused: DEMO_PASSWORD is still the template placeholder.');
        }
        if ([] !== PasswordRules::errors($password, '/password')) {
            return self::refuse(\sprintf('demo-seed: refused: the password must be %d to 4096 characters.', PasswordRules::MINIMUM_LENGTH));
        }

        try {
            $seal = CredentialSeal::configured(self::value('AUTH_CREDENTIAL_MAC_KEY'), self::value(CredentialSeal::KEY_ID_NAME), $appEnv);
            $kdf = PasswordVerifier::kdfFor(self::dummyHash($appEnv));
        } catch (\Throwable) {
            return self::refuse('demo-seed: refused: AUTH_CREDENTIAL_MAC_KEY (and its id) in .env, or the configured KDF cost, is not usable.');
        }

        try {
            $connection = \Doctrine\DBAL\DriverManager::getConnection($params);
            $bypass = true === $connection->fetchOne('SELECT rolbypassrls FROM pg_roles WHERE rolname = session_user');
            $comment = $connection->fetchOne("SELECT shobj_description(oid, 'pg_database') FROM pg_database WHERE datname = current_database()");
        } catch (DbalException) {
            // Deliberately not echoing the DSN — it carries a password.
            return self::refuse(\sprintf('demo-seed: refused: cannot connect to %s:%s. Is the stack up?', $host, (string) ($params['port'] ?? '?')));
        }
        if (null !== ($refusal = Locality::afterConnecting($appEnv, $bypass, \is_string($comment) ? $comment : null, $slug))) {
            return self::refuse($refusal);
        }

        if (false !== $connection->fetchOne('SELECT 1 FROM tenant WHERE slug = ?', [$slug])) {
            fwrite(\STDERR, \sprintf("demo-seed: workspace %s already exists; nothing was written. Run make reset in the demo database, or pass another --workspace.\n", $slug));

            return self::EXIT_EXISTS;
        }

        try {
            self::write($connection, $slug, $kdf->hash($password), $seal);
        } catch (DriverException $e) {
            fwrite(\STDERR, \sprintf("demo-seed: failed (SQLSTATE %s); nothing was written.\n", $e->getSQLState() ?? '?'));

            return self::EXIT_FAILED;
        } catch (\Throwable $e) {
            // The class only: a message or a trace could carry a value this run must not print.
            fwrite(\STDERR, \sprintf("demo-seed: failed (%s); nothing was written.\n", $e::class));

            return self::EXIT_FAILED;
        }

        fwrite(\STDOUT, \sprintf("demo-seed: workspace %s created with its %d built-in roles and one owner (%s). Sign in with the password you supplied.\n",
            $slug, \count(self::ROLE_NAMES), self::OWNER_EMAIL));

        return self::EXIT_OK;
    }

    /** One transaction; every id `Uuid::v4()`; the tenant setting set inside it so FORCE RLS's `WITH CHECK` holds. */
    private static function write(Connection $connection, string $slug, string $passwordHash, CredentialSeal $seal): void
    {
        $tenantId = Uuid::v4()->toRfc4122();
        $ownerId = Uuid::v4()->toRfc4122();
        $email = EmailAddress::fromInput(self::OWNER_EMAIL)->value;

        $connection->beginTransaction();
        try {
            $connection->executeStatement("INSERT INTO tenant (id, slug, name, status) VALUES (?::uuid, ?, ?, 'active')",
                [$tenantId, $slug, self::WORKSPACE_NAME]);
            $connection->executeStatement("SELECT set_config('app.tenant_id', ?, true)", [$tenantId]);
            $connection->executeStatement(
                'INSERT INTO tenant_settings (id, tenant_id, default_currency_code, display_name) VALUES (?::uuid, ?::uuid, ?, ?)',
                [Uuid::v4()->toRfc4122(), $tenantId, self::DEFAULT_CURRENCY, self::WORKSPACE_NAME]);

            $roleIds = [];
            foreach (DefaultRoles::names() as $key) {
                $roleIds[$key] = Uuid::v4()->toRfc4122();
                $connection->executeStatement(
                    'INSERT INTO role (id, tenant_id, name, is_system, default_key) VALUES (?::uuid, ?::uuid, ?, true, ?)',
                    [$roleIds[$key], $tenantId, self::ROLE_NAMES[$key], $key]);
                foreach (DefaultRoles::codesFor($key) as $code) {
                    $connection->executeStatement(
                        'INSERT INTO role_permission (id, tenant_id, role_id, permission_code) VALUES (?::uuid, ?::uuid, ?::uuid, ?)',
                        [Uuid::v4()->toRfc4122(), $tenantId, $roleIds[$key], $code]);
                }
            }

            $connection->executeStatement(<<<'SQL'
                INSERT INTO app_user (id, tenant_id, email, password_hash, password_mac, identity_mac, display_name)
                VALUES (?::uuid, ?::uuid, ?, ?, ?, ?, ?)
                SQL, [$ownerId, $tenantId, $email, $passwordHash, $seal->seal($tenantId, $ownerId, $passwordHash),
                $seal->sealIdentity($tenantId, $ownerId, $email), self::OWNER_NAME]);
            $connection->executeStatement('INSERT INTO user_role (id, tenant_id, user_id, role_id) VALUES (?::uuid, ?::uuid, ?::uuid, ?::uuid)',
                [Uuid::v4()->toRfc4122(), $tenantId, $ownerId, $roleIds[DefaultRoles::OWNER]]);

            // The trail, truthful about who wrote it: no actor, an owner-process label. `role.assigned` is what reconciliation
            // arm A pairs with the grant, and arm B reports if the grant later disappears.
            foreach ([
                [AuditEvent::USER_CREATED, [RoleEventMetadata::FIELDS => UserAuditWriter::CREATED_FIELDS]],
                [AuditEvent::ROLE_ASSIGNED, [RoleEventMetadata::ROLE_ID => $roleIds[DefaultRoles::OWNER]]],
            ] as [$event, $metadata]) {
                $connection->executeStatement(<<<'SQL'
                    INSERT INTO audit_log (id, tenant_id, occurred_at, event_type, actor_user_id, actor_label, subject_type, subject_id, ip_address, metadata)
                    VALUES (?::uuid, ?::uuid, clock_timestamp(), ?, NULL, ?, ?, ?::uuid, NULL, ?::jsonb)
                    SQL, [Uuid::v4()->toRfc4122(), $tenantId, $event, self::ACTOR_LABEL, AuditEvent::SUBJECT_TYPES[$event], $ownerId,
                    json_encode($metadata, \JSON_THROW_ON_ERROR)]);
            }

            $connection->commit();
        } catch (\Throwable $e) {
            $connection->rollBack();

            throw $e;
        }
    }

    /**
     * `--password-stdin` (everything on stdin, one trailing newline removed), else `DEMO_PASSWORD` from `.env.demo`, parsed
     * into a variable — never put into the environment.
     *
     * @return string|array{0: string} the password, or a refusal line
     */
    private static function password(string $root, bool $fromStdin): string|array
    {
        if ($fromStdin) {
            return preg_replace('/\r?\n\z/', '', (string) stream_get_contents(\STDIN)) ?? '';
        }
        if (!is_file($root.'/.env.demo')) {
            return ['demo-seed: refused: no password (pipe one to --password-stdin, or cp .env.demo.example .env.demo and set DEMO_PASSWORD).'];
        }

        return (new Dotenv())->parse((string) file_get_contents($root.'/.env.demo'), $root.'/.env.demo')['DEMO_PASSWORD'] ?? '';
    }

    /** The KDF cost the kernel of this APP_ENV uses: `app.auth.dummy_password_hash`, `when@<env>`'s value when present. */
    private static function dummyHash(string $appEnv): string
    {
        $config = Yaml::parseFile(\dirname(__DIR__).'/config/services.yaml');
        $hash = $config['when@'.$appEnv]['parameters']['app.auth.dummy_password_hash']
            ?? $config['parameters']['app.auth.dummy_password_hash']
            ?? null;
        if (!\is_string($hash)) {
            throw new \RuntimeException('app.auth.dummy_password_hash is not configured.');
        }

        return $hash;
    }

    /** A value the caller's environment supplied, else the one `Dotenv::load()` read (`$_SERVER` first, then `$_ENV`). */
    private static function value(string $name): string
    {
        $value = $_SERVER[$name] ?? $_ENV[$name] ?? '';

        return \is_string($value) ? $value : '';
    }

    private static function refuse(string $line): int
    {
        fwrite(\STDERR, $line."\n");

        return self::EXIT_REFUSED;
    }
}

if ('cli' === \PHP_SAPI && isset($_SERVER['SCRIPT_FILENAME']) && realpath((string) $_SERVER['SCRIPT_FILENAME']) === __FILE__) {
    exit(Seeder::main(array_values(array_map('strval', $_SERVER['argv'] ?? []))));
}
