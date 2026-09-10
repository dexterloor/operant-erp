<?php

declare(strict_types=1);

namespace App\Modules\Purchasing\Application;

use App\Modules\Purchasing\ApiResource\NamedReference;
use App\Modules\Purchasing\ApiResource\PurchaseOrderLineResource;

/**
 * The one place a database row becomes a `PurchaseOrderLineResource`.
 *
 * ---------------------------------------------------------------------------
 * `quantity_outstanding` is computed by the database, not in PHP
 * ---------------------------------------------------------------------------
 * Both source columns are `numeric(19,6)` (S-12) and are carried as decimal
 * **strings** in PHP precisely because they must never become floats. Subtracting
 * them here would mean either `bcsub` — correct, and a second place the scale
 * `6` is written down — or arithmetic on strings PHP would silently coerce.
 * `GREATEST(a - b, 0)` in the SELECT keeps the subtraction on the same numeric
 * type the columns are declared as, and returns it as text like every other
 * numeric on this row.
 *
 * **The `GREATEST` floor is a judgement and is reported as one.** §4.4.3 defines
 * the field as the outstanding quantity and does not say what happens under
 * over-receipt, which `PurchaseOrderLine::recordReceipt()` deliberately permits
 * and `purchase_order_line_qty_sane` deliberately does not forbid. F-3 step 2
 * pre-fills a receiving screen from this number, and a negative pre-fill is a
 * defect wearing a 200.
 */
final class PurchaseOrderLineRow
{
    public const COLUMNS = <<<'SQL'
        pol.id, pol.quantity_ordered, pol.quantity_received, pol.unit_cost,
        pol.line_number, pol.deleted_at,
        -- Cast back to the columns' own type, and it is not cosmetic: without it
        -- GREATEST returns the bare literal `0` with scale 0 on an over-received
        -- line, so one row of a list renders "0" and the next "6.000000". A
        -- client that compares decimal strings -- which S-12 is the reason it
        -- must -- sees two different shapes for the same kind of number.
        -- Measured, and it was caught by
        -- `PurchaseOrderReadTest::theOutstandingQuantityIsOrderedMinusReceivedAndNeverNegative`.
        GREATEST(pol.quantity_ordered - pol.quantity_received, 0)::numeric(19,6) AS quantity_outstanding,
        i.id AS inventory_item_id, i.name AS inventory_item_name
        SQL;

    /**
     * The item join is inner: `inventory_item_id` is `NOT NULL` with a composite
     * foreign key, so a line with no item is not expressible.
     *
     * **It carries no `deleted_at` predicate on `inventory_item`, and that is
     * §5.2a rather than an omission**: an archived item is still the item this
     * line was ordered for — *"documents legitimately reference it, which is the
     * entire reason it is soft-deleted"* — and dropping the row from this list
     * would make a purchase order lose a line because somebody tidied a
     * catalogue.
     */
    public const FROM = <<<'SQL'
        FROM purchase_order_line pol
        JOIN inventory_item i ON i.tenant_id = pol.tenant_id AND i.id = pol.inventory_item_id
        SQL;

    /** @param array<string, mixed> $row */
    public static function toResource(array $row): PurchaseOrderLineResource
    {
        return new PurchaseOrderLineResource(
            id: (string) $row['id'],
            inventoryItem: new NamedReference((string) $row['inventory_item_id'], (string) $row['inventory_item_name']),
            quantity: (string) $row['quantity_ordered'],
            unitCost: (string) $row['unit_cost'],
            quantityReceived: (string) $row['quantity_received'],
            quantityOutstanding: (string) $row['quantity_outstanding'],
            archivedAt: PurchaseOrderRow::timestamp($row['deleted_at']),
        );
    }
}
