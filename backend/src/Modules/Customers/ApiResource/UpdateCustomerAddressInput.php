<?php

declare(strict_types=1);

namespace App\Modules\Customers\ApiResource;

use Symfony\Component\Serializer\Attribute\SerializedName;

/**
 * `UpdateCustomerAddress`'s body — a PATCH of any of §4.2's five fields. Properties are left **uninitialised** so an absent
 * field and an explicit `null` stay distinguishable (`UpdateCustomerInput`'s precedent; the writer refuses `null` on all
 * five, which are NOT NULL).
 */
final class UpdateCustomerAddressInput
{
    public ?string $kind;

    public ?string $line;

    public ?string $city;

    #[SerializedName('postal_code')]
    public ?string $postalCode;

    #[SerializedName('country_code')]
    public ?string $countryCode;

    private const WIRE = [
        'kind' => 'kind',
        'line' => 'line',
        'city' => 'city',
        'postalCode' => 'postal_code',
        'countryCode' => 'country_code',
    ];

    /** @return array<string, ?string> wire name => value, for exactly the fields the body carried */
    public function submitted(): array
    {
        $submitted = [];

        foreach ((new \ReflectionClass($this))->getProperties(\ReflectionProperty::IS_PUBLIC) as $property) {
            if ($property->isInitialized($this)) {
                $submitted[self::WIRE[$property->getName()]] = $property->getValue($this);
            }
        }

        return $submitted;
    }
}
