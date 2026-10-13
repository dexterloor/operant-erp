<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Application\Contract;

/**
 * **Where an item can be picked from, and how much each place holds** — the
 * candidate bins of the pick read (`GetPickList`, sales step 5 commit 3), read
 * through Inventory's own contract rather than as a raw cross-module join from
 * Sales, so the definition of *pickable* lives in one module (`-spar.md` S1).
 *
 * **Pickable (owner ruling R2, 2026-09-22):** a live (not archived) location of
 * the given warehouse whose `kind` is not {@see self::NEVER_PICKABLE_KIND}, and
 * nothing while the warehouse itself is archived. `DepleteStock` refuses exactly
 * the complement, so a bin this lists is a bin a fulfilment may take from and a
 * bin it omits is one a fulfilment is refused — the two are held together by
 * `PickListReadTest`. Only bins with `qty_on_hand > 0` are listed.
 *
 * **On-hand only, never an available figure** (owner ruling R1 with Q6(a)):
 * `qty_reserved` and `qty_available` are G-7's wire question and stay with
 * `ListStockLevels`. A read is not refused by G-7's tripwire, which is about
 * *writing* `qty_reserved`.
 *
 * Call it inside your own tenancy transaction (W-2's shape; it joins yours). It
 * writes nothing.
 */
interface PickableStock
{
    /**
     * `storage_location.kind`'s one value that is never pickable (R2). The
     * Warehouses module's CHECK value, held here once for both halves of the
     * rule — this read and `DepleteStock`'s refusal. **Since warehouses slice 1
     * C3 the Warehouses module carries the same value as
     * `LocationKinds::NEVER_PICKABLE`** (it guards changes into and out of this
     * kind): `InventoryContract` may not reach `WarehousesContract`, so the two
     * copies are held equal by `StorageLocationWriteTest`, never imported. A
     * location's kind changes only through `UpdateStorageLocation`: a hold is
     * always allowed, a release of a stocked location is the owner's alone
     * (`RESIDUAL_GAPS.md` G-44). This read takes no lock — a display.
     */
    public const NEVER_PICKABLE_KIND = 'quarantine';

    /**
     * @param list<string> $inventoryItemIds canonical lower-case RFC 4122
     *
     * @return array<string, list<PickableBin>> item id => its candidate bins, by location code then id; an item with none is absent
     */
    public function candidateBins(string $warehouseId, array $inventoryItemIds): array;
}
