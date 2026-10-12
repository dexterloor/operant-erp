<?php

declare(strict_types=1);

namespace App\Modules\Suppliers\ApiResource;

use Symfony\Component\Serializer\Attribute\SerializedName;

/**
 * `CreateSupplier`'s body — §4.2's accepted cell: `code`; `name`; `country_code`; `currency_code`;
 * `payment_terms_days`. No `id` (B-3): the server mints it. Every value is checked by `SupplierWriter`, so a defect is a
 * 422 naming the field. `payment_terms_days` is `mixed` so a non-integer reaches the writer's own `out_of_range`
 * refusal rather than the deserializer's, and is left **uninitialised** so an omitted value (the column's default, 30,
 * applies) and an explicit `null` (refused, `not_nullable`) stay distinguishable — `CreateCustomerInput`'s reason.
 * `email`, `phone` and `is_active` are not fields (owner Q-S6; `RESIDUAL_GAPS.md` G-36): sent, they are refused as
 * undeclared (API-9).
 */
final class CreateSupplierInput
{
    public ?string $code = null;

    public ?string $name = null;

    #[SerializedName('country_code')]
    public ?string $countryCode = null;

    #[SerializedName('currency_code')]
    public ?string $currencyCode = null;

    #[SerializedName('payment_terms_days')]
    public mixed $paymentTermsDays;

    /** @return array<string, mixed> wire name => value, for the fields the body carried (absent ones omitted) */
    public function submitted(): array
    {
        $values = [
            'code' => $this->code,
            'name' => $this->name,
            'country_code' => $this->countryCode,
            'currency_code' => $this->currencyCode,
        ];
        if ((new \ReflectionProperty($this, 'paymentTermsDays'))->isInitialized($this)) {
            $values['payment_terms_days'] = $this->paymentTermsDays;
        }

        return $values;
    }
}
