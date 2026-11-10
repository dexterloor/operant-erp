<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * **A reorder point, reorder quantity or safety stock can no longer be negative** (BK-26, items batch C4, 2026-11-10;
 * `docs/session13/items-batch-design.md` Revision 0 §1 C4 and "Revision 1 — FINAL" C4; first RED M-4 and final RED FM-1).
 *
 * `ItemWriter` refuses a negative value first (422 `not_a_decimal`); these three CHECKs refuse it for SQL as `erp_app`.
 * One CHECK per column, so a 23514 names the column it is about. They never clamp: a stored negative stops this migration.
 *
 * One transaction (DDL is transactional; `ALTER TABLE` holds ACCESS EXCLUSIVE until COMMIT):
 *  1. `NO FORCE ROW LEVEL SECURITY` — the migration principal (`neondb_owner`) owns the table and is neither superuser nor
 *     BYPASSRLS, so under FORCE, with no `app.tenant_id`, its count would see 0 rows and the pre-check would pass over
 *     negatives the CHECKs then refuse with a bare error (`Version20261022020000` step 2 records the same fact);
 *  2. the pre-check (`preCheck()`): counts the rows with any of the three below zero and, if there are any, stops with the
 *     count and a remedy the database owner can run as given (`REMEDY_STATEMENTS`, wrapped in one transaction). The
 *     remedy itself switches FORCE off for its UPDATE: under FORCE the owner's UPDATE matches 0 rows
 *     (`ReorderNumberMigrationTest` proves both halves, as `neondb_owner`);
 *  3. `FORCE ROW LEVEL SECURITY` again;
 *  4. the three CHECKs, validated by the heap scan (RLS hides no row from it).
 *
 * `down()` drops the three CHECKs. FORCE stays on. No grant.
 */
final class Version20261110000200 extends AbstractMigration
{
    /**
     * The owner-runnable remedy, one statement per entry, with no `BEGIN`/`COMMIT` so a test can run it inside its own
     * rolled-back transaction (final RED FM-1). The RAISE text wraps it as `BEGIN; …; COMMIT;`.
     */
    public const REMEDY_STATEMENTS = [
        'ALTER TABLE inventory_item NO FORCE ROW LEVEL SECURITY',
        'UPDATE inventory_item SET reorder_point = GREATEST(reorder_point, 0), reorder_quantity = GREATEST(reorder_quantity, 0), safety_stock = GREATEST(safety_stock, 0) WHERE reorder_point < 0 OR reorder_quantity < 0 OR safety_stock < 0',
        'ALTER TABLE inventory_item FORCE ROW LEVEL SECURITY',
    ];

    /** The three CHECKs, one per column. */
    public const CHECKS = [
        'inventory_item_reorder_point_non_negative' => 'reorder_point',
        'inventory_item_reorder_quantity_non_negative' => 'reorder_quantity',
        'inventory_item_safety_stock_non_negative' => 'safety_stock',
    ];

    public static function remedy(): string
    {
        return 'BEGIN; '.implode('; ', self::REMEDY_STATEMENTS).'; COMMIT;';
    }

    /** The pre-check `DO` block; public so a test runs THIS statement, never a transcription. */
    public static function preCheck(): string
    {
        $message = 'inventory_item rows with a negative reorder number: %. Fix as the database owner, in one transaction: '
            .self::remedy().' then run the migration again.';

        return <<<SQL
            DO \$\$
            DECLARE
                negative bigint;
            BEGIN
                SELECT count(*) INTO negative FROM inventory_item
                 WHERE reorder_point < 0 OR reorder_quantity < 0 OR safety_stock < 0;
                IF negative > 0 THEN
                    RAISE EXCEPTION '{$message}', negative;
                END IF;
            END
            \$\$
            SQL;
    }

    public function getDescription(): string
    {
        return 'BK-26: refuse negative reorder numbers on inventory_item (three CHECKs, after a pre-check that names the remedy)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE inventory_item NO FORCE ROW LEVEL SECURITY');
        $this->addSql(self::preCheck());
        $this->addSql('ALTER TABLE inventory_item FORCE ROW LEVEL SECURITY');
        foreach (self::CHECKS as $name => $column) {
            $this->addSql(\sprintf('ALTER TABLE inventory_item ADD CONSTRAINT %s CHECK (%s >= 0)', $name, $column));
        }
    }

    public function down(Schema $schema): void
    {
        foreach (array_keys(self::CHECKS) as $name) {
            $this->addSql(\sprintf('ALTER TABLE inventory_item DROP CONSTRAINT %s', $name));
        }
    }
}
