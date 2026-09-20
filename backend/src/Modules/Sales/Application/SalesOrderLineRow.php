<?php

declare(strict_types=1);

namespace App\Modules\Sales\Application;

use App\Modules\Sales\ApiResource\SalesItemReference;
use App\Modules\Sales\ApiResource\SalesOrderLineResource;
use App\Modules\Sales\Infrastructure\Entity\SalesOrder;

/**
 * The one place a database row becomes a `SalesOrderLineResource`.
 *
 * ---------------------------------------------------------------------------
 * `quantity_outstanding` — V2-C-14(b), and the obvious implementation is wrong
 * ---------------------------------------------------------------------------
 * **The decision was taken on 2026-09-19 by `CancelSalesOrder`, which shipped
 * first of the two rows that shared the trigger:** this figure reads **0** when
 * the parent order's status is `cancelled`; it is a **derivation in this SQL**;
 * there is **no `quantity_cancelled` column** and none may be added to satisfy
 * it, because §8.3's OI-2 block defines the cancelled remainder as *"derived and
 * never written into `quantity`"* and a column would write it.
 *
 * **The purchase analogue is NOT the model here.** `PurchaseOrderLineRow::COLUMNS`
 * computes `quantity_ordered - quantity_received` with no status clause, because
 * a cancelled purchase order's lines are not read for outstanding work. Copying
 * that expression across ships the defect: a cancelled order's lines would keep
 * reporting work owed, which F-5 step 4 pre-fills from and a reader would take as
 * a commitment the tenant still has.
 *
 * ---------------------------------------------------------------------------
 * Today the status clause does ALL the work and the subtraction does none
 * ---------------------------------------------------------------------------
 * **Nothing under `src/` writes `sales_order_line.quantity_fulfilled`** —
 * `docs/RESIDUAL_GAPS.md` **G-7**, whose trigger list names `CreateFulfilment`
 * first. So every non-cancelled line reports its full `quantity`, and the
 * `partially_fulfilled` half of the expression is a **forward declaration** with
 * the same hard trigger `SalesOrder::cancel()`'s docblock already names. That
 * means the defect this expression avoids is at its **widest** right now, not its
 * narrowest: without the `CASE`, a cancelled order would report its entire
 * quantity as outstanding.
 *
 * ---------------------------------------------------------------------------
 * ⚠ The `::numeric(19,6)` cast on the zero is load-bearing, not decoration
 * ---------------------------------------------------------------------------
 * Measured against the live cluster:
 *
 *     CASE WHEN true THEN 0                  ... END  ->  "0"          (scale 0)
 *     CASE WHEN true THEN 0::numeric(19,6)   ... END  ->  "0.000000"   (scale 6)
 *
 * A bare `0` renders `"0"` beside its neighbour's `"0.000000"` — byte-for-byte
 * the defect `PurchaseOrderLineRow`'s docblock records its withdrawn
 * `GREATEST(…, 0)` as having had (*"`GREATEST` resolves the bare literal `0` at
 * scale 0"*). `WireScaleTest::theScalesSection443NamesAreTheScalesTheWireCarries`
 * holds the whole artefact's distinct scale set to the `{4, 6}` API-23c names, so
 * a third value is red — **but that guard is not durable**: the first legitimate
 * scale-0 or scale-2 field anywhere on this API disarms it for this column. The
 * literal `"0.000000"` assertion in `SalesOrderLineReadTest` is what actually
 * holds this, and it is not optional. **Do not delete the cast because it looks
 * redundant.**
 *
 * ---------------------------------------------------------------------------
 * No `GREATEST(…, 0)` floor, and that is a database fact
 * ---------------------------------------------------------------------------
 * Measured: `sales_order_line_qty_sane CHECK (quantity > 0 AND quantity_fulfilled
 * >= 0 AND quantity_fulfilled <= quantity)`. So the subtraction cannot go
 * negative and a floor would be unreachable code no test could distinguish —
 * exactly the fake-green shape `PurchaseOrderLineRow` records having removed.
 * **If `sales_order_line_qty_sane` is ever relaxed, the floor must come back with
 * it**, because F-5 step 4 pre-fills a picking screen from this number and a
 * negative pre-fill is a defect wearing a 200.
 *
 * ---------------------------------------------------------------------------
 * ⚠ CARRIED, NOT SOLVED: the archived parent (`RESIDUAL_GAPS.md` CD-4)
 * ---------------------------------------------------------------------------
 * V2-C-14(b) ruled the `cancelled` branch and only that branch. An order archived
 * while `confirmed` keeps `status = 'confirmed'`, so its lines still report
 * outstanding work on a deleted document. That is **unreachable today** —
 * `DeleteSalesOrder` is unbuilt — and it is not this output's to rule. It is
 * carried as `RESIDUAL_GAPS.md` **CD-4** with `DeleteSalesOrder` as its hard
 * trigger, and by `SalesOrderLineReadTest::theArchivedParentBranchIsUnruledAndUnreachable`,
 * which reddens the moment a delete operation on this resource is built.
 */
