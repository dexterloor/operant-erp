<?php

declare(strict_types=1);

namespace App\Modules\UserManagement\ApiResource;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\QueryParameter;
use ApiPlatform\OpenApi\Model\Operation as OpenApiOperation;
use App\Modules\UserManagement\ApiResource\State\RoleCollectionProvider;
use App\Platform\Security\PermissionGate;
use Symfony\Component\Serializer\Attribute\SerializedName;

/**
 * `ListRoles` — `GET /api/v1/roles`, `role.view`, *(bounded)*, `archived`; `q`. Roles slice 1, C4.
 *
 * **Bounded (TB-1c)**: the envelope's cursors are always null and there is no `limit`, `after`, `before` or
 * `sort` — undeclared, and therefore refused (§10.3 step 4, §10.2 exclusion 2).
 *
 * `archived` is offered on **API-19c limb 1**: `IA.md` §5.6 has an `/admin/roles Archived` row that is not
 * `⛔`. `q` is offered because TB-5 names `role.name` (API-19d). Rows come back by `name`, then `id`.
 *
 * Each item is the `Role` component — `GetRole`'s shape, which is why `GetRole` shipped first: the `$ref`
 * resolves only once that operation mints the component. The "no privilege history" rule is the same as
 * `GetRole`'s, and `RoleReadTest` drives this operation's three forms through it.
 */
#[ApiResource(
    shortName: 'RoleCollection',
    operations: [
        new Get(
            uriTemplate: '/roles',
            name: 'ListRoles',
            openapi: new OpenApiOperation(operationId: 'ListRoles'),
            provider: RoleCollectionProvider::class,
            parameters: [
                'archived' => new QueryParameter(
                    description: 'Return the archived roles instead of the live ones. API-19c limb 1: IA §5.6 has an /admin/roles Archived view.',
                    schema: ['type' => 'boolean', 'default' => false],
                ),
                'q' => new QueryParameter(
                    description: 'Free-text search. API-19d/TB-5 name one searchable column on this table, role.name, and it is the only column searched (a case-insensitive prefix, IA TB-5a).',
                    schema: ['type' => 'string'],
                ),
            ],
            // §4.2's `Perm` cell. Enforced by `PermissionGate` on `kernel.controller`, before the provider
            // loads anything — E-1.
            extraProperties: [PermissionGate::OPERATION_PERMISSION => 'role.view'],
        ),
    ],
)]
final class RoleCollection
{
    /**
     * @param list<RoleResource> $items
     */
    public function __construct(
        #[ApiProperty(schema: ['type' => 'array', 'items' => ['$ref' => '#/components/schemas/Role']])]
        public array $items,
        /** Always null on a bounded list (§4.4.2). */
        #[SerializedName('next_cursor')]
        public ?string $nextCursor,
        #[SerializedName('previous_cursor')]
        public ?string $previousCursor,
        /** ST-2's discriminator: probed only on an empty page, against `role` with no filter predicate (API-22). */
        #[SerializedName('tenant_has_rows')]
        public bool $tenantHasRows,
    ) {
    }
}
