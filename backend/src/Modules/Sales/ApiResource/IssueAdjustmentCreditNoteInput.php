<?php

declare(strict_types=1);

namespace App\Modules\Sales\ApiResource;

/**
 * `IssueAdjustmentCreditNote`'s body — §4.2's accepted fields: `reason`, `lines[].fulfilment_line_id`,
 * `lines[].quantity`. No `sales_return_id`: an adjustment credits goods the customer KEEPS (owner ruling Q-A = B′).
 * Every field is checked by `CreditNoteWriter::adjust()`, so a missing or malformed one is a 422 naming it.
 */
final class IssueAdjustmentCreditNoteInput
{
    /** One of `CreditNoteWriter::ADJUST_REASONS` (`damaged_goods_kept`, `short_shipped`). */
    public mixed $reason = null;

    /** @var array<mixed>|null */
    public ?array $lines = null;
}
