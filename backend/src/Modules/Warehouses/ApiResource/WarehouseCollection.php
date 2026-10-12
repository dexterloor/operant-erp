<?php

declare(strict_types=1);

namespace App\Modules\Warehouses\ApiResource;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\QueryParameter;
use ApiPlatform\OpenApi\Model\Operation as OpenApiOperation;
use App\Modules\Warehouses\ApiResource\State\WarehouseCollectionProvider;
use App\Platform\Security\PermissionGate;
use Symfony\Component\Serializer\Attribute\SerializedName;

/**
 * **`ListWarehouses` — `GET /api/v1/warehouses`, `warehouse.view`, *(bounded)*, `archived`; `q`** (warehouses slice 1
 * C1; design §1.6 and FINAL item 2; `ListRoles`' shape).
 *
 * **Bounded (TB-1c)**: the envelope's cursors are always null and there is no `limit`, `after`, `before` or `sort` —
 * undeclared, and therefore refused (§10.3 step 4, §10.2 exclusion 2). Rows come back by `code`, then `id`.
 *
 * `archived` is offered on **API-19c limb 1**: `IA.md` §5.6 has a `/warehouses Archived` row. `archived=true` returns the
 * archived set **instead of** the live one. `q` is offered because TB-5 names `warehouse.code` (API-19d): a
 * **case-sensitive prefix on the code, as stored** (TB-5a), and nothing else — `warehouse.name` is filtered in the client.
 *
 * Each item is the `Warehouse` component — `GetWarehouse`'s shape, which is why `GetWarehouse` ships in the same commit:
 * the `$ref` resolves only once that operation mints the component.
 */
#[ApiResource(
    shortName: 'WarehouseCollection',
    operations: [
        new Get(
            uriTemplate: '/warehouses',
            name: 'ListWarehouses',
            openapi: new OpenApiOperation(operationId: 'ListWarehouses'),
            provider: WarehouseCollectionProvider::class,
            parameters: [
                'archived' => new QueryParameter(
                    description: 'Return the archived warehouses instead of the live ones. API-19c limb 1: IA §5.6 has a /warehouses Archived view.',
                    schema: ['type' => 'boolean', 'default' => false],
                ),
                'q' => new QueryParameter(
                    description: 'Free-text search. API-19d/TB-5 name one searchable column on this table, warehouse.code, and it is the only column searched (a prefix, matched as stored, IA TB-5a).',
                    schema: ['type' => 'string'],
                ),
            ],
            // §4.2's `Perm` cell. Enforced by `PermissionGate` on `kernel.controller`, before the provider loads anything (E-1).
            extraProperties: [PermissionGate::OPERATION_PERMISSION => 'warehouse.view'],
        ),
    ],
)]
final class WarehouseCollection
{
    /**
     * @param list<WarehouseResource> $items
     */
    public function __construct(
        #[ApiProperty(schema: ['type' => 'array', 'items' => ['$ref' => '#/components/schemas/Warehouse']])]
        public array $items,
        /** Always null on a bounded list (§4.4.2). */
        #[SerializedName('next_cursor')]
        public ?string $nextCursor,
        #[SerializedName('previous_cursor')]
        public ?string $previousCursor,
        /** ST-2's discriminator: probed only on an empty set, against `warehouse` with no filter predicate (API-22). */
        #[SerializedName('tenant_has_rows')]
        public bool $tenantHasRows,
    ) {
    }
}
