<?php

declare(strict_types=1);

namespace App\Modules\UserManagement\Domain;

/**
 * **The create-time address rule (U-C1b, `u-c1-build-plan.md` L-3 as settled by the spar; `u-c1-final-red.md` M-2):** would
 * the mail worker be able to build an address out of this stored value at all? A port, so Application imports no mailer
 * library (`ARCH` TO CONFIRM the placement); the one implementation is `Infrastructure\MimeAddressCheck`.
 *
 * **Never folded into {@see EmailAddress}** — that is the lookup predicate and a wire contract (L-7: nothing stricter than
 * storage), and a stricter `EmailAddress` would lock out addresses already stored (`PasswordResetRequestTest::
 * aStoredAddressTheCreateRuleRefusesStillRequestsAReset`).
 */
interface ChecksMailAddresses
{
    /** @param string $normalised the value `EmailAddress::fromInput()` produced — exactly what will be stored */
    public function accepts(string $normalised): bool;
}
