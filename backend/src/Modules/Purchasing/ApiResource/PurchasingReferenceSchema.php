<?php

declare(strict_types=1);

namespace App\Modules\Purchasing\ApiResource;

/**
 * BK-14: the published shape of the two short references the purchasing documents serve from an `Application` class
 * (`ItemReference`, `OrderReference`).
 *
 * Those classes stay free of API Platform (A-7), so each serving property says the shape with
 * `#[ApiProperty(schema: self::X)]` and the reference is served inline, never as a component of its own (BK-13 F-3; the
 * `StockLevelResource::WIRE_SCHEMA` precedent). `OpenApiContractTest` holds each served copy to its class: keys,
 * `required`, nullability and scalar type, and a uuid `id` with no other `format`.
 */
final class PurchasingReferenceSchema
{
    public const ITEM = ['type' => 'object', 'required' => ['id', 'sku', 'name'], 'properties' => [
        'id' => ['type' => 'string', 'format' => 'uuid'],
        'sku' => ['type' => 'string'],
        'name' => ['type' => 'string'],
    ]];

    public const ORDER = ['type' => 'object', 'required' => ['id', 'order_number'], 'properties' => [
        'id' => ['type' => 'string', 'format' => 'uuid'],
        'order_number' => ['type' => 'string'],
    ]];
}
