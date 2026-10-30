<?php

declare(strict_types=1);

namespace App\Modules\Sales\ApiResource;

use ApiPlatform\Metadata\ApiProperty;
use App\Modules\Sales\Application\CreditNoteWriter;
use Symfony\Component\Serializer\Attribute\SerializedName;

/**
 * `IssueCreditNote`'s body — §4.2's accepted fields: `reason`, `sales_return_id`, `lines[].fulfilment_line_id`,
 * `lines[].quantity`. Every field is checked by `CreditNoteWriter`, so a missing or malformed one is a 422 naming it.
 */
final class IssueCreditNoteInput
{
    /** One of `CreditNoteWriter::ISSUE_REASONS` (`goods_returned`). */
    public mixed $reason = null;

    /** Required: the return this note credits (Q-A = B′; a credit without a return is `IssueAdjustmentCreditNote`). */
    #[SerializedName('sales_return_id')]
    public mixed $salesReturnId = null;

    /** @var array<mixed>|null */
    #[ApiProperty(schema: [
        'type' => 'array',
        'minItems' => 1,
        'maxItems' => CreditNoteWriter::MAXIMUM_LINES,
        'items' => [
            'type' => 'object',
            'additionalProperties' => false,
            'properties' => [
                'fulfilment_line_id' => ['type' => 'string', 'format' => 'uuid'],
                'quantity' => ['type' => 'string'],
            ],
        ],
    ])]
    public ?array $lines = null;
}
