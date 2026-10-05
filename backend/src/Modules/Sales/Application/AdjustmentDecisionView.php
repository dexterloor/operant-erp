<?php

declare(strict_types=1);

namespace App\Modules\Sales\Application;

use Symfony\Component\Serializer\Attribute\Context;
use Symfony\Component\Serializer\Attribute\SerializedName;
use Symfony\Component\Serializer\Normalizer\AbstractObjectNormalizer;

/**
 * **One decision about a return line, as the return serves it** (`adjustment_decisions[]`; G-29 / G-30 part 3,
 * `docs/session13/g29-g30-identity-design.md` §5). Served ONLY to an `invoice.view` holder: the members are credit facts
 * (PC-9; `SEC` S-7 — a hold-test field under PC-17), so the whole list is ABSENT otherwise.
 *
 * - `id`: the decision's own id — what a reversal names in `reverses_decision_id` (D2 (b)). *(Not in design §5's member
 *   list, which predates the ruling on D2; without it no client could address a reversal. Recorded as a deviation.)*
 * - `credit_note`: the ADJUSTMENT the decision is about, `{id, credit_note_number}`.
 * - `kind`: `same` (with `quantity`), `distinct`, or `reversal` (with `reverses_decision_id`). The list is the history,
 *   append-only: a reversed row stays, and the reversal row says which row it undid.
 * - `recorded_by`: PC-11 (4a), ruled by `SEC` 2026-10-05 (`g29-g30-sec.md` §5), owner informed — `{id, display_name}`
 *   only, the authenticated caller at write.
 */
final readonly class AdjustmentDecisionView
{
    public function __construct(
        public string $id,
        #[SerializedName('credit_note')]
        public CreditNoteNumberRef $creditNote,
        public string $kind,
        /** Served as `null` for `distinct` and `reversal`: the parent member's SKIP_NULL context must not reach it. */
        #[Context([AbstractObjectNormalizer::SKIP_NULL_VALUES => false])]
        public ?string $quantity,
        #[SerializedName('reverses_decision_id')]
        #[Context([AbstractObjectNormalizer::SKIP_NULL_VALUES => false])]
        public ?string $reversesDecisionId,
        #[SerializedName('recorded_by')]
        public SalesActorRef $recordedBy,
        #[SerializedName('recorded_at')]
        public string $recordedAt,
    ) {
    }
}
