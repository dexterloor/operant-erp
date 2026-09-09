<?php

declare(strict_types=1);

namespace App\Auth\ApiResource;

use Symfony\Component\Serializer\Attribute\SerializedName;

/**
 * §4.2's `ChangePassword` row, `Accepted request fields`: `current_password`;
 * `new_password`. **Exhaustive** — that column is the subject of §3.3's check,
 * and `allow_extra_attributes: false` on the operation is what makes it true at
 * runtime rather than only in the spec (API-9).
 *
 * **There is no `user_id` and there will not be one.** The principal comes from
 * the signed token, resolved by `AuthenticationGate` before the controller runs.
 * A field naming the subject would turn this into the administrative reset
 * **API-O-12** defers — a different operation, a different permission question,
 * and under `SECURITY_MODEL.md` §5.1.4's re-derivation trigger a **different
 * catalogue member** whose subject is `app_user`.
 *
 * **`current_password` is here because this operation costs it.** E-20's own
 * violation clause — *"any operation issues a token without the password among
 * its accepted request fields"* — is about token issue, but the reasoning
 * transfers: a credential change that a stolen token alone could make would let
 * an eight-hour window become a permanent one. Requiring the current password is
 * what stops this endpoint being the revocation-free session's escalation path.
 *
 * The properties are promoted and non-nullable, exactly as `IssueTokenInput`'s
 * are: a missing field is a `MissingConstructorArgumentsException`, which
 * `FrameworkProblemTranslator` already renders as `validation-failed` with a
 * pointer, so "required" needs no second expression.
 */
final class ChangePasswordInput
{
    public function __construct(
        #[SerializedName('current_password')]
        public string $currentPassword,
        #[SerializedName('new_password')]
        public string $newPassword,
    ) {
    }
}
