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
        $connection = $em->getConnection();
        self::refuseOutsideATenancyTransaction($em, 'SupplierOpenWork');

        return (int) $connection->fetchOne(self::COUNT, [$supplierId, '{'.implode(',', self::OPEN_STATUSES).'}']);
    }

    /**
     * **The port refuses to answer outside a tenancy transaction** (`docs/session13/owed-arch-c5.md` follow-up B, condition
     * B; RED L-5). Its answer FAILS OPEN: called outside `TenantContext::run()`, or inside a transaction that set no tenant,
     * RLS's `NULLIF(current_setting('app.tenant_id', true), '')::uuid` is NULL, every row is invisible, the count is 0, and
     * the archive goes through. So both conditions are checked, not stated: nesting level 0 (`SalesOrderRowLock`'s shape),
     * then an empty tenant setting (the read `TenantContext` makes). Public so the refusal tests name it.
     */
    public const TENANT_GUC = "SELECT NULLIF(current_setting('app.tenant_id', true), '')";

    private static function refuseOutsideATenancyTransaction(EntityManagerInterface $em, string $port): void
    {
        // Through the EntityManager, never a typed `Connection` (A-10; the caller's own connection, S-7(b)).
        $connection = $em->getConnection();
        if (0 === $connection->getTransactionNestingLevel()) {
            throw new \LogicException($port.' needs the caller\'s open tenancy transaction: outside one, RLS hides every row and the answer is 0, which lets the archive through.');
        }
        $tenant = $connection->fetchOne(self::TENANT_GUC);
        if (null === $tenant || false === $tenant) {
            throw new \LogicException($port.' was called in a transaction with no tenant set: RLS would hide every row and the answer would be 0, which lets the archive through.');
        }
    }
}
