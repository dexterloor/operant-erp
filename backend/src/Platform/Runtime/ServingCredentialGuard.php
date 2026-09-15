<?php

declare(strict_types=1);

namespace App\Platform\Runtime;

use App\Platform\Tenancy\DatabaseRoles;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Exception\MalformedDsnException;
use Doctrine\DBAL\Tools\DsnParser;

/**
 * Names every environment variable that holds a database credential, in a recognised form, that is not an
 * application role's (V3-1(i), owner ruling 2026-09-15 option A "App refuses to start"; V4 batch B2b-2, draft rev 3).
 *
 * ---------------------------------------------------------------------------
 * What it is for, stated exactly
 * ---------------------------------------------------------------------------
 * `public/index.php` calls it before `new Kernel` and refuses to build a kernel while it returns anything: under
 * PHP-FPM or `php -S` every request is answered 503 (a health check must treat 503 as down); under a worker runtime the
 * worker crash-loops; the FrankenPHP worker mode is refused outright. `bin/console` calls it too and refuses every
 * command except CONSOLE_OWNER_COMMANDS — that half is hygiene, not a control: `bootEnv` has already loaded the
 * credential, and anyone who can run the console with the owner DSN can run `psql`.
 *
 * "Recognised form" is the limit (E-11′). A credential is seen as (1) a connection string — URL, PDO `pgsql:` or libpq
 * keyword form — whose effective login is not an application role, or (2) a variable named like a password or an API
 * key. An owner password under a name neither rule recognises (`DB_CRED`) is not seen, and neither is a credential
 * delivered as a file or baked into an image or a config file. Those are O-1b's residual.
 *
 * ---------------------------------------------------------------------------
 * Why it reads DBAL's effective parameters and not the URL
 * ---------------------------------------------------------------------------
 * DBAL decodes the whole URL, then `parse_str`s the query and merges it OVER the scheme, user and password
 * (`DsnParser::parseDatabaseUrlQuery`). So `postgresql://erp_app:…@h/erp?user=neondb_owner` logs in as the owner, and a
 * double-encoded `wrapperClass` with `primary[user]=…` builds a primary/replica connection whose primary does. The guard
 * parses with the same parser and the same scheme map, and allowlists the keys of the result (DSN_KEYS) instead of
 * blocklisting raw query keys, which read one decode where DBAL reads two (RED R2-BLK-1).
 *
 * ---------------------------------------------------------------------------
 * Dependency, named
 * ---------------------------------------------------------------------------
 * It trusts `DatabaseRoles::APPLICATION_ROLES`: adding a role there silently widens it. That constant is held by
 * `SchemaGuardTest::neitherApplicationRoleCarriesBypassRlsOrSuperuser` and `::theRoleListCoversEveryLoginRoleInTheDatabase`.
 * The set includes `erp_ai`, which C-3 keeps out of the API kernel; at Output 7 a SERVING_WEB_ROLES subset (application
 * roles minus AI) becomes this guard's set (worklist carry V3-5).
 *
 * Plain PHP with static methods and no container: it runs before any `src/` service exists. It handles variable NAMES
 * and never returns, logs or formats a value (Standing Rule 10).
 *
 * @see tests/Runtime/ServingCredentialGuardTest.php         one row per rule, each with its mutation
 * @see tests/Runtime/ServingCredentialGuardProcessTest.php  the wiring in public/index.php and bin/console
 * @see tests/Architecture/EntryPointScanTest.php            no other way to start the application
 */
final class ServingCredentialGuard
{
    /** EX_CONFIG. */
    public const EXIT_CODE = 78;

    public const MARKER = 'SERVING_CREDENTIAL_GUARD: refused';

    public const WORKER_MODE_UNDESIGNED = 'worker-mode-undesigned';

    /**
     * Copied from DoctrineBundle's `ConnectionFactory::DEFAULT_SCHEME_MAP` (marked internal there); a test pins equality,
     * and `EnvProcessorPinTest` pins `doctrine.dbal` to carry no `driver_schemes` that would extend the container's copy.
     */
    public const SCHEME_MAP = [
        'db2' => 'ibm_db2',
        'mssql' => 'pdo_sqlsrv',
        'mysql' => 'pdo_mysql',
        'mysql2' => 'pdo_mysql',
        'postgres' => 'pdo_pgsql',
        'postgresql' => 'pdo_pgsql',
        'pgsql' => 'pdo_pgsql',
        'sqlite' => 'pdo_sqlite',
        'sqlite3' => 'pdo_sqlite',
    ];

