<?php

declare(strict_types=1);

namespace App\Tests\Tenancy;

use App\Modules\Inventory\Infrastructure\Entity\InventoryItem;
use App\Modules\Suppliers\Infrastructure\Entity\Supplier;
use Doctrine\ORM\EntityNotFoundException;
use Doctrine\ORM\Tools\SchemaValidator;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Uid\Uuid;

/**
 * The mapping decision, pinned.
 *
 * ADR-002 §5 wants FOREIGN KEY (tenant_id, supplier_id) REFERENCES
 * supplier (tenant_id, id), which asks tenant_id to be both a mapped field and
 * half a join. Three candidate mappings were measured (see the W0-a report);
 * this locks in the one that survived and records why the others could not.
 */
final class DoctrineMappingTest extends TenancyTestCase
{
    #[Test]
    public function theOrmMappingIsValid(): void
    {
        $errors = (new SchemaValidator($this->em()))->validateMapping();
        self::assertSame([], $errors, json_encode($errors, JSON_PRETTY_PRINT));
    }

    /**
     * The accepted divergence, asserted as an invariant rather than as a list.
     *
     * A list of statements is fine for two tables and useless for forty: the diff
     * grows with every module until it is a wall of noise, and the response to a
     * wall of noise is to switch the check off. So this derives what the diff is
     * *allowed* to contain from the catalogue itself.
     *
     * The invariant, in both directions:
     *
     *   every composite foreign key in the database is (tenant_id, X)
     *     -> parent (tenant_id, id)                                  -- ADR-002 §5
     *   every one of them has a matching single-column ORM association
     *     on X referencing parent.id                                 -- the mapping decision
     *   the ORM/DB diff contains exactly the three statements each of those
     *     produces, and nothing else
     *
     * Add a module and this needs no edit. Add a real drift and it fails.
     */
    #[Test]
    public function everyCompositeForeignKeyHasAMatchingOrmAssociationAndNothingElseDiverges(): void
    {
        $compositeKeys = $this->compositeTenantForeignKeys();
        self::assertNotSame([], $compositeKeys,
            'No composite tenant foreign key found at all -- this test would pass vacuously.');

        $allowed = [];
        $mappedCompositeKeys = 0;

        foreach ($compositeKeys as $fk) {
            $table = $fk['tbl'];
            $parent = $fk['parent'];
            $column = $fk['child_column'];

            // ADR-002 §5's shape, read back from the catalogue.
            self::assertSame(['tenant_id', $column], $fk['cols'], "$table.{$fk['conname']} is not led by tenant_id.");
            self::assertSame(['tenant_id', 'id'], $fk['refcols'], "$table.{$fk['conname']} does not reference $parent (tenant_id, id).");

            // The association check only applies where an entity exists. A
            // tenant-scoped table with no mapping (stock_movement) still has to
            // obey ADR-002 §5 above, but produces no diff to account for.
            if (!$this->isMappedTable($table)) {
                continue;
            }

            self::assertTrue(
                $this->hasSingleColumnAssociation($table, $column, $parent),
                "The composite key $table.{$fk['conname']} has no matching single-column ORM association "
                ."on $column referencing $parent.id. Either the mapping is missing or the constraint is orphaned.",
            );
            ++$mappedCompositeKeys;

            $t = preg_quote($table, '/');
            $c = preg_quote($column, '/');
            $pt = preg_quote($parent, '/');
            $allowed[] = '/^ALTER TABLE '.$t.' DROP CONSTRAINT '.preg_quote($fk['conname'], '/').'$/';
            $allowed[] = '/^ALTER TABLE '.$t.' ADD CONSTRAINT \w+ FOREIGN KEY \('.$c.'\) REFERENCES '.$pt.' \(id\)/';
            $allowed[] = '/^CREATE INDEX \w+ ON '.$t.' \('.$c.'\)$/';
        }

        // The second unmappable constraint, and it is not a composite key.
        // Every tenant-scoped table carries FOREIGN KEY (tenant_id) REFERENCES
        // tenant (id), which is single-column and so invisible to the query
        // above -- and just as unmappable, because mapping it would make
        // tenantId an association, which is A1.6 exactly.
        $registryKeys = $this->registryForeignKeysOnMappedTables();
        foreach ($registryKeys as $table => $conname) {
            $allowed[] = '/^ALTER TABLE '.preg_quote($table, '/').' DROP CONSTRAINT '.preg_quote($conname, '/').'$/';
        }

        [$mappedDiff, $unmappedDiff] = $this->partitionSchemaDiff();

        fwrite(STDERR, "\n  [ORM/DB divergence] ".count($compositeKeys)." composite tenant FK(s) + "
            .count($registryKeys)." registry FK(s), ".count($mappedDiff)." statement(s) on mapped tables\n");

        // DBAL synthesises an index for every foreign key no declared index
        // exactly fulfils, during introspection, and names it IDX_<hash>. Each
        // of the constraints above therefore drags one of these along. They are
        // not indexes -- nothing in the catalogue has these names -- so the
        // pattern is allowed only after that is checked, rather than trusted.
        $syntheticDrops = [];
        foreach ($mappedDiff as $sql) {
            if (preg_match('/^DROP INDEX (IDX_[0-9A-F]+)$/', $sql, $m)) {
                $syntheticDrops[] = $m[1];
            }
        }

        if ([] !== $syntheticDrops) {
            $real = $this->owner()->fetchFirstColumn(
                'SELECT indexname FROM pg_indexes WHERE schemaname = \'public\' AND indexname = ANY(string_to_array(?, \',\'))',
                [implode(',', $syntheticDrops)]);

            self::assertSame([], $real,
                "A DROP INDEX in the diff names an index that really exists, so it is not a DBAL artefact:\n  "
                .implode("\n  ", $real));

            $allowed[] = '/^DROP INDEX IDX_[0-9A-F]+$/';
        }

        $unexplained = array_values(array_filter($mappedDiff, static function (string $sql) use ($allowed): bool {
            foreach ($allowed as $pattern) {
                if (preg_match($pattern, $sql)) {
                    return false;
                }
            }

            return true;
        }));

        self::assertSame([], $unexplained,
            "Divergence between the mapping and the schema that is not an unmappable tenant foreign key:\n  "
            .implode("\n  ", $unexplained));

        // Both directions, counted by statement class rather than by a single
        // total: a total would let a silently dropped constraint be masked by an
        // extra synthetic index. Every unmappable constraint must produce
        // exactly one DROP, and every mapped association exactly one ADD and one
        // CREATE INDEX.
        $count = static function (array $diff, string $pattern): int {
            return \count(array_filter($diff, static fn (string $sql): bool => 1 === preg_match($pattern, $sql)));
        };

        self::assertSame(
            $mappedCompositeKeys + \count($registryKeys),
            $count($mappedDiff, '/^ALTER TABLE \w+ DROP CONSTRAINT /'),
            'A constraint that should be in the diff is missing, which means it is missing from the database.');
        self::assertSame($mappedCompositeKeys, $count($mappedDiff, '/^ALTER TABLE \w+ ADD CONSTRAINT /'));
        self::assertSame($mappedCompositeKeys, $count($mappedDiff, '/^CREATE INDEX /'));

        // An unmapped table may only ever be proposed for removal. SchemaTool
        // drops its foreign keys before the table itself, so DROP CONSTRAINT
        // belongs here too -- but nothing that would alter or create anything.
        foreach ($unmappedDiff as $sql) {
            self::assertMatchesRegularExpression(
                '/^(DROP (TABLE|SEQUENCE|INDEX)|ALTER TABLE \\w+ DROP CONSTRAINT)/i', $sql,
                "An unmapped asset produced something other than a removal:\n$sql");
        }
    }

