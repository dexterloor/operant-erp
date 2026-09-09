<?php

declare(strict_types=1);

namespace App\Modules\UserManagement\Application;

/**
 * The three answers `POST /api/v1/me/password` has.
 *
 * **`Refused` is not E-7's `Refused` and the difference is ruled rather than
 * inherited.** On the sign-in path every refusal is one case *on the wire*
 * because the prober may not be the principal. Here `AuthenticationGate` has
 * already resolved the caller as this principal from a signed token, so the
 * response says which of the caller's **own** inputs was wrong — see
 * `ChangePassword`'s docblock for the ruling and for the property that is kept
 * from E-7 regardless, which is the KDF invocation count.
 */
enum ChangePasswordOutcome
{
    case Changed;
    case Refused;
    case Throttled;
}