final class SalesOrderLineRow
{
    /**
     * The `CASE`'s status is **bound**, never written as a SQL literal (API-15,
     * and `noGuardEnumeratesAStatusAsAStringLiteral`), so a change to the entity's
     * constant moves this expression with it.
     *
     * ⚠ **It is a positional parameter inside the SELECT list, so it binds BEFORE
     * anything in the `WHERE`.** {@see self::columnParameters()} is the one place
     * that order is written down; every caller prepends it rather than typing the
     * constant again.
     */
    public const COLUMNS = <<<'SQL'
        sol.id, sol.line_number, sol.quantity, sol.quantity_fulfilled, sol.unit_price,
        sol.deleted_at,
        -- V2-C-14(b): 0 on a cancelled order, DERIVED, with no `quantity_cancelled`
        -- column. The cast is NOT redundant -- a bare 0 renders "0" at scale 0
        -- beside its neighbour's "0.000000" (measured). See the class docblock
        -- before changing either half.
        CASE WHEN so.status = ?
             THEN 0::numeric(19,6)
             ELSE sol.quantity - sol.quantity_fulfilled
        END AS quantity_outstanding,
        i.id AS inventory_item_id, i.sku AS inventory_item_sku, i.name AS inventory_item_name
        SQL;

    /**
     * **The join to `sales_order` is new relative to the purchase analogue and is
     * not optional**: the `quantity_outstanding` derivation needs the parent's
     * status, and keeping the derivation a property of the SQL that produces the
     * row is what puts it inside `WireScaleTest`'s domain and what lets a future
     * `GetSalesOrderLine` reuse this `FROM` unchanged.
     *
     * Every join carries `tenant_id` explicitly even though RLS already bounds
     * each table — ADR-002 A1.6: the composite key is `(tenant_id, id)`, and a
     * join on `id` alone cannot be served from the composite unique index.
     *
     * The item join is inner: `inventory_item_id` is `NOT NULL` with a composite
     * foreign key, so a line with no item is not expressible. **It carries no
     * `deleted_at` predicate on `inventory_item`, and that is §5.2a rather than an
     * omission** — an archived item is still the item this line was ordered for,
     * and dropping the row would make a sales order lose a line because somebody
     * tidied a catalogue.
     */
    public const FROM = <<<'SQL'
        FROM sales_order_line sol
        JOIN sales_order    so ON so.tenant_id = sol.tenant_id AND so.id = sol.sales_order_id
        JOIN inventory_item i  ON i.tenant_id = sol.tenant_id AND i.id = sol.inventory_item_id
        SQL;

    /**
     * The parameters {@see self::COLUMNS} itself binds, in order, before any the
     * caller's `WHERE` adds.
     *
     * @return list<string>
     */
    public static function columnParameters(): array
    {
        return [SalesOrder::STATUS_CANCELLED];
    }

    /** @param array<string, mixed> $row */
    public static function toResource(array $row): SalesOrderLineResource
    {
        return new SalesOrderLineResource(
            id: (string) $row['id'],
            lineNumber: (int) $row['line_number'],
            inventoryItem: new SalesItemReference((string) $row['inventory_item_id'], (string) $row['inventory_item_sku'], (string) $row['inventory_item_name']),
            quantity: (string) $row['quantity'],
            unitPrice: (string) $row['unit_price'],
            quantityFulfilled: (string) $row['quantity_fulfilled'],
            quantityOutstanding: (string) $row['quantity_outstanding'],
            archivedAt: SalesOrderRow::timestamp($row['deleted_at']),
        );
    }
}
