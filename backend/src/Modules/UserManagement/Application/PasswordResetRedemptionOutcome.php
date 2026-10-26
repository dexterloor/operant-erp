<?php

declare(strict_types=1);

namespace App\Modules\UserManagement\Application;

/** Mail-C2: the four answers. `Refused` is the one constant `authentication-failed` for every bad or lost link. */
enum PasswordResetRedemptionOutcome
{
    case Changed;
    case Refused;
    case Invalid;
    case Throttled;
}