    /**
     * The single-column tenant_id -> tenant (id) key on every mapped
     * tenant-scoped table.
     *
     * @return array<string, string> table => constraint name
     */
    private function registryForeignKeysOnMappedTables(): array
    {
        $rows = $this->owner()->fetchAllKeyValue(<<<'SQL'
            SELECT c.relname, con.conname
            FROM pg_constraint con
            JOIN pg_class c ON c.oid = con.conrelid
            JOIN pg_class p ON p.oid = con.confrelid
            JOIN pg_namespace n ON n.oid = c.relnamespace
            WHERE n.nspname = 'public' AND con.contype = 'f'
              AND array_length(con.conkey, 1) = 1
              AND p.relname = 'tenant'
            ORDER BY 1
            SQL);

        return array_filter($rows, fn (string $conname, string $table): bool => $this->isMappedTable($table),
            ARRAY_FILTER_USE_BOTH);
    }

    /** @return list<array{tbl:string,parent:string,conname:string,cols:list<string>,refcols:list<string>,child_column:string}> */
    private function compositeTenantForeignKeys(): array
    {
        $rows = $this->owner()->fetchAllAssociative(<<<'SQL'
            SELECT c.relname AS tbl, p.relname AS parent, con.conname,
                   (SELECT array_agg(a.attname ORDER BY k.ord)
                      FROM unnest(con.conkey) WITH ORDINALITY k(attnum, ord)
                      JOIN pg_attribute a ON a.attrelid = con.conrelid AND a.attnum = k.attnum) AS cols,
                   (SELECT array_agg(a.attname ORDER BY k.ord)
                      FROM unnest(con.confkey) WITH ORDINALITY k(attnum, ord)
                      JOIN pg_attribute a ON a.attrelid = con.confrelid AND a.attnum = k.attnum) AS refcols
            FROM pg_constraint con
            JOIN pg_class c ON c.oid = con.conrelid
            JOIN pg_class p ON p.oid = con.confrelid
            JOIN pg_namespace n ON n.oid = c.relnamespace
            WHERE n.nspname = 'public' AND con.contype = 'f'
              AND array_length(con.conkey, 1) > 1
            ORDER BY 1, 3
            SQL);

        return array_map(static function (array $r): array {
            $cols = array_map('trim', explode(',', trim((string) $r['cols'], '{}')));
            $refcols = array_map('trim', explode(',', trim((string) $r['refcols'], '{}')));

            return [
                'tbl' => $r['tbl'],
                'parent' => $r['parent'],
                'conname' => $r['conname'],
                'cols' => $cols,
                'refcols' => $refcols,
                'child_column' => end($cols),
            ];
        }, $rows);
    }

