<?php

declare(strict_types=1);

namespace App\Modules\UserManagement\ApiResource;

use ApiPlatform\Metadata\ApiProperty;

/**
 * `CreatePasswordReset`'s body (Mail-C1; §4.2 `Accepts`: `workspace`; `email`). Deny-unknown, like sign-in's: a field the
 * spec does not declare is refused, never ignored.
 */
final class CreatePasswordResetInput
{
    public function __construct(
        #[ApiProperty(required: true, schema: ['type' => 'string', 'minLength' => 1])]
        public string $workspace,
        #[ApiProperty(required: true, schema: ['type' => 'string', 'minLength' => 1])]
        public string $email,
    ) {
    }
}
