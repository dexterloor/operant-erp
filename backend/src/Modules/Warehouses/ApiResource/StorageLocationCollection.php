<?php

declare(strict_types=1);

namespace App\Modules\Warehouses\ApiResource;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\Link;
use ApiPlatform\Metadata\QueryParameter;
use ApiPlatform\OpenApi\Model\Operation as OpenApiOperation;
use App\Modules\Warehouses\ApiResource\State\StorageLocationCollectionProvider;
use App\Platform\Security\PermissionGate;
use Symfony\Component\Serializer\Attribute\SerializedName;

/**
 * **`ListStorageLocations` — `GET /api/v1/warehouses/{id}/locations`, `storage_location.view`, *(bounded)*, `archived`**
 * (warehouses slice 1 C1; design §1.6, §2.1 and FINAL item 2; `ListSupplierProducts`' shape).
 *
 * **Bounded (TB-1c)**: both cursors always null; no `limit`, `after`, `before` or `sort`. Rows come back by `code`, then
 * `id`. `archived` is offered on **API-19c limb 2** — its text names this operation: a bounded sub-list of a
 * `SchemaManifest::SOFT` table. `archived=true` returns the archived locations **instead of** the live ones.
 *
 * **Gated `storage_location.view` alone** (PC-9's surface exception, the `ListSupplierProducts` precedent): order is
 * permission → parent 404 → 200. A holder of `storage_location.view` without `warehouse.view` learns whether a warehouse id
 * exists by 404-vs-200 — true of no default role (`SECURITY_MODEL.md` §4); put to `SEC` as a `RoleCompanions` row
 * (`storage_location.view` → `warehouse.view`). It is **not a bin picker** for roles without `storage_location.view`
 * (`sales_rep`, `buyer`, `purchasing_manager`): they are refused (§15.1).
 *
 * An archived warehouse's locations are listed (§5.2a); an unknown or another workspace's warehouse is the same 404. The
 * denial subject is declared `['warehouse', 'id']`: the permission names `storage_location`, the path's `{id}` a warehouse.
 */
#[ApiResource(
    shortName: 'StorageLocationCollection',
    operations: [
        new Get(
            uriTemplate: '/warehouses/{id}/locations',
            uriVariables: [
                'id' => new Link(fromClass: WarehouseResource::class, identifiers: ['id'], parameterName: 'id'),
            ],
            name: 'ListStorageLocations',
            openapi: new OpenApiOperation(operationId: 'ListStorageLocations'),
            provider: StorageLocationCollectionProvider::class,
            parameters: [
                'archived' => new QueryParameter(
                    description: 'Return the archived locations instead of the live ones. API-19c limb 2: a bounded sub-list of a SchemaManifest::SOFT table.',
                    schema: ['type' => 'boolean', 'default' => false],
                ),
            ],
            // §4.2's `Perm` cell, enforced before the provider runs (E-1).
            extraProperties: [
                PermissionGate::OPERATION_PERMISSION => 'storage_location.view',
                PermissionGate::OPERATION_DENIAL_SUBJECT => ['warehouse', 'id'],
            ],
        ),
    ],
)]
final class StorageLocationCollection
{
    /**
     * @param list<StorageLocationResource> $items
     */
    public function __construct(
        // A `$ref` since C3 (2026-10-13): `CreateStorageLocation` outputs `StorageLocationResource`, so the component exists.
        // (C1 published an inline `WIRE_SCHEMA` here while no `StorageLocation` operation was built.)
        #[ApiProperty(schema: ['type' => 'array', 'items' => ['$ref' => '#/components/schemas/StorageLocation']])]
        public array $items,
        /** Always null on a bounded list (§4.4.2). */
        #[SerializedName('next_cursor')]
        public ?string $nextCursor,
        #[SerializedName('previous_cursor')]
        public ?string $previousCursor,
        /** ST-2's discriminator (API-22): does this workspace hold any storage location at all — probed on an empty set. */
        #[SerializedName('tenant_has_rows')]
        public bool $tenantHasRows,
    ) {
    }
}
