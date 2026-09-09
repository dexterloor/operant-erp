<?php

declare(strict_types=1);

namespace App\Modules\UserManagement\Application;

/** The three fields §4.2's `IssueToken` row accepts, plus the address the server observed. */
final readonly class SignInCommand
{
    public function __construct(
        public string $workspace,
        public string $email,
        public string $password,
        public ?string $ipAddress,
    ) {
    }
}
