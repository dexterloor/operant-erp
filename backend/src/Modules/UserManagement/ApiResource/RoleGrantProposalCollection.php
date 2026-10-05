<?php

declare(strict_types=1);

namespace App\Modules\UserManagement\ApiResource;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\QueryParameter;
use ApiPlatform\OpenApi\Model\Operation as OpenApiOperation;
use App\Modules\UserManagement\ApiResource\State\RoleGrantProposalCollectionProvider;
use App\Platform\Security\PermissionGate;
use Symfony\Component\Serializer\Attribute\SerializedName;

/**
 * **`ListRoleGrantProposals` — the open (and decided) role proposals, newest first** (roles slice 3, C1;
 * `docs/session13/roles-s3-design.md` §3.1, §4.6). Gated on **`role.assign`, deliberately not `role.view`** (§5.2.3): a
 * pending proposal is privilege history and `viewer` holds `role.view`. **Keyset-paginated, not `(bounded)`** (design F-7,
 * owner acknowledgement Q-4 (a)): proposals are class `none` and accumulate for the tenant's lifetime. It is also the
 * route the role screens use to find the proposal behind a refusal (`?user=&role=&status=pending`, Q-4 (d)).
 */
#[ApiResource(
    shortName: 'RoleGrantProposalCollection',
    operations: [
        new Get(
            uriTemplate: '/role-grant-proposals',
            name: 'ListRoleGrantProposals',
            openapi: new OpenApiOperation(operationId: 'ListRoleGrantProposals'),
            provider: RoleGrantProposalCollectionProvider::class,
            parameters: [
                'status' => new QueryParameter(
                    description: 'Restrict to one stored status. An expired proposal is still `pending` (its expiry is derived and shown as `is_expired`).',
                    schema: ['type' => 'string', 'enum' => RoleGrantProposalCollectionProvider::STATUSES],
                ),
                'user' => new QueryParameter(
                    description: 'Restrict to the proposals naming one person as the subject. A person this workspace cannot see matches nothing: an empty page, never a 404.',
                    schema: ['type' => 'string', 'format' => 'uuid'],
                ),
                'role' => new QueryParameter(
                    description: 'Restrict to the proposals of one role. A role this workspace cannot see matches nothing: an empty page, never a 404.',
                    schema: ['type' => 'string', 'format' => 'uuid'],
                ),
                'limit' => new QueryParameter(
                    description: 'Page size. API-17: default 50, maximum 200.',
                    schema: [
                        'type' => 'integer',
                        'minimum' => 1,
                        'maximum' => RoleGrantProposalCollectionProvider::MAXIMUM_LIMIT,
                        'default' => RoleGrantProposalCollectionProvider::DEFAULT_LIMIT,
                    ],
                ),
                'after' => new QueryParameter(
                    description: 'An opaque cursor from a previous response\'s next_cursor. Mutually exclusive with before.',
                    schema: ['type' => 'string'],
                ),
                'before' => new QueryParameter(
                    description: 'An opaque cursor from a previous response\'s previous_cursor. Mutually exclusive with after.',
                    schema: ['type' => 'string'],
                ),
            ],
            extraProperties: [PermissionGate::OPERATION_PERMISSION => 'role.assign'],
        ),
    ],
)]
final class RoleGrantProposalCollection
{
    /**
     * @param list<RoleGrantProposalResource> $items
     */
    public function __construct(
        #[ApiProperty(schema: ['type' => 'array', 'items' => ['$ref' => '#/components/schemas/RoleGrantProposal']])]
        public array $items,
        #[SerializedName('next_cursor')]
        public ?string $nextCursor,
        #[SerializedName('previous_cursor')]
        public ?string $previousCursor,
        /** ST-2's discriminator: probed only on an empty page, with no filter predicate (API-22). */
        #[SerializedName('tenant_has_rows')]
        public bool $tenantHasRows,
    ) {
    }
}
