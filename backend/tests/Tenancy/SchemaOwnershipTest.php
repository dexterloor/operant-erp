<?php

declare(strict_types=1);

namespace App\Tests\Tenancy;

use App\Platform\Tenancy\SchemaManifest;
use PHPUnit\Framework\Attributes\Test;

/**
 * ARCHITECTURE S-7(a), made checkable by Output 5.
 *
 * -------------------------------------------------------------------------
 * What this closes
 * -------------------------------------------------------------------------
 * `SchemaManifestTest::everyTableNamesAnOwningModuleAndAReason` asserts that
 * `module` is a non-empty string. That is all it could assert while all 29
 * entities lived in one `App\Entity` namespace: no arrangement of the code
 * could contradict the value, so every row could have said `Modules\Sales` and
 * the suite would have stayed green. `SCHEMA.md` §14 recorded the consequence
 * in terms -- *"`SchemaManifest`'s `module` is a declaration, not a check ...
 * until it exists the ownership column is documentation"*.
 *
 * Output 5 moved each entity into the module that owns its table, so the
 * declaration now has something to be wrong about, and this file is the check
 * §14 was waiting for.
 *
 * The rule is `ARCHITECTURE.md` §4.0's, unchanged and not restated: **a module
 * is named by its PHP namespace relative to `App\`**. So the manifest value
 * `Modules\Inventory` is satisfied by an entity whose FQCN begins
 * `App\Modules\Inventory\`, and by nothing else. The sub-layer below that
 * prefix is deliberately not constrained here -- A-7 and the deptrac ruleset
 * own layering, this file owns ownership.
 *
 * -------------------------------------------------------------------------
 * Why the second test exists
 * -------------------------------------------------------------------------
 * A prefix check over "every mapped entity" passes vacuously the moment the
 * entities stop being mapped -- deleting a `mappings:` block in
 * `config/packages/doctrine.yaml` would empty the loop and turn this file
 * green rather than red. `everyTableTheDomainOwnsCarriesAMappedEntity` derives
 * the expected coverage from the manifest instead of restating a count, so a
 * missing mapping is a failure and not a silence.
 *
 * @see \App\Tests\Tenancy\SchemaManifestTest for the catalogue reconciliation
 * @see \App\Tests\Tenancy\DoctrineMappingTest for the ORM/DB divergence pin
 */
final class SchemaOwnershipTest extends TenancyTestCase
{
    /**
     * table name => entity FQCN, for every entity the ORM has metadata for.
     *
     * @return array<string, class-string>
     */
    private function mappedEntities(): array
    {
        $mapped = [];

        foreach ($this->em()->getMetadataFactory()->getAllMetadata() as $meta) {
            $mapped[$meta->getTableName()] = $meta->getName();
        }

        ksort($mapped);

        return $mapped;
    }

    #[Test]
    public function everyMappedEntityLivesInTheModuleTheManifestDeclaresForItsTable(): void
    {
        $mapped = $this->mappedEntities();

        self::assertNotSame([], $mapped,
            'No entity has ORM metadata at all, so every assertion below would hold over an empty loop.');

        $wrong = [];

        foreach ($mapped as $table => $class) {
            self::assertArrayHasKey($table, SchemaManifest::TABLES,
                $class.' maps to `'.$table.'`, which SchemaManifest does not declare. An entity writing an '
                .'undeclared table has no stated owner, which is the S-7(a) hole this file exists to close.');

            // ARCHITECTURE.md §4.0: the module identifier IS the namespace
            // relative to `App\`. Nothing is translated here, and nothing is
            // restated -- the prefix is computed from the manifest value.
            $prefix = 'App\\'.SchemaManifest::TABLES[$table]['module'].'\\';

            if (!str_starts_with($class, $prefix)) {
                $wrong[] = $table.': '.$class.' is not under '.$prefix;
            }
        }

        self::assertSame([], $wrong, implode("\n", $wrong)
            ."\nARCHITECTURE S-7(a): exactly one module writes each table, and `SchemaManifest` names which. "
            .'An entity mapped to a table its own module does not own is either a mis-declared manifest row '
            .'or an entity in the wrong directory, and both are the same defect from the schema\'s side.');
    }

    /**
     * The non-vacuity half, derived rather than restated (Rule 15).
     *
     * The expected coverage is not a count and not a list: it is every table
     * whose deletion class is something other than `n/a`. `SchemaManifest::NA`
     * is documented as *"infrastructure tables nothing in the domain deletes"*
     * -- the migration ledger, the queue, and the three pre-authentication
     * counter tables -- and those are exactly the tables no entity maps,
     * because the domain never loads a row from them. Everything else is a
     * business table, and a business table with no entity would take its own
     * ownership assertion above out of scope silently.
     *
     * If a future table legitimately breaks this correlation -- an ORM entity
     * over a counter table, or a business table read only through DBAL -- this
     * test is the thing that has to be argued with, which is the point.
     */
    #[Test]
    public function everyTableTheDomainOwnsCarriesAMappedEntity(): void
    {
        $expected = [];

        foreach (SchemaManifest::TABLES as $table => $spec) {
            if (SchemaManifest::NA !== $spec['deletion']) {
                $expected[] = $table;
            }
        }

        sort($expected);

        self::assertSame($expected, array_keys($this->mappedEntities()),
            'The set of tables with an ORM entity is no longer the set of tables the domain owns. If a '
            .'mapping block has been dropped from config/packages/doctrine.yaml, '
            .'everyMappedEntityLivesInTheModuleTheManifestDeclaresForItsTable is now passing over a shorter '
            .'loop than it looks like it covers.');
    }
}
