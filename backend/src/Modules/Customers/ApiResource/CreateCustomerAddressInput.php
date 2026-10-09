<?php

declare(strict_types=1);

namespace App\Modules\Customers\ApiResource;

use Symfony\Component\Serializer\Attribute\SerializedName;

/**
 * `CreateCustomerAddress`'s body — §4.2's accepted cell: `kind`; `line`; `city`; `postal_code`; `country_code`. No `id`
 * (B-3) and no `customer_id` (the path implies it, API-23). `region` is not accepted (`sales_order` has no column for it),
 * so its column default applies. Every value is checked by `CustomerAddressWriter`, so a defect is a 422 naming the field.
 */
final class CreateCustomerAddressInput
{
    public ?string $kind = null;

    public ?string $line = null;

    public ?string $city = null;

    #[SerializedName('postal_code')]
    public ?string $postalCode = null;

    #[SerializedName('country_code')]
    public ?string $countryCode = null;

    /** @return array<string, ?string> wire name => value */
    public function submitted(): array
    {
        return [
            'kind' => $this->kind,
            'line' => $this->line,
            'city' => $this->city,
            'postal_code' => $this->postalCode,
            'country_code' => $this->countryCode,
        ];
    }
}
