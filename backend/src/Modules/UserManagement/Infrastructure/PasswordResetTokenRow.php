<?php

declare(strict_types=1);

namespace App\Modules\UserManagement\Infrastructure;

/**
 * What `PasswordResetTokenLookup` read in its one statement (Mail-C2 step 3). Every step-4 check runs on these values with
 * no further statement. Carries no secret: the verifier is the client's, and `verifierMac` is a keyed MAC of it.
 */
final readonly class PasswordResetTokenRow
{
    public function __construct(
        public string $id,
        public string $tenantId,
        public string $userId,
        public string $purpose,
        public string $selector,
        public string $verifierMac,
        public int $expiresUnix,
        public bool $used,
        public int $createdUnix,
        public string $storedEmail,
        public bool $userIsActive,
        public bool $uncredentialed,
        public ?string $identityMac,
        public ?float $sessionFloorEpoch,
        public string $tenantSlug,
        public string $tenantStatus,
    ) {
    }
}