    /**
     * Every table in the schema that no entity maps.
     *
     * @return list<string>
     */
    private function unmappedTables(): array
    {
        $mapped = [];
        foreach ($this->em()->getMetadataFactory()->getAllMetadata() as $meta) {
            $mapped[] = $meta->getTableName();
        }

        $all = $this->owner()->fetchFirstColumn(<<<'SQL'
            SELECT c.relname FROM pg_class c JOIN pg_namespace n ON n.oid = c.relnamespace
            WHERE n.nspname = 'public' AND c.relkind IN ('r', 'p')
            ORDER BY 1
            SQL);

        return array_values(array_diff($all, $mapped));
    }

    private function isMappedTable(string $table): bool
    {
        foreach ($this->em()->getMetadataFactory()->getAllMetadata() as $meta) {
            if ($meta->getTableName() === $table) {
                return true;
            }
        }

        return false;
    }

    private function hasSingleColumnAssociation(string $table, string $column, string $parentTable): bool
    {
        foreach ($this->em()->getMetadataFactory()->getAllMetadata() as $meta) {
            if ($meta->getTableName() !== $table) {
                continue;
            }

            foreach ($meta->getAssociationMappings() as $association) {
                if (!$meta->isSingleValuedAssociation($association->fieldName) || !$association->isOwningSide()) {
                    continue;
                }

                $joinColumns = $association->joinColumns ?? [];
                if (1 !== \count($joinColumns)) {
                    continue;
                }

                $target = $this->em()->getClassMetadata($association->targetEntity);

                if ($joinColumns[0]->name === $column
                    && 'id' === $joinColumns[0]->referencedColumnName
                    && $target->getTableName() === $parentTable) {
                    return true;
                }
            }
        }

        return false;
    }

