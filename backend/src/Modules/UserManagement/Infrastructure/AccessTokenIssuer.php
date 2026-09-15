<?php

declare(strict_types=1);

namespace App\Modules\UserManagement\Infrastructure;

use App\Platform\Security\AccessTokenVerifier;
use Firebase\JWT\JWT;

/**
 * The minting half of the access token — `SECURITY_MODEL.md` **E-20**: one
 * credential, an absolute lifetime of eight hours from issuance, and nothing
 * extends it.
 *
 * ---------------------------------------------------------------------------
 * Why minting stays in UserManagement (API-O-26, ruled 2026-09-14)
 * ---------------------------------------------------------------------------
 * `ARCHITECTURE.md` §4 gives UserManagement "JWT minting/refresh" and Platform
 * "JWT verification". Verifying moved to `Platform\Security\AccessTokenVerifier`;
 * minting did not follow it, because every module — `Ai` included — may import
 * `PlatformSecurity`, and a Platform class that signs would put a token within
 * reach of the LLM module. The algorithm is **read off the verifier**
 * (`AccessTokenVerifier::ALGORITHM`) rather than restated, so the two halves
 * cannot drift onto different algorithms by an edit to one of them; the key is
 * the same `%env(AUTH_TOKEN_SIGNING_KEY)%`, bound to both by name (Standing
 * Rule 10), and `AccessTokenSplitTest` holds the round trip.
 *
 * ---------------------------------------------------------------------------
 * What is in the token
 * ---------------------------------------------------------------------------
 * ADR-005 D-5: **`tenant_id` is a signed JWT claim**, read server-side and
 * passed to `set_config`, and never accepted from a header, query parameter or
 * body (T-1 / API-7). `sub` is the `app_user` id. `iat` and `exp` are E-20's
 * pair and `exp` is `iat` + `AccessTokenVerifier::LIFETIME_SECONDS` — derived, so no deployment
 * setting and no client-supplied value can reach it, which is two of E-20's
 * three violation clauses closed by construction rather than by a check.
 *
 * There is no `jti` and no refresh claim. D-14 ships neither `RefreshToken` nor
 * `RevokeSession`, there is no session store, and **no path mints a token from a
 * token** — a claim that only a revocation mechanism could use would be a
 * promise this wave does not keep (API-O-13).
 */
final readonly class AccessTokenIssuer
{
    public function __construct(private string $signingKey)
    {
        AccessTokenVerifier::refuseAKeyShorterThanTheDigest($signingKey);
    }

    public function issue(string $tenantId, string $userId, \DateTimeImmutable $issuedAt): IssuedToken
    {
        $iat = $issuedAt->getTimestamp();
        // Read off the verifier (V1-S-4), which refuses anything longer, so the
        // two halves cannot disagree about E-20's eight hours.
        $exp = $iat + AccessTokenVerifier::LIFETIME_SECONDS;

        $jwt = JWT::encode([
            'sub' => $userId,
            'tenant_id' => $tenantId,
            'iat' => $iat,
            'exp' => $exp,
        ], $this->signingKey, AccessTokenVerifier::ALGORITHM);

        return new IssuedToken($jwt, (new \DateTimeImmutable('@'.$exp))->setTimezone(new \DateTimeZone('UTC')));
    }
}
