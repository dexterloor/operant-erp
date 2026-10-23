<?php

declare(strict_types=1);

namespace App\Tests\Architecture;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * Every environment variable the configuration reads, and how it decodes it, is a reviewed list; and the Doctrine
 * connection config cannot re-route a DSN the front-controller guard has already judged (V3-1(i); V4 batch B2b-2,
 * draft rev 3 §6, RED E-9 and E-1′ (e)).
 *
 * ---------------------------------------------------------------------------
 * Why a pin on env processors
 * ---------------------------------------------------------------------------
 * `ServingCredentialGuard` reads environment VALUES. A `%env(base64:OWNER_BLOB)%`, `json:`, `file:`, `require:`,
 * `query_string:`, `key:` or `csv:` processor would let an owner DSN sit in the environment as an opaque blob that the
 * configuration decodes, out of the guard's sight. So every `%env(` in `config/**` and every `Autowire(env: …)` or
 * `%env(` string in `src/` is pinned to PINNED (name and processor chain), and the forbidden processors fail even if pinned.
 *
 * ---------------------------------------------------------------------------
 * Why a pin on the Doctrine connection keys
 * ---------------------------------------------------------------------------
 * The guard's scheme map equals DoctrineBundle's constant (`ServingCredentialGuardTest` U26), but the container's DSN parser
 * is that constant PLUS `doctrine.dbal.driver_schemes`: `driver_schemes: {mysql: pdo_pgsql}` would make a `mysql://` owner
 * DSN connect to Postgres while the guard reads `pdo_mysql`. `wrapper_class`, `driver_class`, `primary`, `replica`, `host`,
 * `user` and `password` on a connection are config-level routes of the same kind. So, in every YAML file under `config/`
 * and every `when@*` block in it, `doctrine.dbal`'s keys and each connection's keys are pinned to today's sets, and
 * `config/packages/` may hold YAML only (a PHP or XML package file would configure Doctrine outside this pin).
 *
 * `config/reference.php` is generated documentation: its `%env(` mentions are comments, and PHP config files are read by
 * token, comments excluded.
 *
 * Plants shown red at build: `%env(base64:OWNER_BLOB)%` in a scratch config; a new unpinned `%env(FOO)%`;
 * `driver_schemes: {mysql: pdo_pgsql}`; `wrapper_class` on one connection; `user: neondb_owner` on one connection.
 */
final class EnvProcessorPinTest extends TestCase
{
    /** `file => list of "processor:…:NAME"` (a bare NAME when there is no processor), in file order. */
    private const PINNED = [
        'config/packages/doctrine.yaml' => ['resolve:DATABASE_URL', 'resolve:APP_DATABASE_URL', 'resolve:AI_DATABASE_URL', 'resolve:AUTH_DATABASE_URL', 'resolve:AUTHLOG_DATABASE_URL', 'resolve:RATELIMIT_DATABASE_URL', 'resolve:WORKER_DATABASE_URL'],
        'config/packages/framework.yaml' => ['APP_SECRET'],
        // Mail-C0: the worker's mailer (`.env.worker` only) and the queue (both transports read one DSN).
        'config/packages/mailer.yaml' => ['MAILER_DSN', 'MAILER_FROM'],
        'config/packages/messenger.yaml' => ['MESSENGER_TRANSPORT_DSN', 'MESSENGER_TRANSPORT_DSN'],
        'config/packages/routing.yaml' => ['DEFAULT_URI'],
        // Cred-C1: AUTH_CREDENTIAL_MAC_KEY, bound once to CredentialSeal (decoded in PHP, so no `base64:` processor).
        // Mail-C1: the reset mail's sender and link base (worker settings; APP_PUBLIC_URL declared empty for the web), the
        // declared key id (`default::` — empty when unset) and check-key's PREVIOUS key (`default::`, check-key only).
        'config/services.yaml' => ['MAILER_FROM', 'APP_PUBLIC_URL', 'AUTH_EMAIL_HASH_KEY', 'AUTH_TOKEN_SIGNING_KEY', 'AUTH_TOKEN_SIGNING_KEY', 'AUTH_CREDENTIAL_MAC_KEY', 'default::AUTH_CREDENTIAL_MAC_KEY_ID', 'default::AUTH_CREDENTIAL_MAC_KEY_PREVIOUS'],
    ];

    private const FORBIDDEN_PROCESSORS = ['base64', 'json', 'file', 'require', 'query_string', 'key', 'csv'];

    private const DBAL_KEYS = ['connections', 'default_connection', 'types'];

    private const CONNECTION_KEYS = ['mapping_types', 'profiling_collect_backtrace', 'url'];