    /** @return array{0: list<string>, 1: list<string>} */
    private function partitionSchemaDiff(): array
    {
        // Derived, not listed. Some tables deliberately have no ORM mapping --
        // the Messenger transport's own table, the migration ledger, and the two
        // pre-authentication counter tables, which are reached through DBAL on a
        // different principal entirely. SchemaTool proposes to drop whatever it
        // does not know about, so those are separated rather than silently
        // tolerated; but a hardcoded list of them would need editing every time
        // a module lands, which is how a check stops being read.
        $unmapped = $this->unmappedTables();
        $mappedDiff = [];
        $unmappedDiff = [];

        foreach ((new SchemaValidator($this->em()))->getUpdateSchemaList() as $sql) {
            $isUnmapped = false;
            foreach ($unmapped as $asset) {
                if (str_contains($sql, $asset)) {
                    $isUnmapped = true;
                }
            }
            $isUnmapped ? $unmappedDiff[] = $sql : $mappedDiff[] = $sql;
        }

        return [$mappedDiff, $unmappedDiff];
    }

    /**
     * A1.6 as an invariant over every entity, rather than over the two the
     * slice happened to have.
     *
     * The rejected mapping is seductive precisely because it validates cleanly:
     * `doctrine:schema:validate` is green, the DDL it emits is exactly ADR-002
     * §5's, and only then does it write the wrong tenant. So the check cannot be
     * "does the mapping validate" -- it has to be the three structural facts
     * that distinguish the shape that works from the shape that does not, and it
     * has to run over all twenty-nine classes, because it takes one to reopen it.
     */
    #[Test]
    public function noEntityAnywhereMakesTenantIdPartOfAnIdentifierOrAJoin(): void
    {
        $checked = 0;

        foreach ($this->em()->getMetadataFactory()->getAllMetadata() as $meta) {
            if (!$meta->hasField('tenantId')) {
                continue;
            }
            ++$checked;
            $entity = $meta->getName();

            // 1. The identifier is scalar and is not tenant_id.
            self::assertSame(['id'], $meta->getIdentifierFieldNames(),
                "$entity has a composite or non-standard identifier. A composite ORM identifier validates "
                .'cleanly, emits exactly ADR-002 §5\'s DDL, and then silently writes the wrong tenant -- and it '
                .'propagates into every find(), every DQL bind and every API Platform IRI.');

            // 2. tenant_id is a real mapped column, not a shadow of an association.
            self::assertSame('tenant_id', $meta->getColumnName('tenantId'), "$entity does not map tenantId to tenant_id.");

            foreach ($meta->getAssociationMappings() as $association) {
                // 3. No association owns the tenant column, and none is composite.
                foreach ($association->joinColumns ?? [] as $joinColumn) {
                    self::assertNotSame('tenant_id', $joinColumn->name,
                        "$entity::{$association->fieldName} owns the tenant_id column. The association then wins "
                        .'over the field, so a parent in another tenant overrides an explicitly-set tenantId and '
                        .'the INSERT is accepted storing the wrong one (A1.6).');
                }

                if ($association->isOwningSide() && $meta->isSingleValuedAssociation($association->fieldName)) {
                    self::assertCount(1, $association->joinColumns ?? [],
                        "$entity::{$association->fieldName} is a multi-column association. The composite key is "
                        .'the migration\'s job; the ORM keeps single-column joins.');
                }
            }
        }

        self::assertGreaterThan(20, $checked,
            'Too few tenant-scoped entities were inspected for this to be covering the schema.');
    }

