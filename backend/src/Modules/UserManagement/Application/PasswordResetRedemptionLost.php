<?php

declare(strict_types=1);

namespace App\Modules\UserManagement\Application;

/**
 * Mail-C2: thrown inside step 7's transaction to roll it back when the link was used, or the person changed, between the
 * step-4 checks and the write. Never escapes `PasswordResetRedemption`: it becomes the one constant refusal.
 */
final class PasswordResetRedemptionLost extends \RuntimeException
{
}
