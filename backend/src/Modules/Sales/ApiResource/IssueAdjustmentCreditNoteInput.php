<?php

declare(strict_types=1);

namespace App\Modules\Sales\ApiResource;

/**
 * `IssueAdjustmentCreditNote`'s body — §4.2's accepted fields: `reason`, `lines[].fulfilment_line_id`,
 * `lines[].quantity`. No `sales_return_id`: an adjustment credits billed units that come back on no return (owner
 * ruling Q-A = B′) — received damaged and kept (`damaged_goods_kept`), or not received at all (`short_shipped`);
 * definitions in `API.md` §15.1 `IssueAdjustmentCreditNote` (2026-10-02).
 * Every field is checked by `CreditNoteWriter::adjust()`, so a missing or malformed one is a 422 naming it.
 */
final class IssueAdjustmentCreditNoteInput
{
    /** One of `CreditNoteWriter::ADJUST_REASONS` (`damaged_goods_kept`, `short_shipped`). */
    public mixed $reason = null;

    /** @var array<mixed>|null */
    public ?array $lines = null;
}
