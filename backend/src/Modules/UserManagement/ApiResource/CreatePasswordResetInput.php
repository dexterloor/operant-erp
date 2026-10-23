<?php

declare(strict_types=1);

namespace App\Modules\UserManagement\ApiResource;

/**
 * `CreatePasswordReset`'s body (Mail-C1; §4.2 `Accepts`: `workspace`; `email`). Deny-unknown, like sign-in's: a field the
 * spec does not declare is refused, never ignored.
 */
final class CreatePasswordResetInput
{
    public function __construct(
        public string $workspace,
        public string $email,
    ) {
    }
}
