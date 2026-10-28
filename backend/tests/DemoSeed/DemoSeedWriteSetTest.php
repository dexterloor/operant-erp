<?php

declare(strict_types=1);

namespace App\Tests\DemoSeed;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * **W-3: `bin/demo-seed.php` writes exactly the seven tables the application cannot write for itself, and nothing else**
 * — the write-ownership pin `WriteOwnershipTest` cannot give (it scans `src/Modules` only). Read from the file's string
 * literals and heredoc bodies (`token_get_all`; comments are not code). It also issues no `COMMENT ON` (spar S-5: the
 * database's purpose marker is the seeder's own guard, which it must never rewrite), and no DDL, grant, `UPDATE`,
 * `DELETE`, `TRUNCATE`, `MERGE` or `COPY`.
 */
final class DemoSeedWriteSetTest extends TestCase
{
    private const WRITE_SET = ['app_user', 'audit_log', 'role', 'role_permission', 'tenant', 'tenant_settings', 'user_role'];

    private const FORBIDDEN = '/\b(?:UPDATE\s+\w|DELETE\s+FROM|TRUNCATE|MERGE\s+INTO|COPY\s+\w|COMMENT\s+ON|ALTER\s+\w|CREATE\s+\w|DROP\s+\w|GRANT\s|REVOKE\s|SET\s+ROLE)/i';

    #[Test]
    public function theSeederInsertsIntoExactlyTheSevenTablesAndIssuesNothingElse(): void
    {
        $inserted = [];
        $forbidden = [];
        foreach (token_get_all((string) file_get_contents(\dirname(__DIR__, 2).'/bin/demo-seed.php')) as $token) {
            if (!\is_array($token) || !\in_array($token[0], [\T_CONSTANT_ENCAPSED_STRING, \T_ENCAPSED_AND_WHITESPACE], true)) {
                continue;
            }
            if (preg_match_all('/INSERT\s+INTO\s+(?:ONLY\s+)?"?(\w+)/i', $token[1], $matches)) {
                array_push($inserted, ...$matches[1]);
            }
            if (preg_match(self::FORBIDDEN, $token[1], $match)) {
                $forbidden[] = 'line '.$token[2].': '.$match[0];
            }
        }
        $inserted = array_values(array_unique(array_map('strtolower', $inserted)));
        sort($inserted);

        self::assertSame(self::WRITE_SET, $inserted, 'The demo seed writes a different set of tables.');
        self::assertSame([], $forbidden, 'The demo seed issues a statement it must not.');
    }
}
