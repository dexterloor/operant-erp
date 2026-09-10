<?php

declare(strict_types=1);

namespace App\Modules\Purchasing\Application;

use App\Modules\Purchasing\ApiResource\ActorReference;
use App\Modules\Purchasing\ApiResource\NamedReference;
use App\Modules\Purchasing\ApiResource\PurchaseOrderResource;

/**
 * The one place a database row becomes a `PurchaseOrderResource`.
 *
 * It is one class rather than a method on each of the reader and the writer
 * because both have to produce the *same* shape: an approval that built its
 * response from the values it was handed, and a read that built it from the row,
 * would disagree the first time the database applied a default — and
 * `total_amount` and `status` both have one.
 *
 * **Both parent joins are inner and the creator's is outer, and the three are not
 * a style choice.** `purchase_order.supplier_id` and `.warehouse_id` are
 * `NOT NULL` with composite foreign keys onto `(tenant_id, id)`, so a row with no
 * supplier is not expressible; `created_by_user_id` is nullable *"a purchase
 * order raised by the reorder agent has no human author"*, and that null is
 * DSoD-1's third case rather than missing data.
 *
 * **The joins carry `tenant_id` explicitly even though RLS already bounds every
 * table.** ADR-002 A1.6: the composite key is `(tenant_id, id)`, and a join on
 * `id` alone is a join the planner cannot serve from the composite unique index.
 * It is also the shape that stays correct if a future path ever runs without a
 * tenant context — belt and braces, at no cost.
 */
final class PurchaseOrderRow
{
    /**
     * Every column the read shape needs, and no others. Written once so that a
     * field added to the resource is added to exactly one SELECT.
     *
     * `ordered_at` and `order_number` are also the two keyset sort keys
     * (`PurchaseOrderCollectionProvider::legalSorts()`), so the cursor is built
     * from the same row the resource is.
     */
    public const COLUMNS = <<<'SQL'
        po.id, po.order_number, po.status, po.currency_code, po.total_amount,
        po.ordered_at, po.expected_at, po.deleted_at,
        s.id AS supplier_id, s.name AS supplier_name,
        w.id AS warehouse_id, w.name AS warehouse_name,
        u.id AS created_by_id, u.display_name AS created_by_display_name
        SQL;

    public const FROM = <<<'SQL'
        FROM purchase_order po
        JOIN supplier  s ON s.tenant_id = po.tenant_id AND s.id = po.supplier_id
        JOIN warehouse w ON w.tenant_id = po.tenant_id AND w.id = po.warehouse_id
        LEFT JOIN app_user u ON u.tenant_id = po.tenant_id AND u.id = po.created_by_user_id
        SQL;

    /** @param array<string, mixed> $row */
    public static function toResource(array $row): PurchaseOrderResource
    {
        return new PurchaseOrderResource(
            id: (string) $row['id'],
            // Every expansion is joined in the same statement, inside the
            // tenancy transaction. P-7 forbids reaching for one later: after the
            // commit there is no tenant context, so the lookup would return
            // nothing and the serializer would raise.
            supplier: new NamedReference((string) $row['supplier_id'], (string) $row['supplier_name']),
            warehouse: new NamedReference((string) $row['warehouse_id'], (string) $row['warehouse_name']),
            currencyCode: (string) $row['currency_code'],
            expectedAt: self::timestamp($row['expected_at']),
            orderNumber: (string) $row['order_number'],
            status: (string) $row['status'],
            totalAmount: (string) $row['total_amount'],
            orderedAt: self::timestamp($row['ordered_at']),
            createdBy: null === $row['created_by_id']
                ? null
                : new ActorReference((string) $row['created_by_id'], (string) $row['created_by_display_name']),
            archivedAt: self::timestamp($row['deleted_at']),
        );
    }

    /** RFC 3339 with milliseconds, the form every other timestamp on this API uses. */
    public static function timestamp(mixed $value): ?string
    {
        return null === $value
            ? null
            : new \DateTimeImmutable((string) $value)->format(\DateTimeInterface::RFC3339_EXTENDED);
    }
}