    #[Test]
    public function everyEnvReadIsPinnedAndUsesNoDecodingProcessor(): void
    {
        $found = self::envReads();

        self::assertArrayHasKey('config/packages/doctrine.yaml', $found, 'the scan found no %env( in doctrine.yaml, so it reads nothing');
        self::assertSame(self::PINNED, $found, 'an %env( read was added, removed or re-processed; review it and update PINNED');

        foreach ($found as $file => $reads) {
            foreach ($reads as $read) {
                $processors = \array_slice(explode(':', $read), 0, -1);
                self::assertSame([], array_values(array_intersect($processors, self::FORBIDDEN_PROCESSORS)), $file.' decodes an environment value with a forbidden processor: '.$read);
            }
        }
    }

    #[Test]
    public function doctrineConnectionConfigCannotRemapOrRerouteADsn(): void
    {
        $connectionsSeen = 0;
        foreach (self::yamlFiles() as $relative => $absolute) {
            $document = Yaml::parseFile($absolute, Yaml::PARSE_CUSTOM_TAGS);
            if (!\is_array($document)) {
                continue;
            }
            $roots = ['' => $document];
            foreach ($document as $key => $value) {
                if (\is_string($key) && str_starts_with($key, 'when@') && \is_array($value)) {
                    $roots[$key] = $value;
                }
            }
            foreach ($roots as $block => $root) {
                $dbal = $root['doctrine']['dbal'] ?? null;
                if (null === $dbal) {
                    continue;
                }
                $where = $relative.('' === $block ? '' : ' '.$block).' doctrine.dbal';
                self::assertIsArray($dbal, $where.' is not a mapping');
                self::assertArrayNotHasKey('driver_schemes', $dbal, $where.' remaps DSN schemes');
                self::assertArrayNotHasKey('driver_scheme', $dbal, $where.' remaps DSN schemes');
                $keys = array_keys($dbal);
                sort($keys);
                self::assertSame(self::DBAL_KEYS, $keys, $where.' keys changed');
                foreach ($dbal['connections'] as $name => $connection) {
                    $connectionKeys = array_keys((array) $connection);
                    sort($connectionKeys);
                    self::assertSame(self::CONNECTION_KEYS, $connectionKeys, $where.'.connections.'.$name.' keys changed');
                    ++$connectionsSeen;
                }
            }
        }

        self::assertSame(7, $connectionsSeen, 'the pin must see the seven connections of doctrine.yaml (Mail-C1 added worker)');
    }

    #[Test]
    public function packageConfigurationIsYamlOnly(): void
    {
        $other = array_values(array_filter(
            scandir(self::backend().'/config/packages') ?: [],
            static fn (string $file): bool => !\in_array($file, ['.', '..'], true) && !str_ends_with($file, '.yaml'),
        ));

        self::assertSame([], $other, 'config/packages holds a non-YAML file, which the Doctrine pin does not read');
    }

    /** @return array<string, list<string>> */
    private static function envReads(): array
    {
        $found = [];
        foreach (self::yamlFiles() as $relative => $absolute) {
            preg_match_all('/%env\(([^()%]+)\)%/', (string) file_get_contents($absolute), $matches);
            if ([] !== $matches[1]) {
                $found[$relative] = $matches[1];
            }
        }

        foreach (['config', 'src'] as $root) {
            $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(self::backend().'/'.$root, \FilesystemIterator::SKIP_DOTS));
            foreach ($iterator as $file) {
                if (!$file->isFile() || !str_ends_with($file->getFilename(), '.php')) {
                    continue;
                }
                $relative = substr($file->getPathname(), \strlen(self::backend()) + 1);
                $tokens = array_values(array_filter(
                    \PhpToken::tokenize((string) file_get_contents($file->getPathname())),
                    static fn (\PhpToken $token): bool => !$token->is([\T_WHITESPACE, \T_COMMENT, \T_DOC_COMMENT]),
                ));
                foreach ($tokens as $i => $token) {
                    if ($token->is([\T_CONSTANT_ENCAPSED_STRING, \T_ENCAPSED_AND_WHITESPACE]) && preg_match_all('/%env\(([^()%]+)\)%/', $token->text, $m)) {
                        array_push($found[$relative] ??= [], ...$m[1]);
                    }
                    // #[Autowire(env: 'NAME')]
                    if ($token->is(\T_STRING) && 'env' === $token->text && ':' === ($tokens[$i + 1] ?? null)?->text) {
                        $found[$relative][] = 'Autowire(env:'.trim((string) ($tokens[$i + 2] ?? null)?->text, '\'"').')';
                    }
                }
            }
        }
        ksort($found);

        return $found;
    }

    /** @return array<string, string> */
    private static function yamlFiles(): array
    {
        $files = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(self::backend().'/config', \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if ($file->isFile() && preg_match('/\.ya?ml$/', $file->getFilename())) {
                $files[substr($file->getPathname(), \strlen(self::backend()) + 1)] = $file->getPathname();
            }
        }
        ksort($files);

        return $files;
    }

    private static function backend(): string
    {
        return \dirname(__DIR__, 2);
    }
}
