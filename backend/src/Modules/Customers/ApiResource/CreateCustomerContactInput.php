<?php

declare(strict_types=1);

namespace App\Modules\Customers\ApiResource;

use Symfony\Component\Serializer\Attribute\SerializedName;

/**
 * `CreateCustomerContact`'s body — §4.2's accepted cell: `name`; `email`; `phone`; `is_primary`. No `id` (B-3) and no
 * `customer_id` (the path implies it, API-23). Properties are left **uninitialised** so an absent field and an explicit
 * `null` stay distinguishable; the writer applies the defaults (`email`/`phone` null, `is_primary` false) and checks every
 * value, so a defect is a 422 naming the field. `is_primary` is `mixed` so a non-boolean reaches the writer's
 * `not_a_boolean` (`UpdateCustomerInput::$paymentTermsDays`' precedent).
 */
final class CreateCustomerContactInput
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
