<?php

declare(strict_types=1);

namespace App\Modules\Purchasing\Application;

use App\Platform\Tenancy\CurrentTenant;
use App\Platform\Tenancy\TenantContext;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * The read half of `/goods-receipts`. One statement for the receipt and its
 * order, one for its lines, both inside the tenancy transaction, so RLS bounds
 * them and nothing is lazy-loaded after the transaction closes.
 *
 * **Lines are listed in the order's line-number order**, then by bin. Every
 * line of a receipt is inserted in one transaction, so `created_at` ties and
 * the table has no line number of its own; ordering by the order line's number
 * is the one stable order that also means something to the reader.
 */
final readonly class GoodsReceiptReader
{
    public function __construct(
        private TenantContext $tenants,
        private CurrentTenant $tenant,
    ) {
    }

    public function get(string $id): ?GoodsReceiptView
    {
        if (!Uuid::isValid($id)) {
            return null;
        }

        return $this->tenants->run(
            $this->tenant->tenantId(),
            fn (EntityManagerInterface $em): ?GoodsReceiptView => $this->readInside($em, $id),
        );
    }

    /** Inside a transaction the caller already holds. */
    public function readInside(EntityManagerInterface $em, string $id): ?GoodsReceiptView
    {
        $connection = $em->getConnection();

        $row = $connection->fetchAssociative(<<<'SQL'
            SELECT gr.id, gr.receipt_number, gr.received_at, po.id AS purchase_order_id, po.order_number
              FROM goods_receipt gr
              JOIN purchase_order po ON po.tenant_id = gr.tenant_id AND po.id = gr.purchase_order_id
             WHERE gr.id = ?::uuid
            SQL, [$id]);

        if (false === $row) {
            return null;
        }

        $lines = $connection->fetchAllAssociative(<<<'SQL'
            SELECT grl.id, grl.purchase_order_line_id, grl.storage_location_id, grl.quantity_received
              FROM goods_receipt_line grl
              JOIN purchase_order_line pol ON pol.tenant_id = grl.tenant_id AND pol.id = grl.purchase_order_line_id
             WHERE grl.goods_receipt_id = ?::uuid
             ORDER BY pol.line_number, grl.storage_location_id, grl.id
            SQL, [$id]);

        return new GoodsReceiptView(
            id: (string) $row['id'],
            receiptNumber: (string) $row['receipt_number'],
            receivedAt: (string) PurchaseOrderRow::timestamp($row['received_at']),
            purchaseOrder: new OrderReference((string) $row['purchase_order_id'], (string) $row['order_number']),
            lines: array_map(static fn (array $line): GoodsReceiptLineView => new GoodsReceiptLineView(
                id: (string) $line['id'],
                purchaseOrderLineId: (string) $line['purchase_order_line_id'],
                quantity: (string) $line['quantity_received'],
                storageLocationId: (string) $line['storage_location_id'],
            ), $lines),
        );
    }
}
