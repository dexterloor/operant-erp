<?php

declare(strict_types=1);

namespace App\Modules\Warehouses\ApiResource;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Link;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\Metadata\Post;
use ApiPlatform\OpenApi\Model\Operation as OpenApiOperation;
use App\Modules\Warehouses\ApiResource\State\CreateStorageLocationProcessor;
use App\Modules\Warehouses\ApiResource\State\StorageLocationProvider;
use App\Modules\Warehouses\ApiResource\State\UpdateStorageLocationProcessor;
use App\Modules\Warehouses\Application\StorageLocationView;
use App\Platform\Http\Idempotency\IdempotencyKeyGuard;
use App\Platform\Http\Location\CreatedRowLocation;
use App\Platform\Security\PermissionGate;
use Symfony\Component\Serializer\Attribute\SerializedName;

/**
 * **The `StorageLocation` item** `ListStorageLocations` serves (warehouses slice 1 C1), and since C3 the resource of
 * **`CreateStorageLocation`** and **`UpdateStorageLocation`** (`docs/session13/warehouses-s1-design.md` §4 and FINAL item 4;
 * `SupplierProductResource`'s operation shape). A DTO, never the entity.
 *
 * §4.4.3's derivation (API-23): `id`, the accepted fields `code`, `kind`, then `archived_at` (the table is `soft`).
 * `warehouse_id` is path-implied and not served (`SupplierProductResource`'s `supplier_id` precedent). `kind` is one of
 * `bin`, `shelf`, `zone`, `staging`, `quarantine` (`LocationKinds`); stock in a `quarantine` location is never picked.
 *
 * **C3 removed the inline `WIRE_SCHEMA`** C1 published while no `StorageLocation` operation was built: these operations
 * mint the `StorageLocation` component, so `StorageLocationCollection.items` is a `$ref`.
 *
 * **No `GetStorageLocation`**: §4.2 declares none (a bounded sub-list), so API Platform mints its not-exposed fallback route
 * and `CreatedRowLocation` names the URI §4.2 does declare (`/warehouses/{id}/locations/{locationId}`).
 *
 * **`OPERATION_DENIAL_SUBJECT` = `['warehouse', 'id']`** on both (the `ListStorageLocations` precedent): the permission
 * names `storage_location`, the recorded `{id}` a warehouse row.
 *
 * **The quarantine rule** (owner Q-W1 (d)): a hold (into `quarantine`) is always allowed; a release (out of `quarantine`)
 * of a location holding stock is the owner's alone — 409 `storage_location.release_needs_owner` for anyone else
 * (`StorageLocationWriter`; `RESIDUAL_GAPS.md` G-44).
 */
#[ApiResource(
    shortName: 'StorageLocation',
    operations: [
        // API-21 branch 4: §4.2 declares `UpdateStorageLocation` at `/warehouses/{id}/locations/{locationId}`, the row-level
        // URI of the row this POST creates, so the answer is 201 with a `Location` naming it and the location as the body.
        new Post(
            uriTemplate: '/warehouses/{id}/locations',
            uriVariables: [
                'id' => new Link(fromClass: WarehouseResource::class, identifiers: ['id'], parameterName: 'id'),
            ],
            name: 'CreateStorageLocation',
            status: 201,
            openapi: new OpenApiOperation(operationId: 'CreateStorageLocation'),
            input: CreateStorageLocationInput::class,
            denormalizationContext: [
                // API-9: unknown fields are refused and named back — `warehouse_id` included (the path names the warehouse).
                'allow_extra_attributes' => false,
                'api_assign_object_to_populate' => false,
            ],
            processor: CreateStorageLocationProcessor::class,
            // §4.2: `unique:storage_location_tenant_warehouse_code_live`. API-14 demands the header; a repeat is refused by
            // the code's live unique, never replayed (`CreateWarehouse`'s precedent).
            extraProperties: [
                IdempotencyKeyGuard::OPERATION_FLAG => true,
                PermissionGate::OPERATION_PERMISSION => 'storage_location.create',
                PermissionGate::OPERATION_DENIAL_SUBJECT => ['warehouse', 'id'],
                // Without it API Platform derives `Location` from its `NotExposed` fallback (`/storage_locations/{id}`), a
                // path that answers 404 (the reason `CreatedRowLocation` exists).
                CreatedRowLocation::OPERATION_URI_TEMPLATE => '/warehouses/{id}/locations/{locationId}',
            ],
        ),
        new Patch(
            uriTemplate: '/warehouses/{id}/locations/{locationId}',
            uriVariables: [
                'id' => new Link(fromClass: WarehouseResource::class, identifiers: ['id'], parameterName: 'id'),
                'locationId' => new Link(fromClass: StorageLocationResource::class, identifiers: ['id'], parameterName: 'locationId'),
            ],
            name: 'UpdateStorageLocation',
            status: 200,
            openapi: new OpenApiOperation(operationId: 'UpdateStorageLocation'),
            input: UpdateStorageLocationInput::class,
            denormalizationContext: [
                'allow_extra_attributes' => false,
                'api_assign_object_to_populate' => false,
                'api_allow_update' => false,
            ],
            // The pair is read first, so a PATCH at a location no row of THIS warehouse answers is a 404 (§5.3 clause 2).
            provider: StorageLocationProvider::class,
            processor: UpdateStorageLocationProcessor::class,
            // No `OPERATION_FLAG`: §4.2's `Idem` cell is `no` — a PATCH sets absolute values on a row that exists.
            extraProperties: [
                PermissionGate::OPERATION_PERMISSION => 'storage_location.edit',
                PermissionGate::OPERATION_DENIAL_SUBJECT => ['warehouse', 'id'],
            ],
        ),
    ],
)]
final class StorageLocationResource
{
    public function __construct(
        public string $id,
        public string $code,
        public string $kind,
        #[SerializedName('archived_at')]
        public ?string $archivedAt,
    ) {
    }

    public static function fromView(StorageLocationView $view): self
    {
        return new self($view->id, $view->code, $view->kind, $view->archivedAt);
    }
}
