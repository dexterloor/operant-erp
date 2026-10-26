<?php

declare(strict_types=1);

namespace App\Modules\UserManagement\Application;

use Symfony\Component\PasswordHasher\PasswordHasherInterface;

/**
 * **E-24, the one copy** (Mail-C2, `docs/session13/mail-c2-build-plan.md` §5.1). Extracted from `ChangePassword` so the
 * password a mailed reset link sets follows exactly the rule a signed-in change follows: the same three checks, the same
 * codes, the same messages and the same order. Only the `errors[]` pointer differs (`/new_password` for a change,
 * `/password` for a redemption), so it is the one parameter.
 *
 * Why each check is what it is — twelve **characters** (E-24, the user's decision of 2026-09-10), empty kept apart from too
 * short, and a maximum in the component's own unit (bytes, `PasswordHasherInterface::MAX_PASSWORD_LENGTH`, above which
 * `NativePasswordHasher::hash()` throws) — is argued in `ChangePassword`'s class docblock and not restated here.
 * `PasswordChangeTest` pins 12 as a literal of its own against `ChangePassword::MINIMUM_LENGTH`, which aliases this.
 *
 * The codes stay `new_password_*` on both operations (`UX` TO CONFIRM): a client already maps them.
 */
final class PasswordRules
{
    /** `SECURITY_MODEL.md` §5 E-24. The register's figure, not this class's. */
    public const MINIMUM_LENGTH = 12;

    public const EMPTY = 'new_password_empty';

    public const TOO_SHORT = 'new_password_too_short';

    public const TOO_LONG = 'new_password_too_long';

    /** Not instantiable: one pure function. */
    private function __construct()
    {
    }

    /**
     * @return list<array{pointer: string, code: string, message: string}> empty when the password is acceptable
     */
    public static function errors(string $password, string $pointer): array
    {
        if ('' === $password) {
            return [[
                'pointer' => $pointer,
                'code' => self::EMPTY,
                'message' => 'The new password must not be empty.',
            ]];
        }

        // E-24. Characters, not bytes: four emoji are not twelve characters.
        if (mb_strlen($password, 'UTF-8') < self::MINIMUM_LENGTH) {
            return [[
                'pointer' => $pointer,
                'code' => self::TOO_SHORT,
                'message' => \sprintf('The new password must be at least %d characters.', self::MINIMUM_LENGTH),
            ]];
        }

        // The component's bound, in the component's unit: exceeding it makes the KDF throw, and an invalid request must
        // not be a 500.
        if (\strlen($password) > PasswordHasherInterface::MAX_PASSWORD_LENGTH) {
            return [[
                'pointer' => $pointer,
                'code' => self::TOO_LONG,
                'message' => \sprintf('The new password must be at most %d bytes.', PasswordHasherInterface::MAX_PASSWORD_LENGTH),
            ]];
        }

        return [];
    }
}
