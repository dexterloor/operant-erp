<?php

declare(strict_types=1);

namespace App\Modules\UserManagement\Infrastructure;

/**
 * **The PREVIOUS key's seal, for `app:credentials:check-key` only** (Mail-C1, `mail-c1-build-plan.md` R1.4 item 3; RED
 * M-7). `null` when `AUTH_CREDENTIAL_MAC_KEY_PREVIOUS` is empty — the ordinary state outside a rotation — and otherwise a
 * one-argument {@see CredentialSeal}: no declared key id and no production requirement, which belong to the current key
 * alone. Never wired by a global `bind:` or `_defaults`, which would hand the current key's arguments to this one.
 * Sign-in, `ChangePassword`, redeem and the worker do not accept PREVIOUS until Cred-C2.
 */
final class PreviousSealFactory
{
    private function __construct()
    {
    }

    public static function create(?string $previousKey): ?CredentialSeal
    {
        return null === $previousKey || '' === $previousKey ? null : new CredentialSeal($previousKey);
    }
}
