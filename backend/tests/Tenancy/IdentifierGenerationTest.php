<?php

declare(strict_types=1);

namespace App\Tests\Tenancy;

use PHPUnit\Framework\Attributes\Test;

/**
 * The half of D-12 the CHECK constraint cannot do.
 *
 * `RED` was right to push on this. The constraint pins the version nibble to 4,
 * which closes the substitution A1.8 named -- UUIDv7 is also type `uuid`, is
 * time-ordered, and is a fashionable choice for index locality, so a type check
 * would not have noticed. It does **not** create entropy: three ids ending
 * `…0001`, `…0002`, `…0003` satisfy it, and the A1.8 oracle is live and
 * reproducible against them.
 *
 * A1.8 said the residual "becomes a database constraint, not a convention". As
 * far as *shape* goes that is true. As far as *unguessability* goes it is not,
 * and the migration used to imply otherwise. Entropy still comes from the
 * generator, so the generator gets the check the claim needs: nothing in the
 * application may mint an identifier from a time-ordered or derived source.
 *
 * Mechanical rather than judgement, and the reason it is worth having: the
 * change that reopens this is a *performance* change made in good faith, by
 * someone who has read that v7 improves index locality and has no idea it is
 * load-bearing here.
 */
final class IdentifierGenerationTest extends TenancyTestCase
{
    /**
     * Every UUID factory that produces something predictable from a clock or a
     * name.
     *
     * The two halves have to agree, and they did not. The static-factory half
     * listed v1, v2, v3, v5, v6 and v7; the class-name half listed only V1, V6
     * and V7, so `new UuidV3($ns, $name)` and `new UuidV5($ns, $name)` walked
     * past a list whose whole purpose is to be exhaustive -- and the SQL half
     * named `uuid_generate_v1` while `uuid_generate_v3` and `uuid_generate_v5`
     * are equally name-derived. V8 is worse than either: it is the
     * caller-supplies-the-bits version, so it can be anything at all. Neither
     * gap was reachable (nothing in the tree used them), which is exactly how a
     * list like this rots. Closed 2026-09-07.
     */
    private const FORBIDDEN = [
        'Uuid::v1(', 'Uuid::v2(', 'Uuid::v3(', 'Uuid::v5(', 'Uuid::v6(', 'Uuid::v7(', 'Uuid::v8(',
        'UuidV1', 'UuidV3', 'UuidV5', 'UuidV6', 'UuidV7', 'UuidV8', 'uuidv7(',
        'uuid_generate_v1', 'uuid_generate_v3', 'uuid_generate_v5',
    ];

    #[Test]
    public function noApplicationCodeMintsATimeOrderedOrDerivedIdentifier(): void
    {
        $offenders = [];

        foreach ($this->phpSources() as $file) {
            $contents = file_get_contents($file);
            foreach (self::FORBIDDEN as $needle) {
                if (str_contains($contents, $needle) && !str_contains($file, 'IdentifierGenerationTest')) {
                    $offenders[] = basename($file).' uses '.$needle;
                }
            }
        }

        self::assertSame([], $offenders,
            "A predictable identifier source reached the application:\n  ".implode("\n  ", $offenders)
            ."\nD-12's CHECK pins the version nibble; it cannot pin randomness. Use Uuid::v4() in PHP and "
            .'gen_random_uuid() in SQL, both of which are 122 random bits.');
    }

    /**
     * And the positive half, so this is not a test about a string: the ids the
     * database actually mints are random, not adjacent.
     *
     * Sorted and differenced, a counter or a timestamp source produces mostly
     * small gaps. 122 random bits over a handful of samples produces gaps in
     * the same order as the space itself.
     */
    #[Test]
    public function theDatabasesOwnGeneratorProducesIdsThatAreNotAdjacent(): void
    {
        $ids = $this->owner()->fetchFirstColumn(
            'SELECT gen_random_uuid()::text FROM generate_series(1, 200) ORDER BY 1');

        $firstBytes = array_map(static fn (string $id): string => substr($id, 0, 8), $ids);

        // A time-ordered generator gives 200 samples sharing a leading prefix;
        // a counter gives 200 identical ones. Random v4 gives 200 distinct.
        self::assertCount(200, array_unique($firstBytes),
            'gen_random_uuid() produced ids sharing a leading prefix, which means this build is not using the '
            .'random generator D-12 assumes.');

        foreach ($ids as $id) {
            self::assertSame('4', $id[14], 'gen_random_uuid() did not produce a version 4 UUID.');
        }
    }

    /** @return list<string> */
    private function phpSources(): array
    {
        $files = [];
        $root = \dirname(__DIR__, 2);

        foreach (['/src', '/migrations', '/tests'] as $dir) {
            $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root.$dir));
            foreach ($iterator as $file) {
                if ($file->isFile() && 'php' === $file->getExtension()) {
                    $files[] = $file->getPathname();
                }
            }
        }

        self::assertGreaterThan(30, \count($files), 'The source scan found almost nothing, so it proves nothing.');

        return $files;
    }
}
