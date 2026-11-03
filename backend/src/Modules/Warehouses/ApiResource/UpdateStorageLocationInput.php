<?php

declare(strict_types=1);

namespace App\Modules\Warehouses\ApiResource;

use ApiPlatform\Metadata\ApiProperty;
use App\Modules\Warehouses\Application\Contract\LocationKinds;

/**
 * `UpdateStorageLocation`'s body — a PATCH of `code` and/or `kind` (§4.2). Never `warehouse_id` (frozen: moving a location
 * would move its stock between warehouses with no movement — sent, it is refused as undeclared). Properties are left
 * **uninitialised** so an absent field and an explicit `null` stay distinguishable (`UpdateWarehouseInput`'s precedent; the
 * writer refuses `null` on both, which are NOT NULL).
 */
final class UpdateStorageLocationInput
{
    public ?string $code;

    /**
     * Served as one of {@see LocationKinds::ALL}, never `null` and with NO `default` (BK-19): an absent `kind` on a patch
     * leaves the location's kind as it is, it does not reset it to `bin`. An annotation only; the writer checks the value.
     */
    #[ApiProperty(schema: ['type' => 'string', 'enum' => LocationKinds::ALL])]
    public ?string $kind;

    /** @return array<string, mixed> wire name => value, for exactly the fields the body carried */
    public function submitted(): array
    {
        $submitted = [];

        foreach ((new \ReflectionClass($this))->getProperties(\ReflectionProperty::IS_PUBLIC) as $property) {
            if ($property->isInitialized($this)) {
                $submitted[$property->getName()] = $property->getValue($this);
            }
        }

        return $submitted;
    }
}
