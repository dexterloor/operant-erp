<?php

declare(strict_types=1);

namespace App\Modules\UserManagement\Application;

/**
 * **One grant rule's answer** (users slice 1 C1a; `docs/session13/users-s1-design.md` rev 3 §6 C1a, RED-2 H-2 fix 2): the
 * `user_role.*` code that refuses the grant, and the data its sentence needs — `{rows, role_name}` for
 * `separation_of_duty`, nothing for the others. A bare `?string` would lose PC-13's rows; the per-route sentences take
 * the route at {@see GrantRules::problem()}, never here. The picker (`ListOfferedRoles`) serves only `code`.
 */
final readonly class GrantVerdict
{
    /**
     * @param array{rows?: list<string>, role_name?: string} $data
     */
    public function __construct(
        public string $code,
        public array $data = [],
    ) {
    }
}