    /** The only console commands that may run with a non-application database credential present (E-5; hygiene). */
    public const CONSOLE_OWNER_COMMANDS = [
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
     * Reviewed application secrets exempt from rule 2 (E-8). Adding a name is a reviewed edit. None of the three matches
     * a credential rule today, so the list excludes nothing yet; it is where an exemption would have to be written.
     */
    public const ALLOWED_NAMES = ['APP_SECRET', 'AUTH_TOKEN_SIGNING_KEY', 'AUTH_EMAIL_HASH_KEY'];

    /** E-1′ (a): the only top-level keys a DBAL-shaped DSN may carry after `DsnParser::parse()`. Adding one is a reviewed edit. */
    public const DSN_KEYS = ['driver', 'host', 'port', 'user', 'password', 'dbname', 'serverVersion', 'charset'];

    /** RFC 3875 meta-variables and request-derived keys, never read from `$_SERVER` (E-3; mirrors `EnvVarProcessor.php:170`). */
    public const SERVER_SKIP_PREFIXES = ['HTTP_', 'REDIRECT_', 'PHP_AUTH_'];

    public const SERVER_SKIP_NAMES = ['QUERY_STRING', 'REQUEST_METHOD', 'REQUEST_URI', 'REQUEST_TIME', 'REQUEST_TIME_FLOAT',
        'REQUEST_SCHEME', 'SCRIPT_NAME', 'SCRIPT_FILENAME', 'PATH_INFO', 'PATH_TRANSLATED', 'CONTENT_TYPE', 'CONTENT_LENGTH',
        'REMOTE_ADDR', 'REMOTE_HOST', 'REMOTE_PORT', 'REMOTE_USER', 'SERVER_NAME', 'SERVER_PORT', 'SERVER_PROTOCOL',
        'SERVER_SOFTWARE', 'SERVER_ADDR', 'GATEWAY_INTERFACE', 'DOCUMENT_ROOT', 'DOCUMENT_URI', 'AUTH_TYPE', 'argv', 'argc', 'PHP_SELF'];

    /** Rule 2's token form. `PWD` and `OLDPWD` (every shell's working directory) are excluded by name. */
    private const PASSWORD_TOKEN = '/(^|_)(PASS|PASSWD|PASSWORD|PWD|PASSPHRASE)$/';

    private const SHELL_DIRECTORY_NAMES = ['PWD', 'OLDPWD'];

    private const API_KEY_SUFFIX = '/_API_KEY$/';

    private const NEON_PREFIX = '/^NEON_/';

    private const PG_CREDENTIAL_NAMES = ['PGPASSWORD', 'PGUSER', 'PGSERVICE', 'PGSERVICEFILE', 'PGPASSFILE', 'PGSYSCONFDIR', 'PGSSLCERT', 'PGSSLKEY'];

    /** E-1′ (a): `host`, `dbname` and `charset` reach the PDO DSN and, for `charset`, `SET NAMES '…'` unescaped. */
    private const CONSTRAINED_KEYS = ['host', 'dbname', 'charset'];

    private const UNSAFE_CHARACTERS = '/[;\'=\s]/';

    /** Rule 1(b): `pgsql:` followed by `key=value`, case-insensitively. */
    private const PDO_SHAPE = '/^pgsql:\s*[A-Za-z_]+\s*=/i';

    /** Rule 1(c): a libpq keyword string. */
    private const KEYWORD_SHAPE = '/(^|[\s;])(user|password|service|passfile)\s*=/i';

    /** Every `user=` occurrence of a PDO or keyword string: a single-quoted value (with `\'` escapes) or a bare word. */
    private const USER_OCCURRENCE = '/(?:^|[\s;:])user\s*=\s*(\'(?:[^\'\\\\]|\\\\.)*\'|[^\s;]*)/i';

    /**
     * Each source is checked separately and the offending NAMES are unioned (E-2): Symfony resolves `$_ENV` first, then
     * non-`HTTP_` `$_SERVER`, then `getenv()`, so a keyed merge would check one value while the container uses another.
     *
     * @param array<array-key, mixed> $env    `$_ENV`
     * @param array<array-key, mixed> $server `$_SERVER`, filtered here (E-3)
     * @param array<array-key, mixed> $getenv `getenv()`
     *
     * @return list<string>
     */
    public static function offendingNames(array $env, array $server, array $getenv): array
    {
        $names = [];
        foreach ([[$env, false], [$server, true], [$getenv, false]] as [$source, $isServer]) {
            foreach (self::offendingNamesIn($source, $isServer) as $name) {
                $names[$name] = true;
            }
        }

        return array_keys($names);
    }

    /**
     * @param array<array-key, mixed> $source
     *
     * @return list<string>
     */
    private static function offendingNamesIn(array $source, bool $isServer): array
    {
        $names = [];
        foreach ($source as $name => $value) {
            $name = (string) $name;
            if ($isServer && self::isRequestDerived($name)) {
                continue;
            }
            // Rule 5 (E-10): in worker mode per-request server variables merge after the kernel exists, out of sight.
            if ('FRANKENPHP_WORKER' === $name) {
                $names[] = $name.' ('.self::WORKER_MODE_UNDESIGNED.')';
                continue;
            }
            // Rule 4: scalar string values only.
            if (!\is_string($value)) {
                continue;
            }
            if (self::isCredentialName($name) || self::isCredentialValue($value)) {
                $names[] = $name;
            }
        }

        return $names;
    }

    private static function isRequestDerived(string $name): bool
    {
        foreach (self::SERVER_SKIP_PREFIXES as $prefix) {
            if (str_starts_with($name, $prefix)) {
                return true;
            }
        }

        return \in_array($name, self::SERVER_SKIP_NAMES, true);
    }

    /** Rule 2 (E-8). */
    private static function isCredentialName(string $name): bool
    {
        $upper = strtoupper($name);
        if (\in_array($upper, self::ALLOWED_NAMES, true)) {
            return false;
        }
        if (1 === preg_match(self::PASSWORD_TOKEN, $upper) && !\in_array($upper, self::SHELL_DIRECTORY_NAMES, true)) {
            return true;
        }
        if (1 === preg_match(self::API_KEY_SUFFIX, $upper)) {
            return true;
        }
        if (1 === preg_match(self::NEON_PREFIX, $upper)) {
            return true;
        }

        return \in_array($upper, self::PG_CREDENTIAL_NAMES, true);
    }

    /** Rule 1 (E-1′). Each applicable shape is examined; any refusal refuses. */
    private static function isCredentialValue(string $value): bool
    {
        if (self::isUrlShaped($value) && self::urlRefuses($value)) {
            return true;
        }
        if (1 === preg_match(self::PDO_SHAPE, $value) && self::userOccurrencesRefuse($value)) {
            return true;
        }
        if (1 === preg_match(self::KEYWORD_SHAPE, $value) && self::userOccurrencesRefuse($value)) {
            return true;
        }

        return false;
    }

    /**
     * Rule 1(a)'s shape (E-1′ (c)): contains `://`, or starts with `//` and has a query. A schemeless DSN is usable by
     * Doctrine only with a `driver` query key, so a base64 secret that happens to begin with `//` is not examined.
     */
    private static function isUrlShaped(string $value): bool
    {
        if (str_contains($value, '://')) {
            return true;
        }
        if (!str_starts_with($value, '//')) {
            return false;
        }
        $parts = parse_url($value);

        return \is_array($parts) && isset($parts['query']);
    }

    /** Rule 1(a), on DBAL's own effective, double-decoded parameters. */
    private static function urlRefuses(string $value): bool
    {
        try {
            $params = (new DsnParser(self::SCHEME_MAP))->parse($value);
        } catch (MalformedDsnException) {
            return true;
        }

        if (!self::isDbalShaped($params)) {
            return false;
        }

        foreach ($params as $key => $parameter) {
            if (!\in_array($key, self::DSN_KEYS, true)) {
                return true;
            }
            if ('port' === $key) {
                if (!\is_int($parameter) && !(\is_string($parameter) && ctype_digit($parameter))) {
                    return true;
                }
                continue;
            }
            if (!\is_string($parameter)) {
                return true;
            }
            if (\in_array($key, self::CONSTRAINED_KEYS, true) && 1 === preg_match(self::UNSAFE_CHARACTERS, $parameter)) {
                return true;
            }
        }

        return !isset($params['user']) || !\in_array($params['user'], DatabaseRoles::APPLICATION_ROLES, true);
    }

    /**
     * E-1′ (b): DBAL-shaped when the effective driver, lower-cased and mapped, is a DBAL driver; or it is absent; or a
     * `driverClass` / `wrapperClass` key is present. `http`, `smtp`, `redis` and the like are not examined further.
     *
     * @param array<array-key, mixed> $params
     */
    private static function isDbalShaped(array $params): bool
    {
        if (\array_key_exists('driverClass', $params) || \array_key_exists('wrapperClass', $params) || !\array_key_exists('driver', $params)) {
            return true;
        }
        if (!\is_string($params['driver'])) {
            return true;
        }
        $driver = str_replace('-', '_', strtolower($params['driver']));
        $driver = self::SCHEME_MAP[$driver] ?? $driver;

        return \in_array($driver, DriverManager::getAvailableDrivers(), true);
    }

    /**
     * Rules 1(b) and 1(c) (E-1′ (d)): refused unless EVERY `user=` occurrence, unquoted and trimmed, is an application
     * role and at least one is present. libpq takes the last duplicate.
     */
    private static function userOccurrencesRefuse(string $value): bool
    {
        preg_match_all(self::USER_OCCURRENCE, $value, $matches);
        if ([] === $matches[1]) {
            return true;
        }
        foreach ($matches[1] as $user) {
            $user = trim($user);
            if (\strlen($user) >= 2 && str_starts_with($user, "'") && str_ends_with($user, "'")) {
                $user = str_replace("\\'", "'", substr($user, 1, -1));
            }
            if (!\in_array($user, DatabaseRoles::APPLICATION_ROLES, true)) {
                return true;
            }
        }

        return false;
    }
}
