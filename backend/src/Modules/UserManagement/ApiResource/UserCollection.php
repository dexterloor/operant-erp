<?php

declare(strict_types=1);

namespace App\Modules\UserManagement\ApiResource;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\QueryParameter;
use ApiPlatform\OpenApi\Model\Operation as OpenApiOperation;
use App\Modules\UserManagement\ApiResource\State\UserCollectionProvider;
use App\Platform\Security\PermissionGate;
use Symfony\Component\Serializer\Attribute\SerializedName;

/**
 * **`ListUsers` — `GET /api/v1/users`, `app_user.view`, *(bounded)*, `archived`; `q`** (users slice 1 C2;
 * `docs/session13/users-s1-design.md` rev 3 §2.3).
 *
 * **Bounded (TB-1c)**: the cursors are always null; no `limit`, `after`, `before` or `sort` (undeclared, so refused). Rows
 * come back by `email`, then `id` — both NOT NULL, so no nullable-sort-key trap (`6fe8b7d`).
 *
 * `archived` is offered on **API-19c limb 1** (`IA.md` §5.6's `/admin/users` Archived row). `q` is offered because TB-5
 * names `app_user.email` (API-19d): a case-insensitive prefix of the email, never `display_name`, never a substring. No
 * `is_active` segment: §4.2 declares none, so a deactivated user is in the live list.
 *
 * Each item is the `User` component — `GetUser`'s shape, the same for every caller, which is why `GetUser` shipped first
 * (the `$ref` resolves only once a non-List operation mints the component).
 */
#[ApiResource(
    shortName: 'UserCollection',
    operations: [
        new Get(
            uriTemplate: '/users',
            name: 'ListUsers',
            openapi: new OpenApiOperation(operationId: 'ListUsers'),
            provider: UserCollectionProvider::class,
            parameters: [
                'archived' => new QueryParameter(
                    description: 'Return the removed (archived) users instead of the live ones. API-19c limb 1: IA §5.6 has an /admin/users Archived view.',
                    schema: ['type' => 'boolean', 'default' => false],
                ),
                'q' => new QueryParameter(
                    description: 'Free-text search. API-19d/TB-5 name one searchable column on this table, app_user.email, and it is the only column searched (a case-insensitive prefix, IA TB-5a).',
                    schema: ['type' => 'string'],
                ),
            ],
            // §4.2's `Perm` cell. Enforced by `PermissionGate` on `kernel.controller`, before the provider loads anything.
            extraProperties: [PermissionGate::OPERATION_PERMISSION => 'app_user.view'],
        ),
    ],
)]
final class UserCollection
{
    /**
     * @param list<UserResource> $items
     */
    public function __construct(
        #[ApiProperty(schema: ['type' => 'array', 'items' => ['$ref' => '#/components/schemas/User']])]
        public array $items,
        /** Always null on a bounded list (§4.4.2). */
        #[SerializedName('next_cursor')]
        public ?string $nextCursor,
        #[SerializedName('previous_cursor')]
        public ?string $previousCursor,
        /** ST-2's discriminator: probed only on an empty page, against `app_user` with no filter predicate (API-22). */
        #[SerializedName('tenant_has_rows')]
        public bool $tenantHasRows,
    ) {
    }
}
