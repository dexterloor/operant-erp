<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * **Sales step 5, commit 4 — ADR-002 A4.1: grant `erp_app` exactly the INSERT `CreateFulfilment`'s writer
 * issues, and nothing else.**
 *
 * `FulfilmentWriter` issues `INSERT INTO fulfilment` and `INSERT INTO fulfilment_line`. It never updates or
 * row-locks either table, so **no UPDATE** (A4.1: *"a write no `src/` path issues is not granted"*; A4.4 duty 1's
 * word is *"exactly"*), and **no DELETE** — both tables are deletion class `none`. The foreign-key checks from the
 * new rows take `KEY SHARE` on their parents as the RI trigger's owner and need nothing from `erp_app` (measured by
 * `RED`, probe P5, 2026-09-22: both INSERTs succeeded with INSERT-only).
 *
 * The writer's other statements need no grant: `UPDATE sales_order_line` and the status flush of `sales_order`
 * are covered by `Version20260919000300`; `DepleteStock` inserts `stock_movement` and updates `stock_level`, both
 * already held. Measured before and after this migration with `has_table_privilege()` (progress note, C4), and
 * held every run by `GrantFootprintTest` against `SchemaManifest`, which moves in the same commit (A4.4 duty 1).
 */
final class Version20260922000200 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'ADR-002 A4.1: grant erp_app the INSERT CreateFulfilment issues, on fulfilment and fulfilment_line — no UPDATE, no DELETE';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('GRANT INSERT ON fulfilment TO erp_app');
        $this->addSql('GRANT INSERT ON fulfilment_line TO erp_app');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('REVOKE INSERT ON fulfilment FROM erp_app');
        $this->addSql('REVOKE INSERT ON fulfilment_line FROM erp_app');
    }
}
