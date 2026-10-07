<?php

declare(strict_types=1);

namespace App\Modules\UserManagement\ApiResource;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\OpenApi\Model\Operation as OpenApiOperation;
use App\Modules\UserManagement\ApiResource\State\PermissionCollectionProvider;
use App\Platform\Security\PermissionGate;
use Symfony\Component\Serializer\Attribute\SerializedName;

/**
 * `ListPermissions` — `GET /api/v1/permissions`, `role.view`, *(bounded)*. Roles slice 1.
 *
 * The permission vocabulary a role is composed from: global, seeded, the same for every tenant
 * (IA §6.2). **Bounded (TB-1c)**, so the envelope's cursors are always null and there is **no
 * parameter at all** — `limit`, `after`, `before` and `sort` are undeclared and therefore refused by
 * `Platform\Http\UndeclaredQueryParameterRefusal` (API-19: refuse rather than ignore).
 */
#[ApiResource(
    shortName: 'PermissionCollection',
    operations: [
        new Get(
            uriTemplate: '/permissions',
            name: 'ListPermissions',
            openapi: new OpenApiOperation(operationId: 'ListPermissions'),
            provider: PermissionCollectionProvider::class,
            // §4.2's `Perm` cell. Enforced by `PermissionGate` on `kernel.controller`, before the provider
            // loads anything — E-1.
            extraProperties: [PermissionGate::OPERATION_PERMISSION => 'role.view'],
        ),
    ],
)]
final class PermissionCollection
{
    /**
     * @param list<PermissionResource> $items
     */
    public function __construct(
        // An **inline** item schema, not a `$ref`: `PermissionResource` is not an ApiResource and no
        // operation outputs it, so API Platform mints no `Permission` component and a `$ref` would dangle
        // (`OpenApiContractTest::everyReferenceInTheSpecResolves`). `PermissionListTest::
        // theInlineItemSchemaIsTheDtosShape` holds these property names equal to the DTO's.
        #[ApiProperty(schema: ['type' => 'array', 'items' => [
            'type' => 'object',
            'required' => ['code', 'module', 'description', 'sod_rows'],
            'properties' => [
                'code' => ['type' => 'string'],
                'module' => ['type' => 'string'],
                'description' => ['type' => 'string'],
                // V2-C-5 (roles slice 4 C0): every SoD row naming the code, and its half. `[]` for a code on no split.
                'sod_rows' => ['type' => 'array', 'items' => [
                    'type' => 'object',
                    'required' => ['id', 'half'],
                    'properties' => [
                        'id' => ['type' => 'string'],
                        'half' => ['type' => 'string', 'enum' => ['preparing', 'controlled']],
                    ],
                ]],
            ],
        ]])]
        public array $items,
        /** Always null on a bounded list (§4.4.2). */
        #[SerializedName('next_cursor')]
        public ?string $nextCursor,
        #[SerializedName('previous_cursor')]
        public ?string $previousCursor,
        /** ST-2's discriminator. Never false here: the catalogue is global and seeded (see `PermissionReader`). */
        #[SerializedName('tenant_has_rows')]
        public bool $tenantHasRows,
    ) {
    }
}
