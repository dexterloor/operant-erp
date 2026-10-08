<?php

declare(strict_types=1);

namespace App\Modules\UserManagement\Application;

/**
 * One user as `UserReader` reads it — scalars only, so the reader returns no `ApiResource` class (A-7; `RoleView`'s rule).
 * §4.4.3's `User` row as rewritten at users slice 1 C1 (`docs/session13/users-s1-design.md` rev 3 §2.1, K-1).
 */
final readonly class UserView
{
    /**
     * @param list<array{id: string, name: string}> $roles the grants on LIVE roles, ordered `name, id`; `[]` for a soft-deleted user
     */
    public function __construct(
        public string $id,
        public string $email,
        public string $displayName,
        public bool $isActive,
        public ?string $archivedAt,
        public array $roles,
        public bool $marginExposure,
    ) {
    }
}
