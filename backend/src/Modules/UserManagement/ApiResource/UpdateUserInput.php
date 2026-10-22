<?php

declare(strict_types=1);

namespace App\Modules\UserManagement\ApiResource;

use Symfony\Component\Serializer\Attribute\SerializedName;

/**
 * `UpdateUser`'s accepted request fields — §4.2's cell verbatim: `display_name; is_active` (G4-C1). Any subset. Every member
 * is `mixed` so the service answers a wrong type with the house 422 (`UpdateRoleInput`'s reason). Unknown keys are refused
 * (`allow_extra_attributes => false`); `email`, `roles`, `archived_at`, `margin_exposure` are not accepted (`email` is
 * immutable here, roles are `AssignRole`/`RevokeRole`'s).
 *
 * **The members as submitted, and nothing else** (first RED M-3, R1.2 G4-C1 item 1): no state provider merges a `User`
 * object into this DTO, and nothing reads the person before the role lock (`read: false`). The properties are declared
 * **uninitialised** so {@see self::submitted()} can tell absent from `null` (`UpdateRoleInput`'s precedent).
 */
final class UpdateUserInput
{
    #[SerializedName('display_name')]
    public mixed $displayName;

    #[SerializedName('is_active')]
    public mixed $isActive;

    /**
     * The members this request actually carried, keyed by their WIRE names.
     *
     * @return array<string, mixed>
     */
    public function submitted(): array
    {
        $wire = ['displayName' => 'display_name', 'isActive' => 'is_active'];
        $submitted = [];

        foreach ((new \ReflectionClass($this))->getProperties(\ReflectionProperty::IS_PUBLIC) as $property) {
            if ($property->isInitialized($this)) {
                $submitted[$wire[$property->getName()]] = $property->getValue($this);
            }
        }

        return $submitted;
    }
}