    /**
     * The supplier is a fixture written as owner SQL and loaded with `find()`: no
     * `src/` path writes `supplier`, so `erp_app` (the entity manager's connection)
     * holds SELECT on it and nothing else (ADR-002 Amendment 4). The round trip
     * under test is the `InventoryItem` ORM insert and read, still as `erp_app`.
     */
    #[Test]
    public function anEntityRoundTripsThroughTheOrmInsideATenantTransaction(): void
    {
        $supplierId = $this->seedSupplier(self::TENANT_A, 'Acme');

        $id = $this->tenantContext()->run(self::TENANT_A, function () use ($supplierId): string {
            $s = $this->em()->find(Supplier::class, Uuid::fromString($supplierId));
            $i = $this->newItem(self::TENANT_A, 'SKU-1');
            $i->setSupplier($s);
            $this->em()->persist($i);
            $this->em()->flush();

            return $i->getId()->toRfc4122();
        });

        $this->em()->clear();

        $sku = $this->tenantContext()->run(self::TENANT_A, fn (): string =>
            $this->em()->find(InventoryItem::class, Uuid::fromString($id))->getSku());

        self::assertSame('SKU-1', $sku);
    }

    /**
     * The case that eliminated the composite-primary-key mapping: with tenant_id
     * shared between the identifier and the join, Doctrine writes the column from
     * the association, so a null parent nulls the tenant. Here the parent is
     * optional and tenant_id must still be written correctly.
     */
    #[Test]
    public function aChildWithNoParentStillCarriesItsOwnTenantId(): void
    {
        $id = $this->tenantContext()->run(self::TENANT_A, function (): string {
            $i = $this->newItem(self::TENANT_A, 'ORPHAN');
            $this->em()->persist($i);
            $this->em()->flush();

            return $i->getId()->toRfc4122();
        });

        self::assertSame(1, $this->countAsOwnerForTenant(
            self::TENANT_A, 'SELECT count(*) FROM inventory_item WHERE id = ?::uuid AND supplier_id IS NULL', [$id]));
    }

    #[Test]
    public function findUsesTheScalarIdentifierAndNotACompositeOne(): void
    {
        $meta = $this->em()->getClassMetadata(InventoryItem::class);
        self::assertSame(['id'], $meta->getIdentifierFieldNames(),
            'A composite ORM identifier would propagate into every repository lookup and every API Platform IRI.');
    }

    /**
     * Extension from RED via LEAD, test 4. Whether the tenant transaction may
     * span a whole HTTP request depends on what happens when a lazy association
     * is touched after it closes. Recorded, not summarised.
     */
    #[Test]
    public function touchingALazyAssociationAfterTheTenantTransactionClosesFails(): void
    {
        // Supplier as owner SQL, for the reason on the round-trip test above.
        $supplierId = $this->seedSupplier(self::TENANT_A, 'Acme');

        $itemId = $this->tenantContext()->run(self::TENANT_A, function () use ($supplierId): string {
            $s = $this->em()->find(Supplier::class, Uuid::fromString($supplierId));
            $i = $this->newItem(self::TENANT_A, 'LAZY');
            $i->setSupplier($s);
            $this->em()->persist($i);
            $this->em()->flush();

            return $i->getId()->toRfc4122();
        });

        $this->em()->clear();

        $item = $this->tenantContext()->run(self::TENANT_A, fn (): InventoryItem =>
            $this->em()->find(InventoryItem::class, Uuid::fromString($itemId)));

        // Transaction is committed; there is no tenant context on the connection now.
        $thrown = null;
        try {
            $item->getSupplier()->getName();
        } catch (\Throwable $e) {
            $thrown = $e;
        }

        fwrite(STDERR, sprintf(
            "\n  [lazy-load after transaction close] %s\n  %s\n",
            $thrown === null ? 'NO EXCEPTION -- association resolved' : $thrown::class,
            $thrown?->getMessage() ?? '(supplier name: '.$item->getSupplier()->getName().')',
        ));

        self::assertInstanceOf(EntityNotFoundException::class, $thrown,
            'A lazy association resolved outside the tenant transaction. Either RLS did not apply to the proxy load, '
            .'or the context outlived its transaction.');
    }
}
