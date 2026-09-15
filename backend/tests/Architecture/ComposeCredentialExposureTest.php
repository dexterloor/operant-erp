<?php

declare(strict_types=1);

namespace App\Tests\Architecture;

use App\Platform\Tenancy\DatabaseRoles;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * ARCHITECTURE C-4's check, as a committed static test: no Compose service that serves traffic can see the schema owner's
 * or the bootstrap superuser's credential (V3-12, RED RV3-6; V4 batch B6; F-6).
 *
 * The check it replaces, `docker compose config | grep -c erp_owner`, grepped for a literal that does not exist locally
 * (`DB_OWNER=neondb_owner`), so it returned 0 whatever the configuration — while the `pgbouncer` service, which serves every
 * pooled connection, received `DB_OWNER`/`DB_OWNER_PASSWORD` and rendered them into its userlist.
 *
 * ---------------------------------------------------------------------------
 * What it reads, and what it never reads
 * ---------------------------------------------------------------------------
 * - `infra/docker-compose.yml`, parsed as YAML. It does not shell out to Docker.
 * - The compose-only env file `.env.infra` (the Makefile's `--env-file`), **line by line, for key names**. A value is taken
 *   only for the two user-name keys (`DB_OWNER`, `POSTGRES_SUPERUSER`); no password value is ever extracted, held, compared
 *   or printed (Rule 10). {@see self::infraEnvNames()} returns names only, and
 *   {@see self::theEnvFileIsReadForNamesOnly} pins that.
 * - The repository files a checked service bind-mounts (today `pgbouncer.ini`), as text.
 *
 * **A missing `.env.infra` fails, never skips** (F-6): step E needs 0 skipped, and without the file the owner's name
 * cannot be derived, so the check would have no subject.
 *
 * ---------------------------------------------------------------------------
 * The rule
 * ---------------------------------------------------------------------------
 * Owner-credential key names are derived from `.env.infra`: every key named `DB_OWNER` or `DB_OWNER_*`, and every key named
 * `POSTGRES_SUPERUSER` or `POSTGRES_SUPERUSER_*`. The owner's user name is `DB_OWNER`'s value, never a literal.
 *
 * A service other than CREDENTIAL_AUTHORITY_SERVICES fails if, anywhere in its definition (environment keys and values,
 * command, entrypoint, healthcheck, any other string):
 * 1. an environment key is an owner-credential key;
 * 2. a string interpolates an owner-credential key (`${DB_OWNER}`, `$DB_OWNER_PASSWORD`, `$$DB_OWNER` in a command);
 * 3. a string names the owner's user (as a word) or the superuser's user in a login form (the exact value, a quoted
 *    string, `user=<name>`, `://<name>:` / `://<name>@`, or a PgBouncer `*_users =` list). The superuser is matched in login
 *    forms only because its local name, `postgres`, is also the database host's service name;
 * 4. a bind-mounted repository file does 2 or 3;
 * 5. it declares a key this test cannot see through (`env_file`, `secrets`, `configs`, `extends`, `build`, …): the
 *    understood keys are pinned in UNDERSTOOD_SERVICE_KEYS, so a new delivery route is loud instead of invisible.
 *
 * `postgres` is the one authority service: it is the database server, and its init script creates the owner and the
 * roles, so it must hold their bootstrap passwords. It is pinned by name and image. It does not proxy client logins.
 *
 * **Not seen (named):** a credential baked into an image, a file mounted from outside the repository, a Docker secret
 * created out of band, and any deployment artefact other than this compose file (none exists; the first one carries C-4's
 * *fails if*). Those are O-1b's gap 1.
 */
final class ComposeCredentialExposureTest extends TestCase
{
    private const COMPOSE_FILE = 'infra/docker-compose.yml';

    private const INFRA_ENV_FILE = '.env.infra';

    private const OWNER_NAME_KEY = 'DB_OWNER';

    private const SUPERUSER_NAME_KEY = 'POSTGRES_SUPERUSER';

    /** The only keys whose value this test reads from `.env.infra`. Both hold user names, never a password. */
    private const USER_NAME_KEYS = [self::OWNER_NAME_KEY, self::SUPERUSER_NAME_KEY];

    /** @var array<string, array{image_prefix: string, reason: string}> */
    private const CREDENTIAL_AUTHORITY_SERVICES = [
        'postgres' => [
            'image_prefix' => 'postgres:',
            'reason' => 'the database server; infra/postgres/init/10-roles.sh creates the owner and the application roles from its environment',
        ],
    ];

    private const UNDERSTOOD_SERVICE_KEYS = [
        'image', 'container_name', 'depends_on', 'environment', 'volumes', 'entrypoint', 'command', 'tmpfs', 'ports', 'healthcheck',
    ];

    #[Test]
    public function noServingServiceCanSeeAnOwnerOrSuperuserCredential(): void
    {
        $env = self::infraEnvNames();
        $services = self::composeServices();

        $checked = 0;
        $findings = [];
        foreach ($services as $name => $definition) {
            if (\array_key_exists($name, self::CREDENTIAL_AUTHORITY_SERVICES)) {
                continue;
            }
            ++$checked;
            foreach (self::exposures($name, $definition, $env['owner_keys'], $env['owner'], $env['superuser'], self::repoRoot().'/infra') as $finding) {
                $findings[] = $finding;
            }
        }

        self::assertGreaterThan(0, $checked, 'The compose file has no service besides the credential authority, so this check had no subject.');
        self::assertContains('pgbouncer', array_keys($services), 'The pooler service is not in the compose file under the name this check expects.');
        self::assertSame([], $findings,
            'C-4: a Compose service that serves traffic can see the schema owner\'s or the bootstrap superuser\'s credential.');
    }

    #[Test]
    public function theCredentialAuthorityIsTheDatabaseServerAndNothingElse(): void
    {
        $services = self::composeServices();

        foreach (self::CREDENTIAL_AUTHORITY_SERVICES as $name => $pin) {
            self::assertArrayHasKey($name, $services, "The credential authority service `$name` is gone; the exemption must go with it.");
            self::assertIsString($services[$name]['image'] ?? null, "`$name` has no image.");
            self::assertStringStartsWith($pin['image_prefix'], $services[$name]['image'],
                "`$name` is exempt as the database server ({$pin['reason']}), but its image is not one.");
        }
    }

    /**
     * The detector is shown to see each form on in-memory plants, so an empty findings list above cannot come from a detector
     * that sees nothing (the empty-set trap). The owner's name is the derived one.
     */
    #[Test]
    public function theDetectorSeesEveryFormItClaims(): void
    {
        $env = self::infraEnvNames();
        $owner = $env['owner'];
        $super = $env['superuser'];
        $keys = $env['owner_keys'];
        $dir = self::repoRoot().'/infra';

        $plants = [
            'environment key' => ['environment' => ['DB_OWNER_PASSWORD' => 'x']],
            'environment list form key' => ['environment' => ['DB_OWNER=x']],
            'interpolated key' => ['environment' => ['PGB_ADMIN_PASSWORD' => '${DB_OWNER_PASSWORD}']],
            'interpolated key with default' => ['environment' => ['X' => '${DB_OWNER:-nobody}']],
            'escaped key in a command' => ['command' => ['printf \'"%s" "%s"\n\' "$$DB_OWNER" "$$DB_OWNER_PASSWORD"']],
            'unbraced key in an entrypoint' => ['entrypoint' => 'sh -c "echo $POSTGRES_SUPERUSER_PASSWORD"'],
            'literal owner in a userlist line' => ['command' => ["printf '\"$owner\" \"x\"\\n'"]],
            'literal owner as a value' => ['environment' => ['PGUSER' => $owner]],
            'owner in a DSN' => ['environment' => ['URL' => "postgresql://$owner:x@postgres:5432/erp"]],
            'superuser in a DSN' => ['environment' => ['URL' => "postgresql://$super:x@postgres:5432/erp"]],
            'superuser as a value' => ['environment' => ['PGUSER' => $super]],
            'superuser as user=' => ['healthcheck' => ['test' => ['CMD-SHELL', "pg_isready -d 'host=postgres user=$super'"]]],
            'env_file' => ['env_file' => ['../.env.infra']],
            'secrets' => ['secrets' => ['owner_password']],
        ];

        foreach ($plants as $label => $definition) {
            self::assertNotSame([], self::exposures('plant', $definition, $keys, $owner, $super, $dir), "The detector misses: $label.");
        }

        // The discriminating half: an application role, the database host named `postgres`, and the old literal `erp_owner`
        // (not the owner here) are not findings.
        $clean = [
            'environment' => ['ERP_APP_USER' => '${ERP_APP_USER}', 'ERP_APP_PASSWORD' => '${ERP_APP_PASSWORD}'],
            'command' => ['printf \'"%s" "%s"\n\' "$$ERP_APP_USER" "$$ERP_APP_PASSWORD"', 'host=postgres port=5432'],
            'depends_on' => ['postgres'],
        ];
        if ('erp_owner' !== $owner) {
            $clean['environment']['LEGACY'] = 'erp_owner';
        }
        self::assertSame([], self::exposures('clean', $clean, $keys, $owner, $super, $dir), 'The detector flags an application-role definition.');
    }

    /** F-6: names only. The parser returns key names and exactly the two user names, and the names are sane. */
    #[Test]
    public function theEnvFileIsReadForNamesOnly(): void
    {
        $env = self::infraEnvNames();

        $extracted = array_keys($env['user_values']);
        $expected = self::USER_NAME_KEYS;
        sort($extracted);
        sort($expected);
        self::assertSame($expected, $extracted, 'infraEnvNames() extracted a value for a key that is not a user name.');
        self::assertContains('DB_OWNER_PASSWORD', $env['owner_keys'], 'The derived owner-credential key set lacks the owner password key.');
        self::assertContains('POSTGRES_SUPERUSER_PASSWORD', $env['owner_keys'], 'The derived owner-credential key set lacks the superuser password key.');
        self::assertMatchesRegularExpression('/\A[a-z_][a-z0-9_]*\z/', $env['owner'], 'DB_OWNER is not a plain role name.');
        self::assertMatchesRegularExpression('/\A[a-z_][a-z0-9_]*\z/', $env['superuser'], 'POSTGRES_SUPERUSER is not a plain role name.');
        self::assertNotContains($env['owner'], DatabaseRoles::APPLICATION_ROLES, 'The owner name is an application role.');
        self::assertNotContains($env['superuser'], DatabaseRoles::APPLICATION_ROLES, 'The superuser name is an application role.');
    }

    /**
     * @return array{keys: list<string>, owner_keys: list<string>, user_values: array<string, string>, owner: string, superuser: string}
     */
    private static function infraEnvNames(): array
    {
        $path = self::repoRoot().'/'.self::INFRA_ENV_FILE;
        if (!is_file($path)) {
            self::fail('C-4 cannot be checked: '.self::INFRA_ENV_FILE.' is missing at the repository root (cp .env.infra.example .env.infra). '
                .'This check fails rather than skips (F-6): without the file the owner\'s name cannot be derived.');
        }

        $keys = [];
        $userValues = [];
        $file = new \SplFileObject($path);
        while (!$file->eof()) {
            $line = (string) $file->fgets();
            // The pattern captures the key name only; nothing after `=` is captured.
            if (1 !== preg_match('/\A\s*(?:export\s+)?([A-Za-z_][A-Za-z0-9_]*)\s*=/', $line, $m)) {
                continue;
            }
            $key = $m[1];
            $keys[] = $key;
            if (\in_array($key, self::USER_NAME_KEYS, true)) {
                $userValues[$key] = trim(trim(substr($line, \strlen($m[0]))), '"\'');
            }
        }

        foreach (self::USER_NAME_KEYS as $required) {
            self::assertArrayHasKey($required, $userValues, self::INFRA_ENV_FILE." does not declare $required.");
            self::assertNotSame('', $userValues[$required], self::INFRA_ENV_FILE." declares $required empty.");
        }

        $ownerKeys = array_values(array_filter($keys, static fn (string $k): bool => self::OWNER_NAME_KEY === $k || str_starts_with($k, self::OWNER_NAME_KEY.'_')
            || self::SUPERUSER_NAME_KEY === $k || str_starts_with($k, self::SUPERUSER_NAME_KEY.'_')));

        return [
            'keys' => $keys,
            'owner_keys' => $ownerKeys,
            // Every value extracted, unfiltered, so the names-only pin below sees an extraction that widens.
            'user_values' => $userValues,
            'owner' => $userValues[self::OWNER_NAME_KEY],
            'superuser' => $userValues[self::SUPERUSER_NAME_KEY],
        ];
    }

    /** @return array<string, array<mixed>> */
    private static function composeServices(): array
    {
        $path = self::repoRoot().'/'.self::COMPOSE_FILE;
        self::assertFileExists($path);
        $parsed = Yaml::parseFile($path);
        self::assertIsArray($parsed);
        self::assertIsArray($parsed['services'] ?? null, 'The compose file has no services map.');

        return $parsed['services'];
    }

    /**
     * @param array<mixed>  $definition
     * @param list<string>  $ownerKeys
     *
     * @return list<string>
     */
    private static function exposures(string $service, array $definition, array $ownerKeys, string $owner, string $superuser, string $composeDir): array
    {
        $findings = [];

        foreach (array_keys($definition) as $key) {
            if (!\in_array($key, self::UNDERSTOOD_SERVICE_KEYS, true)) {
                $findings[] = "$service: declares `$key`, which this check cannot see through";
            }
        }

        $environment = $definition['environment'] ?? [];
        if (\is_array($environment)) {
            foreach ($environment as $k => $v) {
                $name = \is_int($k) ? explode('=', (string) $v, 2)[0] : (string) $k;
                if (\in_array($name, $ownerKeys, true)) {
                    $findings[] = "$service: environment key $name";
                }
            }
        }

        // `depends_on` names services, and the database service is called `postgres`; a service name is not a login.
        $walked = $definition;
        unset($walked['depends_on']);
        $strings = [];
        array_walk_recursive($walked, static function ($value, $key) use (&$strings): void {
            if (\is_string($key)) {
                $strings[] = $key;
            }
            if (\is_string($value)) {
                $strings[] = $value;
            }
        });

        foreach ($strings as $string) {
            foreach (self::textFindings($string, $ownerKeys, $owner, $superuser) as $what) {
                $findings[] = "$service: $what";
            }
        }

        foreach ((array) ($definition['volumes'] ?? []) as $volume) {
            $source = \is_array($volume) ? ($volume['source'] ?? '') : explode(':', (string) $volume, 2)[0];
            if (!\is_string($source) || !str_starts_with($source, '.')) {
                continue;
            }
            $file = $composeDir.'/'.$source;
            if (is_file($file)) {
                foreach (self::textFindings((string) file_get_contents($file), $ownerKeys, $owner, $superuser) as $what) {
                    $findings[] = "$service: mounted $source: $what";
                }
            }
        }

        return array_values(array_unique($findings));
    }

    /**
     * @param list<string> $ownerKeys
     *
     * @return list<string>
     */
    private static function textFindings(string $text, array $ownerKeys, string $owner, string $superuser): array
    {
        $found = [];

        foreach ($ownerKeys as $key) {
            if (1 === preg_match('/\$\{?'.preg_quote($key, '/').'(?![A-Za-z0-9_])/', $text)) {
                $found[] = "interpolates $key";
            }
        }

        if (1 === preg_match('/(?<![A-Za-z0-9_])'.preg_quote($owner, '/').'(?![A-Za-z0-9_])/', $text)) {
            $found[] = 'names the owner\'s user';
        }

        $s = preg_quote($superuser, '/');
        $loginForms = [
            '/\A\s*'.$s.'\s*\z/',
            '/["\']'.$s.'["\']/',
            '/\buser\s*=\s*["\']?'.$s.'(?![A-Za-z0-9_])/i',
            '/:\/\/'.$s.'[:@]/',
            '/_users\s*=[^\n]*(?<![A-Za-z0-9_])'.$s.'(?![A-Za-z0-9_])/',
        ];
        foreach ($loginForms as $pattern) {
            if (1 === preg_match($pattern, $text)) {
                $found[] = 'names the superuser\'s user in a login form';
                break;
            }
        }

        return $found;
    }

    private static function repoRoot(): string
    {
        return \dirname(__DIR__, 3);
    }
}
