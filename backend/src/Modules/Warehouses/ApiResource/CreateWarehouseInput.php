<?php

declare(strict_types=1);

namespace App\Modules\Warehouses\ApiResource;

use Symfony\Component\Serializer\Attribute\SerializedName;

/**
 * `CreateWarehouse`'s body — §4.2's accepted cell: `code`; `name`; `country_code`. No `id` (B-3): the server mints it.
 * Every value is checked by `WarehouseWriter`, so a defect is a 422 naming the field. `address_line`, `city`,
 * `postal_code` and `is_active` are not fields (Q-W4; `RESIDUAL_GAPS.md` G-36): sent, they are refused as undeclared
 * (API-9).
 */
final class CreateWarehouseInput
{
    public ?string $code = null;

    public ?string $name = null;

    #[SerializedName('country_code')]
    public ?string $countryCode = null;

    /** @return array<string, mixed> wire name => value */
    public function submitted(): array
    {
        return ['code' => $this->code, 'name' => $this->name, 'country_code' => $this->countryCode];
    }
}
