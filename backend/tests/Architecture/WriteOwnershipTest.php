<?php

declare(strict_types=1);

namespace App\Tests\Architecture;

use App\Modules\UserManagement\Infrastructure\AuthTrailWriter;
use App\Platform\Tenancy\SchemaManifest;
use App\Tests\Support\PhpSourceScan;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * **`ARCHITECTURE.md` S-7(c): a module writes, in raw SQL or DQL, only the
 * tables `SchemaManifest` says it owns.**
 *
 * ---------------------------------------------------------------------------
 * Why this exists (owner ruling on API-O-27, 2026-09-14)
 * ---------------------------------------------------------------------------
 * S-7 is "exactly one module writes each table", in three parts. (a) is
 * `SchemaOwnershipTest`: no module *maps an entity* to a table it does not own.
 * (b) said deptrac's A-10 closed the raw-SQL route, and that was false: A-10
 * sees a named `Doctrine\DBAL\Connection`, and module code runs its SQL through
 * `EntityManager::getConnection()`, which names no such type. (c) was specified
 * and **never built**. So until this test, a module could `INSERT INTO` another
 * module's table and nothing in the suite would say so.
 *
 * ---------------------------------------------------------------------------
 * What is scanned, and how
 * ---------------------------------------------------------------------------
 * Every string literal in every PHP file under `src/Modules/<M>/` — inside a
 * method body **or** outside one (a `const SQL = 'UPDATE …'` is a write wherever
 * it is declared) — through `PhpSourceScan`, the token walk the purchase-order
 * lock-first scan runs on. Comments and docblocks are not literals, so prose in
 * them is never a statement.
 *
 * A **write statement** is `INSERT INTO`, `DELETE FROM` or `UPDATE`, in any
 * case, except:
 *  - `DO UPDATE` (the arm of `ON CONFLICT`, whose table is the `INSERT`'s) and
 *    `FOR UPDATE` / `FOR NO KEY UPDATE` (a row lock, not a write);
 *  - a non-upper-case `update` that is not followed by `<name> [alias] SET` and
 *    does not end the literal piece — which is how an exception message such as
 *    *"Update is not allowed"* stays prose. An upper-case `UPDATE` is always a
 *    statement.
 *
 * Its **target** is the name after the keyword (after `ONLY`): a SQL table
 * name, bare, quoted or schema-qualified, or a DQL entity FQCN, which is
 * resolved through the entity's own `#[ORM\Table]`. The writer's module is its
 * namespace below `App\` (`ARCHITECTURE.md` §4.0), and the table's is
 * `SchemaManifest::TABLES[<table>]['module']`. They must be equal.
 *
 * ---------------------------------------------------------------------------
 * Fail closed
 * ---------------------------------------------------------------------------
 * A statement whose target **cannot be read as a manifest table** is a failure,
 * not a pass: an interpolated `"INSERT INTO {$table}"` (the walk yields the piece
 * `INSERT INTO ` and nothing after it), a concatenation `'UPDATE '.$table`, a
 * `sprintf('DELETE FROM %s')`, a class constant from somewhere else, or a name
 * the manifest does not declare. The fix is a literal table name, or an entry in
 * {@see self::REGISTERED_DEVIATIONS} under {@see self::UNRESOLVED} with a reason.
 *
 * ---------------------------------------------------------------------------
 * What this does NOT see — named residuals
 * ---------------------------------------------------------------------------
 *  - **`Platform\Tenancy` is out of scope**, and so is the rest of `src/Platform`
 *    and `src/SharedKernel`: `Platform\Tenancy` is the RLS / `set_config` layer
 *    (A-10 lets it take a `Connection` directly), its tables are not a module's,
 *    and `SchemaManifest`'s own `why` strings are full of upper-case `UPDATE`
 *    prose that would fail closed here for no reason.
 *  - **ORM writes.** A module that `persist()`s another module's entity writes
 *    that table without spelling SQL. S-7(a) checks where entities are mapped,
 *    not who persists them.
 *  - **DBAL's convenience writers** — `Connection::insert('t', …)`, `update()`,
 *    `delete()` and the query builder's `->insert('t')` / `->update('t')` /
 *    `->delete('t')`. None is used under `src/Modules` today (grep, 2026-09-14);
 *    a name scan cannot tell them from a service method called `update()`.
 *  - **SQL assembled from pieces with no keyword in them**, and triggers — the
 *    same blind spots `PurchaseOrderRowLock`'s docblock names for the lock scan.
 *  - **Reads.** Cross-module SQL reads are a disclosed residual, not a rule:
 *    `UserManagement\Application\PasswordChangeThrottle` reads `audit_log` by
 *    design.
 */
final class WriteOwnershipTest extends TestCase
{
    /**
     * **The empty-set floor.** 25 write statements under `src/Modules` on
     * 2026-09-14 at `526bfef`, counted independently of this scanner with
     *
     *     grep -rnE "INSERT INTO|DELETE FROM|UPDATE [a-z_]+( |$)" src/Modules
     *
     * which prints 34 lines: 9 are comment or docblock prose ("UPDATE is
     * retained", "no UPDATE restates the field list" — the reason a line grep is
     * not the check) and 25 are statements, by module AuditLog 4, Inventory 6,
     * Purchasing 10, UserManagement 5. It
     * is the exact count, not a round number below it, so a scanner that loses
     * even one statement is red. Removing a real write is the one legitimate
     * reason to lower it, in the same commit.
     */
    private const WRITE_STATEMENT_FLOOR = 25;

    /** The target key for a registered statement whose table the scan cannot read. */
    private const UNRESOLVED = '(unresolved)';

    /**
     * **Cross-module raw writes that are deliberate: writer FQCN => target table
     * (or {@see self::UNRESOLVED}) => reason.** Every entry must still match a
     * statement the scan finds, so a deviation cannot outlive the write it
     * excuses.
     *
     * @var array<class-string, array<string, non-empty-string>>
     */
    private const REGISTERED_DEVIATIONS = [
        AuthTrailWriter::class => [
            'audit_log' => 'ADR-006 / API.md §7.1 step 8: authentication events are written on the pre-authentication '
                .'erp_authlog principal, which holds INSERT on audit_log and no SELECT. Every AuditLog writer runs on '
                .'erp_app, which §7.2 forbids on this path, so the write cannot go through the owning module. Deliberate, '
                .'owner ruling on API-O-27 (2026-09-14).',
        ],
    ];

    #[Test]
    public function everyRawWriteInAModuleTargetsATableThatModuleOwns(): void
    {
        $statements = self::statementsUnder(\dirname(__DIR__, 2).'/src/Modules');

        // 1. The floor: a scanner that matches nothing, or less than the tree
        //    holds, is red before any ownership assertion can pass vacuously.
        self::assertGreaterThanOrEqual(self::WRITE_STATEMENT_FLOOR, \count($statements),
            'The write scan found '.\count($statements).' write statements under src/Modules, fewer than the '
            .self::WRITE_STATEMENT_FLOOR.' the tree held when this floor was set. Either the scanner is broken — and '
            .'every assertion below would hold over too little — or a write was removed and the floor must be lowered '
            .'in the same commit.');

        // 2. The positive control, in this test so a --filter run carries it:
        //    the same statement parser must resolve a write to every manifest
        //    table and every mapped module entity, and must read a fixture
        //    exactly — prose, locks and upserts out; interpolation, concatenation
        //    and unknown names in, as unresolved.
        foreach (array_keys(SchemaManifest::TABLES) as $table) {
            foreach (["INSERT INTO $table (id) VALUES (1)", "UPDATE $table SET x = 1", "DELETE FROM $table WHERE id = 1", "UPDATE ONLY \"$table\" AS t SET x = 1", "delete from public.$table"] as $sql) {
                $found = self::statementsInLiteral($sql);
                self::assertCount(1, $found, 'The statement parser does not see exactly one write in: '.$sql);
                self::assertSame($table, $found[0]['table'], 'The statement parser does not resolve '.$table.' in: '.$sql);
            }
        }
        $src = \dirname(__DIR__, 2).'/src/';
        $entities = 0;
        foreach (PhpSourceScan::phpFilesUnder($src.'Modules') as $path) {
            // PSR-4: `App\` is `src/`, so the file names its class.
            $class = 'App\\'.str_replace('/', '\\', substr($path, \strlen($src), -\strlen('.php')));
            if (null === ($table = self::tableOfEntity($class))) {
                continue;
            }
            ++$entities;
            foreach (['UPDATE '.$class.' e SET e.x = 1', 'DELETE FROM '.str_replace('\\', '\\\\', $class).' e'] as $dql) {
                self::assertSame([$table], array_column(self::statementsInLiteral($dql), 'table'),
                    'The statement parser does not resolve entity '.$class.' to '.$table.' in: '.$dql);
            }
        }
        self::assertGreaterThan(0, $entities, 'No mapped entity was found under src/Modules, so the DQL arm had no control.');
        self::assertSame(self::FIXTURE_EXPECTED, array_map(
            static fn (array $s): array => [$s['where'], $s['table']],
            self::statementsIn(self::FIXTURE, 'fixture'),
        ), 'The statement parser misreads its fixture.');

        // 3. Ownership, fail closed, with registered deviations.
        $violations = [];
        $matched = [];
        foreach ($statements as $s) {
            $target = $s['table'] ?? self::UNRESOLVED;
            if (isset(self::REGISTERED_DEVIATIONS[$s['class']][$target])) {
                $matched[$s['class']][$target] = true;
                continue;
            }
            if (null === $s['table']) {
                $violations[] = \sprintf('  %s (%s) — %s: %s', $s['where'], $s['at'], $s['problem'], $s['statement']);
            } elseif (($owner = SchemaManifest::TABLES[$s['table']]['module']) !== $s['module']) {
                $violations[] = \sprintf('  %s (%s) — %s writes %s, which SchemaManifest assigns to %s: %s',
                    $s['where'], $s['at'], $s['module'], $s['table'], $owner, $s['statement']);
            }
        }

        self::assertSame([], $violations,
            "ARCHITECTURE.md S-7(c) / A-13: a module may write only the tables SchemaManifest assigns to it, and every "
            ."write's table must be a literal the scan can read. A cross-module write goes through the owning module's "
            ."Application\\Contract command interface; a deliberate exception needs an owner decision and an entry in "
            ."REGISTERED_DEVIATIONS with its reason. A computed table name must become a literal.\n"
            .implode("\n", $violations));

        $stale = [];
        foreach (self::REGISTERED_DEVIATIONS as $class => $targets) {
            foreach ($targets as $target => $reason) {
                if (!isset($matched[$class][$target])) {
                    $stale[] = '  '.$class.' → '.$target.' ('.$reason.')';
                }
            }
        }
        self::assertSame([], $stale,
            "A registered deviation no longer matches any write statement the scan finds. Remove it, so that it cannot "
            ."excuse a different write later:\n".implode("\n", $stale));
    }

    private const FIXTURE = <<<'PHP'
        <?php

        namespace App\Modules\Probe\Infrastructure;

        /**
         * Prose in a docblock: INSERT INTO audit_log and UPDATE purchase_order SET x are not statements here.
         */
        final class Fixture
        {
            private const LOCK = 'SELECT 1 FROM purchase_order WHERE id = ?::uuid FOR NO KEY UPDATE';

            private const CLEAR = 'DELETE FROM auth_lockout WHERE slug_normalised = ?';

            public function write(string $table): void
            {
                // UPDATE stock_level SET qty_on_hand = 0 -- a comment, not a statement
                $upsert = 'INSERT INTO stock_level (id) VALUES (1) ON CONFLICT (id) DO UPDATE SET qty_on_hand = 1';
                $interpolated = "UPDATE {$table} SET x = 1";
                $concatenated = 'DELETE FROM '.$table.' WHERE id = 1';
                $prose = 'Update is not allowed for this operation.';
                $dql = 'UPDATE App\\Modules\\Purchasing\\Infrastructure\\Entity\\PurchaseOrder o SET o.status = 1';
                $unknown = <<<SQL
                    INSERT INTO no_such_table (id) VALUES (1)
                    SQL;
                $locked = "SELECT 1 FROM purchase_order FOR UPDATE";
            }
        }
        PHP;

    private const FIXTURE_EXPECTED = [
        ['Fixture (class body)', 'auth_lockout'],
        ['Fixture::write', 'stock_level'],
        ['Fixture::write', null],
        ['Fixture::write', null],
        ['Fixture::write', 'purchase_order'],
        ['Fixture::write', null],
    ];

    /**
     * @return list<array{class: string, module: string, where: string, at: string, statement: string, table: ?string, problem: ?string}>
     */
    private static function statementsUnder(string $directory): array
    {
        $statements = [];
        foreach (PhpSourceScan::phpFilesUnder($directory) as $path) {
            $at = substr($path, \strlen(\dirname(__DIR__, 2)) + 1);
            array_push($statements, ...self::statementsIn((string) file_get_contents($path), $at));
        }

        return $statements;
    }

    /**
     * @return list<array{class: string, module: string, where: string, at: string, statement: string, table: ?string, problem: ?string}>
     */
    private static function statementsIn(string $source, string $at): array
    {
        $scan = PhpSourceScan::scan($source);
        $namespace = $scan['namespace'];
        $module = 1 === preg_match('/^App\\\\(Modules\\\\[A-Za-z0-9_]+)(?:\\\\|$)/', $namespace, $m) ? $m[1] : '(not a module: '.$namespace.')';

        $literals = [];
        foreach ($scan['outside'] as $literal) {
            $literals[] = [(string) $literal['class'], ($literal['class'] ?? '(file)').' (class body)', $literal];
        }
        foreach ($scan['methods'] as $key => $method) {
            foreach ($method['literals'] as $literal) {
                $literals[] = [$method['class'], $key, $literal];
            }
        }

        $statements = [];
        foreach ($literals as [$class, $where, $literal]) {
            foreach (self::statementsInLiteral($literal['text']) as $found) {
                $statements[] = [
                    'class' => ltrim($namespace.'\\'.$class, '\\'),
                    'module' => $module,
                    'where' => $where,
                    'at' => $at.':'.$literal['line'],
                ] + $found;
            }
        }

        return $statements;
    }

    /**
     * The write statements one literal piece spells, each with its resolved
     * table or, if it cannot be read as a manifest table, the reason.
     *
     * @return list<array{statement: string, table: ?string, problem: ?string}>
     */
    private static function statementsInLiteral(string $text): array
    {
        // A quoted literal keeps its quotes in the token; strip them so that a
        // keyword at the end of the string reads as "nothing follows".
        if (1 === preg_match('/^([\'"]).*\1$/s', $text)) {
            $text = substr($text, 1, -1);
        }

        preg_match_all('/(?<![\w$\\\\])(INSERT\s+INTO|DELETE\s+FROM|UPDATE)(?![\w$])/i', $text, $matches, \PREG_OFFSET_CAPTURE);

        $found = [];
        foreach ($matches[1] as [$keyword, $offset]) {
            $before = substr($text, 0, $offset);
            $rest = substr($text, $offset + \strlen($keyword));
            $isUpdate = 0 === strncasecmp($keyword, 'UPDATE', 6);

            if ($isUpdate && 1 === preg_match('/\b(?:DO|FOR|KEY)\s+$/i', $before)) {
                continue; // ON CONFLICT … DO UPDATE, or a row lock
            }
            if ($isUpdate && 'UPDATE' !== $keyword
                && 1 !== preg_match('/^\s*$/', $rest)
                && 1 !== preg_match('/^\s+(?:ONLY\s+)?\S+(?:\s+(?:AS\s+)?\w+)?\s+SET\b/i', $rest)
            ) {
                continue; // prose: "Update is not allowed"
            }

            $statement = preg_replace('/\s+/', ' ', trim($keyword.substr($rest, 0, 60)));

            if (1 === preg_match('/^\s+(?:ONLY\s+)?(\\\\*[A-Za-z_]\w*(?:\\\\+[A-Za-z_]\w*)+)(?![\w\\\\])/i', $rest, $t)) {
                $entity = ltrim((string) preg_replace('/\\\\+/', '\\', $t[1]), '\\');
                $table = self::tableOfEntity($entity);
                $found[] = null !== $table && isset(SchemaManifest::TABLES[$table])
                    ? ['statement' => $statement, 'table' => $table, 'problem' => null]
                    : ['statement' => $statement, 'table' => null, 'problem' => 'DQL target '.$entity.' is not an entity mapped to a SchemaManifest table'];
                continue;
            }

            if (1 === preg_match('/^\s+(?:ONLY\s+)?(?:"?([A-Za-z_][\w$]*)"?\.)?"?([A-Za-z_][\w$]*)"?(?![\w$\\\\])/i', $rest, $t)) {
                $name = $t[2];
                $found[] = isset(SchemaManifest::TABLES[$name])
                    ? ['statement' => $statement, 'table' => $name, 'problem' => null]
                    : ['statement' => $statement, 'table' => null, 'problem' => '`'.$name.'` is not a SchemaManifest table'];
                continue;
            }

            $found[] = ['statement' => $statement, 'table' => null, 'problem' => 'no literal table name follows the keyword (interpolated, concatenated or computed)'];
        }

        return $found;
    }

    /** @param string $class a FQCN that may not exist */
    private static function tableOfEntity(string $class): ?string
    {
        if (!class_exists($class)) {
            return null;
        }
        $attributes = (new \ReflectionClass($class))->getAttributes(\Doctrine\ORM\Mapping\Table::class);

        return [] === $attributes ? null : $attributes[0]->newInstance()->name;
    }
}
