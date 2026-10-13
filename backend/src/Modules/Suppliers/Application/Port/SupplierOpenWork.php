<?php

declare(strict_types=1);

namespace App\Modules\Suppliers\Application\Port;

use Doctrine\ORM\EntityManagerInterface;

/**
 * **What stops a supplier being archived** — Suppliers' inbound port for ONE named consumer (C5 C2b,
 * `docs/session13/c5-design.md` §2.1–§2.2 and Revision 1 R1.6; owner ruling Q-S2: *"refuse a supplier with open purchase
 * orders"*). `DeleteSupplier` (`SupplierWriter::delete()`) asks it; **Purchasing answers it**
 * (`Purchasing\Application\SupplierOpenPurchaseOrders`, bound in `services.yaml`), because only Purchasing may spell a
 * purchase order's statuses (API-15) and read its table (A-2).
 *
 * **Not a Contract** (R1.6, RED round 2 F-3): `Application\Contract` is the AI's door into a module (A-4), and this port
 * returns a count of documents with no `.view` gate. It lives in its own deptrac layer, `SuppliersPort`, which only
 * `SuppliersApplication` and `PurchasingApplication` may use — never `Ai*` or `Analytics*` (`DeptracRulesetTest` probes
 * it). `ARCH` co-signs the layer kind (design §9 A-1; owed).
 *
 * **Open** (binding, `suppliers-s1-design.md:637`): a LIVE (`deleted_at IS NULL`) purchase order of the supplier in
 * `draft`, `ordered` or `partially_received`. `received` and `cancelled` are closed; a deleted draft is not counted.
 *
 * Call it inside your own tenancy transaction, AFTER taking the supplier `FOR UPDATE` (W-2's shape for a read; it issues
 * one statement on `$em`'s connection and writes nothing). Under READ COMMITTED the statement sees every order whose
 * writer held the supplier `FOR KEY SHARE` and committed before the lock was granted.
 */
interface SupplierOpenWork
{
    /** @param string $supplierId canonical lower-case RFC 4122 */
    public function openPurchaseOrderCountInside(EntityManagerInterface $em, string $supplierId): int;
}
