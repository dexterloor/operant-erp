<?php

declare(strict_types=1);

namespace App\Modules\Purchasing\Application\Contract;

/**
 * **The reorder computation's Purchasing half: what placed purchase orders still owe** (remaining-ops G3-C1, 2026-10-22;
 * `docs/session13/g3-g7b-reorder-settings-design.md` §2.3; `remaining-ops-final-red.md` M-4). The first class of this
 * Contract layer. Read by `Modules\Analytics`' `ReorderSuggester`, never by a raw cross-module join (A-2).
 *
 * **On order** (M-4): the outstanding quantity — `PurchaseOrderLineRow::OUTSTANDING`, floored at 0 — of every live line of
 * every live purchase order in `ordered` or `partially_received`. A draft is not placed; a received or cancelled order owes
 * nothing; an archived order or line is discarded supply (`DeletionPolicyTest`'s concern).
 *
 * Call it inside your own tenancy transaction (W-2's shape; it joins yours). It writes nothing and takes no lock.
 */
interface OpenPurchaseSupply
{
    /**
     * One grouped pass, served by `purchase_order_tenant_open_idx` (plan test P-1). Items with nothing on order are absent.
     *
     * @return array<string, string> inventory item id => on-order quantity (scale 6, > 0)
     */
    public function onOrderByItem(): array;
}
