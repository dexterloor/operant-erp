<?php

declare(strict_types=1);

namespace App\Modules\Warehouses\ApiResource;

use Symfony\Component\Serializer\Attribute\SerializedName;

/**
 * `UpdateWarehouse`'s body — a PATCH of any of §4.2's three fields. Properties are left **uninitialised** so an absent
 * field and an explicit `null` stay distinguishable (`UpdateSupplierInput`'s precedent; the writer refuses `null` on all
 * three, which are NOT NULL).
 */
final class UpdateWarehouseInput
{
    public ?string $code;

    public ?string $name;

    #[SerializedName('country_code')]
    public ?string $countryCode;

    private const WIRE = [
        'code' => 'code',
        'name' => 'name',
        'countryCode' => 'country_code',
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
