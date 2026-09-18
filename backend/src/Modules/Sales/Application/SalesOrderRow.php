<?php

declare(strict_types=1);

namespace App\Modules\Sales\Application;

use App\Modules\Sales\ApiResource\SalesNamedReference;
use App\Modules\Sales\ApiResource\SalesOrderResource;

/**
 * The one place a database row becomes a `SalesOrderResource`.
 *
 * It is a class of its own rather than a method on the writer because the read this slice does — the write
 * path's own read-back — and the read `GetSalesOrder` will do have to produce the *same* shape. A create
 * that built its response from the values it was handed, and a read that built it from the row, would
 * disagree the first time the database applied a default, and `total_amount` and `status` both have one.
 *
 * **Both parent joins are inner and there is no creator join, and neither is a style choice.**
 * `sales_order.customer_id` and `.warehouse_id` are `NOT NULL` with composite foreign keys onto
 * `(tenant_id, id)`, so a row with no customer is not expressible. `created_by_user_id` is nullable and
 * **is written on every HTTP create**, but it is not selected here: `SalesOrderResource` holds the member
 * back pending `SEC`'s B1N-2 ruling, and a column selected for a field that does not exist is the start of
 * a second shape nobody maintains. The join arrives with the member.
 *
 * **The joins carry `tenant_id` explicitly even though RLS already bounds every table.** ADR-002 A1.6: the
 * composite key is `(tenant_id, id)`, and a join on `id` alone is a join the planner cannot serve from the
 * composite unique index.
 */
final class SalesOrderRow
{
    /**
     * Every column the read shape needs, and no others. Written once so that a field added to the resource
     * is added to exactly one SELECT.
     */
    public const COLUMNS = <<<'SQL'
        so.id, so.order_number, so.status, so.currency_code, so.total_amount, so.ordered_at,
        so.ship_to_line, so.ship_to_city, so.ship_to_postal_code, so.ship_to_country_code,
        so.deleted_at,
        c.id AS customer_id, c.name AS customer_name,
        w.id AS warehouse_id, w.name AS warehouse_name
        SQL;

    public const FROM = <<<'SQL'
        FROM sales_order so
        JOIN customer  c ON c.tenant_id = so.tenant_id AND c.id = so.customer_id
        JOIN warehouse w ON w.tenant_id = so.tenant_id AND w.id = so.warehouse_id
        SQL;

    /** @param array<string, mixed> $row */
    public static function toResource(array $row): SalesOrderResource
    {
        return new SalesOrderResource(
            id: (string) $row['id'],
            // Every expansion is joined in the same statement, inside the tenancy transaction. P-7 forbids
            // reaching for one later: after the commit there is no tenant context, so the lookup would
            // return nothing and the serializer would raise.
            customer: new SalesNamedReference((string) $row['customer_id'], (string) $row['customer_name']),
            warehouse: new SalesNamedReference((string) $row['warehouse_id'], (string) $row['warehouse_name']),
            currencyCode: (string) $row['currency_code'],
            orderNumber: (string) $row['order_number'],
            status: (string) $row['status'],
            totalAmount: (string) $row['total_amount'],
            orderedAt: self::timestamp($row['ordered_at']),
            shipToLine: (string) $row['ship_to_line'],
            shipToCity: (string) $row['ship_to_city'],
            shipToPostalCode: (string) $row['ship_to_postal_code'],
            shipToCountryCode: (string) $row['ship_to_country_code'],
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
