<?php

declare(strict_types=1);

namespace App\Modules\Sales\Application;

use App\Modules\Sales\ApiResource\SalesActorReference;
use App\Modules\Sales\ApiResource\SalesNamedReference;
use App\Modules\Sales\ApiResource\SalesOrderResource;

/**
 * The one place a database row becomes a `SalesOrderResource`.
 *
 * It is a class of its own rather than a method on either side because the write path's own read-back and
 * `GetSalesOrder` have to produce the *same* shape. A create that built its response from the values it
 * was handed, and a read that built it from the row, would disagree the first time the database applied a
 * default, and `total_amount` and `status` both have one.
 *
 * **The two parent joins are inner and the creator's is outer, and the three are not a style choice.**
 * `sales_order.customer_id` and `.warehouse_id` are `NOT NULL` with composite foreign keys onto
 * `(tenant_id, id)`, so a row with no customer is not expressible. `created_by_user_id` is **nullable**,
 * so `null` is a real value a read has to be able to render — an order raised by something that is not a
 * person has no human author — and the join is therefore `LEFT`. *(It arrived with `GetSalesOrder`, which
 * is when B1N-2 was ruled; the column was written from the first create and only not returned.)*
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
        w.id AS warehouse_id, w.name AS warehouse_name,
        u.id AS created_by_id, u.display_name AS created_by_display_name
        SQL;

    public const FROM = <<<'SQL'
        FROM sales_order so
        JOIN customer  c ON c.tenant_id = so.tenant_id AND c.id = so.customer_id
        JOIN warehouse w ON w.tenant_id = so.tenant_id AND w.id = so.warehouse_id
        LEFT JOIN app_user u ON u.tenant_id = so.tenant_id AND u.id = so.created_by_user_id
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
            // B1N-2, ruled 2026-09-19 on PC-11 (4a) ground: the creator is the document's own business
            // state. `null` is rendered as unknown and never as a guess.
            createdBy: null === $row['created_by_id']
                ? null
                : new SalesActorReference((string) $row['created_by_id'], (string) $row['created_by_display_name']),
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
