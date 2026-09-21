<?php

declare(strict_types=1);

namespace App\Modules\Sales\ApiResource;

use Symfony\Component\Serializer\Attribute\SerializedName;

/**
 * `UpdateSalesOrderLine`'s accepted request fields (§4.2): `quantity` and `unit_price`.
 *
 * `inventory_item_id` is deliberately absent, and that is §4.2's cell rather than a choice — the purchase
 * twin's docblock gives the reason in terms: *changing which item a line is for is not an edit of that
 * line, it is a different line.* Both columns are `NOT NULL`, so neither may be cleared by a `PATCH`.
 *
 * ⚠ **`unit_price` is D-A's frozen column**, and the freeze is carried by §8.3a's edit window rather than
 * by a per-field rule: draft-only editing refuses every request the freeze would refuse, from every
 * non-draft status, and more besides. What makes that more than an argument is
 * `EditWindowGuardTest::theUnitPriceFreezeIsCarriedByTheEditWindowOnlyWhileTheWindowIsDraftOnly`, which
 * asserts the window of **every row naming this operation** — so widening the window, splitting this
 * operation onto a row of its own, or dropping it from the table all go red. Read that method before
 * touching either the row or this field.
 *
 * ===========================================================================
 * Why `submitted()` is written here and not taken from a trait — MEASURED
 * ===========================================================================
 * A `PATCH` has to tell three cases apart — absent, sent as `null`, sent with a value — and a property
 * with a `= null` default collapses the first two. The properties below are declared **uninitialised**
 * and `\ReflectionProperty::isInitialized()` separates them.
 *
 * `Modules\Purchasing\ApiResource\SubmittedFields` cannot be copied per module. Measured on 2026-09-21
 * by the slice-3 build, on its first run:
 *
 *     LogicException: Two classes in src/ share the short name in SubmittedFields::submitted;
 *                     key the scan by FQCN.
 *
 * The writer scan keys every class in `src/` by its **short name** and refuses on collision, so the
 * available shape is `Modules\Inventory\ApiResource\UpdateItemInput`'s: the method inline, on the DTO.
 *
 * ⚠ **This is the FOURTH hand-written copy of four lines of reflection**, and `UpdateSalesOrderInput`'s
 * docblock already names the `SharedKernel` promotion with an owner and records that the design's
 * *"copying is free"* was wrong. It is **not** done here: the promotion edits four shipped DTOs in a
 * commit whose subject is something else, and a fifth copy is now near-certain (`CreateFulfilment`). The
 * cost of deferring it again is that each copy makes the promotion's diff larger, and that cost is
 * recorded rather than absorbed silently.
 */
final class UpdateSalesOrderLineInput
{
    public ?string $quantity;

    #[SerializedName('unit_price')]
    public ?string $unitPrice;

    /**
     * The fields this request actually carried, keyed by property name.
     *
     * @return array<string, string|null>
     */
    public function submitted(): array
    {
        $submitted = [];

        foreach ((new \ReflectionClass($this))->getProperties(\ReflectionProperty::IS_PUBLIC) as $property) {
            if ($property->isInitialized($this)) {
                /** @var string|null $value */
                $value = $property->getValue($this);
                $submitted[$property->getName()] = $value;
            }
        }

        return $submitted;
    }
}
