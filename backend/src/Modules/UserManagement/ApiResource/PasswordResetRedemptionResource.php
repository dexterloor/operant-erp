<?php

declare(strict_types=1);

namespace App\Modules\UserManagement\ApiResource;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Post;
use ApiPlatform\OpenApi\Model\Operation as OpenApiOperation;
use ApiPlatform\OpenApi\Model\Response as OpenApiResponse;
use App\Modules\UserManagement\ApiResource\State\CreatePasswordResetRedemptionProcessor;
use App\Platform\Http\Instant;
use App\Platform\Security\PermissionGate;
use Symfony\Component\Serializer\Attribute\SerializedName;

/**
 * **Mail-C2 `CreatePasswordResetRedemption` — `POST /auth/password-resets/redemption`, pre-authentication**
 * (`docs/session13/mail-c2-build-plan.md` Revision 1 FINAL). `Create` is in the closed verb set (D-M6); the singular
 * sub-resource follows `/cancellation`.
 *
 * **200 `{changed_at}`** — API-21 branch 5 (a POST with no row-level URI), the `ChangePassword` precedent. Every bad, used,
 * expired or foreign link is the one constant 401 `authentication-failed`; a bad new password on a good link is 422
 * (`/password`); a spent IP budget 429. A redemption signs the person out everywhere and ends their other links; a sign-in in
 * the same second as `changed_at` is refused once, so a page may wait until `changed_at + 1 s` (owner notice N-2).
 */
#[ApiResource(
    shortName: 'PasswordResetRedemption',
    operations: [
        new Post(
            uriTemplate: '/auth/password-resets/redemption',
            name: 'CreatePasswordResetRedemption',
            status: 200,
            // BK-18 R18-h: this operation's own success description (no template is true of it) and its own refusals
            // beyond the derived set (its `Errors` cell). The decorator keeps each description; it gives the refusals the
            // house problem shape (`App\Platform\Http\ProblemResponses`).
            openapi: new OpenApiOperation(
                operationId: 'CreatePasswordResetRedemption',
                summary: 'Set a new password',
                description: 'Sets a new password using the token from a reset link, and signs the account out everywhere.',
                responses: [
                    '200' => new OpenApiResponse(description: 'The new password is saved; `changed_at` is when. Every session of this account has ended, this link and every other unused link for the account no longer work, and any pause on signing in is lifted. Sign in with the new password, no sooner than one second after `changed_at`: a token issued in that same second is not accepted.'),
                    '401' => new OpenApiResponse(description: 'This link can\'t be used. The same answer is given for every reason (for example a link that is wrong, expired or already used, or an account or workspace that can no longer sign in), so it does not say which.'),
                    '429' => new OpenApiResponse(description: 'Too many attempts from this network. This limit is shared with signing in. Wait before trying again; no wait time is given.'),
                ],
            ),
            input: CreatePasswordResetRedemptionInput::class,
            denormalizationContext: [
                // API-9 / B-3: only a deny-unknown deserializer stops an undeclared field being accepted.
                'allow_extra_attributes' => false,
                'api_assign_object_to_populate' => false,
            ],
            read: false,
            processor: CreatePasswordResetRedemptionProcessor::class,
            extraProperties: [PermissionGate::OPERATION_PERMISSION => null],
        ),
    ],
)]
final class PasswordResetRedemptionResource
{
    public function __construct(
        /** RFC 3339, whole seconds — the instant the session floor was raised to. */
        #[Instant]
        #[SerializedName('changed_at')]
        public string $changedAt,
    ) {
    }
}
