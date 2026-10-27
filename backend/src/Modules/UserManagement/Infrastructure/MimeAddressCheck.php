<?php

declare(strict_types=1);

namespace App\Modules\UserManagement\Infrastructure;

use App\Modules\UserManagement\Domain\ChecksMailAddresses;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Exception\InvalidArgumentException as MimeInvalidArgument;
use Symfony\Component\Mime\Exception\RfcComplianceException;

/**
 * **The worker's own call, made at create time** (U-C1b; `u-c1-final-red.md` M-2, applied as prescribed). The mail
 * worker builds `new Address($storedEmail)` outside its transport `catch`, so a stored value Mime refuses can never be
 * mailed: its message retries into `failed` for ever. So the create rule IS that call — `new Address()` on the
 * NORMALISED value (the one stored), not a validator chosen to resemble it: Mime validates with egulias'
 * `MessageIDValidation`, not `RFCValidation`, and the two differ (`a(comment)@example.test`: RFC accepts, Mime refuses).
 *
 * Then any `\p{Z}` separator (U+00A0, U+3000, U+2028 … — Mime accepts several; `SEC`/`UX` TO CONFIRM).
 *
 * Only Mime's two refusals are a "no": `RfcComplianceException` (the addr-spec) and its `InvalidArgumentException` (control
 * characters). A `LogicException` (egulias not installed) propagates: a missing library is a 500, never "your address is
 * wrong".
 *
 * **It does not establish deliverability** (owner notice N-9): `ünï@example.test` passes and still needs an SMTPUTF8
 * server (a residual).
 */
final readonly class MimeAddressCheck implements ChecksMailAddresses
{
    private const SEPARATOR = '/\p{Z}/u';

    public function accepts(string $normalised): bool
    {
        try {
            new Address($normalised);
        } catch (RfcComplianceException|MimeInvalidArgument) {
            return false;
        }

        return 1 !== preg_match(self::SEPARATOR, $normalised);
    }
}
