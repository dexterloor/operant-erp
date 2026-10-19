<?php

declare(strict_types=1);

namespace App\Modules\Customers\ApiResource;

use Symfony\Component\Serializer\Attribute\SerializedName;

/**
 * `UpdateCustomerContact`'s body — a PATCH of any of §4.2's four fields. Properties are left **uninitialised** so an absent
 * field and an explicit `null` stay distinguishable (`UpdateCustomerAddressInput`'s precedent): `null` clears `email` or
 * `phone` and is refused on `name` and `is_primary`. `is_primary` is `mixed` so a non-boolean reaches the writer's
 * `not_a_boolean`.
 */
final class UpdateCustomerContactInput
{
    public ?string $name;

    public ?string $email;

    public ?string $phone;

    #[SerializedName('is_primary')]
    public mixed $isPrimary;

    private const WIRE = [
        'name' => 'name',
        'email' => 'email',
        'phone' => 'phone',
        'isPrimary' => 'is_primary',
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
