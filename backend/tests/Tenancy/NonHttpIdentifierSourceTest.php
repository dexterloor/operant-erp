<?php

declare(strict_types=1);

namespace App\Tests\Tenancy;

use App\Platform\Tenancy\SchemaManifest;
use PHPUnit\Framework\Attributes\Test;

/**
 * **B-3 clause 4's positive check (`ROADMAP.md` M0-b, clause 4; owner ruling v3 row 4): every `INSERT` a deployable
 * NON-HTTP writer issues into a table whose primary key is a `uuid` takes its id from the generator at run time (class 1)
 * or from a named `SchemaManifest` constant (class 2)** — never a literal, `Uuid::fromString()` on a constant, or an id
 * carried in. Built in W-3, the local demo seed's commit. **Its trigger was met earlier and missed:** the mail worker's
 * handlers (Mail-C1, `c4df34c`) were the first deployable non-HTTP writers of a uuid-keyed row (`password_reset_token`).
 *
 * **The non-HTTP writer paths, named (and derived where they can be — spar S-3):**
 *  - every class in `src/` carrying `#[AsMessageHandler]` or `#[AsCommand]` (derived by scanning `src/`; anti-vacuity: at
 *    least the four found at W-3 — two mail handlers, `app:credentials:check-key`, `app:roles:reconcile`);
 *  - every PHP file in `bin/` (`demo-seed.php`, the first);
 *  - every migration in `migrations/`.
 * Tests (`tests/`) are class 3 and out of scope. The uuid-keyed tables are read from the live server (`pg_index` primary
 * key on one `uuid` column), never listed here.
 *
 * **Per `INSERT` into such a table (string literals and heredoc bodies only; comments are not code):** the column list
 * omits `id` (the column default supplies it — class 1); or the statement calls `gen_random_uuid()` (class 1); or it
 * carries a uuid literal equal to a `SchemaManifest` constant (class 2); or its id is a bound value and the file mints
 * with `Uuid::v4()`. **Per file:** no uuid-shaped literal other than a class-2 constant's value, and no `Uuid` factory or
 * constructor fed a string literal (`Uuid::fromString('…')`).
 *
 * **Not seen (recorded):** which bound parameter is the id — a file that mints `Uuid::v4()` for one row and binds a value
 * carried in from elsewhere for another passes the per-INSERT clause (the per-file literal clause still sees a literal); an
 * id built at run time from constant parts by concatenation; `gen_random_uuid()` used for a column other than `id` while
 * `id` comes from elsewhere. The literal grep (`IdentifierGenerationTest`) and the run-time half (`DemoSeedProcessTest`:
 * two seeded workspaces share no id, every id v4) stand beside it.
 */
final class NonHttpIdentifierSourceTest extends TenancyTestCase
{
    private const UUID_SHAPE = '/[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}/';

    private const LITERAL_FACTORY = '/(?:Uuid(?:V4)?::from\w*|new\s+\\\\?(?:Symfony\\\\Component\\\\Uid\\\\)?Uuid(?:V4)?)\s*\(\s*[\'"]/';

    #[Test]
    public function everyNonHttpInsertIntoAUuidKeyedTableTakesItsIdFromTheGenerator(): void
    {
        $tables = $this->uuidKeyedTables();
        self::assertContains('tenant', $tables, 'Anti-vacuity: the server-derived uuid-keyed set lacks tenant.');
        self::assertContains('password_reset_token', $tables, 'Anti-vacuity: the server-derived uuid-keyed set lacks password_reset_token.');
        $constants = $this->classTwoConstants();

        $files = $this->domain();
        $inserts = 0;
        $violations = [];
        foreach ($files as $relative => $path) {
            [$code, $literals] = self::split((string) file_get_contents($path));
            $mintsV4 = str_contains($code, 'Uuid::v4()');

            if (1 === preg_match(self::LITERAL_FACTORY, $code, $match)) {
                $violations[] = $relative.': a Uuid built from a string literal ('.$match[0].'…)';
            }
            foreach ($literals as [$line, $text]) {
                if (preg_match_all(self::UUID_SHAPE, $text, $uuids)) {
                    foreach ($uuids[0] as $uuid) {
                        if (!\in_array(strtolower($uuid), $constants, true)) {
                            $violations[] = $relative.':'.$line.': uuid literal '.$uuid.' is not a SchemaManifest constant';
                        }
                    }
                }
                if (!preg_match_all('/INSERT\s+INTO\s+(?:ONLY\s+)?"?(\w+)"?\s*(\(([^)]*)\))?/i', $text, $found, \PREG_SET_ORDER)) {
                    continue;
                }
                foreach ($found as $insert) {
                    $table = strtolower($insert[1]);
                    if (!\in_array($table, $tables, true)) {
                        continue;
                    }
                    ++$inserts;
                    if (!isset($insert[3])) {
                        $violations[] = $relative.':'.$line.': INSERT INTO '.$table.' names no columns, so its id cannot be checked';
                        continue;
                    }
                    $columns = array_map(static fn (string $c): string => strtolower(trim($c, " \t\n\r\"")), explode(',', $insert[3]));
                    if (!\in_array('id', $columns, true) || str_contains($text, 'gen_random_uuid()')) {
                        continue;
                    }
                    if (1 === preg_match(self::UUID_SHAPE, $text)) {
                        continue; // class 2, checked by the literal clause above
                    }
                    if (!$mintsV4) {
                        $violations[] = $relative.':'.$line.': INSERT INTO '.$table.' binds an id, and the file never mints Uuid::v4()';
                    }
                }
            }
        }

        self::assertGreaterThanOrEqual(10, $inserts, 'Anti-vacuity: almost no INSERT into a uuid-keyed table was found in the non-HTTP paths.');
        self::assertSame([], $violations, "A non-HTTP writer can write a uuid-keyed row with an id it did not generate at run time:\n  ".implode("\n  ", $violations));
    }

