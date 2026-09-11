<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * **Step 8-ii — an order line cannot be received beyond what was ordered, at
 * the database.**
 *
 * FLOWS F-3 step 3 blocks over-receipt at the line level, and
 * `GoodsReceiptWriter` refuses it (409 `purchase_order_line.is_not_over_received`)
 * before it writes anything. Until this file the database did not agree:
 * `purchase_order_line_qty_sane` is `quantity_ordered > 0 AND quantity_received
 * >= 0`, so any writer that skipped the check (the next one, a bug, `psql`)
 * could record more stock arriving than was bought. The sales side has had the
 * equivalent since the first schema (`sales_order_line_qty_sane`'s
 * `quantity_fulfilled <= quantity`).
 *
 * A separate named constraint rather than a widened `_qty_sane`, so a refusal
 * names the rule it breaks.
 *
 * **Existing rows.** It validates at add time. Nothing has written
 * `quantity_received` except fixtures (no receipt operation existed), and no
 * database has been deployed. A row that fails is a row that is already wrong.
 *
 * **What it makes a 500 rather than a 409:** `UpdatePurchaseOrderLine` lowering
 * `quantity_ordered` below `quantity_received`. Unreachable: that operation is
 * refused on anything but a draft, and a draft has received nothing.
 */
final class Version20260911000500 extends AbstractMigration
{
    private const CONSTRAINT = 'purchase_order_line_not_over_received';

    public function getDescription(): string
    {
        return 'Step 8-ii: purchase_order_line_not_over_received — quantity_received <= quantity_ordered';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(sprintf(
            'ALTER TABLE purchase_order_line ADD CONSTRAINT %s CHECK (quantity_received <= quantity_ordered)',
            self::CONSTRAINT,
        ));
    }

    public function down(Schema $schema): void
    {
        $this->addSql(sprintf('ALTER TABLE purchase_order_line DROP CONSTRAINT IF EXISTS %s', self::CONSTRAINT));
    }
}
