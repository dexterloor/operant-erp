<?php

declare(strict_types=1);

namespace App\Modules\UserManagement\Application;

/** The three answers `CreatePasswordReset` can give (Mail-C1). None of them depends on whether an account exists. */
enum PasswordResetRequestOutcome
{
    /** 202: well-formed and within the budgets — queued (or, if the queue failed, logged). Same for every such input. */
    case Accepted;

    /** 429 `too-many-attempts`: one of the two budgets shared with sign-in is spent (D-M3). */
    case Throttled;

    /** 422 `validation-failed`: the input is not a slug or an address. Decided before any statement. */
    case Invalid;
}
