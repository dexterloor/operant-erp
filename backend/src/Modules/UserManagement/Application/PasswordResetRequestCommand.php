<?php

declare(strict_types=1);

namespace App\Modules\UserManagement\Application;

/** `CreatePasswordReset`'s input, as typed, plus the client address (Mail-C1). Nothing in it has been looked up. */
final readonly class PasswordResetRequestCommand
{
    public function __construct(
        public string $workspace,
        public string $email,
        public ?string $ipAddress,
    ) {
    }
}
