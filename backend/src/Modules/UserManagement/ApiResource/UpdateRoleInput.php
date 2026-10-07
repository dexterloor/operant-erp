<?php

declare(strict_types=1);

namespace App\Modules\UserManagement\ApiResource;

use Symfony\Component\Serializer\Attribute\SerializedName;

/**
 * `UpdateRole`'s accepted request fields — §4.2's cell verbatim: `name; description; permission_codes` (roles slice 4, C2).
 * Any subset; a present `permission_codes` REPLACES the role's set. Every member is `mixed` so the service answers a wrong
 * type with the house 422 (`CreateRoleInput`'s reason). Unknown keys are refused (`allow_extra_attributes => false`);
 * `id`, `is_system`, `holder_count`, `archived_at` and `margin_exposure` are not accepted (B-3, E-15h).
 *
 * A `PATCH` must tell three cases apart — absent, sent as `null`, sent with a value — so the properties are declared
 * **uninitialised** and {@see self::submitted()} separates them with `\ReflectionProperty::isInitialized()`:
 * `Modules\Inventory\ApiResource\UpdateItemInput`'s shipped precedent, inline on the DTO (a shared trait of that name collides
 * in `PurchaseOrderRowLockTest`'s short-name scan, as `UpdateSalesOrderInput`'s docblock records).
 */
final class UpdateRoleInput
{
    public mixed $name;

    public mixed $description;

    #[SerializedName('permission_codes')]
    public mixed $permissionCodes;

    /**
     * The members this request actually carried, keyed by their WIRE names.
     *
     * @return array<string, mixed>
     */
    public function submitted(): array
    {
        $wire = ['name' => 'name', 'description' => 'description', 'permissionCodes' => 'permission_codes'];
        $submitted = [];

        foreach ((new \ReflectionClass($this))->getProperties(\ReflectionProperty::IS_PUBLIC) as $property) {
            if ($property->isInitialized($this)) {
                $submitted[$wire[$property->getName()]] = $property->getValue($this);
            }
        }

        return $submitted;
    }
}
