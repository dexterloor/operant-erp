<?php

declare(strict_types=1);

namespace App\Modules\Sales\ApiResource;

use Symfony\Component\Serializer\Attribute\SerializedName;

/**
 * `CreateSalesReturn`'s body — §4.2's accepted cell: `returned_at`; `reason`; `lines[].fulfilment_line_id`;
 * `lines[].quantity`; `lines[].storage_location_id` (optional for `recorded_in_error`, whose goods go back to the bin
 * they left from — owner ruling Q-M). Lines are checked, API-9 included, by `SalesReturnWriter::lines()`. **No
 * recorder field**: `recorded_by` is the authenticated caller, never a request field. **No `deducts` field**: it is
 * decided by the server from who records the return (Q-L (a′)).
 */
final class CreateSalesReturnInput
{
    /** RFC 3339. Absent means "now". */
    #[SerializedName('returned_at')]
    public ?string $returnedAt = null;

    /** One of `SalesReturnWriter::REASONS`. Checked by the writer, so an unknown value is a 422 naming the field. */
    public mixed $reason = null;

    /** @var array<mixed>|null */
    public ?array $lines = null;
}
