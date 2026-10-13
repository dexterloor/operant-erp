<?php

declare(strict_types=1);

namespace App\Modules\Warehouses\Application\Contract;

/**
 * **A storage location's five kinds, held once** (warehouses slice 1 C3; `docs/session13/warehouses-s1-design.md` §4.2).
 * The values of the `storage_location_kind_values` CHECK; `StorageLocationWriteTest` holds {@see self::ALL} equal to the
 * live constraint (server-derived), so a kind added to the schema without this list, or here without the schema, is red.
 *
 * **{@see self::NEVER_PICKABLE} is the one kind no stock may be picked from** (owner ruling R2, 2026-09-22): putting a bin
 * into it is a **hold**, taking a bin out of it is a **release** (Q-W1 (d)). `Inventory`'s
 * `PickableStock::NEVER_PICKABLE_KIND` carries the same value for the pick read and `DepleteStock`'s refusal, and stays
 * there: `InventoryContract` may not reach `WarehousesContract` (deptrac), and widening it is not worth an edge. The two
 * copies are held equal by a test (tests are not deptrac-analysed) — the suppliers C2 deviation 5 precedent.
 */
final class LocationKinds
{
    public const BIN = 'bin';
    public const SHELF = 'shelf';
    public const ZONE = 'zone';
    public const STAGING = 'staging';
    public const QUARANTINE = 'quarantine';

    /** In the CHECK's order. */
    public const ALL = [self::BIN, self::SHELF, self::ZONE, self::STAGING, self::QUARANTINE];

    /** Owner ruling R2: stock in a location of this kind is never picked. */
    public const NEVER_PICKABLE = self::QUARANTINE;

    /** Not instantiable: constants only. */
    private function __construct()
    {
    }
}
