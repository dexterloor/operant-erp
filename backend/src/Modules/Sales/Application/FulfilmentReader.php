<?php

declare(strict_types=1);

namespace App\Modules\Sales\Application;

use App\Platform\Tenancy\CurrentTenant;
use App\Platform\Tenancy\TenantContext;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * **The read half of `/fulfilments` — `GetFulfilment` (owner ruling R9: built in step 5, so the 201's
 * `Location` names a route that answers).** `GoodsReceiptReader`'s shape, one module over: one statement
 * for the fulfilment and its order and shipper, one for its lines, both inside the tenancy transaction.
 *
 * **Joins, and why each is what it is.** The order join is inner (`fulfilment.sales_order_id` is `NOT NULL`
 * under a composite key) and unfiltered — a fulfilment of an order archived later is still that order's (an
 * archived order is always a draft, so this cannot happen today, but the read does not lean on that). The
 * shipper join is **inner** — `shipped_by_user_id` is `NOT NULL` (owner ruling R6) — and has no `deleted_at`
 * predicate: archiving a person does not un-ship what they shipped. The line joins are `GoodsReceiptReader`'s:
 * the order line (for `line_number`), the item through it (`fulfilment_line` has no item column), and the bin,
 * all unfiltered, because a retired bin or an archived item still names where the goods left from and what
 * they were. Lines are listed in the order's line-number order, then by bin.
 */
final readonly class FulfilmentReader
{
    public function __construct(
        private TenantContext $tenants,
        private CurrentTenant $tenant,
    ) {
    }

    public function get(string $id): ?FulfilmentView
    {
        if (!Uuid::isValid($id)) {
            return null;
        }

        return $this->tenants->run(
            $this->tenant->tenantId(),
            fn (EntityManagerInterface $em): ?FulfilmentView => $this->readInside($em, Uuid::fromString($id)->toRfc4122()),
        );
    }

    /** Inside a transaction the caller already holds. */
    public function readInside(EntityManagerInterface $em, string $id): ?FulfilmentView
    {
        $connection = $em->getConnection();

        $row = $connection->fetchAssociative(<<<'SQL'
            SELECT f.id, f.fulfilment_number, f.shipped_at, so.id AS sales_order_id, so.order_number,
                   u.id AS shipped_by_id, u.display_name AS shipped_by_display_name
              FROM fulfilment f
              JOIN sales_order so ON so.tenant_id = f.tenant_id AND so.id = f.sales_order_id
              JOIN app_user u ON u.tenant_id = f.tenant_id AND u.id = f.shipped_by_user_id
             WHERE f.id = ?::uuid
            SQL, [$id]);

        if (false === $row) {
            return null;
        }

        $lines = $connection->fetchAllAssociative(<<<'SQL'
            SELECT fl.id, fl.quantity, sol.id AS sales_order_line_id, sol.line_number,
                   i.id AS inventory_item_id, i.sku AS inventory_item_sku, i.name AS inventory_item_name,
                   sl.id AS storage_location_id, sl.code AS storage_location_code
              FROM fulfilment_line fl
              JOIN sales_order_line sol ON sol.tenant_id = fl.tenant_id AND sol.id = fl.sales_order_line_id
              JOIN inventory_item i ON i.tenant_id = sol.tenant_id AND i.id = sol.inventory_item_id
              JOIN storage_location sl ON sl.tenant_id = fl.tenant_id AND sl.id = fl.storage_location_id
             WHERE fl.fulfilment_id = ?::uuid
             ORDER BY sol.line_number, fl.storage_location_id, fl.id
            SQL, [$id]);

        return new FulfilmentView(
            id: (string) $row['id'],
            fulfilmentNumber: (string) $row['fulfilment_number'],
            shippedAt: (string) SalesOrderRow::timestamp($row['shipped_at']),
            salesOrder: new SalesOrderRef((string) $row['sales_order_id'], (string) $row['order_number']),
            shippedBy: new SalesActorRef((string) $row['shipped_by_id'], (string) $row['shipped_by_display_name']),
            lines: array_map(static fn (array $line): FulfilmentLineView => new FulfilmentLineView(
                id: (string) $line['id'],
                salesOrderLine: new SalesLineRef((string) $line['sales_order_line_id'], (int) $line['line_number']),
                inventoryItem: new SalesItemRef((string) $line['inventory_item_id'], (string) $line['inventory_item_sku'], (string) $line['inventory_item_name']),
                quantity: (string) $line['quantity'],
                storageLocation: new SalesLocationRef((string) $line['storage_location_id'], (string) $line['storage_location_code']),
            ), $lines),
        );
    }
}
