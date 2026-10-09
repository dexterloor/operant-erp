<?php

declare(strict_types=1);

namespace App\Modules\Customers\ApiResource;

use Symfony\Component\Serializer\Attribute\SerializedName;

/**
 * `UpdateCustomer`'s body — a PATCH of any of §4.2's five fields. Properties are left **uninitialised** so an absent
 * field and an explicit `null` stay distinguishable (`UpdateItemInput`'s precedent; the writer refuses `null` on all five,
 * which are NOT NULL).
 */
final class UpdateCustomerInput
{
    public ?string $code;

    public ?string $name;

    #[SerializedName('country_code')]
    public ?string $countryCode;

    #[SerializedName('currency_code')]
    public ?string $currencyCode;

    #[SerializedName('payment_terms_days')]
    public mixed $paymentTermsDays;

    private const WIRE = [
        'code' => 'code',
        'name' => 'name',
        'countryCode' => 'country_code',
        'currencyCode' => 'currency_code',
        'paymentTermsDays' => 'payment_terms_days',
    ];

    /** @return array<string, mixed> wire name => value, for exactly the fields the body carried */
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
