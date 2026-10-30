<?php

declare(strict_types=1);

namespace App\Modules\Purchasing\ApiResource;

use ApiPlatform\Metadata\ApiProperty;
use App\Modules\Purchasing\Application\GoodsReceiptWriter;
use Symfony\Component\Serializer\Attribute\SerializedName;

/**
 * `CreateGoodsReceipt`'s accepted request fields: `received_at` and
 * `lines[].{purchase_order_line_id, quantity, storage_location_id}`.
 *
 * `lines` arrives as the decoded JSON array and is checked field by field by
 * `GoodsReceiptWriter`, which refuses an unknown key on a line as API-9 refuses
 * one at the top level. That keeps every pointer (`/lines/2/quantity`) in one
 * place, the one that also knows which order line a refusal is about.
 *
 * No `idempotency_key`: it arrives in the header, one value from one source,
 * as `CreatePurchaseOrderInput` explains.
 */
final class CreateGoodsReceiptInput
{
    /** RFC 3339. Absent means "now". */
    #[SerializedName('received_at')]
    public ?string $receivedAt = null;

    /** @var array<mixed>|null */
    #[ApiProperty(schema: [
        'type' => 'array',
        'minItems' => 1,
        'maxItems' => GoodsReceiptWriter::MAXIMUM_LINES,
        'items' => [
            'type' => 'object',
            'additionalProperties' => false,
            'properties' => [
                'purchase_order_line_id' => ['type' => 'string', 'format' => 'uuid'],
                'quantity' => ['type' => 'string'],
                'storage_location_id' => ['type' => 'string', 'format' => 'uuid'],
            ],
        ],
    ])]
    public ?array $lines = null;
}
