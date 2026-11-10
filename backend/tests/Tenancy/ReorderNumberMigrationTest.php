<?php

declare(strict_types=1);

namespace App\Tests\Tenancy;

use DoctrineMigrations\Version20261110000200;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\DriverException;
use Doctrine\DBAL\Schema\Schema;
use PHPUnit\Framework\Attributes\Test;
use Psr\Log\NullLogger;
use Symfony\Component\Uid\Uuid;

require_once \dirname(__DIR__, 2).'/migrations/Version20261110000200.php';

/**
 * **`Version20261110000200`'s pre-check and the remedy it prints, proven for the principal that will run them** (BK-26,
 * items batch C4; first RED M-4, final RED FM-1 — applied as prescribed). The migration principal is `neondb_owner`:
 * the table's owner, NOT superuser, NOT BYPASSRLS (measured), and `inventory_item` is FORCEd. So the remedy is tested as
 * that owner (`$this->owner()`), never as `postgres`, which would pass for the wrong reason.
 *
 * At any HEAD where this test exists the CHECKs already exist, so the test runs the migration's own `down()` SQL, seeds
 * a negative row — seeded as the owner under NO FORCE; no tenant is set until the final read (D-3) — and runs `up()` —
 * all in ONE owner transaction that it ROLLS BACK. The remedy is the migration's `REMEDY_STATEMENTS` (no
 * `BEGIN`/`COMMIT`; a `COMMIT` inside the test would commit `down()` into the shared database, FM-1), and the RAISE
 * text is built from the same list, so the message and this test share one source. A `P0001` aborts the transaction, so
 * the failing `up()` and the control each run inside a SAVEPOINT that is rolled back to.
 */
final class ReorderNumberMigrationTest extends TenancyTestCase
{
    #[Test]
    public function aNegativeRowStopsTheMigrationAndThePrintedRemedyClearsItAsTheOwner(): void
    {
        $owner = $this->owner();
        $owner->beginTransaction();

        try {
            $this->runMigration($owner, 'down');
            self::assertSame(0, $this->checkCount($owner), 'down() left a CHECK in place.');

            // The negative row is seeded as the owner under NO FORCE, so no tenant is ever set in this transaction: the
            // migration runs with none, and a tenant set here could not be cleared again (the sanctioned writer has no
            // clear, and a raw GUC write is refused by TenantContextContainmentTest) — it would let the remedy's UPDATE
            // reach the row without NO FORCE and void the control below (deviation D-3 from FM-1 step 3's wording).
            $item = Uuid::v4()->toRfc4122();
            $owner->executeStatement('ALTER TABLE inventory_item NO FORCE ROW LEVEL SECURITY');
            $owner->executeStatement(
                "INSERT INTO inventory_item (id, tenant_id, sku, name, unit_of_measure_code, currency_code, reorder_point) VALUES (?::uuid, ?::uuid, 'SKU-NEG-MIG', 'Negative', 'EA', 'EUR', -1)",
                [$item, self::TENANT_A],
            );
            $owner->executeStatement('ALTER TABLE inventory_item FORCE ROW LEVEL SECURITY');
            self::assertTrue((bool) $owner->fetchOne("SELECT relforcerowsecurity FROM pg_class WHERE oid = 'inventory_item'::regclass"), 'seed did not restore FORCE');

            // The migration stops with the count and the remedy.
            $owner->executeStatement('SAVEPOINT pre_check');
            $error = null;
            try {
                $this->runMigration($owner, 'up');
            } catch (DriverException $e) {
                $error = $e;
            }
            $owner->executeStatement('ROLLBACK TO SAVEPOINT pre_check');
            self::assertNotNull($error, 'The migration accepted a negative reorder number.');
            self::assertSame('P0001', $error->getSQLState());
            self::assertStringContainsString('inventory_item rows with a negative reorder number: 1. Fix as the database owner, in one transaction: '.Version20261110000200::remedy().' then run the migration again.', $error->getMessage());

            // The remedy's UPDATE, found by its content, never by its position (a dropped entry would shift a position).
            $updates = array_values(array_filter(Version20261110000200::REMEDY_STATEMENTS, static fn (string $s): bool => str_starts_with($s, 'UPDATE ')));
            self::assertCount(1, $updates, 'The remedy does not hold exactly one UPDATE.');
            $update = $updates[0];

            // Control (the fact M-4 rests on): under FORCE, with no tenant set, the remedy's UPDATE alone matches 0 rows.
            $owner->executeStatement('SAVEPOINT control');
            self::assertSame(0, $owner->executeStatement($update), 'Control: the UPDATE alone reached the row under FORCE.');
            $owner->executeStatement('ROLLBACK TO SAVEPOINT control');

            // The remedy as printed, statement by statement; the UPDATE's own row count is the evidence.
            $updated = null;
            foreach (Version20261110000200::REMEDY_STATEMENTS as $statement) {
                $count = $owner->executeStatement($statement);
                if ($statement === $update) {
                    $updated = $count;
                }
            }
            self::assertSame(1, $updated, 'The remedy\'s UPDATE did not fix the one negative row.');

            $this->runMigration($owner, 'up');
            self::assertSame(3, $this->checkCount($owner));
            self::assertTrue((bool) $owner->fetchOne("SELECT relforcerowsecurity FROM pg_class WHERE oid = 'inventory_item'::regclass"), 'FORCE is not on again.');
            self::assertSame('0.000000', $this->asTenant($owner, $item), 'The remedy did not set the negative value to 0.');
        } finally {
            $owner->rollBack();
        }

        self::assertSame(3, $this->checkCount($this->owner()), 'The rollback did not restore the CHECKs.');
        self::assertTrue((bool) $this->owner()->fetchOne("SELECT relforcerowsecurity FROM pg_class WHERE oid = 'inventory_item'::regclass"));
    }

