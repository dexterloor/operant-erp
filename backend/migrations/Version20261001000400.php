<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Sales step 7 — **the return reasons, as the owner ruled them** (Q-I, owner ruling 2026-10-01,
 * `docs/session13/session17-owner-ruling.md`; reasoning `docs/session13/spar-qi-judgement.md`).
 *
 * `Version20261001000100` (R1) wrote design §12 Q-I's PROPOSAL into `sales_return_reason_values`:
 * `customer_returned`, `damaged_in_transit`, `wrong_item_shipped`, `recorded_in_error`. The ruled set is six values,
 * one per party at fault plus a catch-all and OI-2 R3's fixed value:
 * `damaged_in_transit` (carrier), `wrong_item_shipped` (warehouse), `defective` (product), `no_longer_wanted`
 * (customer), `other`, `recorded_in_error`. `customer_returned` is gone (every row here IS a customer return, so it
 * said nothing); it became `other`. R1's docblock sentence calling the set a proposal is superseded by this migration;
 * R1 itself is not edited (a forward migration, never a rewrite of a committed one).
 *
 * **No backfill, deliberately** (the judgement's route): no row exists outside test volumes, and a return is never
 * edited (OI-2). On a database that already holds a `customer_returned` row the re-added CHECK fails loudly — that is
 * the intended answer, not something to paper over with an `UPDATE` of an issued document.
 *
 * Only `recorded_in_error` changes behaviour (writer, OI-2 R3); the other five are descriptive. `SalesReturnWriter::
 * REASONS` holds the same list in the same commit, and `SalesReturnSchemaTest::reasons()` pins the CHECK.
 */
final class Version20261001000400 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Sales step 7: sales_return_reason_values becomes the owner-ruled six reasons (Q-I, 2026-10-01).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE sales_return DROP CONSTRAINT sales_return_reason_values');
        $this->addSql(<<<'SQL'
            ALTER TABLE sales_return ADD CONSTRAINT sales_return_reason_values
                CHECK (reason IN ('damaged_in_transit', 'wrong_item_shipped', 'defective', 'no_longer_wanted', 'other', 'recorded_in_error'))
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE sales_return DROP CONSTRAINT sales_return_reason_values');
        $this->addSql(<<<'SQL'
            ALTER TABLE sales_return ADD CONSTRAINT sales_return_reason_values
                CHECK (reason IN ('customer_returned', 'damaged_in_transit', 'wrong_item_shipped', 'recorded_in_error'))
            SQL);
    }
}
