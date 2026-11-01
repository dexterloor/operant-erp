<?php

declare(strict_types=1);

namespace App\Modules\UserManagement\ApiResource;

use ApiPlatform\Metadata\ApiProperty;

/**
 * API-23b — a foreign-key reference is returned expanded as
 * `{id, <display field>}` and never as a bare uuid, and an expansion carries
 * the id and the display field only. `role`'s display field is `name`.
 *
 * The "id and display field only" half is what keeps PC-12 out of this: an
 * expansion never carries a further reference, so a role here discloses its name
 * and never its permission list. The caller's **own** effective set is a
 * separate member of `CurrentUser`, flattened across roles, which is F-7 step 2's
 * *"a four-role user whose effective set is invisible is a user nobody can
 * audit"*.
 */
final class RoleReference
{
    /** The published item schema wherever a list of role references is served inline: the members below, nothing more. */
    public const WIRE_SCHEMA = ['type' => 'object', 'required' => ['id', 'name'], 'properties' => [
        'id' => ['type' => 'string', 'format' => 'uuid'],
        'name' => ['type' => 'string'],
    ]];

    /**
     * BK-14: the minted `RoleReference` component takes its members from {@see self::WIRE_SCHEMA}, so the component and the
     * inline `roles[]` items are one source.
     */
    public function __construct(
        #[ApiProperty(required: true, schema: self::WIRE_SCHEMA['properties']['id'])]
        public string $id,
        #[ApiProperty(required: true, schema: self::WIRE_SCHEMA['properties']['name'])]
        public string $name,
    ) {
    }
}
