<?php

declare(strict_types=1);

namespace App\Auth\Application;

/**
 * `ChangePassword`'s two accepted request fields (§4.2), plus the three things
 * the **server** already knows about the caller and the address it observed.
 *
 * The principal is not a request field and there is no parameter that could make
 * it one. §4.2's row declares `current_password` and `new_password` and nothing
 * else, and the operation is self-service **by construction**: `tenantId`,
 * `actorUserId` and `actorLabel` come from the `Identity`
 * `App\Api\Security\AuthenticationGate` resolved out of the signed token before
 * the controller ran. A `user_id` field here would be the administrative reset
 * API-O-12 defers — a different operation, a different permission question and,
 * under `SECURITY_MODEL.md` §5.1.4's re-derivation trigger, a different catalogue
 * member.
 */
final readonly class ChangePasswordCommand
{
    public function __construct(
        public string $tenantId,
        public string $actorUserId,
        public string $actorLabel,
        public string $currentPassword,
        public string $newPassword,
        public ?string $ipAddress,
    ) {
    }
}
