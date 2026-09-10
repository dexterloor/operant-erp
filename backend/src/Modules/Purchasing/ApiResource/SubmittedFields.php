<?php

declare(strict_types=1);

namespace App\Modules\Purchasing\ApiResource;

/**
 * The `PATCH` three-way distinction, in one place.
 *
 * A `PATCH` has to tell three cases apart — the field was absent, the field was
 * sent as `null`, the field was sent with a value — and a property with a
 * `= null` default collapses the first two. The input DTOs using this trait
 * declare their properties **uninitialised**, and
 * `\ReflectionProperty::isInitialized()` separates them.
 *
 * It is a trait rather than three copies of `UpdateItemInput::submitted()`
 * because three of this module's four input DTOs need it and the fourth
 * (`ApprovePurchaseOrderInput`) needs it for a reason of its own:
 * `PurchaseOrder::place()` assigns `expectedAt` unconditionally, so *"the caller
 * did not send an expected date"* and *"the caller cleared the expected date"*
 * are the difference between preserving the order's date and silently erasing it.
 *
 * The set of fields a request carried is therefore **derived** from the object
 * rather than restated as a list in the writer.
 */
trait SubmittedFields
{
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
