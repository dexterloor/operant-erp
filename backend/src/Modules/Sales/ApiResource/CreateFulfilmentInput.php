<?php

declare(strict_types=1);

namespace App\Modules\Sales\ApiResource;

use Symfony\Component\Serializer\Attribute\SerializedName;

/**
 * `CreateFulfilment`'s body — §4.2's accepted cell: `shipped_at`; `lines[].sales_order_line_id`;
 * `lines[].quantity`; `lines[].storage_location_id`. The lines arrive as a decoded array and are checked, API-9
 * included, by `FulfilmentWriter::lines()`, because a decoded array has no declared properties for the
 * deserializer to refuse extra members of. **No shipper field**: `shipped_by` is the authenticated caller, never
 * a request field (owner ruling R6).
 */
final class CreateFulfilmentInput
{
    /** RFC 3339. Absent means "now". */
    #[SerializedName('shipped_at')]
    public ?string $shippedAt = null;

    /** @var array<mixed>|null */
    public ?array $lines = null;
}
