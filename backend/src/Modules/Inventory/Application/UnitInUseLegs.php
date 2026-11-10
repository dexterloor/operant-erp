<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Application;

/**
 * **The legs of BK-24's "this item's unit is in use" rule — names and statements only** (items batch C3, 2026-11-10;
 * `docs/session13/items-batch-design.md` "Revision 1 — FINAL" C3, first RED M-3; `docs/session13/items-c3-deviations-red.md`
 * D-7). A constants class: it holds no connection and issues no statement. {@see ItemWriter} aliases both constants
 * (`UNIT_IN_USE_LEGS`, `OWN_LEGS`) and runs the statements itself.
 *
 * **Why the names live here and not in `ItemWriter`.** The stock-write scans in `WriteOwnershipTest` treat a bare
 * `'stock_level'` / `'stock_movement'` literal as the shape of a DBAL convenience writer (`$connection->insert('stock_movement',
 * …)`), and exempt only classes that issue no statement. Keeping the names in `ItemWriter` would have meant exempting a
 * class that issues statements — so any later stock write added to it would pass the G-44 / E-26 review point unseen.
 * Here the exemption covers a class that cannot write anything.
 */
final class UnitInUseLegs
{
    /**
     * Every table whose rows name an item, sorted — exactly the tables with a foreign key to `inventory_item`
     * (`ItemUnitInUseTest::everyTableThatNamesAnItemIsALegOfTheRule`; `PO` TO CONFIRM). Deleted order lines count.
     */
    public const TABLES = ['purchase_order_line', 'sales_order_line', 'stock_level', 'stock_movement', 'supplier_product'];

    /** The two legs that are Inventory's own tables: any row, any quantity (a zero stock row was a stock row). */
    public const OWN = [
        'stock_level' => 'SELECT EXISTS (SELECT 1 FROM stock_level WHERE inventory_item_id = ?::uuid)',
        'stock_movement' => 'SELECT EXISTS (SELECT 1 FROM stock_movement WHERE inventory_item_id = ?::uuid)',
    ];

    private function __construct()
    {
    }
}
