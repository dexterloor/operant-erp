<?php

declare(strict_types=1);

namespace App\Modules\Purchasing\Application;

use App\Modules\Purchasing\Infrastructure\Entity\PurchaseOrder;
use App\Modules\Suppliers\Application\Port\SupplierOpenWork;
use Doctrine\ORM\EntityManagerInterface;

/**
 * **Purchasing's answer to {@see SupplierOpenWork}** (C5 C2b; `docs/session13/c5-design.md` §2.2, Revision 1 R1.6): how
 * many LIVE purchase orders of a supplier are still open — `draft`, `ordered` or `partially_received`, the entity's own
 * constants (API-15), never literals. One statement, inside the caller's transaction; it reads only Purchasing's table
 * (A-2), and RLS bounds it to the workspace. `purchase_order_tenant_supplier_idx` (`tenant_id, supplier_id, ordered_at`
 * `WHERE deleted_at IS NULL`) serves it.
 */
final readonly class SupplierOpenPurchaseOrders implements SupplierOpenWork
{
    /** The statuses a supplier may not be archived under (Q-S2; `suppliers-s1-design.md:637`). */
    public const OPEN_STATUSES = [
        PurchaseOrder::STATUS_DRAFT,
        PurchaseOrder::STATUS_ORDERED,
        PurchaseOrder::STATUS_PARTIALLY_RECEIVED,
    ];

    /** Public so a test reads the statement it pins, never a transcription. */
    public const COUNT = 'SELECT count(*) FROM purchase_order WHERE supplier_id = ?::uuid AND deleted_at IS NULL AND status = ANY(?::text[])';

    public function openPurchaseOrderCountInside(EntityManagerInterface $em, string $supplierId): int
    {
        return (int) $em->getConnection()->fetchOne(self::COUNT, [$supplierId, '{'.implode(',', self::OPEN_STATUSES).'}']);
    }
}
