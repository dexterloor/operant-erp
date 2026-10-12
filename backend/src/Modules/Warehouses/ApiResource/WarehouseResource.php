<?php

declare(strict_types=1);

namespace App\Modules\Warehouses\ApiResource;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\Metadata\Post;
use ApiPlatform\OpenApi\Model\Operation as OpenApiOperation;
use App\Modules\Warehouses\ApiResource\State\CreateWarehouseProcessor;
use App\Modules\Warehouses\ApiResource\State\UpdateWarehouseProcessor;
use App\Modules\Warehouses\ApiResource\State\WarehouseProvider;
use App\Modules\Warehouses\Application\WarehouseView;
use App\Platform\Http\Idempotency\IdempotencyKeyGuard;
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
 *
 * **`CreateWarehouse` / `UpdateWarehouse`** (warehouses slice 1 C2): `WarehouseWriter` behind both, `SupplierResource`'s
 * operation shape. Each writes one audit row in its transaction (`warehouse.created` `{values}` / `warehouse.updated`
 * `{fields, from, to}`; owner Q-W2 (a)). One live unique (code), refused, never replayed; the name is not unique.
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
        new Post(
            uriTemplate: '/warehouses',
            name: 'CreateWarehouse',
            status: 201,
            openapi: new OpenApiOperation(operationId: 'CreateWarehouse'),
            input: CreateWarehouseInput::class,
            denormalizationContext: [
                // API-9: unknown fields are refused and named back (`address_line`, `city`, `postal_code`, `is_active`
                // included, Q-W4 / G-36). A client `id` is refused by the layers `ItemResource` records (B-3);
                // `WarehouseWriter::create()` mints the id regardless.
                'allow_extra_attributes' => false,
                'api_assign_object_to_populate' => false,
            ],
            processor: CreateWarehouseProcessor::class,
            // §4.2: `unique:warehouse_tenant_code_live`. API-14 demands the header; a repeat is refused by the code's live
            // unique, never replayed (`CreateSupplier`'s precedent).
            extraProperties: [
                IdempotencyKeyGuard::OPERATION_FLAG => true,
                PermissionGate::OPERATION_PERMISSION => 'warehouse.create',
            ],
        ),
        new Patch(
            uriTemplate: '/warehouses/{id}',
            name: 'UpdateWarehouse',
            status: 200,
            openapi: new OpenApiOperation(operationId: 'UpdateWarehouse'),
            input: UpdateWarehouseInput::class,
            denormalizationContext: [
                'allow_extra_attributes' => false,
                'api_assign_object_to_populate' => false,
                // A body `id` is an undeclared field, never an IRI to resolve (`UpdateItem`'s reason).
                'api_allow_update' => false,
            ],
            // The row is read first, so a PATCH at an id no row answers is `GetWarehouse`'s 404 (§5.3 clause 2).
            provider: WarehouseProvider::class,
            processor: UpdateWarehouseProcessor::class,
            extraProperties: [PermissionGate::OPERATION_PERMISSION => 'warehouse.edit'],
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
