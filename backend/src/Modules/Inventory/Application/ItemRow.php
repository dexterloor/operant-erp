<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Application;

use App\Modules\Inventory\ApiResource\ItemResource;
use App\Modules\Inventory\ApiResource\SupplierReference;

/**
 * The one place a database row becomes an `ItemResource`.
 *
 * It is one class rather than a method on each of the reader and the writer
 * because both have to produce the *same* shape: a create that built its
 * response from the values it was handed, and a read that built it from the row,
 * would disagree the first time the database applied a default.
 */
final class ItemRow
{
    /**
     * Every column the read shape needs, and no others. Written once so that a
     * field added to the resource is added to exactly one SELECT.
     */
    public const COLUMNS = <<<'SQL'
        i.id, i.sku, i.name, i.unit_of_measure_code,
        i.reorder_point, i.reorder_quantity, i.safety_stock, i.deleted_at,
        s.id AS supplier_id, s.name AS supplier_name,
        SQL.' '.self::HOLDS_STOCK.' AS holds_stock';

    /**
     * **Whether the item still holds any stock** (BK-27, items batch C2): any `stock_level` row of it, in any location of
     * any kind — quarantine and archived bins included — with `qty_on_hand <> 0`. One boolean, served as `holds_stock` on
     * every item body (Get, List incl. `?archived=true`, the create/update/restore responses and the item export), and the
     * predicate {@see ItemWriter::STOCKED} is built from, so the bulk refusal and the read are one fact.
     *
     * `SECURITY_MODEL.md` PC-9's sixth named exception (`SEC`, `docs/session13/items-batch-sec-arch.md` S-1): a fact of
     * `stock_level` served under `inventory_item.view`, bounded to exactly this boolean — never a quantity, a bin, a
     * warehouse or a pickable split, and never on the `{id, sku, name}` reference documents carry. It says nothing about
     * pickability, so it is not `RESIDUAL_GAPS.md` G-47's closing boolean.
     *
     * `<> 0`, not `> 0`: the same "any stock" `storage_location.holds_stock` means. The explicit
     * `sl.tenant_id = i.tenant_id` lets `stock_level_unique (tenant_id, inventory_item_id, …)` serve the probe with both
     * leading columns as index conditions, without relying on the RLS qual (`SchemaQueryPlanTest`). Correlated to the
     * alias `i` of {@see self::FROM}.
     */
    public const HOLDS_STOCK = 'EXISTS (SELECT 1 FROM stock_level sl WHERE sl.tenant_id = i.tenant_id '
        .'AND sl.inventory_item_id = i.id AND sl.qty_on_hand <> 0)';

    public const FROM = <<<'SQL'
        FROM inventory_item i
        LEFT JOIN supplier s ON s.tenant_id = i.tenant_id AND s.id = i.supplier_id
        SQL;

    /** @param array<string, mixed> $row */
    public static function toResource(array $row): ItemResource
    {
        return new ItemResource(
            id: (string) $row['id'],
            sku: (string) $row['sku'],
            name: (string) $row['name'],
            unitOfMeasureCode: (string) $row['unit_of_measure_code'],
            // The supplier is joined in the same statement, inside the tenancy
            // transaction. P-7 forbids reaching for it later: after the commit
            // there is no tenant context, so the lookup would return nothing.
            supplier: null === $row['supplier_id']
                ? null
                : new SupplierReference((string) $row['supplier_id'], (string) $row['supplier_name']),
            reorderPoint: (string) $row['reorder_point'],
            reorderQuantity: (string) $row['reorder_quantity'],
            safetyStock: (string) $row['safety_stock'],
            archivedAt: null === $row['deleted_at']
                ? null
                : new \DateTimeImmutable((string) $row['deleted_at'])->format(\DateTimeInterface::RFC3339_EXTENDED),
            holdsStock: true === $row['holds_stock'],
        );
    }
}
