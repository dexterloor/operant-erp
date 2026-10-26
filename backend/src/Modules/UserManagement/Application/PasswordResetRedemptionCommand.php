<?php

declare(strict_types=1);

namespace App\Modules\UserManagement\Application;

/** Mail-C2: what the redeem request carried. No workspace: the tenant is inside the token (API-7's second exception). */
final readonly class PasswordResetRedemptionCommand
{
    public function __construct(
        #[\SensitiveParameter] public string $token,
        #[\SensitiveParameter] public string $password,
        public ?string $ipAddress,
    ) {
    }
}
