<?php

declare(strict_types=1);

namespace App\Modules\UserManagement\ApiResource;

/**
 * Mail-C2: the token from the mailed link's URL fragment, in the BODY (never the path, so it is in no access log; D-M5),
 * and the new password. No workspace: the tenant is inside the token, bound by the keyed verifier MAC.
 */
final class CreatePasswordResetRedemptionInput
{
    public function __construct(
        #[\SensitiveParameter] public string $token,
        #[\SensitiveParameter] public string $password,
    ) {
    }
}
