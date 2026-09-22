<?php

declare(strict_types=1);

namespace App\Modules\Sales\Application;

use App\Modules\Inventory\Application\Contract\PickableStock;
use App\Modules\Sales\Infrastructure\Entity\SalesOrder;
use App\Platform\Tenancy\CurrentTenant;
use App\Platform\Tenancy\TenantContext;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * **`GetPickList` — the money-free pick read (V2-S-1b; owner rulings R1, R2, R7
 * and R8 of 2026-09-22).** What `warehouse_operator`, which holds
 * `fulfilment.create` and no `sales_order.view`, needs to ship an order: what to
 * pick, how much is still owed, where it can be picked from, and where it goes.
 * Its columns are `SECURITY_MODEL.md` PC-9's second deliberate exception, and
 * nothing else.
 *
 * ---------------------------------------------------------------------------
 * Which orders it shows — decided by the ROW, so a hidden one is not-found
 * ---------------------------------------------------------------------------
 * An order is readable here only once it has been released to the warehouse:
 * `confirmed`, `partially_fulfilled` or `fulfilled`, or `cancelled` **after**
 * being confirmed (`ordered_at` set). A draft, an archived order (always a
 * draft, `sales_order_archived_only_while_draft`) and one cancelled while still
 * a draft are **not-found**, byte-identical to an id in another workspace (E-2):
 * a 409 "not confirmed yet" would show a role with no `sales_order.view` which
 * drafts exist. **Status first, `ordered_at` only for the cancelled branch**
 * (`-sec-red.md` F-8): `sales_order` has no CHECK tying `ordered_at` to status,
 * so the predicate does not lean on one for the statuses that decide it alone.
 * The statuses are **bound** from `SalesOrder::STATUS_*`, never written as SQL
 * literals (API-15). `fulfilled` is readable because B1N-12's re-read after a
 * `CreateFulfilment` 201 depends on it.
 *
 * ---------------------------------------------------------------------------
 * What it projects
 * ---------------------------------------------------------------------------
 *  - the order's `id`, `order_number`, `status`, and its four `ship_to_*`
 *    snapshot columns;
 *  - `customer = {id, name}` from the live row, joined as `SalesOrderRow` joins
 *    it — **inner and unfiltered**, so an archived customer is still named and
 *    never drops the order (PC-15 condition 3; `-sec-red.md` F-7). The snapshot
 *    has no name column, so the live name is the only one there is;
 *  - `warehouse = {id, name}`, joined the same way;
 *  - live lines only, with `quantity`, `quantity_fulfilled` and
 *    `quantity_outstanding` — the last from
 *    `SalesOrderLineRow::QUANTITY_OUTSTANDING`, **the same expression, shared,
 *    never a copy** — and `inventory_item = {id, sku, name}`;
 *  - per line **with a remainder**, the candidate bins from Inventory's
 *    `PickableStock` (R2's pickable locations of the order's warehouse, on-hand
 *    only, none while the warehouse is archived).
 *
 * **Never:** `unit_price`, `total_amount`, `currency_code`, `customer.*` beyond
 * `{id, name}`, `created_by`, `ordered_at`, `qty_reserved`, an available figure,
 * a location's `kind`, or the retry-key plumbing columns. The pick read's
 * money-free check is `PickListReadTest::thePickReadCarriesNoMoneyColumn`.
 */
final readonly class PickListReader
{
    public function __construct(
        private TenantContext $tenants,
        private CurrentTenant $tenant,
        private PickableStock $stock,
    ) {
    }

    public function get(string $orderId): ?PickListView
    {
        if (!Uuid::isValid($orderId)) {
            return null;
        }

        return $this->tenants->run(
            $this->tenant->tenantId(),
            fn (EntityManagerInterface $em): ?PickListView => $this->readInside($em, Uuid::fromString($orderId)->toRfc4122()),
        );
    }

    /** Inside a transaction the caller already holds. */
    public function readInside(EntityManagerInterface $em, string $orderId): ?PickListView
    {
        $connection = $em->getConnection();

        $order = $connection->fetchAssociative(<<<'SQL'
            SELECT so.id, so.order_number, so.status, so.warehouse_id,
                   so.ship_to_line, so.ship_to_city, so.ship_to_postal_code, so.ship_to_country_code,
                   c.id AS customer_id, c.name AS customer_name, w.name AS warehouse_name
              FROM sales_order so
              JOIN customer  c ON c.tenant_id = so.tenant_id AND c.id = so.customer_id
              JOIN warehouse w ON w.tenant_id = so.tenant_id AND w.id = so.warehouse_id
             WHERE so.id = ?::uuid
               AND so.deleted_at IS NULL
               AND (so.status IN (?, ?, ?) OR (so.status = ? AND so.ordered_at IS NOT NULL))
            SQL, [
                $orderId,
                SalesOrder::STATUS_CONFIRMED, SalesOrder::STATUS_PARTIALLY_FULFILLED, SalesOrder::STATUS_FULFILLED,
                SalesOrder::STATUS_CANCELLED,
            ]);

        if (false === $order) {
            return null;
        }

        $rows = $connection->fetchAllAssociative(
            'SELECT sol.id, sol.line_number, sol.quantity, sol.quantity_fulfilled, '
            .SalesOrderLineRow::QUANTITY_OUTSTANDING.' AS quantity_outstanding, '
            .'i.id AS inventory_item_id, i.sku AS inventory_item_sku, i.name AS inventory_item_name '
            .SalesOrderLineRow::FROM
            .' WHERE sol.sales_order_id = ?::uuid AND sol.deleted_at IS NULL ORDER BY sol.line_number, sol.id',
            [...SalesOrderLineRow::columnParameters(), $orderId],
        );

        $owed = [];
        foreach ($rows as $row) {
            if (1 === bccomp((string) $row['quantity_outstanding'], '0', 6)) {
                $owed[(string) $row['inventory_item_id']] = true;
            }
        }
        $bins = $this->stock->candidateBins((string) $order['warehouse_id'], array_keys($owed));

        return new PickListView(
            id: (string) $order['id'],
            orderNumber: (string) $order['order_number'],
            status: (string) $order['status'],
            customer: new SalesNamedRef((string) $order['customer_id'], (string) $order['customer_name']),
            warehouse: new SalesNamedRef((string) $order['warehouse_id'], (string) $order['warehouse_name']),
            shipToLine: (string) $order['ship_to_line'],
            shipToCity: (string) $order['ship_to_city'],
            shipToPostalCode: (string) $order['ship_to_postal_code'],
            shipToCountryCode: (string) $order['ship_to_country_code'],
            lines: array_map(static fn (array $row): PickListLineView => new PickListLineView(
                salesOrderLine: new SalesLineRef((string) $row['id'], (int) $row['line_number']),
                inventoryItem: new SalesItemRef((string) $row['inventory_item_id'], (string) $row['inventory_item_sku'], (string) $row['inventory_item_name']),
                quantity: (string) $row['quantity'],
                quantityFulfilled: (string) $row['quantity_fulfilled'],
                quantityOutstanding: (string) $row['quantity_outstanding'],
                // Only a line with a remainder lists bins: a fulfilled or cancelled order discloses no stock.
                candidateBins: 1 === bccomp((string) $row['quantity_outstanding'], '0', 6)
                    ? array_map(static fn ($bin): PickListBinView => new PickListBinView(
                        new SalesLocationRef($bin->storageLocationId, $bin->code),
                        $bin->qtyOnHand,
                    ), $bins[(string) $row['inventory_item_id']] ?? [])
                    : [],
            ), $rows),
        );
    }
}