    /** @return array<string, string> relative path => absolute path */
    private function domain(): array
    {
        $root = \dirname(__DIR__, 2);
        $files = [];
        $workers = 0;
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root.'/src', \FilesystemIterator::SKIP_DOTS)) as $file) {
            if ($file->isFile() && 'php' === $file->getExtension()) {
                [$code] = self::split((string) file_get_contents($file->getPathname()));
                if (1 === preg_match('/#\[\s*\\\\?(?:[\w\\\\]+\\\\)?(?:AsMessageHandler|AsCommand)\b/', $code)) {
                    $files[substr($file->getPathname(), \strlen($root) + 1)] = $file->getPathname();
                    ++$workers;
                }
            }
        }
        self::assertGreaterThanOrEqual(4, $workers, 'Anti-vacuity: fewer #[AsMessageHandler]/#[AsCommand] classes than the four found at W-3.');

        foreach (['bin', 'migrations'] as $dir) {
            foreach (glob($root.'/'.$dir.'/*.php') ?: [] as $path) {
                $files[$dir.'/'.basename($path)] = $path;
            }
        }
        self::assertArrayHasKey('bin/demo-seed.php', $files);
        ksort($files);

        return $files;
    }

    /**
     * Code with comments removed, and the string literals / heredoc bodies with their lines.
     *
     * @return array{0: string, 1: list<array{0: int, 1: string}>}
     */
    private static function split(string $source): array
    {
        $code = '';
        $literals = [];
        foreach (token_get_all($source) as $token) {
            if (!\is_array($token)) {
                $code .= $token;
                continue;
            }
            if (\in_array($token[0], [\T_COMMENT, \T_DOC_COMMENT], true)) {
                continue;
            }
            $code .= $token[1];
            if (\in_array($token[0], [\T_CONSTANT_ENCAPSED_STRING, \T_ENCAPSED_AND_WHITESPACE], true)) {
                $literals[] = [$token[2], $token[1]];
            }
        }

        return [$code, $literals];
    }

    /** @return list<string> */
    private function uuidKeyedTables(): array
    {
        return $this->owner()->fetchFirstColumn(<<<'SQL'
            SELECT c.relname
              FROM pg_index i
              JOIN pg_class c ON c.oid = i.indrelid
              JOIN pg_namespace n ON n.oid = c.relnamespace
              JOIN pg_attribute a ON a.attrelid = c.oid AND a.attnum = i.indkey[0]
             WHERE i.indisprimary AND n.nspname = 'public' AND i.indnatts = 1 AND a.atttypid = 'uuid'::regtype
             ORDER BY 1
            SQL);
    }

    /** @return list<string> the values of `SchemaManifest`'s uuid-valued constants (class 2) */
    private function classTwoConstants(): array
    {
        $values = [];
        foreach ((new \ReflectionClass(SchemaManifest::class))->getReflectionConstants() as $constant) {
            $value = $constant->getValue();
            if (\is_string($value) && 1 === preg_match('/^'.trim(self::UUID_SHAPE, '/').'$/', $value)) {
                $values[] = strtolower($value);
            }
        }
        self::assertContains(SchemaManifest::SENTINEL_TENANT_ID, $values, 'Anti-vacuity: no class-2 constant was found.');

        return $values;
    }
}
