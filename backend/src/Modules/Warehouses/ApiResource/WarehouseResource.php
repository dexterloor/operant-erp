<?php

declare(strict_types=1);

namespace App\Modules\Warehouses\ApiResource;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\OpenApi\Model\Operation as OpenApiOperation;
use App\Modules\Warehouses\ApiResource\State\WarehouseProvider;
use App\Modules\Warehouses\Application\WarehouseView;
use App\Platform\Security\PermissionGate;
use Symfony\Component\Serializer\Attribute\SerializedName;

/**
 * **`GetWarehouse` — `GET /api/v1/warehouses/{id}`, `warehouse.view`** (warehouses slice 1 C1;
 * `docs/session13/warehouses-s1-design.md` §2 and FINAL item 2). A DTO, never the entity (`ItemResource`'s reason).
 *
 * The shape is §4.4.3's derivation (API-23): `id`, the accepted fields `code`, `name`, `country_code`, then `archived_at`
 * (the table is `soft`). `country_code` is a reference-data code, served as a scalar. `address_line`, `city`,
 * `postal_code` and `is_active` are **columns, not fields**: nothing writes them (Q-W4).
 *
 * The gate refuses before the provider loads anything (E-1). A non-uuid, absent or another workspace's id is the same
 * 404; an archived warehouse in the caller's workspace is returned with `archived_at` set (§5.2a, ST-9). Other documents'
 * warehouse reference stays `{id, name}`.
 *
 * **Shipped first in the slice on purpose:** `DenialSubjectTest`'s path-first derivation resolves `/warehouses/{}` to
 * `warehouse` from this built item-`GET`, so every operation nested under it can declare its denial subject.
 */
#[ApiResource(
    shortName: 'Warehouse',
    operations: [
        new Get(
            uriTemplate: '/warehouses/{id}',
            name: 'GetWarehouse',
            openapi: new OpenApiOperation(operationId: 'GetWarehouse'),
            provider: WarehouseProvider::class,
            // §4.2's `Perm` cell. Enforced by `PermissionGate` on `kernel.controller`, before the provider loads anything.
            extraProperties: [PermissionGate::OPERATION_PERMISSION => 'warehouse.view'],
        ),
    ],
)]
final class WarehouseResource
{
    public function __construct(
        public string $id,
        public string $code,
        public string $name,
        #[SerializedName('country_code')]
        public string $countryCode,
        #[SerializedName('archived_at')]
        public ?string $archivedAt,
    ) {
    }

    public static function fromView(WarehouseView $view): self
    {
        return new self($view->id, $view->code, $view->name, $view->countryCode, $view->archivedAt);
    }
}
