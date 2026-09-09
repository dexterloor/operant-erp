<?php

declare(strict_types=1);

namespace App\Modules\UserManagement\ApiResource;

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
    public function __construct(public string $id, public string $name)
    {
    }
}
