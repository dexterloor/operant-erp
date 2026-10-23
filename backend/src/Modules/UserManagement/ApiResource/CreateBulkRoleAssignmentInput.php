<?php

declare(strict_types=1);

namespace App\Modules\UserManagement\ApiResource;

use ApiPlatform\Metadata\ApiProperty;
use Symfony\Component\Serializer\Attribute\SerializedName;

/**
 * `CreateBulkRoleAssignment`'s body: `{role_id, user_ids}` (§4.2's cell). An unknown top-level field is refused by the
 * deserializer (`allow_extra_attributes: false`, API-9). The types are checked by the processor, not by PHP property types,
 * so a wrong type is a 422 naming the field (`CreateBulkArchivalInput`'s precedent).
 */
final class CreateBulkRoleAssignmentInput
{
    #[SerializedName('role_id')]
    #[ApiProperty(description: 'The role to give: one role in this workspace.', required: true, schema: ['type' => 'string', 'format' => 'uuid'])]
    public mixed $roleId = null;

    #[SerializedName('user_ids')]
    #[ApiProperty(
        description: 'The people to give it to: 1 to 50 distinct user ids.',
        required: true,
        schema: ['type' => 'array', 'minItems' => 1, 'maxItems' => 50, 'uniqueItems' => true, 'items' => ['type' => 'string', 'format' => 'uuid']],
    )]
    public mixed $userIds = null;
}
