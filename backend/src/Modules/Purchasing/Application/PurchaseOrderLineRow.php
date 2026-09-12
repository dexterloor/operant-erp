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
 * ---------------------------------------------------------------------------
 * ⚠ The floor at zero was REMOVED on 2026-09-12, and the claim under it was
 * false when it was written
 * ---------------------------------------------------------------------------
 * This column read `GREATEST(ordered - received, 0)::numeric(19,6)`, described
 * as a judgement §4.4.3 does not make, on the stated ground that over-receipt
 * is *"a real event `PurchaseOrderLine::recordReceipt()` deliberately permits
 * and `purchase_order_line_qty_sane` deliberately does not forbid"*. **Step
 * 8-ii made both halves of that false in the same commit that left the sentence
 * standing:** `Version20260911000500` added
 * `purchase_order_line_not_over_received` (`quantity_received <=
 * quantity_ordered`), so the database forbids it outright, and
 * `PurchaseOrderLine::recordReceipt()` had no caller and is now deleted.
 *
 * **So the floor was unreachable — and, measured rather than assumed, untested:
 * removing the `GREATEST` left the whole suite green, and so did removing the
 * `::numeric(19,6)` cast that existed only to undo it** (`GREATEST` resolves the
 * bare literal `0` at scale 0, so an over-received line would have rendered
 * `"0"` where its neighbour rendered `"6.000000"` — a real defect, on a row that
 * can no longer exist). Unreachable code that no test can distinguish is the
 * shape this project keeps finding as a fake green, so it is gone rather than
 * commented.
 *
 * **What holds the invariant now is a constraint with a test of its own**:
 * `GoodsReceiptCreateTest::theDatabaseRefusesAnOverReceivedOrderLineWhateverWritesIt`
 * writes the row directly as the owner and requires a `23514`. The subtraction
 * of two `numeric(19,6)` columns is itself scale 6, which
 * `PurchaseOrderReadTest` pins by asserting `"6.000000"` and `"0.000000"`
 * literally. **If `purchase_order_line_not_over_received` is ever dropped — and
 * a product ruling could drop it, since a supplier really can send more than
 * was ordered — the floor must come back with it**, because F-3 step 2 pre-fills
 * a receiving screen from this number and a negative pre-fill is a defect
 * wearing a 200.
 */
final class PurchaseOrderLineRow
{
    public const COLUMNS = <<<'SQL'
        pol.id, pol.quantity_ordered, pol.quantity_received, pol.unit_cost,
        pol.line_number, pol.deleted_at,
        -- No clamp and no cast: purchase_order_line_not_over_received keeps
        -- received <= ordered, and numeric(19,6) minus numeric(19,6) is already
        -- scale 6, which is the shape S-12 makes a client compare on. See the
        -- class docblock before adding either back.
        pol.quantity_ordered - pol.quantity_received AS quantity_outstanding,
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
