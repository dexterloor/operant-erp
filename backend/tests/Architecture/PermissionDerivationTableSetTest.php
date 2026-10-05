<?php

declare(strict_types=1);

namespace App\Tests\Architecture;

use App\Tests\Support\PhpSourceScan;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * **T2 — no reader of grants reads proposals** (roles slice 3, C1; `docs/session13/roles-s3-design.md` §9 item 6,
 * response R-5; `SECURITY_MODEL.md` §5.2.2: *"a structural one over the permission readers' table set, with its domain
 * derived from the tree"*).
 *
 * A pending grant confers nothing **by construction**: it lives in `role_grant_proposal`, a table no permission
 * derivation reads. This holds that construction: **every SQL literal under `src/` that names `user_role` must not also
 * name `role_grant_proposal`** — so a `UNION … FROM role_grant_proposal` added to `IdentityReader`, `CurrentUserReader`
 * or `LiveGrantReader` (mutation M1) is red here as well as in T1's behavioural half
 * (`RoleGrantProposalTest::aPendingProposalConfersNoPermission`).
 *
 * The domain is derived (`PhpSourceScan`: method, constant, property and out-of-method literals of every `src/` file),
 * and it must contain the three readers of grants, so a scanner that went blind cannot pass. A planted literal naming both
 * tables is the positive control. **Consequence for the build, stated by the design:** `AssignRole`'s pending-pair read
 * (C3) and Create's same-pair read are separate literals naming only `role_grant_proposal`.
 *
 * **Named limits:** SQL assembled by concatenation across two literals, a table name in a variable, or a DQL entity
 * name (`RoleGrantProposal`) is not seen; a view or function that reads proposals is `SchemaGuardTest`'s sweep's subject.
 */
final class PermissionDerivationTableSetTest extends TestCase
{
    private const GRANTS = '/\buser_role\b/i';

    private const PROPOSALS = '/\brole_grant_proposal\b/i';

    /** The readers of grants the domain must reach (design §9 item 6). */
    private const READERS = ['IdentityReader', 'CurrentUserReader', 'LiveGrantReader'];

    #[Test]
    public function noReaderOfGrantsReadsProposals(): void
    {
        $planted = self::offenders(['Planted' => PhpSourceScan::scan(<<<'PHP'
            <?php
            final class Planted
            {
                private const SQL = 'SELECT 1 FROM user_role UNION SELECT 1 FROM role_grant_proposal';

                public function grantsOnly(object $c): void
                {
                    $c->fetchAll('SELECT role_id FROM user_role WHERE user_id = ?');
                }

                public function proposalsOnly(object $c): void
                {
                    $c->fetchAll('SELECT id FROM role_grant_proposal WHERE user_id = ?');
                }
            }
            PHP)]);
        self::assertSame(['Planted: SELECT 1 FROM user_role UNION SELECT 1 FROM role_grant_proposal'], $planted[1],
            'The scan does not see a planted literal naming both tables, or flags a literal naming one.');

        $scans = [];
        foreach (PhpSourceScan::phpFilesUnder(\dirname(__DIR__, 2).'/src') as $path) {
            $scans[basename($path, '.php')] = PhpSourceScan::scan((string) file_get_contents($path));
        }
        [$readers, $offenders] = self::offenders($scans);

        foreach (self::READERS as $reader) {
            self::assertContains($reader, $readers, 'The scan found no user_role literal in '.$reader.', so it is blind to the readers it exists for.');
        }
        self::assertSame([], $offenders,
            "A SQL literal under src/ names both user_role and role_grant_proposal. A pending grant must confer nothing by construction (SECURITY_MODEL.md §5.2.2): no reader of grants may read proposals, and a proposal read is its own literal:\n  ".implode("\n  ", $offenders));
    }

    /**
     * @param array<string, array<string, mixed>> $scans class short name => `PhpSourceScan::scan()`
     *
     * @return array{0: list<string>, 1: list<string>} the classes with a `user_role` literal, and the offending literals
     */
    private static function offenders(array $scans): array
    {
        $readers = [];
        $offenders = [];
        foreach ($scans as $class => $scan) {
            foreach (self::literalsOf($scan) as $value) {
                if (1 !== preg_match(self::GRANTS, $value)) {
                    continue;
                }
                $readers[] = $class;
                if (1 === preg_match(self::PROPOSALS, $value)) {
                    $offenders[] = $class.': '.trim((string) preg_replace('/\s+/', ' ', $value));
                }
            }
        }

        return [array_values(array_unique($readers)), array_values(array_unique($offenders))];
    }

    /**
     * @param array<string, mixed> $scan
     *
     * @return list<string>
     */
    private static function literalsOf(array $scan): array
    {
        $values = [];
        foreach ($scan['methods'] as $method) {
            foreach ($method['literals'] as $literal) {
                $values[] = $literal['value'];
            }
        }
        foreach ([...array_values($scan['constants']), ...array_values($scan['properties'])] as $holder) {
            foreach ($holder['literals'] as $literal) {
                $values[] = $literal['value'];
            }
        }
        foreach ($scan['outside'] as $literal) {
            $values[] = $literal['value'];
        }

        return $values;
    }
}
