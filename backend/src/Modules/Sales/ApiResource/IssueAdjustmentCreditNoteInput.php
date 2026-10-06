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

    /**
     * G-29 / G-30 part 3: the returns still waiting for a credit that the caller confirms are DIFFERENT goods from the
     * units adjusted — each one named here gets a `distinct` decision in the same transaction (an owner-marker act). A
     * list of UUIDs; every pending return on an adjusted line must be named (`credit_note.has_no_pending_return`).
     */
    public mixed $acknowledged_return_ids = null;
}
