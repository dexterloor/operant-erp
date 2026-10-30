<?php

declare(strict_types=1);

namespace App\Modules\Sales\ApiResource;

/**
 * BK-14: the published shape of each short reference the sales documents serve from an `Application` class
 * (`FulfilmentRef`, `InvoiceNumberRef`, `SalesActorRef`, `SalesNamedRef`, `SalesOrderRef`, `SalesReturnNumberRef`).
 *
 * Those classes stay free of API Platform (A-7), so they cannot say themselves that every member is always present and
 * that `id` is a uuid. Each serving property says it instead, with `#[ApiProperty(schema: self::X)]`, and the reference is
 * served inline, never as a component of its own: the rule BK-13 F-3 set for hand-written nodes, and the one
 * `StockLevelResource::WIRE_SCHEMA` already follows. `*_OR_NULL` is the same shape where the member is nullable.
 *
 * These are copies of the classes' members. `OpenApiContractTest` holds each served copy to its class: keys, `required`,
 * nullability and scalar type, and a uuid `id` with no other `format`.
 */
final class SalesReferenceSchema
{
    public const FULFILMENT = ['type' => 'object', 'required' => ['id', 'fulfilment_number'], 'properties' => [
        'id' => ['type' => 'string', 'format' => 'uuid'],
        'fulfilment_number' => ['type' => 'string'],
    ]];

    public const INVOICE = ['type' => 'object', 'required' => ['id', 'invoice_number'], 'properties' => [
        'id' => ['type' => 'string', 'format' => 'uuid'],
        'invoice_number' => ['type' => 'string'],
    ]];

    public const INVOICE_OR_NULL = ['type' => ['object', 'null']] + self::INVOICE;

    public const ACTOR = ['type' => 'object', 'required' => ['id', 'display_name'], 'properties' => [
        'id' => ['type' => 'string', 'format' => 'uuid'],
        'display_name' => ['type' => 'string'],
    ]];

    public const ACTOR_OR_NULL = ['type' => ['object', 'null']] + self::ACTOR;

    public const NAMED = ['type' => 'object', 'required' => ['id', 'name'], 'properties' => [
        'id' => ['type' => 'string', 'format' => 'uuid'],
        'name' => ['type' => 'string'],
    ]];

    public const ORDER = ['type' => 'object', 'required' => ['id', 'order_number'], 'properties' => [
        'id' => ['type' => 'string', 'format' => 'uuid'],
        'order_number' => ['type' => 'string'],
    ]];

    public const SALES_RETURN = ['type' => 'object', 'required' => ['id', 'sales_return_number'], 'properties' => [
        'id' => ['type' => 'string', 'format' => 'uuid'],
        'sales_return_number' => ['type' => 'string'],
    ]];

    public const SALES_RETURN_OR_NULL = ['type' => ['object', 'null']] + self::SALES_RETURN;
}
