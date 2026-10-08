<?php

declare(strict_types=1);

namespace App\Modules\UserManagement\ApiResource;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\Link;
use ApiPlatform\Metadata\QueryParameter;
use ApiPlatform\OpenApi\Model\Operation as OpenApiOperation;
use App\Modules\UserManagement\ApiResource\State\OfferedRoleCollectionProvider;
use App\Platform\Security\PermissionGate;
use Symfony\Component\Serializer\Attribute\SerializedName;

/**
 * **`ListOfferedRoles` — `GET /api/v1/users/{id}/offered-roles`, `role.assign`, *(bounded)*, `archived`; `q`** (users
 * slice 1 C3; owner ruling Q-U2, 2026-10-08; `docs/session13/users-s1-design.md` rev 3 §2.2).
 *
 * The F-7 picker's data (V2-C-2): per role, pairwise PC-13 conflicts, PC-12 clause 4 margin partners, the `AssignRole`
 * refusal minus `proposal_pending` (Q-1), the E-15e notice. **Gated `role.assign`** — `SEC`'s V2-R-25 ruling (b) made a
 * gate (R1-1): `viewer` gets the gate's 403, byte-equal for a real and a random id. A composed `role.assign` seat without
 * `role.view` gets 200 (`SEC` V-2; residual `RESIDUAL_GAPS.md` G-34); since owner ruling Q-U3 every `role.assign` seat also
 * holds `app_user.view` (`RoleCompanions`). The denial subject is the USER (`['app_user', 'id']`), as `AssignRole`'s.
 *
 * `archived` (API-19c limb 2: bounded, derived from soft `role`) and `q` (TB-5's `role.name`, a case-insensitive prefix)
 * are forced by the shipped contract tests for a list derived from roles (RED-2 H-1); they select which items are
 * returned and nothing else. Rows by `name`, then `id`; *(bounded)*, so the cursors are always null.
 */
#[ApiResource(
    shortName: 'OfferedRoleCollection',
    operations: [
        new Get(
            uriTemplate: '/users/{id}/offered-roles',
            uriVariables: [
                'id' => new Link(fromClass: OfferedRoleCollection::class, identifiers: [], parameterName: 'id'),
            ],
            name: 'ListOfferedRoles',
            openapi: new OpenApiOperation(operationId: 'ListOfferedRoles'),
            provider: OfferedRoleCollectionProvider::class,
            parameters: [
                'archived' => new QueryParameter(
                    description: 'Return the archived roles (each refused) instead of the live ones. API-19c limb 2: a bounded list derived from role, a soft table.',
                    schema: ['type' => 'boolean', 'default' => false],
                ),
                'q' => new QueryParameter(
                    description: 'Free-text search. API-19d/TB-5 name one searchable column on this table, role.name, and it is the only column searched (a case-insensitive prefix, IA TB-5a). It selects which roles are returned, never what their pair lists are computed against.',
                    schema: ['type' => 'string'],
                ),
            ],
            extraProperties: [
                // §4.2's `Perm` cell. Enforced by `PermissionGate` on `kernel.controller`, before the provider loads anything.
                PermissionGate::OPERATION_PERMISSION => 'role.assign',
                // `{id}` addresses the USER the roles are offered to (`AssignRole`'s declaration).
                PermissionGate::OPERATION_DENIAL_SUBJECT => ['app_user', 'id'],
            ],
        ),
    ],
)]
final class OfferedRoleCollection
{
    /**
     * @param list<OfferedRoleResource> $items
     */
    public function __construct(
        // An **inline** item schema: `OfferedRoleResource` is not an ApiResource (no operation outputs one), so no
        // `OfferedRole` component is minted and a `$ref` would dangle (`OpenApiContractTest::everyReferenceInTheSpecResolves`).
        #[ApiProperty(schema: ['type' => 'array', 'items' => OfferedRoleResource::WIRE_SCHEMA])]
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
