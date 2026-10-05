<?php

declare(strict_types=1);

namespace App\Modules\Sales\ApiResource;

use Symfony\Component\Serializer\Attribute\SerializedName;

/**
 * **`CreateAdjustmentDecision`'s body** (G-29 / G-30 part 3, design §3 and §2.4). Every field is typed `mixed` so a value
 * of the wrong type is named by the writer's own 422, never by the framework. By `kind`:
 * - `same`: `sales_return_line_id`, `credit_note_line_id`, `quantity`;
 * - `distinct`: `sales_return_line_id`, `credit_note_line_id` (no `quantity`);
 * - `reversal`: `reverses_decision_id`, optional `acknowledged_return_ids` (the other three are refused).
 * Unknown fields are refused (`allow_extra_attributes: false`).
 */
final class CreateAdjustmentDecisionInput
{
    #[SerializedName('sales_return_line_id')]
    public mixed $salesReturnLineId = null;

    #[SerializedName('credit_note_line_id')]
    public mixed $creditNoteLineId = null;

    public mixed $kind = null;

    public mixed $quantity = null;

    #[SerializedName('reverses_decision_id')]
    public mixed $reversesDecisionId = null;

    #[SerializedName('acknowledged_return_ids')]
    public mixed $acknowledgedReturnIds = null;
}
