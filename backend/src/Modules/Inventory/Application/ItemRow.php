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
        s.id AS supplier_id, s.name AS supplier_name
        SQL;

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
        );
    }
}
