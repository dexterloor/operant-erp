<?php

declare(strict_types=1);

namespace App\Modules\Sales\Application;

use Symfony\Component\Serializer\Attribute\Context;
use Symfony\Component\Serializer\Attribute\SerializedName;
use Symfony\Component\Serializer\Normalizer\AbstractObjectNormalizer;

/**
 * **One decision about an adjustment line, as the credit note serves it** (`return_decisions[]`; G-29 / G-30 part 3,
 * design §5, H-1 fix 2): which return line was declared the same goods as this adjustment's units, or different. Served
 * ONLY to an `invoice.view` holder, in every one of the four credit-note responses including both credit writes' 201
 * (J-8; `SEC` S-7: a hold-test field under PC-17, not PC-15). `sales_return {id, sales_return_number}` matches the shipped
 * `CreditNote.sales_return` reference. `id` and `reverses_decision_id` as on {@see AdjustmentDecisionView}. **No
 * `sales_return_line_id`** (design §5 listed one): API-23b forbids a bare `_id`, a return line has no display field, and
 * the line is already determined — one return line per shipped line per return (`sales_return_line_once_per_line`), and
 * this credit-note line names one shipped line.
 */
final readonly class ReturnDecisionView
{
    public function __construct(
        public string $id,
        #[SerializedName('sales_return')]
        public SalesReturnNumberRef $salesReturn,
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
