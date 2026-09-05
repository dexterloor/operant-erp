<?php

declare(strict_types=1);

namespace App\Tests\Tenancy;

use App\Entity\InventoryItem;
use App\Entity\Supplier;
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

        [$mappedDiff, $unmappedDiff] = $this->partitionSchemaDiff();

        fwrite(STDERR, "\n  [ORM/DB divergence] ".count($compositeKeys)." composite tenant FK(s), "
            .count($mappedDiff)." statement(s) on mapped tables:\n    ".implode("\n    ", $mappedDiff ?: ['(none)'])."\n");

        $unexplained = array_values(array_filter($mappedDiff, static function (string $sql) use ($allowed): bool {
            foreach ($allowed as $pattern) {
                if (preg_match($pattern, $sql)) {
                    return false;
                }
            }

            return true;
        }));

        self::assertSame([], $unexplained,
            "Divergence between the mapping and the schema that is not the composite foreign key:\n  "
            .implode("\n  ", $unexplained));

        // Both directions: every allowed shape must actually be present, so a
        // silently dropped constraint cannot pass as "nothing diverged".
        self::assertCount(3 * $mappedCompositeKeys, $mappedDiff,
            "Expected exactly three statements per composite foreign key:\n  ".implode("\n  ", $mappedDiff));

        // An unmapped table may only ever be proposed for removal. SchemaTool
        // drops its foreign keys before the table itself, so DROP CONSTRAINT
        // belongs here too -- but nothing that would alter or create anything.
        foreach ($unmappedDiff as $sql) {
            self::assertMatchesRegularExpression(
                '/^(DROP (TABLE|SEQUENCE|INDEX)|ALTER TABLE \\w+ DROP CONSTRAINT)/i', $sql,
                "An unmapped asset produced something other than a removal:\n$sql");
        }
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
        // Two tables here deliberately have no ORM mapping: stock_movement proves
        // ALTER DEFAULT PRIVILEGES reaches later migrations, and
        // messenger_messages belongs to the transport. SchemaTool proposes to drop
        // whatever it does not know about, so those are separated rather than
        // silently tolerated.
        $unmapped = ['stock_movement', 'messenger_messages', 'doctrine_migration_versions'];
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

    #[Test]
    public function anEntityRoundTripsThroughTheOrmInsideATenantTransaction(): void
    {
        $id = $this->tenantContext()->run(self::TENANT_A, function (): string {
            $s = new Supplier(Uuid::v4(), Uuid::fromString(self::TENANT_A), 'Acme');
            $i = new InventoryItem(Uuid::v4(), Uuid::fromString(self::TENANT_A), 'SKU-1');
            $i->setSupplier($s);
            $this->em()->persist($s);
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
            $i = new InventoryItem(Uuid::v4(), Uuid::fromString(self::TENANT_A), 'ORPHAN');
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
        $itemId = $this->tenantContext()->run(self::TENANT_A, function (): string {
            $s = new Supplier(Uuid::v4(), Uuid::fromString(self::TENANT_A), 'Acme');
            $i = new InventoryItem(Uuid::v4(), Uuid::fromString(self::TENANT_A), 'LAZY');
            $i->setSupplier($s);
            $this->em()->persist($s);
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
