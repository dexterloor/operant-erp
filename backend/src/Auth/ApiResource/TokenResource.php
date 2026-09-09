<?php

declare(strict_types=1);

namespace App\Auth\ApiResource;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Post;
use ApiPlatform\OpenApi\Model\Operation as OpenApiOperation;
use App\Api\Security\PermissionGate;
use App\Auth\ApiResource\State\IssueTokenProcessor;
use Symfony\Component\Serializer\Attribute\SerializedName;

/**
 * `POST /api/v1/auth/token` — §4.2's `IssueToken`.
 *
 * ---------------------------------------------------------------------------
 * 200 and not 201, and the branch that decides it
 * ---------------------------------------------------------------------------
 * API-21 branch 5: a `POST` that creates no row this contract addresses. §4.4.1
 * names `IssueToken` in that branch in terms — *"a token is not a row"* — so
 * there is no `Location` and no row-level URI to name.
 *
 * ---------------------------------------------------------------------------
 * The response shape is a gap this class fills and records rather than invents
 * ---------------------------------------------------------------------------
 * API-23 derives a read shape from the resource's `Create*` row; this resource's
 * write row is `Issue / Token`, a different verb, so step 1 finds nothing, step 3
 * adds nothing (a token has no `SchemaManifest` deletion class because it has no
 * table), and **API-23a therefore requires an additions row in §4.4.3 — which
 * §4.4.3 does not have.** That is the same vacuity API-23a exists to catch,
 * arriving on the one resource whose shape nobody wrote down. Raised in
 * `API.md` §14.2; the three members below are the minimum a client needs and no
 * more.
 *
 * **`expires_at` and not `expires_in`.** E-20 is an *absolute* lifetime with no
 * sliding window, and a relative figure is the shape a client caches and then
 * refreshes against. The absolute instant is also the only one that stays true
 * if the response sits in a queue.
 *
 * **No refresh token, and no member that hints at one.** D-14 does not ship
 * `RefreshToken`; ADR-005 binds it to a BFF cookie; and E-20's violation clause
 * covers *"any path that issues a token on presentation of a token rather than
 * of a password, whatever it is named"*.
 */
#[ApiResource(
    shortName: 'Token',
    operations: [
        new Post(
            uriTemplate: '/auth/token',
            name: 'IssueToken',
            status: 200,
            openapi: new OpenApiOperation(operationId: 'IssueToken'),
            input: IssueTokenInput::class,
            denormalizationContext: [
                // API-9 / B-3. The spec-level clause stops a field being
                // *declared*; only a deny-unknown deserializer stops it being
                // *accepted*.
                'allow_extra_attributes' => false,
                'api_assign_object_to_populate' => false,
            ],
            read: false,
            processor: IssueTokenProcessor::class,
            // §4.2 marks this row `Idem: no`. Stated because every other write
            // operation in the tree carries the guard flag and its absence here
            // is a decision: a repeated sign-in is not a duplicated row, and an
            // idempotency key on a credential-bearing request would be a
            // client-supplied handle to a response containing a token.
            //
            // §4.2's `Perm` cell is `—`, declared rather than omitted:
            // `PermissionGate` refuses an operation that carries no declaration
            // at all, so "this operation needs no code" has to be said out loud.
            // §4.2's other rule -- whether a JWT is required -- is decided by
            // this row's own Path, and `/auth/*` is pre-authentication.
            extraProperties: [PermissionGate::OPERATION_PERMISSION => null],
        ),
    ],
)]
final class TokenResource
{
    public function __construct(
        #[SerializedName('access_token')]
        public string $accessToken,
        #[SerializedName('token_type')]
        public string $tokenType,
        /** RFC 3339. E-20: `iat` + 8h, and nothing extends it. */
        #[SerializedName('expires_at')]
        public string $expiresAt,
    ) {
    }
}