    /** The printed remedy is M-4's text, byte for byte (DATA TO CONFIRM). */
    #[Test]
    public function theRemedyIsTheReviewedText(): void
    {
        self::assertSame(
            'BEGIN; ALTER TABLE inventory_item NO FORCE ROW LEVEL SECURITY; UPDATE inventory_item SET reorder_point = GREATEST(reorder_point, 0), reorder_quantity = GREATEST(reorder_quantity, 0), safety_stock = GREATEST(safety_stock, 0) WHERE reorder_point < 0 OR reorder_quantity < 0 OR safety_stock < 0; ALTER TABLE inventory_item FORCE ROW LEVEL SECURITY; COMMIT;',
            Version20261110000200::remedy(),
        );
    }

    /** One CHECK per column, so a 23514 names the column; each is `>= 0`. */
    #[Test]
    public function thereIsOneNonNegativeCheckPerColumn(): void
    {
        $rows = $this->owner()->fetchAllKeyValue(
            "SELECT conname, pg_get_constraintdef(oid) FROM pg_constraint WHERE conrelid = 'inventory_item'::regclass AND conname LIKE 'inventory_item_%_non_negative' ORDER BY conname",
        );

        self::assertSame([
            'inventory_item_reorder_point_non_negative' => 'CHECK ((reorder_point >= (0)::numeric))',
            'inventory_item_reorder_quantity_non_negative' => 'CHECK ((reorder_quantity >= (0)::numeric))',
            'inventory_item_safety_stock_non_negative' => 'CHECK ((safety_stock >= (0)::numeric))',
        ], $rows);
    }

    private function runMigration(Connection $owner, string $direction): void
    {
        $migration = new Version20261110000200($owner, new NullLogger());
        $migration->{$direction}(new Schema());
        foreach ($migration->getSql() as $query) {
            $owner->executeStatement($query->getStatement(), $query->getParameters(), $query->getTypes());
        }
    }

    private function checkCount(Connection $owner): int
    {
        return (int) $owner->fetchOne("SELECT count(*) FROM pg_constraint WHERE conrelid = 'inventory_item'::regclass AND conname LIKE 'inventory_item_%_non_negative'");
    }

    /** Under FORCE again, the owner reads the row only with its tenant set. */
    private function asTenant(Connection $owner, string $item): string
    {
        $this->tenantContext()->establishOn($owner, self::TENANT_A);

        return (string) $owner->fetchOne('SELECT reorder_point::text FROM inventory_item WHERE id = ?::uuid', [$item]);
    }
}
